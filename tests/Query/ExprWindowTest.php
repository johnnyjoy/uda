<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Exception\QueryException;
use UDA\Query\Dialect\SQLite;
use UDA\Query\Expr;
use UDA\Query\Select;

final class ExprWindowTest extends TestCase
{
    public function testRowNumberOverClause(): void
    {
        $sql = $this->makeSelect()
            ->select(
                'id',
                Expr::rowNumber()->over()->as('rank')
            )
            ->from('employees')
            ->toSql();

        $this->assertStringContainsString('ROW_NUMBER() OVER () AS "rank"', $sql->getQuery());
    }

    public function testPartitionAndOrderCompilation(): void
    {
        $sql = $this->makeSelect()
            ->select(
                'department_id',
                Expr::rowNumber()
                    ->over()
                    ->partitionBy('department_id')
                    ->orderBy('salary', 'DESC')
                    ->as('dept_rank')
            )
            ->from('employees')
            ->toSql();

        $this->assertStringContainsString('PARTITION BY department_id', $sql->getQuery());
        $this->assertStringContainsString('ORDER BY salary DESC', $sql->getQuery());
    }

    public function testRunningTotalFrameClause(): void
    {
        $sql = $this->makeSelect()
            ->select(
                'account_id',
                Expr::sum('amount')
                    ->over()
                    ->partitionBy('account_id')
                    ->orderBy('txn_date', 'ASC')
                    ->rowsUnboundedPreceding()
                    ->as('running_total')
            )
            ->from('transactions')
            ->toSql();

        $this->assertStringContainsString('SUM(amount) OVER (PARTITION BY account_id ORDER BY txn_date ASC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)', $sql->getQuery());
    }

    public function testLagExpression(): void
    {
        $sql = $this->makeSelect()
            ->select(
                'employee_id',
                Expr::lag('salary')
                    ->over()
                    ->partitionBy('department_id')
                    ->orderBy('salary', 'ASC')
                    ->as('previous_salary')
            )
            ->from('employees')
            ->toSql();

        $this->assertStringContainsString('LAG(salary) OVER (PARTITION BY department_id ORDER BY salary ASC) AS "previous_salary"', $sql->getQuery());
    }

    public function testParameterMergingInsideWindow(): void
    {
        $coalesce = Expr::coalesce('amount', Expr::raw(':fallback', ['fallback' => 0]));

        $sql = $this->makeSelect()
            ->select(
                Expr::sum($coalesce)
                    ->over()
                    ->partitionBy('account_id')
                    ->orderBy('txn_date', 'ASC')
                    ->rowsCurrentRow()
                    ->as('current_total')
            )
            ->from('transactions')
            ->toSql();

        $this->assertStringContainsString('ROWS CURRENT ROW', $sql->getQuery());
        $this->assertSame(['q1'], array_keys($sql->getParams()));
        $this->assertSame([0], array_values($sql->getParams()));
    }

    public function testPartitionByWithoutOverThrows(): void
    {
        $this->expectException(QueryException::class);
        Expr::rowNumber()->partitionBy('department_id');
    }

    private function makeSelect(): Select
    {
        $dialect = new SQLite();
        $builder = new Select();
        $builder->bindDialect($dialect);

        return $builder;
    }
}
