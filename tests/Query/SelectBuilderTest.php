<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Exception\QueryException;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Expr;
use UDA\Query\Select;
use UDA\Query\WhereBuilder;

final class SelectBuilderTest extends TestCase
{
    public function testSelectBuilderCoversFullGrammar(): void
    {
        $subqueryBuilder = $this->makeSelect()
            ->selectRaw('1')
            ->from('sessions');
        /** @var WhereBuilder $subWhere */
        $subWhere = $subqueryBuilder->whereColumn('sessions.user_id', 'users.id');
        $subqueryBuilder = $subWhere->end();
        $subquery = $subqueryBuilder->toSql();

        $select = $this->makeSelect()
            ->distinct()
            ->select('users.id', 'users.email')
            ->from('users')
            ->join('accounts', 'users.account_id', 'accounts.id', 'LEFT');

        /** @var WhereBuilder $where */
        $where = $select->where('status', 'active');
        $where->and('score')->between(50, 500);
        $where->and('tier')->in(['pro', 'enterprise']);
        $where->and('archived_at')->isNull();
        $where->and('restored_at')->isNotNull();
        $where->and('title')->notLike('%Intern%');
        $where->and('category')->notIn(['contractor']);
        $where->and(static function (WhereBuilder $group): void {
            $group->whereRaw('"score" > ?', [80]);
        });
        $where->or(static function (WhereBuilder $group): void {
            $group->where('role', 'admin');
        });
        $where->and(static function (WhereBuilder $group) use ($subquery): void {
            $group->exists($subquery);
        });
        $select = $where->end();

        $select = $select
            ->groupBy('status')
            ->having(Expr::count('id'))->gt(1)
            ->end()
            ->orderBy('email', 'DESC', ['email'])
            ->limit(25)
            ->offset(5);

        $sql = $select->toSql();

        $this->assertStringContainsString('SELECT DISTINCT "users"."id", "users"."email"', $sql->getQuery());
        $this->assertStringContainsString('LEFT JOIN "accounts" ON "users"."account_id" = "accounts"."id"', $sql->getQuery());
        $this->assertStringContainsString('"status" = :q1', $sql->getQuery());
        $this->assertStringContainsString('BETWEEN :q2 AND :q3', $sql->getQuery());
        $this->assertStringContainsString('IN (:q4, :q5)', $sql->getQuery());
        $this->assertStringContainsString('"archived_at" IS NULL', $sql->getQuery());
        $this->assertStringContainsString('"restored_at" IS NOT NULL', $sql->getQuery());
        $this->assertStringContainsString('NOT LIKE :q6', $sql->getQuery());
        $this->assertStringContainsString('NOT IN (:q7)', $sql->getQuery());
        $this->assertStringContainsString('"score" > :q8', $sql->getQuery());
        $this->assertStringContainsString('("role" = :q9)', $sql->getQuery());
        $this->assertStringContainsString('EXISTS (SELECT', $sql->getQuery());
        $this->assertStringContainsString('GROUP BY "status"', $sql->getQuery());
        $this->assertStringContainsString('HAVING COUNT(id) > :q10', $sql->getQuery());
        $this->assertStringContainsString('ORDER BY "email" DESC', $sql->getQuery());
        $this->assertStringContainsString('LIMIT :q11', $sql->getQuery());
        $this->assertStringContainsString('OFFSET :q12', $sql->getQuery());
        $this->assertStringNotContainsString('?', $sql->getQuery(), 'Select builder must emit named params only');

        $this->assertSame(['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9', 'q10', 'q11', 'q12'], array_keys($sql->getParams()));
        $this->assertSame(['users', 'accounts', 'sessions'], $sql->getCacheTables());
    }

    public function testOrderByRejectsInvalidColumns(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Column not allowed in ORDER BY: email');

        $this->makeSelect()
            ->select('email')
            ->from('users')
            ->orderBy('email', 'ASC', ['name']);
    }

    public function testExpressionsRenderInSelectAndOrderBy(): void
    {
        $lastSeenExpr = Expr::raw('COALESCE(last_login, :fallback)', ['fallback' => '1970-01-01']);

        $sql = $this->makeSelect()
            ->select('id', $lastSeenExpr->as('last_seen'))
            ->from('users')
            ->orderBy($lastSeenExpr, 'DESC')
            ->toSql();

        $this->assertMatchesRegularExpression('/COALESCE\(last_login, :q\d+\) AS "last_seen"/', $sql->getQuery());
        $this->assertMatchesRegularExpression('/ORDER BY COALESCE\(last_login, :q\d+\) DESC/', $sql->getQuery());
        $this->assertCount(2, $sql->getParams());
        $this->assertSame(['1970-01-01', '1970-01-01'], array_values($sql->getParams()));
    }

    public function testOrderByExpressionRejectsAllowlist(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ORDER BY expressions cannot be validated against an allowlist.');

        $this->makeSelect()
            ->select('id')
            ->from('users')
            ->orderBy(Expr::raw('COALESCE(last_login, :fallback)', ['fallback' => '1970-01-01']), 'ASC', ['id']);
    }

    public function testWhereBuilderRequiresColumnBeforeOperator(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('No column awaiting operator');

        $builder = $this->makeSelect()->from('users');
        /** @var WhereBuilder $chain */
        $chain = $builder->where('status', 'active');
        $chain->in(['active', 'pending']);
    }

    public function testToSqlReturnsSameInstanceForDeterminism(): void
    {
        $builder = $this->makeSelect()
            ->select('id')
            ->from('users');
        /** @var WhereBuilder $deterministic */
        $deterministic = $builder->where('id', 5);
        $builder = $deterministic->end();

        $first = $builder->toSql();
        $second = $builder->toSql();

        $this->assertSame($first, $second, 'Select::toSql() caches Sql objects for deterministic output');
    }

    public function testHavingRawCompilesExpression(): void
    {
        $builder = $this->makeSelect()
            ->select('status')
            ->from('users')
            ->groupBy('status')
            ->havingRaw('COUNT("users"."id") > :limit', ['limit' => 5]);

        $sql = $builder->toSql();
        $this->assertStringContainsString('HAVING COUNT("users"."id") > :q1', $sql->getQuery());
    }

    private function makeSelect(): Select
    {
        $builder = new Select();
        $builder->bindDialect(new PostgreSql());

        return $builder;
    }
}
