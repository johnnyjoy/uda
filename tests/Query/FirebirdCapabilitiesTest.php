<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Driver;
use UDA\Exception\QueryException;
use UDA\Query\Dialect\Firebird;
use UDA\Query\Select;
use UDA\Query\Upsert;

final class FirebirdCapabilitiesTest extends TestCase
{
    public function test_firebird_dialect_supports_merge_upsert_and_returning(): void
    {
        $firebird = new Firebird();

        self::assertTrue($firebird->supportsReturning());
        self::assertTrue($firebird->supportsMerge());
        self::assertTrue($firebird->supportsUpsert());
        self::assertFalse($firebird->supportsWritableCte());
    }

    public function test_firebird_quote_identifier_uses_double_quotes(): void
    {
        self::assertSame('"users"', Driver::quoteIdentifier('firebird', 'users'));
    }

    public function test_firebird_select_pagination_requires_order_by(): void
    {
        $select = new Select();
        $select->bindDialect(new Firebird());
        $select->engine = 'firebird';

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ORDER BY');

        $select->from('users')->limit(5)->toSql();
    }

    public function test_firebird_select_pagination_compiles_offset_fetch(): void
    {
        $select = new Select();
        $select->bindDialect(new Firebird());
        $select->engine = 'firebird';

        $sql = $select
            ->from('users')
            ->orderBy('id')
            ->limit(5)
            ->offset(10)
            ->toSql();

        self::assertStringContainsString('OFFSET 10 ROWS', $sql->sql);
        self::assertStringContainsString('FETCH NEXT 5 ROWS ONLY', $sql->sql);
    }

    public function test_firebird_select_limit_only_uses_fetch_first(): void
    {
        $select = new Select();
        $select->bindDialect(new Firebird());
        $select->engine = 'firebird';

        $sql = $select
            ->from('users')
            ->orderBy('id')
            ->limit(5)
            ->toSql();

        self::assertStringContainsString('FETCH FIRST 5 ROWS ONLY', $sql->sql);
        self::assertStringNotContainsString('OFFSET 0', $sql->sql);
    }

    public function test_firebird_upsert_compiles_merge(): void
    {
        $upsert = new Upsert();
        $upsert->bindDialect(new Firebird());
        $upsert->engine = 'firebird';

        $sql = $upsert
            ->into('users')
            ->values(['id' => 1, 'name' => 'Ada'])
            ->key(['id'])
            ->toSql();

        self::assertStringContainsString('MERGE INTO', $sql->sql);
        self::assertStringContainsString('RDB$DATABASE', $sql->sql);
    }
}
