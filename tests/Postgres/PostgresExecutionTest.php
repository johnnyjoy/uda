<?php

declare(strict_types=1);

namespace Tests\Postgres;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use UDA\Database;
use UDA\Query\Sql;

final class PostgresExecutionTest extends PostgresTestCase
{
    /**
     * @return Generator<string,array{string}>
     */
    public static function executionScenarioProvider(): Generator
    {
        foreach ([
            'exerciseSelectOrdering',
            'exerciseReturningCrud',
            'exerciseUpsertDoUpdate',
            'exerciseUpsertDoNothing',
            'exerciseRecursiveCte',
            'exerciseUnionQueries',
            'exerciseWindowFunctions',
            'exerciseRawSqlQueries',
        ] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    #[DataProvider('executionScenarioProvider')]
    public function testExecutionScenarios(string $scenario): void
    {
        $this->withPostgresDb(function (Database $db) use ($scenario): void {
            $this->{$scenario}($db);
        });
    }

    private function exerciseSelectOrdering(Database $db): void
    {
        $rows = $db->rows(
            'SELECT name, title, salary FROM employees WHERE salary >= :threshold ORDER BY hired_at DESC LIMIT 2 OFFSET 0',
            ['threshold' => 150000]
        );

        self::assertNotEmpty($rows);
        self::assertGreaterThanOrEqual(1, count($rows));
        self::assertSame('Noel Ops', $rows[0]['name']);
        self::assertSame('Operations Lead', $rows[0]['title']);
    }

    private function exerciseReturningCrud(Database $db): void
    {
        $inserted = $db->returning(
            'INSERT INTO transactions (account, amount, created_at) VALUES (:account, :amount, NOW()) '
            . 'RETURNING id, account, amount',
            ['account' => 'eng', 'amount' => 999.50]
        );

        self::assertCount(1, $inserted);
        $row = $inserted[0];
        $transactionId = (int) $row['id'];
        self::assertSame('eng', $row['account']);

        $updated = $db->returning(
            'UPDATE transactions SET amount = amount + :delta WHERE id = :id RETURNING amount',
            ['delta' => 10.25, 'id' => $transactionId]
        );
        self::assertSame(1009.75, (float) $updated[0]['amount']);

        $deleted = $db->returning(
            'DELETE FROM transactions WHERE id = :id RETURNING id',
            ['id' => $transactionId]
        );
        self::assertCount(1, $deleted);
    }

    private function exerciseUpsertDoUpdate(Database $db): void
    {
        $db->exec('DELETE FROM employees WHERE employee_no = :employee_no', ['employee_no' => 'ENG-777']);

        $firstInsert = $db->returning(
            'INSERT INTO employees (department_id, employee_no, name, title, hired_at, salary) '
            . 'VALUES (:department_id, :employee_no, :name, :title, :hired_at, :salary) '
            . 'ON CONFLICT (employee_no) DO UPDATE SET title = EXCLUDED.title, salary = EXCLUDED.salary '
            . 'RETURNING id, title, salary',
            [
                'department_id' => 10,
                'employee_no' => 'ENG-777',
                'name' => 'Phoenix Upsert',
                'title' => 'Software Engineer',
                'hired_at' => '2024-01-01 00:00:00+00',
                'salary' => 150000.00,
            ]
        );
        $employeeId = (int) $firstInsert[0]['id'];

        $secondInsert = $db->returning(
            'INSERT INTO employees (department_id, employee_no, name, title, hired_at, salary) '
            . 'VALUES (:department_id, :employee_no, :name, :title, :hired_at, :salary) '
            . 'ON CONFLICT (employee_no) DO UPDATE SET title = EXCLUDED.title, salary = EXCLUDED.salary '
            . 'RETURNING id, title, salary',
            [
                'department_id' => 10,
                'employee_no' => 'ENG-777',
                'name' => 'Phoenix Upsert',
                'title' => 'Staff Engineer',
                'hired_at' => '2024-01-01 00:00:00+00',
                'salary' => 165000.00,
            ]
        );

        self::assertSame($employeeId, (int) $secondInsert[0]['id']);
        self::assertSame('Staff Engineer', $secondInsert[0]['title']);
        self::assertSame(165000.00, (float) $secondInsert[0]['salary']);

        $db->exec('DELETE FROM employees WHERE employee_no = :employee_no', ['employee_no' => 'ENG-777']);
    }

    private function exerciseUpsertDoNothing(Database $db): void
    {
        $baseline = (int) $db->value('SELECT COUNT(*) FROM employees');
        $result = $db->returning(
            'INSERT INTO employees (department_id, employee_no, name, title, hired_at, salary) '
            . 'VALUES (10, :employee_no, :name, :title, :hired_at, :salary) '
            . 'ON CONFLICT (employee_no) DO NOTHING RETURNING id',
            [
                'employee_no' => 'ENG-001',
                'name' => 'Duplicate Ada',
                'title' => 'Principal Engineer',
                'hired_at' => '2024-02-01 00:00:00+00',
                'salary' => 200000.00,
            ]
        );

        self::assertSame([], $result, 'DO NOTHING should not return rows');
        self::assertSame($baseline, (int) $db->value('SELECT COUNT(*) FROM employees'));
    }

    private function exerciseRecursiveCte(Database $db): void
    {
        $row = $db->row(
            'WITH RECURSIVE tree_paths AS ('
            . '    SELECT id, parent_id, name, 1 AS depth FROM tree_nodes WHERE parent_id IS NULL'
            . '    UNION ALL'
            . '    SELECT child.id, child.parent_id, child.name, tree_paths.depth + 1'
            . '    FROM tree_nodes AS child'
            . '    JOIN tree_paths ON child.parent_id = tree_paths.id'
            . ')
            SELECT MAX(depth) AS max_depth, COUNT(*) AS total_nodes FROM tree_paths'
        );

        self::assertNotNull($row);
        self::assertSame(3, (int) $row['max_depth']);
        self::assertSame(4, (int) $row['total_nodes']);
    }

    private function exerciseUnionQueries(Database $db): void
    {
        $rows = $db->rows(
            'SELECT name, \'employee\' AS category FROM employees WHERE salary >= :salary '
            . 'UNION ALL '
            . 'SELECT name, \'department\' AS category FROM departments '
            . 'ORDER BY category, name',
            ['salary' => 150000]
        );

        self::assertNotEmpty($rows);
        self::assertSame('department', $rows[0]['category']);
        self::assertSame('employee', $rows[array_key_last($rows)]['category']);
    }

    private function exerciseWindowFunctions(Database $db): void
    {
        $rows = $db->rows(
            'SELECT name, salary, ROW_NUMBER() OVER (ORDER BY salary DESC) AS rank '
            . 'FROM employees ORDER BY rank'
        );

        self::assertNotEmpty($rows);
        self::assertSame(1, (int) $rows[0]['rank']);
        self::assertGreaterThan((float) $rows[1]['salary'], (float) $rows[0]['salary']);
    }

    private function exerciseRawSqlQueries(Database $db): void
    {
        $sql = Sql::of(
            'SELECT COUNT(*) AS matched FROM employees WHERE title LIKE :title',
            ['title' => '%Engineer%'],
            ['employees']
        );

        $rows = $db->rows($sql);
        self::assertSame(1, count($rows));
        self::assertGreaterThanOrEqual(1, (int) $rows[0]['matched']);
    }
}
