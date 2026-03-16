<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Query\Dialect\Db2;
use UDA\Query\Dialect\MariaDb;
use UDA\Query\Dialect\Oracle;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Dialect\SQLite as SQLiteDialect;
use UDA\Query\Dialect\SqlServer;
use UDA\Query\Dialect\Sybase;
use UDA\Query\Select;
use UDA\Query\WhereBuilder;

final class UnionBuilderTest extends TestCase
{
    public function testUnionAllCompilesAndMergesParams(): void
    {
        $active = $this->makeSelect()
            ->select('id', 'name')
            ->from('employees');
        /** @var WhereBuilder $activeWhere */
        $activeWhere = $active->where('active', 1);
        $active = $activeWhere->end();

        $retired = $this->makeSelect()
            ->select('id', 'name')
            ->from('retirees');

        $query = $active->unionAll($retired)
            ->orderBy('name')
            ->limit(50)
            ->offset(10);

        $sql = $query->toSql();

        $this->assertStringContainsString('UNION ALL', $sql->getQuery());
        $this->assertStringContainsString('ORDER BY "name" ASC', $sql->getQuery());
        $this->assertStringContainsString('LIMIT :q2 OFFSET :q3', $sql->getQuery());
        $this->assertSame(['q1' => 1, 'q2' => 50, 'q3' => 10], $sql->getParams());
        $this->assertSame(['employees', 'retirees'], $sql->getCacheTables());
    }

    public function testUnionMaintainsDeterministicSql(): void
    {
        $a = $this->makeSelect()->select('id')->from('employees');
        $b = $this->makeSelect()->select('id')->from('contractors');

        $compound = $a->union($b);
        $sql1 = $compound->toSql();
        $sql2 = $compound->toSql();

        $this->assertSame($sql1->getQuery(), $sql2->getQuery());
        $this->assertSame($sql1->getParams(), $sql2->getParams());
    }

    public function testUnionDoesNotMutateBranches(): void
    {
        $base = $this->makeSelect()->select('id')->from('employees');
        $branch = $this->makeSelect()->select('id')->from('retirees');

        $compound = $base->unionAll($branch);

        $this->assertNotSame($base, $compound);

        $this->assertStringContainsString('FROM "employees"', $base->toSql()->getQuery());
        $this->assertStringNotContainsString('UNION', $base->toSql()->getQuery());
    }

    public function testUnionsCompileAcrossDialects(): void
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
            $a = new Select();
            $a->bindDialect($dialect);
            $a = $a->select('id')->from('one');

            $b = new Select();
            $b->bindDialect($dialect);
            $b = $b->select('id')->from('two');

            $sql = $a->unionAll($b)->toSql();
            $this->assertStringContainsString('UNION', $sql->getQuery());
        }
    }

    private function makeSelect(): Select
    {
        $builder = new Select();
        $builder->bindDialect(new PostgreSql());

        return $builder;
    }
}
