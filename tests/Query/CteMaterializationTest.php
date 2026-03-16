<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Query\Dialect\Dialect;
use UDA\Query\Dialect\MariaDb;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Dialect\SQLite;
use UDA\Query\Select;

final class CteMaterializationTest extends TestCase
{
    public function testMaterializedHintRendersForSupportedDialect(): void
    {
        $dialect = new PostgreSql();

        $totals = $this->makeSelect($dialect)
            ->select('department_id')
            ->from('employees');

        $sql = $this->makeSelect($dialect)
            ->select('department_id')
            ->with('expensive_data', $totals)
            ->materialized()
            ->from('expensive_data')
            ->toSql();

        $this->assertStringContainsString('WITH "expensive_data" AS MATERIALIZED (', $sql->getQuery());
    }

    public function testNotMaterializedHintRenders(): void
    {
        $dialect = new PostgreSql();

        $transactions = $this->makeSelect($dialect)
            ->select('id')
            ->from('transactions');

        $sql = $this->makeSelect($dialect)
            ->select('id')
            ->with('temp_data', $transactions)
            ->notMaterialized()
            ->from('temp_data')
            ->toSql();

        $this->assertStringContainsString('AS NOT MATERIALIZED (', $sql->getQuery());
    }

    public function testHintsApplyPerCte(): void
    {
        $dialect = new SQLite();

        $active = $this->makeSelect($dialect)
            ->select('id')
            ->from('employees');

        $regions = $this->makeSelect($dialect)
            ->select('id')
            ->from('departments');

        $sql = $this->makeSelect($dialect)
            ->select('id')
            ->with('active_employees', $active)
            ->materialized()
            ->with('department_regions', $regions)
            ->notMaterialized()
            ->from('active_employees')
            ->toSql()
            ->getQuery();

        $materializedPos = strpos($sql, '"active_employees" AS MATERIALIZED');
        $notMaterializedPos = strpos($sql, '"department_regions" AS NOT MATERIALIZED');

        $this->assertIsInt($materializedPos);
        $this->assertIsInt($notMaterializedPos);
        $this->assertLessThan($notMaterializedPos, $materializedPos);
    }

    public function testUnsupportedDialectsIgnoreHints(): void
    {
        $dialect = new MariaDb();

        $cte = $this->makeSelect($dialect)
            ->select('id')
            ->from('employees');

        $sql = $this->makeSelect($dialect)
            ->select('id')
            ->with('a', $cte)
            ->materialized()
            ->from('a')
            ->toSql()
            ->getQuery();

        $this->assertStringNotContainsString('MATERIALIZED', $sql);
        $this->assertStringNotContainsString('NOT MATERIALIZED', $sql);
    }

    public function testRecursiveCteAcceptsMaterializationHint(): void
    {
        $dialect = new PostgreSql();

        $seed = $this->makeSelect($dialect)
            ->select('id', 'parent_id')
            ->from('nodes');

        $step = $this->makeSelect($dialect)
            ->select('n.id', 'n.parent_id')
            ->from('nodes', 'n')
            ->join('tree', 't.id', 'n.parent_id', 'INNER', 't');

        $tree = $seed->unionAll($step);

        $sql = $this->makeSelect($dialect)
            ->withRecursive('tree', $tree)
            ->materialized()
            ->from('tree')
            ->toSql()
            ->getQuery();

        $this->assertStringContainsString('WITH RECURSIVE "tree" AS MATERIALIZED', $sql);
    }

    private function makeSelect(Dialect $dialect): Select
    {
        $builder = new Select();
        $builder->bindDialect($dialect);

        return $builder;
    }
}
