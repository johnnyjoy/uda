<?php

declare(strict_types=1);

namespace Tests\Oracle;

require_once __DIR__ . '/OracleTestCase.php';

use UDA\Exception\QueryException;
use UDA\Query\Expr;
use UDA\Query\WhereBuilder;

final class OracleSmokeTest extends OracleTestCase
{
    public function testRawSqlExecution(): void
    {
        $row = $this->db()->row('SELECT 1 AS "one" FROM SYS.DUAL');

        $this->assertSame(['one' => 1], $this->normalizeRow($row));
    }

    public function testNamedParameterBinding(): void
    {
        $row = $this->db()->row('SELECT :x AS "value" FROM SYS.DUAL', ['x' => 42]);

        $this->assertSame(['value' => 42], $this->normalizeRow($row));
    }

    public function testSelectBuilderExecution(): void
    {
        $builder = $this->db()->select()
            ->selectRaw('1 AS "one"')
            ->from('SYS.DUAL');

        $row = $builder->row();

        $this->assertSame(['one' => 1], $this->normalizeRow($row));
    }

    public function testBuilderExpressionParameters(): void
    {
        $builder = $this->db()->select()
            ->select(Expr::raw(':value AS "test_value"', ['value' => 99]))
            ->from('SYS.DUAL');

        $row = $builder->row();

        $this->assertSame(['test_value' => 99], $this->normalizeRow($row));
    }

    public function testTableQueryReturnsSeedData(): void
    {
        $this->resetUsersTable();

        $rows = $this->db()->select()
            ->selectRaw('id AS "id"', 'name AS "name"')
            ->from('UDA_TEST_USERS')
            ->rows();

        $this->assertSame([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ], array_map(fn (array $row): array => $this->normalizeRow($row), $rows));
    }

    public function testWhereClauseReturnsSingleRow(): void
    {
        $this->resetUsersTable();

        $builder = $this->db()->select()
            ->selectRaw('name AS "name"')
            ->from('UDA_TEST_USERS');
        /** @var WhereBuilder $where */
        $where = $builder->where('ID', 1);
        $builder = $where->end();

        $row = $builder->row();

        $this->assertSame(['name' => 'Alice'], $this->normalizeRow($row));
    }

    public function testStreamingIteration(): void
    {
        $this->resetUsersTable();

        $select = $this->db()->select()
            ->selectRaw('id AS "id"', 'name AS "name"')
            ->from('UDA_TEST_USERS');

        $collected = [];
        $count = $select->each(function (array $row) use (&$collected): void {
            $collected[] = $this->normalizeRow($row);
        });

        $this->assertSame(2, $count);
        $this->assertSame([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ], $collected);
    }

    public function testErrorsPropagate(): void
    {
        $this->expectException(QueryException::class);
        $this->db()->row('SELECT * FROM non_existent_table');
    }

}
