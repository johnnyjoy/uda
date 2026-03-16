<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Query\Dialect\SQLite;
use UDA\Query\Expr;
use UDA\Query\Select;

final class WindowFunctionTest extends TestCase
{
    public function testRowNumberOrderBy(): void
    {
        $sql = $this->compileExpression(
            Expr::rowNumber()
                ->over()
                ->orderBy('id')
        );

        $this->assertStringContainsString('ROW_NUMBER() OVER (ORDER BY id ASC)', $sql);
    }

    public function testPartitionedRanking(): void
    {
        $sql = $this->compileExpression(
            Expr::rowNumber()
                ->over()
                ->partitionBy('department_id')
                ->orderBy('salary', 'DESC')
        );

        $this->assertStringContainsString('PARTITION BY department_id ORDER BY salary DESC', $sql);
    }

    public function testLagOverOrderBy(): void
    {
        $sql = $this->compileExpression(
            Expr::lag('price')
                ->over()
                ->orderBy('ts')
        );

        $this->assertStringContainsString('LAG(price) OVER (ORDER BY ts ASC)', $sql);
    }

    public function testRunningSum(): void
    {
        $sql = $this->compileExpression(
            Expr::sum('sales')
                ->over()
                ->orderBy('sale_date')
        );

        $this->assertStringContainsString('SUM(sales) OVER (ORDER BY sale_date ASC)', $sql);
    }

    public function testRowsFrameHelpers(): void
    {
        $sql = $this->compileExpression(
            Expr::sum('sales')
                ->over()
                ->orderBy('sale_date')
                ->rowsBetweenUnboundedPreceding()
        );

        $this->assertStringContainsString('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW', $sql);
    }

    public function testRangeFrameHelpers(): void
    {
        $sql = $this->compileExpression(
            Expr::avg('sales')
                ->over()
                ->orderBy('sale_date')
                ->rangeBetween('INTERVAL 7 DAY PRECEDING', 'CURRENT ROW')
        );

        $this->assertStringContainsString('RANGE BETWEEN INTERVAL 7 DAY PRECEDING AND CURRENT ROW', $sql);
    }

    public function testAliasRendering(): void
    {
        $sql = $this->compileExpression(
            Expr::rank()
                ->over()
                ->orderBy('score', 'DESC')
                ->as('score_rank')
        );

        $this->assertStringContainsString('AS "score_rank"', $sql);
    }

    public function testSelectIntegration(): void
    {
        $builder = $this->makeSelect()
            ->select(
                'employee_id',
                Expr::rowNumber()
                    ->over()
                    ->partitionBy('department_id')
                    ->orderBy('salary', 'DESC')
                    ->as('rank')
            )
            ->from('employees');

        $sql = $builder->toSql()->getQuery();

        $this->assertStringContainsString('SELECT "employee_id", ROW_NUMBER() OVER (PARTITION BY department_id ORDER BY salary DESC) AS "rank"', $sql);
    }

    private function compileExpression(Expr $expr): string
    {
        $builder = $this->makeSelect()
            ->select($expr)
            ->from('employees');

        return $builder->toSql()->getQuery();
    }

    private function makeSelect(): Select
    {
        $dialect = new SQLite();
        $builder = new Select();
        $builder->bindDialect($dialect);

        return $builder;
    }
}
