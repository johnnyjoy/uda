<?php

declare(strict_types=1);

namespace Tests\Driver;

use PHPUnit\Framework\TestCase;
use UDA\Driver\Oracle;
use UDA\Driver\PostgreSQL;
use UDA\Driver\SQLite;

final class DriverDialectTest extends TestCase
{
    public function testPostgresDsnAndQuoting(): void
    {
        $driver = $this->buildDriverWithoutConstructor(PostgreSQL::class);

        $dsn = $this->invokeProtected($driver, 'buildDsn', [[
            'host' => 'db1.internal',
            'port' => 5432,
            'dbname' => 'app',
            'sslmode' => 'require',
        ]]);

        $this->assertSame('pgsql:host=db1.internal;dbname=app;port=5432;sslmode=require', $dsn);
        $this->assertSame('"Users"', $this->invokeProtected($driver, 'quoteIdentifier', ['Users']));
        $this->assertSame('LIMIT 25 OFFSET 10', $driver->limitOffset(25, 10));
    }

    public function testOracleDsnAndPagination(): void
    {
        $driver = $this->buildDriverWithoutConstructor(Oracle::class);

        $dsn = $this->invokeProtected($driver, 'buildDsn', [[
            'host' => 'ora.internal',
            'port' => 1521,
            'service' => 'analytics',
        ]]);

        $this->assertSame('oci:dbname=//ora.internal:1521/analytics', $dsn);
        $this->assertSame('"ORDERS"', $this->invokeProtected($driver, 'quoteIdentifier', ['orders']));
        $this->assertSame('OFFSET 5 ROWS FETCH NEXT 20 ROWS ONLY', $driver->limitOffset(20, 5));
    }

    public function testSqliteLimitOffsetAndInList(): void
    {
        $driver = $this->buildDriverWithoutConstructor(SQLite::class);

        $this->assertSame('LIMIT 50 OFFSET 0', $driver->limitOffset(50, 0));

        [$sql, $params] = $driver->inList(['alpha', 'beta'], 'ids');
        $this->assertSame('IN (:ids_0, :ids_1)', $sql);
        $this->assertSame(['ids_0' => 'alpha', 'ids_1' => 'beta'], $params);
    }

    private function buildDriverWithoutConstructor(string $class): object
    {
        $ref = new \ReflectionClass($class);

        return $ref->newInstanceWithoutConstructor();
    }

    private function invokeProtected(object $object, string $method, array $args)
    {
        $ref = new \ReflectionClass($object);
        $methodRef = $ref->getMethod($method);
        $methodRef->setAccessible(true);

        return $methodRef->invokeArgs($object, $args);
    }
}
