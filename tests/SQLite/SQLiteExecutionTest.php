<?php

declare(strict_types=1);

namespace Tests\SQLite;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use UDA\Database;

final class SQLiteExecutionTest extends SQLiteTestCase
{
    /**
     * @return Generator<string,array{string,string}>
     */
    public static function executionScenarioProvider(): Generator
    {
        $dsns = ['withMemoryDb', 'withTempDb'];
        $scenarios = [
            'exerciseCrud',
            'exerciseReturning',
            'exerciseTransactions',
            'exerciseSavepoints',
            'exerciseNonRecursiveCte',
            'exerciseRecursiveCte',
        ];

        foreach ($scenarios as $scenario) {
            foreach ($dsns as $dsnHelper) {
                $datasetKey = sprintf('%s:%s', $dsnHelper, $scenario);
                yield $datasetKey => [$dsnHelper, $scenario];
            }
        }
    }

    #[DataProvider('executionScenarioProvider')]
    public function testExecutionScenarios(string $dsnHelper, string $scenario): void
    {
        $this->{$dsnHelper}(function (Database $db) use ($scenario): void {
            $this->{$scenario}($db);
        });
    }

    private function exerciseCrud(Database $db): void
    {
        $baselineEmployees = (int) $db->value('SELECT COUNT(*) FROM employees');
        self::assertSame(2, $baselineEmployees, 'Unexpected fixture baseline');

        $payload = [
            'id' => 3,
            'name' => 'Casey Query',
            'title' => 'Staff Engineer',
            'hired_at' => '2024-09-30',
        ];

        $inserted = $db->exec(
            'INSERT INTO employees (id, name, title, hired_at) VALUES (:id, :name, :title, :hired_at)',
            $payload
        );
        self::assertSame(1, $inserted, 'Employee insert failed');

        $row = $db->row('SELECT name, title FROM employees WHERE id = :id', ['id' => $payload['id']]);
        self::assertNotNull($row);
        self::assertSame('Casey Query', $row['name']);
        self::assertSame('Staff Engineer', $row['title']);

        $updates = $db->exec(
            'UPDATE employees SET title = :title WHERE id = :id',
            ['title' => 'Director of Databases', 'id' => $payload['id']]
        );
        self::assertSame(1, $updates, 'Employee update failed');

        $updatedRow = $db->row('SELECT title FROM employees WHERE id = :id', ['id' => $payload['id']]);
        self::assertNotNull($updatedRow);
        self::assertSame('Director of Databases', $updatedRow['title']);

        $deleted = $db->exec('DELETE FROM employees WHERE id = :id', ['id' => $payload['id']]);
        self::assertSame(1, $deleted, 'Employee delete failed');

        $finalCount = (int) $db->value('SELECT COUNT(*) FROM employees');
        self::assertSame($baselineEmployees, $finalCount, 'CRUD scenario left residual rows');
    }

    private function exerciseReturning(Database $db): void
    {
        $db->exec('DELETE FROM contractors WHERE id >= 200');

        $returningRows = $db->returning(
            'INSERT INTO contractors (name, company, hourly_rate) VALUES (:name, :company, :hourly_rate) '
            . 'RETURNING id, name, company, hourly_rate',
            ['name' => 'Dana Returning', 'company' => 'Result Set LLC', 'hourly_rate' => 250.00]
        );

        self::assertCount(1, $returningRows);
        $row = $returningRows[0];
        self::assertArrayHasKey('id', $row);
        $contractorId = (int) $row['id'];

        self::assertSame('Dana Returning', $row['name']);
        self::assertSame('Result Set LLC', $row['company']);
        self::assertEquals(250.00, (float) $row['hourly_rate']);

        $persisted = $db->row('SELECT name, company FROM contractors WHERE id = :id', ['id' => $contractorId]);
        self::assertNotNull($persisted);
        self::assertSame('Dana Returning', $persisted['name']);
        self::assertSame('Result Set LLC', $persisted['company']);

        $db->exec('DELETE FROM contractors WHERE id = :id', ['id' => $contractorId]);
    }

