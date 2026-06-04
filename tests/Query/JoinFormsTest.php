<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Query\Dialect\SQLite;
use UDA\Query\Select;

final class JoinFormsTest extends TestCase
{
    private function select(): Select
    {
        $select = new Select();
        $select->bindDialect(new SQLite());
        $select->engine = 'sqlite';

        return $select;
    }

    public function test_inline_join_uses_raw_on_predicate_and_parses_alias(): void
    {
        $sql = $this->select()
            ->from('employees e')
            ->join('departments d', 'd.id = e.department_id')
            ->toSql()
            ->sql;

        self::assertStringContainsString('INNER JOIN', $sql);
        self::assertStringContainsString('departments', $sql);
        self::assertStringContainsString('d.id = e.department_id', $sql);
    }

    public function test_column_join_quotes_columns(): void
    {
        $sql = $this->select()
            ->from('employees e')
            ->join('departments', 'd.id', 'e.department_id', 'INNER', 'd')
            ->toSql()
            ->sql;

        self::assertStringContainsString('INNER JOIN', $sql);
        // Column form quotes identifiers, so the raw predicate must NOT appear verbatim.
        self::assertStringNotContainsString('d.id = e.department_id', $sql);
        self::assertStringContainsString('department_id', $sql);
    }

    public function test_left_join_inline_form(): void
    {
        $sql = $this->select()
            ->from('employees e')
            ->leftJoin('payroll p', 'p.employee_id = e.id')
            ->toSql()
            ->sql;

        self::assertStringContainsString('LEFT JOIN', $sql);
        self::assertStringContainsString('payroll', $sql);
        self::assertStringContainsString('p.employee_id = e.id', $sql);
    }

    public function test_left_join_column_form(): void
    {
        $sql = $this->select()
            ->from('employees e')
            ->leftJoin('payroll', 'p.employee_id', 'e.id', 'p')
            ->toSql()
            ->sql;

        self::assertStringContainsString('LEFT JOIN', $sql);
        self::assertStringNotContainsString('p.employee_id = e.id', $sql);
    }
}
