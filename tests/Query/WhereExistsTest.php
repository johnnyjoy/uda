<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Query\Dialect\SQLite;
use UDA\Query\Select;

final class WhereExistsTest extends TestCase
{
    private function select(): Select
    {
        $select = new Select();
        $select->bindDialect(new SQLite());
        $select->engine = 'sqlite';

        return $select;
    }

    public function test_whereExists_accepts_bare_select(): void
    {
        $sub = $this->select()->from('payroll p');

        $sql = $this->select()
            ->from('employees e')
            ->whereExists($sub)
            ->toSql()
            ->sql;

        self::assertStringContainsString('EXISTS', $sql);
        self::assertStringContainsString('payroll', $sql);
    }

    public function test_whereExists_accepts_where_builder_subquery(): void
    {
        $sub = $this->select('1')
            ->from('payroll p')
            ->whereRaw('p.employee_id = e.id');

        $sql = $this->select()
            ->from('employees e')
            ->whereExists($sub)
            ->toSql()
            ->sql;

        self::assertStringContainsString('EXISTS', $sql);
        self::assertStringContainsString('p.employee_id = e.id', $sql);
    }

    public function test_whereNotExists_accepts_where_builder_subquery(): void
    {
        $sub = $this->select('1')
            ->from('terminations t')
            ->whereRaw('t.employee_id = e.id');

        $sql = $this->select()
            ->from('employees e')
            ->whereNotExists($sub)
            ->toSql()
            ->sql;

        self::assertStringContainsString('NOT EXISTS', $sql);
        self::assertStringContainsString('t.employee_id = e.id', $sql);
    }

    public function test_whereExists_chain_terminates_to_rows(): void
    {
        $sub = $this->select('1')
            ->from('payroll p')
            ->whereRaw('p.employee_id = e.id');

        $sql = $this->select()
            ->from('employees e')
            ->whereExists($sub)
            ->toSql()
            ->sql;

        self::assertStringStartsWith('SELECT', $sql);
    }
}
