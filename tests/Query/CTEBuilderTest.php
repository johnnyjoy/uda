<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Query\Dialect\Db2;
use UDA\Query\Dialect\MariaDb;
use UDA\Query\Dialect\Oracle;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Dialect\SQLite;
use UDA\Query\Dialect\SqlServer;
use UDA\Query\Dialect\Sybase;
use UDA\Query\Expr;
use UDA\Query\Select;

final class CTEBuilderTest extends TestCase
{
    public function testWithCompilesBasicCte(): void
    {
        $dialect = new PostgreSql();

        $totals = $this->makeSelect($dialect)
            ->select('department_id', Expr::count('id')->as('employee_count'))
            ->from('employees')
            ->groupBy('department_id');

        $query = $this->makeSelect($dialect)
            ->select('department_id', 'employee_count')
            ->with('totals', $totals)
            ->from('totals');

        /** @var \UDA\Query\WhereBuilder $where */
        $where = $query->where('employee_count', 5, '>');
        $query = $where->end();

        $sql = $query->toSql();

        $this->assertStringContainsString('WITH "totals" AS (SELECT "department_id", COUNT(id) AS "employee_count" FROM "employees" GROUP BY "department_id")', $sql->getQuery());
        $this->assertStringContainsString('FROM "totals"', $sql->getQuery());
        $this->assertSame(['q1'], array_keys($sql->getParams()));
        $this->assertSame(['employees'], $sql->getCacheTables());
    }

    public function testMultipleCtesMaintainOrderAndTables(): void
    {
        $dialect = new PostgreSql();

        $active = $this->makeSelect($dialect)
            ->select('id', 'department_id')
            ->from('employees');
        /** @var \UDA\Query\WhereBuilder $activeWhere */
        $activeWhere = $active->where('active', 1);
        $active = $activeWhere->end();

        $departments = $this->makeSelect($dialect)
            ->select('id', 'region')
            ->from('departments');

        $query = $this->makeSelect($dialect)
            ->select('a.id', 'd.region')
            ->with('active_employees', $active)
            ->with('department_regions', $departments)
            ->from('active_employees', 'a')
            ->join('department_regions', 'd.id', 'a.department_id', 'INNER', 'd');

        $sql = $query->toSql();

        $this->assertStringContainsString('WITH "active_employees" AS (SELECT', $sql->getQuery());
        $this->assertStringContainsString(', "department_regions" AS (SELECT', $sql->getQuery());
        $activePos = strpos($sql->getQuery(), '"active_employees"');
        $deptPos = strpos($sql->getQuery(), '"department_regions"');
        $this->assertNotFalse($activePos);
        $this->assertNotFalse($deptPos);
        $this->assertLessThan($deptPos, $activePos);
        $this->assertSame(['employees', 'departments'], $sql->getCacheTables());
        $this->assertSame(['q1'], array_keys($sql->getParams()));
    }

    public function testWithRecursiveUnionAll(): void
    {
        $dialect = new PostgreSql();

        $seed = $this->makeSelect($dialect)
            ->select('id', 'parent_id')
            ->from('nodes');
        /** @var \UDA\Query\WhereBuilder $seedWhere */
        $seedWhere = $seed->where('id', 5);
        $seed = $seedWhere->end();

        $step = $this->makeSelect($dialect)
            ->select('n.id', 'n.parent_id')
            ->from('nodes', 'n')
            ->join('tree', 't.id', 'n.parent_id', 'INNER', 't');

        $tree = $seed->unionAll($step);

        $query = $this->makeSelect($dialect)
            ->withRecursive('tree', $tree)
            ->from('tree');

        $sql = $query->toSql();

        $this->assertStringContainsString('WITH RECURSIVE "tree" AS (SELECT "id", "parent_id" FROM "nodes" WHERE "id" = :q1 UNION ALL', $sql->getQuery());
        $this->assertStringContainsString('FROM "tree"', $sql->getQuery());
        $this->assertSame(['q1'], array_keys($sql->getParams()));
    }

    public function testBuilderImmutabilityWithCtes(): void
    {
        $dialect = new PostgreSql();
        $base = $this->makeSelect($dialect)
            ->select('id')
            ->from('employees');

        $cte = $this->makeSelect($dialect)
            ->select('department_id')
            ->from('employees');

        $with = $base->with('dept_ids', $cte);

        $this->assertStringNotContainsString('WITH', $base->toSql()->getQuery());
        $this->assertStringContainsString('WITH', $with->toSql()->getQuery());
    }

    public function testDeterministicParameterMerging(): void
    {
        $dialect = new PostgreSql();

        $cte = $this->makeSelect($dialect)
            ->select('department_id')
            ->from('employees');
        /** @var \UDA\Query\WhereBuilder $cteWhere */
        $cteWhere = $cte->where('region', 'EMEA');
        $cte = $cteWhere->end();

        $query = $this->makeSelect($dialect)
            ->select('department_id')
            ->with('filtered', $cte)
            ->from('filtered');
        /** @var \UDA\Query\WhereBuilder $mainWhere */
        $mainWhere = $query->where('department_id', 10);
        $query = $mainWhere->end();

        $sql = $query->toSql();

        $this->assertSame(['q1', 'q2'], array_keys($sql->getParams()));
        $this->assertSame([10, 'EMEA'], array_values($sql->getParams()));
    }

    public function testDialectSupportForCtes(): void
    {
        $dialects = [
            ['dialect' => new PostgreSql(), 'recursive' => 'WITH RECURSIVE'],
            ['dialect' => new SQLite(), 'recursive' => 'WITH RECURSIVE'],
            ['dialect' => new MariaDb(), 'recursive' => 'WITH RECURSIVE'],
            ['dialect' => new Db2(), 'recursive' => 'WITH RECURSIVE'],
            ['dialect' => new SqlServer(), 'recursive' => 'WITH'],
            ['dialect' => new Oracle(), 'recursive' => 'WITH'],
            ['dialect' => new Sybase(), 'recursive' => 'WITH'],
        ];

        foreach ($dialects as $case) {
            $dialect = $case['dialect'];
            $recursiveKeyword = $case['recursive'];
            $cte = $this->makeSelect($dialect)
                ->select('id')
                ->from('employees');
            $query = $this->makeSelect($dialect)
                ->select('id')
                ->with('ids', $cte)
                ->from('ids');

            $sql = $query->toSql();
            $this->assertStringContainsString('WITH', $sql->getQuery(), $dialect->name() . ' should support WITH');

            $recursive = $this->makeSelect($dialect)
                ->select('id')
                ->from('employees');
            $recursiveQuery = $this->makeSelect($dialect)
                ->withRecursive('tree', $recursive)
                ->from('tree');

            $recursiveSql = $recursiveQuery->toSql();
            $this->assertStringContainsString($recursiveKeyword, $recursiveSql->getQuery(), $dialect->name() . ' recursive syntax expectation');
        }
    }

    private function makeSelect($dialect): Select
    {
        $builder = new Select();
        $builder->bindDialect($dialect);

        return $builder;
    }
}
