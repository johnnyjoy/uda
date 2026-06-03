<?php

declare(strict_types=1);

namespace Tests\Oracle;



use UDA\Exception\QueryException;

final class ReturningAndMergeTest extends OracleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetEmployeesTable();
    }

    public function testInsertReturningRow(): void
    {
        $row = $this->db()->insert()
            ->into('UDA_TEST_EMPLOYEES')
            ->set('id', 3)
            ->set('employee_no', 'E003')
            ->set('first_name', 'Carol')
            ->set('last_name', 'Three')
            ->set('salary', 130000)
            ->returning('id', 'employee_no')
            ->row();

        $this->assertSame(['id' => 3, 'employee_no' => 'E003'], $this->normalizeRow($row));
    }

    public function testInsertReturningValue(): void
    {
        $value = $this->db()->insert()
            ->into('UDA_TEST_EMPLOYEES')
            ->set('id', 4)
            ->set('employee_no', 'E004')
            ->set('first_name', 'David')
            ->set('last_name', 'Four')
            ->set('salary', 140000)
            ->returning('id')
            ->value();

        $this->assertSame(4, (int) $value);
    }

    public function testUpdateReturning(): void
    {
        $builder = $this->db()->update()
            ->table('UDA_TEST_EMPLOYEES')
            ->set('salary', 150000);
        /** @var \UDA\Query\WhereBuilder $where */
        $where = $builder->where('employee_no', 'E001');
        $builder = $where->end()
            ->returning('employee_no', 'salary');

        $row = $builder->row();

        $this->assertSame(['employee_no' => 'E001', 'salary' => 150000], $this->normalizeRow($row));
        $this->assertSame(150000, $this->fetchEmployee('E001')['salary']);
    }

    public function testDeleteReturning(): void
    {
        $builder = $this->db()->delete()
            ->table('UDA_TEST_EMPLOYEES');
        /** @var \UDA\Query\WhereBuilder $where */
        $where = $builder->where('employee_no', 'E002');
        $builder = $where->end()->returning('employee_no', 'id');

        $row = $builder->row();

        $this->assertSame(['employee_no' => 'E002', 'id' => 2], $this->normalizeRow($row));
    }

    public function testExplicitDatabaseExecution(): void
    {
        $builder = $this->db()->insert()
            ->into('UDA_TEST_EMPLOYEES')
            ->set('id', 5)
            ->set('employee_no', 'E005')
            ->set('first_name', 'Eve')
            ->set('last_name', 'Five')
            ->set('salary', 160000)
            ->returning('id', 'employee_no');

        $row = $this->db()->row($builder->toSql());

        $this->assertSame(['id' => 5, 'employee_no' => 'E005'], $this->normalizeRow($row));
    }

    public function testBulkInsertReturning(): void
    {
        $rows = $this->db()->insert()
            ->into('UDA_TEST_EMPLOYEES')
            ->rows([
                ['id' => 6, 'employee_no' => 'E006', 'first_name' => 'Frank', 'last_name' => 'Six', 'salary' => 170000],
                ['id' => 7, 'employee_no' => 'E007', 'first_name' => 'Grace', 'last_name' => 'Seven', 'salary' => 180000],
            ])
            ->returning('id', 'employee_no')
            ->rows();

        $this->assertSame([
            ['id' => 6, 'employee_no' => 'E006'],
            ['id' => 7, 'employee_no' => 'E007'],
        ], array_map(fn (array $row): array => $this->normalizeRow($row), $rows));
    }

    public function testUpsertCompilesToMerge(): void
    {
        $sql = $this->db()->upsert()
            ->into('UDA_TEST_EMPLOYEES')
            ->values([
                'employee_no' => 'E001',
                'first_name' => 'Alice',
                'last_name' => 'One',
                'salary' => 155000,
            ])
            ->key(['employee_no'])
            ->update(['first_name', 'last_name', 'salary'])
            ->toSql()
            ->getQuery();

        $this->assertStringContainsString('MERGE INTO "UDA_TEST_EMPLOYEES"', $sql);
        $this->assertStringContainsString('WHEN MATCHED', $sql);
        $this->assertStringContainsString('WHEN NOT MATCHED', $sql);
    }

    public function testMergeUpdatesExistingRow(): void
    {
        $this->db()->upsert()
            ->into('UDA_TEST_EMPLOYEES')
            ->values([
                'employee_no' => 'E001',
                'first_name' => 'Alice',
                'last_name' => 'Updated',
                'salary' => 165000,
            ])
            ->key(['employee_no'])
            ->update(['first_name', 'last_name', 'salary'])
            ->exec();

        $row = $this->fetchEmployee('E001');
        $this->assertSame('Updated', $row['last_name']);
        $this->assertSame(165000, $row['salary']);
    }

    public function testMergeInsertsMissingRow(): void
    {
        $this->db()->upsert()
            ->into('UDA_TEST_EMPLOYEES')
            ->values([
                'employee_no' => 'E099',
                'first_name' => 'New',
                'last_name' => 'Person',
                'salary' => 111000,
            ])
            ->key(['employee_no'])
            ->update(['first_name', 'last_name', 'salary'])
            ->exec();

        $row = $this->fetchEmployee('E099');
        $this->assertSame('New', $row['first_name']);
        $this->assertSame(111000, $row['salary']);
    }

    public function testDoNothingSkipsUpdate(): void
    {
        $this->db()->upsert()
            ->into('UDA_TEST_EMPLOYEES')
            ->values([
                'employee_no' => 'E001',
                'first_name' => 'Alice',
                'last_name' => 'Ignored',
                'salary' => 999999,
            ])
            ->key(['employee_no'])
            ->doNothing()
            ->exec();

        $row = $this->fetchEmployee('E001');
        $this->assertSame('One', $row['last_name']);
        $this->assertSame(100000, $row['salary']);
    }

    public function testReturningTerminators(): void
    {
        $rows = $this->db()->insert()
            ->into('UDA_TEST_EMPLOYEES')
            ->rows([
                ['id' => 8, 'employee_no' => 'E008', 'first_name' => 'Henry', 'last_name' => 'Eight', 'salary' => 190000],
                ['id' => 9, 'employee_no' => 'E009', 'first_name' => 'Isla', 'last_name' => 'Nine', 'salary' => 200000],
            ])
            ->returning('id')
            ->rows();

        $this->assertSame([[ 'id' => 8 ], [ 'id' => 9 ]], array_map(fn (array $row): array => $this->normalizeRow($row), $rows));

        $firstValue = $this->db()->insert()
            ->into('UDA_TEST_EMPLOYEES')
            ->set('id', 10)
            ->set('employee_no', 'E010')
            ->set('first_name', 'Jill')
            ->set('last_name', 'Ten')
            ->set('salary', 210000)
            ->returning('id')
            ->value();

        $this->assertSame(10, (int) $firstValue);
    }

    public function testDuplicateConstraintPropagatesError(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ORA-');

        $this->db()->insert()
            ->into('UDA_TEST_EMPLOYEES')
            ->set('id', 1)
            ->set('employee_no', 'E001')
            ->set('first_name', 'Dup')
            ->set('last_name', 'User')
            ->set('salary', 99999)
            ->returning('id')
            ->row();
    }

    public function testReturningWithoutColumnsFailsOnOracle(): void
    {
        $builder = $this->db()->update()
            ->table('UDA_TEST_EMPLOYEES')
            ->set('salary', 150000);
        /** @var \UDA\Query\WhereBuilder $where */
        $where = $builder->where('employee_no', 'E001');
        $builder = $where->end();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('requires explicit column names');

        $builder->returning()->row();
    }

    private function fetchEmployee(string $employeeNo): array
    {
        $select = $this->db()->select()
            ->selectRaw('employee_no AS "employee_no"', 'first_name AS "first_name"', 'last_name AS "last_name"', 'salary AS "salary"')
            ->from('UDA_TEST_EMPLOYEES');
        /** @var \UDA\Query\WhereBuilder $where */
        $where = $select->where('employee_no', $employeeNo);
        $row = $where->end()->row();

        $normalized = $this->normalizeRow($row);

        return [
            'employee_no' => $normalized['employee_no'] ?? null,
            'first_name' => $normalized['first_name'] ?? null,
            'last_name' => $normalized['last_name'] ?? null,
            'salary' => $normalized['salary'] ?? null,
        ];
    }
}
