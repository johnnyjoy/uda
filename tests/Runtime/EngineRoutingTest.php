<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use UDA\Driver;

final class EngineRoutingTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function engineAliasProvider(): array
    {
        return [
            'mysql' => ['mysql', 'mariadb'],
            'mariadb' => ['mariadb', 'mariadb'],
            'pgsql' => ['pgsql', 'pgsql'],
            'postgres' => ['postgres', 'pgsql'],
            'postgresql' => ['postgresql', 'pgsql'],
            'sqlite' => ['sqlite', 'sqlite'],
            'sqlsrv' => ['sqlsrv', 'sqlserver'],
            'sqlserver' => ['sqlserver', 'sqlserver'],
            'dblib' => ['dblib', 'sybase'],
            'sybase' => ['sybase', 'sybase'],
            'oci' => ['oci', 'oracle'],
            'oracle' => ['oracle', 'oracle'],
            'db2' => ['db2', 'db2'],
            'firebird' => ['firebird', 'firebird'],
            'interbase' => ['interbase', 'firebird'],
        ];
    }

    /**
     * @dataProvider engineAliasProvider
     */
    public function test_engine_key_normalizes_config_aliases(string $alias, string $expected): void
    {
        self::assertSame($expected, Driver::engineKey($alias));
    }

    public function test_engine_key_is_case_insensitive(): void
    {
        self::assertSame('pgsql', Driver::engineKey('PostgreSQL'));
        self::assertSame('sqlserver', Driver::engineKey('SQLSRV'));
    }

    /**
     * @dataProvider engineAliasProvider
     */
    public function test_quote_identifier_routes_aliases_to_same_engine_rules(
        string $alias,
        string $canonical
    ): void {
        $fromAlias = Driver::quoteIdentifier($alias, 'users');
        $fromCanonical = Driver::quoteIdentifier($canonical, 'users');

        self::assertSame($fromCanonical, $fromAlias);
    }

    public function test_engine_key_keeps_sqlserver_and_sybase_distinct(): void
    {
        self::assertSame('sqlserver', Driver::engineKey('sqlsrv'));
        self::assertSame('sybase', Driver::engineKey('dblib'));
        self::assertNotSame(Driver::engineKey('sqlsrv'), Driver::engineKey('dblib'));
    }
}
