<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Driver;
use UDA\Driver\Prepared;

/**
 * Prepared statement LRU lives on Driver; tests reach it via Closure::bind (no Driver API leak).
 */
final class PreparedStatementLruTest extends TestCase
{
    /**
     * @return array<string,\PDOStatement>
     */
    private static function driverLruMap(Database $db): array
    {
        $dprop = new \ReflectionProperty(Database::class, 'driver');
        $driver = $dprop->getValue($db);
        self::assertInstanceOf(Driver::class, $driver);

        $preparedProp = new \ReflectionProperty(Driver::class, 'prepared');
        $prepared = $preparedProp->getValue($driver);
        $mapProp = new \ReflectionProperty(Prepared::class, 'map');

        /** @var array<string,\PDOStatement> */
        return $mapProp->getValue($prepared);
    }

    public function test_same_sql_reuses_single_prepared_statement(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $sql = 'SELECT 1 AS n';

        $db->value($sql);
        $map1 = self::driverLruMap($db);
        self::assertArrayHasKey($sql, $map1);
        $id1 = spl_object_id($map1[$sql]);

        $db->value($sql);
        $map2 = self::driverLruMap($db);
        self::assertSame($id1, spl_object_id($map2[$sql]));
    }

    public function test_reconnect_clears_prepared_statement_cache(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $db->value('SELECT 1');
        self::assertNotEmpty(self::driverLruMap($db));

        $dprop = new \ReflectionProperty(Database::class, 'driver');
        $driver = $dprop->getValue($db);
        self::assertInstanceOf(Driver::class, $driver);

        $pprop = new \ReflectionProperty(Driver::class, 'pdo');
        $pprop->setValue($driver, new \PDO('sqlite::memory:'));

        $reconnect = new \ReflectionMethod(Driver::class, 'reconnect');
        $reconnect->invoke($driver);

        self::assertSame([], self::driverLruMap($db));
    }

    public function test_lru_evicts_oldest_when_capacity_exceeded(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);

        for ($i = 0; $i < 64; $i++) {
            $db->value('SELECT ' . $i . ' AS v');
        }

        $map64 = self::driverLruMap($db);
        self::assertCount(64, $map64);
        self::assertArrayHasKey('SELECT 0 AS v', $map64);

        $db->value('SELECT 999 AS v');
        $map65 = self::driverLruMap($db);
        self::assertCount(64, $map65);
        self::assertArrayHasKey('SELECT 999 AS v', $map65);
        self::assertArrayNotHasKey(
            'SELECT 0 AS v',
            $map65,
            'Oldest entry should be evicted when the 65th distinct prepared SQL is added.'
        );
    }
}
