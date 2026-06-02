<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Driver;
use UDA\Query\Dialect\SqlServer as SqlServerDialect;
use UDA\Query\Dialect\Sybase as SybaseDialect;
use UDA\Query\Upsert;

final class SybaseCapabilitiesTest extends TestCase
{
    public function test_sybase_dialect_disables_merge_and_upsert_while_sql_server_enables_them(): void
    {
        $sybase = new SybaseDialect();
        $sqlServer = new SqlServerDialect();

        self::assertFalse($sybase->supportsMerge());
        self::assertFalse($sybase->supportsUpsert());
        self::assertTrue($sqlServer->supportsMerge());
        self::assertTrue($sqlServer->supportsUpsert());
    }

    public function test_sybase_still_advertises_output_returning_like_sql_server(): void
    {
        $sybase = new SybaseDialect();
        $sqlServer = new SqlServerDialect();

        self::assertTrue($sybase->supportsReturning());
        self::assertTrue($sqlServer->supportsReturning());
    }

    public function test_sybase_and_sql_server_share_bracket_quoting_via_engine_rules(): void
    {
        self::assertSame(
            Driver::quoteIdentifier('sqlserver', 'users'),
            Driver::quoteIdentifier('sybase', 'users')
        );
        self::assertSame('[users]', Driver::quoteIdentifier('sybase', 'users'));
    }

    public function test_sybase_upsert_builder_fails_before_pdo(): void
    {
        $upsert = new Upsert();
        $upsert->bindDialect(new SybaseDialect());
        $upsert->engine = 'sybase';

        $sql = $upsert
            ->into('users')
            ->values(['id' => 1, 'name' => 'Ada'])
            ->key(['id']);

        $this->expectExceptionMessage('Sybase dialect does not support UPSERT builders.');

        $sql->toSql();
    }
}
