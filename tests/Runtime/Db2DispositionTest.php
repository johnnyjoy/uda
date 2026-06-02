<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use UDA\Database;
use UDA\Exception\ConfigException;
use UDA\Exception\QueryException;
use UDA\Query\Dialect\Db2;

final class Db2DispositionTest extends TestCase
{
    protected function tearDown(): void
    {
        $pool = new ReflectionProperty(Database::class, 'databases');
        $pool->setAccessible(true);
        $pool->setValue(null, []);

        parent::tearDown();
    }

    public function test_db2_dialect_class_is_retained_for_future_compilation(): void
    {
        self::assertTrue(class_exists(Db2::class));
        self::assertSame('DB2', (new Db2())->name());
    }

    public function test_database_query_dialect_rejects_db2_until_driver_exists(): void
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

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('No SQL dialect available for engine: db2');

        $queryDialect->invoke($database);
    }

    public function test_db2_engine_cannot_open_pdo_without_driver_rules(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unsupported database engine: db2');

        DriverConnectProbe::dsnForEngine('db2', 'db2', []);
    }
}

/**
 * @internal test helper — invokes Driver DSN routing without opening PDO.
 */
final class DriverConnectProbe
{
    /**
     * @param array<string,mixed> $params
     */
    public static function dsnForEngine(string $engine, string $transport, array $params): string
    {
        $method = new ReflectionMethod(\UDA\Driver::class, 'dsn');
        $method->setAccessible(true);

        /** @var string $dsn */
        $dsn = $method->invoke(null, $engine, $transport, $params);

        return $dsn;
    }
}
