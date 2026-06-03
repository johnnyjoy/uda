<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use UDA\Database;
use UDA\Exception\ConfigException;
use UDA\Query\Dialect\Firebird;

final class FirebirdConnectTest extends TestCase
{
    protected function tearDown(): void
    {
        $pool = new ReflectionProperty(Database::class, 'databases');
        $pool->setAccessible(true);
        $pool->setValue(null, []);

        parent::tearDown();
    }

    public function test_firebird_dialect_class_is_available(): void
    {
        self::assertTrue(class_exists(Firebird::class));
        self::assertSame('Firebird', (new Firebird())->name());
    }

    public function test_firebird_dsn_from_host_port_and_database(): void
    {
        $dsn = DriverConnectProbe::dsnForEngine('firebird', 'firebird', [
            'host' => '127.0.0.1',
            'port' => 3050,
            'database' => '/var/lib/firebird/data/test.fdb',
        ]);

        self::assertSame(
            'firebird:dbname=127.0.0.1/3050:/var/lib/firebird/data/test.fdb',
            $dsn
        );
    }

    public function test_firebird_dsn_accepts_dbname_alias(): void
    {
        self::assertSame(
            'firebird:dbname=db.internal/3050:app.fdb',
            DriverConnectProbe::dsnForEngine('firebird', 'firebird', [
                'host' => 'db.internal',
                'dbname' => 'app.fdb',
            ])
        );
    }

    public function test_firebird_dsn_accepts_full_firebird_prefix(): void
    {
        $full = 'firebird:dbname=localhost/3050:mydb.fdb';

        self::assertSame(
            $full,
            DriverConnectProbe::dsnForEngine('firebird', 'firebird', ['dsn' => $full])
        );
    }

    public function test_interbase_alias_routes_to_firebird_dsn(): void
    {
        self::assertSame(
            'firebird:dbname=127.0.0.1/3050:legacy.fdb',
            DriverConnectProbe::dsnForEngine('interbase', 'firebird', [
                'host' => '127.0.0.1',
                'database' => 'legacy.fdb',
            ])
        );
    }

    public function test_firebird_dsn_requires_host_and_database_when_no_dsn_key(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Firebird configuration requires host and database');

        DriverConnectProbe::dsnForEngine('firebird', 'firebird', []);
    }

    public function test_database_query_dialect_resolves_firebird(): void
    {
        $database = Database::connect('alpha');

        $driverProperty = new ReflectionProperty(Database::class, 'driver');
        $driverProperty->setAccessible(true);
        $driver = $driverProperty->getValue($database);

        $engineProperty = new ReflectionProperty($driver, 'engine');
        $engineProperty->setAccessible(true);
        $engineProperty->setValue($driver, 'firebird');

        $dialectProperty = new ReflectionProperty(Database::class, 'dialect');
        $dialectProperty->setAccessible(true);
        $dialectProperty->setValue($database, null);

        $queryDialect = new ReflectionMethod(Database::class, 'queryDialect');
        $queryDialect->setAccessible(true);

        $dialect = $queryDialect->invoke($database);

        self::assertInstanceOf(Firebird::class, $dialect);
    }

    public function test_firebird_quote_identifier_uppercases_unquoted_names(): void
    {
        self::assertSame('"USERS"', \UDA\Driver::quoteIdentifier('firebird', 'users'));
        self::assertSame('"COL""NAME"', \UDA\Driver::quoteIdentifier('firebird', 'col"name'));
    }
}
