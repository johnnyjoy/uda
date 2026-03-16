<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Exception\QueryException;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Expr;
use UDA\Query\Select;

final class ExprTest extends TestCase
{
    public function testRawRejectsPositionalPlaceholders(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Expr::raw() does not allow positional placeholders.');

        Expr::raw('COUNT(?)');
    }

    public function testRawExpressionBindsNamedParameters(): void
    {
        $expr = Expr::raw('COALESCE(last_login, :fallback)', ['fallback' => '1970-01-01'])->as('last_seen');

        $sql = $this->makeSelect()
            ->select($expr)
            ->from('users')
            ->toSql();

        $this->assertStringContainsString('COALESCE(last_login, :q1) AS "last_seen"', $sql->getQuery());
        $this->assertSame(['q1' => '1970-01-01'], $sql->getParams());
    }

    public function testCoalesceQuotesLiteralFallbacks(): void
    {
        $expr = Expr::coalesce('title', 'Unknown')->as('title_display');

        $sql = $this->makeSelect()
            ->select($expr)
            ->from('employees')
            ->toSql();

        $this->assertStringContainsString("COALESCE(title, 'Unknown') AS \"title_display\"", $sql->getQuery());
    }

    private function makeSelect(): Select
    {
        $builder = new Select();
        $builder->bindDialect(new PostgreSql());

        return $builder;
    }
}