    private function exerciseTransactions(Database $db): void
    {
        $baseline = (int) $db->value('SELECT COUNT(*) FROM salaries');

        $db->exec('BEGIN');
        $db->exec(
            'INSERT INTO salaries (id, employee_id, contractor_id, amount, paid_at) '
            . 'VALUES (:id, :employee_id, :contractor_id, :amount, :paid_at)',
            ['id' => 9001, 'employee_id' => 1, 'contractor_id' => null, 'amount' => 150000, 'paid_at' => '2024-04-30']
        );
        $db->exec('ROLLBACK');
        self::assertSame($baseline, (int) $db->value('SELECT COUNT(*) FROM salaries'), 'Rollback failed');

        $db->exec('BEGIN');
        $db->exec(
            'INSERT INTO salaries (id, employee_id, contractor_id, amount, paid_at) '
            . 'VALUES (:id, :employee_id, :contractor_id, :amount, :paid_at)',
            ['id' => 9002, 'employee_id' => 2, 'contractor_id' => null, 'amount' => 151000, 'paid_at' => '2024-05-31']
        );
        $db->exec('COMMIT');
        self::assertSame($baseline + 1, (int) $db->value('SELECT COUNT(*) FROM salaries'), 'Commit did not persist row');

        $db->exec('DELETE FROM salaries WHERE id = :id', ['id' => 9002]);
        self::assertSame($baseline, (int) $db->value('SELECT COUNT(*) FROM salaries'), 'Cleanup failed');
    }

    private function exerciseSavepoints(Database $db): void
    {
        $db->exec('DELETE FROM salaries WHERE id >= 8000');

        $db->exec('BEGIN');
        $db->exec(
            'INSERT INTO salaries (id, employee_id, contractor_id, amount, paid_at) '
            . 'VALUES (8001, 1, NULL, 160000, "2024-06-30")'
        );
        $db->exec('SAVEPOINT after_first');
        $db->exec('INSERT INTO salaries (id, employee_id, contractor_id, amount, paid_at) VALUES (8002, 2, NULL, 161000, "2024-07-31")');
        $db->exec('ROLLBACK TO after_first');
        $db->exec('INSERT INTO salaries (id, employee_id, contractor_id, amount, paid_at) VALUES (8003, 2, NULL, 162000, "2024-08-31")');
        $db->exec('RELEASE after_first');
        $db->exec('COMMIT');

        $persisted = $db->list(
            'SELECT id FROM salaries WHERE id IN (8001, 8002, 8003) ORDER BY id'
        );

        self::assertSame([8001, 8003], array_map('intval', $persisted));

        $db->exec('DELETE FROM salaries WHERE id IN (8001, 8003)');
    }

    private function exerciseNonRecursiveCte(Database $db): void
    {
        $rows = $db->rows(
            'WITH workforce AS ('
            . '    SELECT id, name, "employee" AS category FROM employees'
            . '    UNION ALL'
            . '    SELECT id, name, "contractor" AS category FROM contractors'
            . ')
            SELECT category, COUNT(*) AS total FROM workforce GROUP BY category ORDER BY category'
        );

        self::assertSame(2, count($rows));
        self::assertSame('contractor', $rows[0]['category']);
        self::assertSame(1, (int) $rows[0]['total']);
        self::assertSame('employee', $rows[1]['category']);
        self::assertSame(2, (int) $rows[1]['total']);
    }

    private function exerciseRecursiveCte(Database $db): void
    {
        $row = $db->row(
            'WITH RECURSIVE seq(n) AS ('
            . '    SELECT 1'
            . '    UNION ALL'
            . '    SELECT n + 1 FROM seq WHERE n < 5'
            . ')
            SELECT SUM(n) AS total, MAX(n) AS max_value FROM seq'
        );

        self::assertNotNull($row);
        self::assertSame(15, (int) $row['total']);
        self::assertSame(5, (int) $row['max_value']);
    }
}
