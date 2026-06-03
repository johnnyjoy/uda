<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use UDA\Database;
use UDA\Exception\ConfigException;
use UDA\Query\Dialect\Db2;

final class Db2ConnectTest extends TestCase
{
    protected function tearDown(): void
    {
        $pool = new ReflectionProperty(Database::class, 'databases');
        $pool->setAccessible(true);
        $pool->setValue(null, []);

        parent::tearDown();
    }

    public function test_db2_dialect_class_is_available(): void
    {
        self::assertTrue(class_exists(Db2::class));
        self::assertSame('DB2', (new Db2())->name());
    }

    public function test_db2_inline_dsn_from_host_and_dbname(): void
    {
        $dsn = DriverConnectProbe::dsnForEngine('db2', 'db2', [
            'host' => 'db.example.com',
            'dbname' => 'SAMPLE',
            'port' => 50001,
        ]);

        self::assertSame(
            'ibm:DATABASE=SAMPLE;HOSTNAME=db.example.com;PORT=50001;PROTOCOL=TCPIP',
            $dsn
        );
    }

    public function test_db2_dsn_accepts_db2cli_section_fragment(): void
    {
        self::assertSame(
            'ibm:DSN=SAMPLE',
            DriverConnectProbe::dsnForEngine('db2', 'db2', ['dsn' => 'DSN=SAMPLE'])
        );
    }

    public function test_db2_dsn_accepts_full_ibm_prefix(): void
    {
        $full = 'ibm:DATABASE=testdb;HOSTNAME=127.0.0.1;PORT=50000;PROTOCOL=TCPIP';

        self::assertSame(
            $full,
            DriverConnectProbe::dsnForEngine('db2', 'db2', ['dsn' => $full])
        );
    }

    public function test_db2_dsn_requires_host_and_dbname_when_no_dsn_key(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Db2 configuration requires dbname and host');

        DriverConnectProbe::dsnForEngine('db2', 'db2', []);
    }

    public function test_database_query_dialect_resolves_db2(): void
    {
        $database = Database::connect('alpha');

        $driverProperty = new ReflectionProperty(Database::class, 'driver');
        $driverProperty->setAccessible(true);
        $driver = $driverProperty->getValue($database);

        $engineProperty = new ReflectionProperty($driver, 'engine');
        $engineProperty->setAccessible(true);
        $engineProperty->setValue($driver, 'db2');

        $dialectProperty = new ReflectionProperty(Database::class, 'dialect');
        $dialectProperty->setAccessible(true);
        $dialectProperty->setValue($database, null);

        $queryDialect = new ReflectionMethod(Database::class, 'queryDialect');
        $queryDialect->setAccessible(true);

        $dialect = $queryDialect->invoke($database);

        self::assertInstanceOf(Db2::class, $dialect);
    }

    public function test_db2_quote_identifier_uppercases_and_escapes(): void
    {
        self::assertSame('"USERS"', \UDA\Driver::quoteIdentifier('db2', 'users'));
        self::assertSame('"COL""NAME"', \UDA\Driver::quoteIdentifier('db2', 'col"name'));
    }
}
