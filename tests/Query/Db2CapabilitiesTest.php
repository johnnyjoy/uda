<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Driver;
use UDA\Query\Dialect\Db2;
use UDA\Query\Select;
use UDA\Query\Upsert;

final class Db2CapabilitiesTest extends TestCase
{
    public function test_db2_dialect_supports_merge_and_upsert_not_returning(): void
    {
        $db2 = new Db2();

        self::assertFalse($db2->supportsReturning());
        self::assertTrue($db2->supportsMerge());
        self::assertTrue($db2->supportsUpsert());
    }

    public function test_db2_quote_identifier_uses_double_quotes_and_uppercase(): void
    {
        self::assertSame('"USERS"', Driver::quoteIdentifier('db2', 'users'));
    }

    public function test_db2_select_pagination_compiles_offset_fetch(): void
    {
        $select = new Select();
        $select->bindDialect(new Db2());
        $select->engine = 'db2';

        $sql = $select
            ->from('users')
            ->limit(5)
            ->offset(10)
            ->toSql();

        self::assertStringContainsString('OFFSET', $sql->sql);
        self::assertStringContainsString('FETCH NEXT', $sql->sql);
    }

    public function test_db2_upsert_compiles_merge(): void
    {
        $upsert = new Upsert();
        $upsert->bindDialect(new Db2());
        $upsert->engine = 'db2';

        $sql = $upsert
            ->into('users')
            ->values(['id' => 1, 'name' => 'Ada'])
            ->key(['id'])
            ->toSql();

        self::assertStringContainsString('MERGE INTO', $sql->sql);
        self::assertStringContainsString('SYSIBM.SYSDUMMY1', $sql->sql);
    }
}
