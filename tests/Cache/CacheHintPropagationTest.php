<?php

declare(strict_types=1);

namespace Tests\Cache;

use Tests\SQLite\SQLiteTestCase;
use UDA\Database;
use UDA\Tracing\QueryTrace;
use UDA\Tracing\QueryTraceCollector;

final class CacheHintPropagationTest extends SQLiteTestCase
{
    public function testRawSqlCacheHitWithTableHintsAlsoUpdatesTraceMetadata(): void
    {
        $this->withMemoryDbConfig($this->arrayCacheConfig(), function (Database $db): void {
            Database::clearTraceListeners();
            $collector = new QueryTraceCollector();
            Database::addTraceListener($collector);

            $sql = 'SELECT name FROM employees WHERE id = :id';
            $params = ['id' => 1];
            $hints = ['employees'];

            $first = $db->rows($sql, $params, $hints);
            self::assertSame('Ada Lovelace', $first[0]['name'] ?? null);
            $firstTrace = $this->lastTrace($collector);
            self::assertFalse($firstTrace->resultCacheHit);

            $second = $db->rows($sql, $params, $hints);
            self::assertSame('Ada Lovelace', $second[0]['name'] ?? null);
            $secondTrace = $this->lastTrace($collector);
            self::assertTrue($secondTrace->resultCacheHit);
            self::assertSame(['employees'], $secondTrace->tables);

            Database::clearTraceListeners();
        });
    }

    public function testWriteInvalidatesCacheWhenUsingTableHints(): void
    {
        $this->withMemoryDbConfig($this->arrayCacheConfig(), function (Database $db): void {
            Database::clearTraceListeners();
            $collector = new QueryTraceCollector();
            Database::addTraceListener($collector);

            $countSql = 'SELECT COUNT(*) AS total FROM employees';
            $hints = ['employees'];

            $first = $db->rows($countSql, [], $hints);
            self::assertSame(2, (int) ($first[0]['total'] ?? 0));
            $collector->clear();

            $insert = 'INSERT INTO employees (id, name, title, hired_at) VALUES (:id, :name, :title, :hired_at)';
            $db->exec($insert, [
                'id' => 999,
                'name' => 'Cache Witness',
                'title' => 'Analyst',
                'hired_at' => '2024-01-01',
            ], ['employees']);

            $afterWrite = $db->rows($countSql, [], $hints);
            self::assertSame(3, (int) ($afterWrite[0]['total'] ?? 0));
            $postWriteTrace = $this->lastTrace($collector);
            self::assertFalse($postWriteTrace->resultCacheHit, 'cache entry should be invalidated after write');

            Database::clearTraceListeners();
        });
    }

    private function arrayCacheConfig(): array
    {
        return [
            'cache' => [
                'store' => ['type' => 'array'],
                'serializer' => 'php',
                'namespace' => 'sqlite-cert',
                'defaultPolicy' => [
                    'ttlSeconds' => 60,
                    'minIntervalSeconds' => 0,
                    'allowStaleOnError' => false,
                    'maxStaleSeconds' => 0,
                    'disabled' => false,
                ],
            ],
        ];
    }

    private function lastTrace(QueryTraceCollector $collector): QueryTrace
    {
        $traces = $collector->getTraces();
        self::assertNotEmpty($traces);

        return $traces[array_key_last($traces)];
    }
}
