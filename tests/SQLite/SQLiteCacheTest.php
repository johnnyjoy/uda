<?php

declare(strict_types=1);

namespace Tests\SQLite;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\SQLite\Cache\CacheService;
use Tests\SQLite\Cache\MemcachedService;
use Tests\SQLite\Cache\RedisService;
use UDA\Database;
use UDA\Tracing\QueryTrace;
use UDA\Tracing\QueryTraceCollector;

final class SQLiteCacheTest extends SQLiteTestCase
{
    #[DataProvider('cacheProvider')]
    public function testReadsUseCacheAndEmitTables(string $label, CacheService $service): void
    {
        $serviceConfig = $service->bootOrSkip($this);

        $this->withMemoryDbConfig($this->sqliteCacheConfig($serviceConfig), function (Database $db) use ($label): void {
            $collector = $this->registerTraceCollector();

            $sql = 'SELECT name FROM employees WHERE id = :id';
            $params = ['id' => 1];
            $tableHints = ['employees'];

            $db->rows($sql, $params, $tableHints);
            $firstTrace = $this->lastTrace($collector);
            self::assertFalse($firstTrace->resultCacheHit, sprintf('[%s] first read should hit DB', $label));
            $this->assertTraceTables($firstTrace, $tableHints, $label);

            $db->rows($sql, $params, $tableHints);
            $secondTrace = $this->lastTrace($collector);
            self::assertTrue($secondTrace->resultCacheHit, sprintf('[%s] second read should come from cache', $label));
            $this->assertTraceTables($secondTrace, $tableHints, $label);
        });
    }

    #[DataProvider('cacheProvider')]
    public function testWriteInvalidatesCachedEntry(string $label, CacheService $service): void
    {
        $serviceConfig = $service->bootOrSkip($this);

        $this->withMemoryDbConfig($this->sqliteCacheConfig($serviceConfig), function (Database $db) use ($label): void {
            $collector = $this->registerTraceCollector();
            $countSql = 'SELECT COUNT(*) AS total FROM employees';
            $tableHints = ['employees'];

            $initial = $db->rows($countSql, [], $tableHints);
            self::assertSame(2, (int) ($initial[0]['total'] ?? 0));
            $this->assertTraceTables($this->lastTrace($collector), $tableHints, $label);
            $collector->clear();

            $insert = 'INSERT INTO employees (id, name, title, hired_at) VALUES (:id, :name, :title, :hired_at)';
            $db->exec($insert, [
                'id' => 999,
                'name' => 'Cache Witness',
                'title' => 'Analyst',
                'hired_at' => '2024-01-01',
            ], $tableHints);

            $afterWrite = $db->rows($countSql, [], $tableHints);
            self::assertSame(3, (int) ($afterWrite[0]['total'] ?? 0));
            $trace = $this->lastTrace($collector);
            self::assertFalse($trace->resultCacheHit, sprintf('[%s] read after write should bypass cache', $label));
            $this->assertTraceTables($trace, $tableHints, $label);
        });
    }

    public static function cacheProvider(): array
    {
        return [
            ['redis', new RedisService()],
            ['memcached', new MemcachedService()],
        ];
    }

    private function sqliteCacheConfig(array $serviceConfig): array
    {
        $defaults = [
            'serializer' => 'php',
            'namespace' => 'sqlite-cert',
            'defaultPolicy' => [
                'ttlSeconds' => 60,
                'minIntervalSeconds' => 0,
                'allowStaleOnError' => false,
                'maxStaleSeconds' => 0,
                'disabled' => false,
            ],
        ];

        return [
            'cache' => array_replace_recursive($defaults, $serviceConfig),
        ];
    }

    private function registerTraceCollector(): QueryTraceCollector
    {
        Database::clearTraceListeners();
        $collector = new QueryTraceCollector();
        Database::addTraceListener($collector);

        return $collector;
    }

    private function lastTrace(QueryTraceCollector $collector): QueryTrace
    {
        $traces = $collector->getTraces();
        self::assertNotEmpty($traces, 'expected at least one trace');

        return $traces[array_key_last($traces)];
    }

    private function assertTraceTables(QueryTrace $trace, array $tableHints, string $label): void
    {
        self::assertSame($tableHints, $trace->tables, sprintf('[%s] trace tables mismatch', $label));
        self::assertArrayHasKey('fingerprint', $trace->meta, sprintf('[%s] trace metadata missing fingerprint', $label));
    }
}
