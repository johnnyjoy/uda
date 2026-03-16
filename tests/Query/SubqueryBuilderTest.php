<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Exception\QueryException;
use UDA\Query\Dialect\Db2;
use UDA\Query\Dialect\MariaDb;
use UDA\Query\Dialect\Oracle;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Dialect\SQLite as SQLiteDialect;
use UDA\Query\Dialect\SqlServer;
use UDA\Query\Dialect\Sybase;
use UDA\Query\Select;
use UDA\Query\WhereBuilder;

final class SubqueryBuilderTest extends TestCase
{
    public function testFromDerivedTableBuildsSql(): void
    {
        $totals = $this->makeSelect()
            ->select('payroll.employee_id')
            ->selectRaw('SUM(amount) AS total')
            ->from('payroll')
            ->groupBy('payroll.employee_id');

        $query = $this->makeSelect()
            ->select('p.employee_id', 'p.total')
            ->fromSub($totals, 'p');

        $sql = $query->toSql();

        $this->assertStringContainsString('FROM (SELECT "payroll"."employee_id", SUM(amount) AS total FROM "payroll" GROUP BY "payroll"."employee_id") AS "p"', $sql->getQuery());
        $this->assertSame(['payroll'], $sql->getCacheTables());
    }

    public function testJoinDerivedTableMergesTables(): void
    {
        $totals = $this->makeSelect()
            ->select('payroll.employee_id')
            ->selectRaw('SUM(amount) AS total')
            ->from('payroll')
            ->groupBy('payroll.employee_id');

        $query = $this->makeSelect()
            ->select('e.id', 'p.total')
            ->from('employees', 'e')
            ->joinSub($totals, 'p', 'p.employee_id = "e"."id"');

        $sql = $query->toSql();

        $this->assertStringContainsString('JOIN (SELECT "payroll"."employee_id", SUM(amount) AS total FROM "payroll" GROUP BY "payroll"."employee_id") AS "p" ON p.employee_id = "e"."id"', $sql->getQuery());
        $this->assertSame(['employees', 'payroll'], $sql->getCacheTables());
    }

    public function testWhereInSubquery(): void
    {
        $departments = $this->makeSelect()
            ->select('id')
            ->from('departments');
        /** @var WhereBuilder $deptWhere */
        $deptWhere = $departments->where('name', 'Engineering');
        $departments = $deptWhere->end();

        $builder = $this->makeSelect()
            ->select('id')
            ->from('employees');

        /** @var WhereBuilder $chain */
        $chain = $builder->where('status', 'active');
        $chain->and('department_id')->in($departments);
        $builder = $chain->end();

        $sql = $builder->toSql();

        $this->assertStringContainsString('"status" = :q1', $sql->getQuery());
        $this->assertStringContainsString('"department_id" IN (SELECT "id" FROM "departments" WHERE "name" = :q2)', $sql->getQuery());
        $this->assertSame(['q1' => 'active', 'q2' => 'Engineering'], $sql->getParams());
    }

    public function testWhereExistsWithBuilder(): void
    {
        $sub = $this->makeSelect()
            ->select('1')
            ->from('payroll', 'p');
        /** @var WhereBuilder $subWhere */
        $subWhere = $sub->whereColumn('p.employee_id', 'e.id');
        $sub = $subWhere->end();

        $builder = $this->makeSelect()
            ->select('e.id')
            ->from('employees', 'e');
        /** @var WhereBuilder $exists */
        $exists = $builder->whereExists($sub);
        $builder = $exists->end();

        $sql = $builder->toSql();

        $this->assertStringContainsString('WHERE EXISTS (SELECT 1 FROM "payroll" AS "p" WHERE "p"."employee_id" = "e"."id")', $sql->getQuery());
    }

    public function testTablesPropagateFromWhereSubqueries(): void
    {
        $sub = $this->makeSelect()
            ->select('department_id')
            ->from('departments')
            ->groupBy('department_id');

        $builder = $this->makeSelect()
            ->select('id')
            ->from('employees');

        /** @var WhereBuilder $chain */
        $chain = $builder->where('status', 'active');
        $chain->and('department_id')->in($sub);
        $builder = $chain->end();

        $sql = $builder->toSql();

        $this->assertSame(['employees', 'departments'], $sql->getCacheTables());
    }

    public function testWhereNotExistsWithBuilder(): void
    {
        $sub = $this->makeSelect()
            ->select('1')
            ->from('terminations', 't');

        $builder = $this->makeSelect()
            ->select('e.id')
            ->from('employees', 'e');
        /** @var WhereBuilder $notExists */
        $notExists = $builder->whereNotExists($sub);
        $builder = $notExists->end();

        $sql = $builder->toSql();
        $this->assertStringContainsString('NOT EXISTS (SELECT 1 FROM "terminations" AS "t")', $sql->getQuery());
    }

    public function testFromSubRequiresAlias(): void
    {
        $sub = $this->makeSelect()
            ->select('id')
            ->from('employees');

        $this->expectException(QueryException::class);
        $this->makeSelect()->fromSub($sub, '');
    }

    public function testJoinSubRequiresAlias(): void
    {
        $sub = $this->makeSelect()
            ->select('id')
            ->from('employees');

        $this->expectException(QueryException::class);
        $this->makeSelect()
            ->select('id')
            ->from('departments')
            ->joinSub($sub, '', '1=1');
    }

    public function testDerivedTablesCompileAcrossDialects(): void
    {
        $dialects = [
            new PostgreSql(),
            new SQLiteDialect(),
            new SqlServer(),
            new Sybase(),
            new Oracle(),
            new MariaDb(),
            new Db2(),
        ];

        foreach ($dialects as $dialect) {
            $sub = new Select();
            $sub->bindDialect($dialect);
            $sub = $sub->select('id')->from('employees');

            $outer = new Select();
            $outer->bindDialect($dialect);
            $outer = $outer->select('t.id')->fromSub($sub, 't');

            $sql = $outer->toSql();
            $this->assertStringContainsString('FROM (', $sql->getQuery());
        }
    }

    private function makeSelect(): Select
    {
        $builder = new Select();
        $builder->bindDialect(new PostgreSql());

        return $builder;
    }
}
