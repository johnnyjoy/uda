<?php

declare(strict_types=1);

namespace Tests\Postgres;

use UDA\Database;

final class PostgresTestCaseTest extends PostgresTestCase
{
    public function testSchemaAndFixturesAreDeterministic(): void
    {
        $collector = $this->registerTraceCollector();

        $this->withPostgresDb(function (Database $db) use ($collector): void {
            $expectedTables = ['audit_log', 'departments', 'employees', 'transactions', 'tree_nodes'];
            $actualTables = array_column(
                $db->rows('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()'),
                'tablename'
            );
            sort($actualTables);

            self::assertSame($expectedTables, $actualTables);

            $departments = $db->rows('SELECT id, name FROM departments ORDER BY id');
            self::assertSame([
                ['id' => 10, 'name' => 'Engineering'],
                ['id' => 20, 'name' => 'Operations'],
            ], $departments);

            $employees = $db->rows(
                'SELECT id, department_id, employee_no, name, title, salary FROM employees ORDER BY id'
            );
            $employeeSnapshot = array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'department_id' => (int) $row['department_id'],
                    'employee_no' => $row['employee_no'],
                    'name' => $row['name'],
                    'title' => $row['title'],
                    'salary' => (float) $row['salary'],
                ],
                $employees
            );
            self::assertSame([
                [
                    'id' => 1,
                    'department_id' => 10,
                    'employee_no' => 'ENG-001',
                    'name' => 'Ada Lovelace',
                    'title' => 'Principal Engineer',
                    'salary' => 180000.00,
                ],
                [
                    'id' => 2,
                    'department_id' => 20,
                    'employee_no' => 'OPS-010',
                    'name' => 'Noel Ops',
                    'title' => 'Operations Lead',
                    'salary' => 150000.00,
                ],
            ], $employeeSnapshot);

            $audit = $db->rows('SELECT action FROM audit_log ORDER BY id');
            self::assertSame(['created', 'created'], array_column($audit, 'action'));

            $tree = $db->rows('SELECT id, parent_id, name FROM tree_nodes ORDER BY id');
            self::assertSame([
                ['id' => 1, 'parent_id' => null, 'name' => 'root'],
                ['id' => 2, 'parent_id' => 1, 'name' => 'engineering'],
                ['id' => 3, 'parent_id' => 1, 'name' => 'operations'],
                ['id' => 4, 'parent_id' => 2, 'name' => 'platform'],
            ], $tree);

            $transactions = $db->rows('SELECT account, amount FROM transactions ORDER BY id');
            $transactionSnapshot = array_map(
                static fn (array $row): array => [
                    'account' => $row['account'],
                    'amount' => (float) $row['amount'],
                ],
                $transactions
            );
            self::assertSame([
                ['account' => 'ops', 'amount' => 1250.00],
                ['account' => 'eng', 'amount' => 5320.00],
                ['account' => 'ops', 'amount' => -210.50],
            ], $transactionSnapshot);

            $nextEmployeeId = $db->value(
                <<<SQL
                INSERT INTO employees (department_id, employee_no, name, title, hired_at, salary)
                VALUES (10, 'ENG-999', 'Sequence Witness', 'QA', NOW(), 1)
                RETURNING id
                SQL
            );

            self::assertSame(3, (int) $nextEmployeeId);
        });

        self::assertGreaterThan(0, count($collector->getTraces()));
    }

    public function testSchemaCreationIdempotence(): void
    {
        $this->withPostgresDb(function (Database $db): void {
            // Runs createSchema() once
            $departments = $db->rows('SELECT name FROM departments ORDER BY id');
            self::assertNotEmpty($departments);

            // Should not throw: $this->createSchema($db); // Second run
            $this->createSchema($db);

            // Verify data consistency across runs
            $departmentsAfter = $db->rows('SELECT name FROM departments ORDER BY id');
            self::assertSame($departments, $departmentsAfter);
        });
    }
}