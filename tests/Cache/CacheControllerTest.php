<?php

declare(strict_types=1);

namespace Tests\Cache;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UDA\Cache;
use UDA\Cache\InMemoryTableWriteTracker;
use UDA\Cache\Serializer\Serializer;
use UDA\Cache\Setup;
use UDA\Cache\Store\CacheStoreInterface;
use UDA\SQL\SqlMessage;

final class CacheControllerTest extends TestCase
{
    public function testMetadataFirstHit(): void
    {
        $store = new InstrumentedStore();
        $tracker = new InMemoryTableWriteTracker();
        $cache = $this->newCache($store, $tracker);

        $sql = new SqlMessage('SELECT 1');
        $tables = ['items'];
        $count = 0;

        $result = $cache->read(
            $sql,
            $tables,
            function () use (&$count) {
                $count++;

                return [['id' => 1]];
            },
            static fn () => false
        );

        $this->assertSame([['id' => 1]], $result);
        $this->assertSame(1, $count);

        $store->resetLog();
        $hit = $cache->read(
            $sql,
            $tables,
            function () use (&$count) {
                $count++;

                return [['id' => 2]];
            },
            static fn () => false
        );

        $this->assertSame([['id' => 1]], $hit);
        $this->assertSame(1, $count, 'Cache hit should skip executor');
        $this->assertCount(2, $store->log);
        $this->assertStringStartsWith('m:', $store->log[0][1]);
        $this->assertStringStartsWith('r:', $store->log[1][1]);
    }

    public function testIntervalProtectedHit(): void
    {
        $store = new InstrumentedStore();
        $tracker = new InMemoryTableWriteTracker();
        $cache = $this->newCache($store, $tracker, [
            'ttlSeconds' => 60,
            'minIntervalSeconds' => 30,
        ]);

        $sql = new SqlMessage('SELECT 1');
        $tables = ['items'];
        $count = 0;

        $cache->read($sql, $tables, function () use (&$count) {
            $count++;

            return [['id' => 1]];
        }, static fn () => false);

        $tracker->touch('conn', 'items');

        $second = $cache->read($sql, $tables, function () use (&$count) {
            $count++;

            return [['id' => 2]];
        }, static fn () => false);

        $this->assertSame([['id' => 1]], $second);
        $this->assertSame(1, $count, 'Interval gating should serve cached data despite table touch');
    }

    public function testStaleOnErrorReturnsPayload(): void
    {
        $store = new InstrumentedStore();
        $tracker = new InMemoryTableWriteTracker();
        $cache = $this->newCache($store, $tracker, [
            'ttlSeconds' => 10,
            'maxStaleSeconds' => 40,
            'allowStaleOnError' => true,
        ]);

        $sql = new SqlMessage('SELECT 1');
        $tables = ['items'];

        $cache->read($sql, $tables, fn () => [['id' => 1]], static fn () => false);

        $metadataKey = $store->findMetadataKey();
        $meta = json_decode($store->items[$metadataKey], true);
        $meta['createdAt'] = time() - 20;
        $store->items[$metadataKey] = json_encode($meta);

        $attempts = 0;
        $result = $cache->read(
            $sql,
            $tables,
            function () use (&$attempts) {
                $attempts++;

                throw new RuntimeException('db down');
            },
            static fn () => true
        );

        $this->assertSame([['id' => 1]], $result, 'Stale payload should be served on transient failure');
        $this->assertSame(1, $attempts);
    }

    public function testTableTouchInvalidatesCache(): void
    {
        $store = new InstrumentedStore();
        $tracker = new InMemoryTableWriteTracker();
        $cache = $this->newCache($store, $tracker);

        $sql = new SqlMessage('SELECT 1');
        $tables = ['items'];
        $count = 0;

        $cache->read($sql, $tables, function () use (&$count) {
            $count++;

            return [['id' => 1]];
        }, static fn () => false);

        $cache->touchTables($tables);

        $cache->read($sql, $tables, function () use (&$count) {
            $count++;

            return [['id' => 2]];
        }, static fn () => false);

        $this->assertSame(2, $count, 'Invalidation should force executor to run again');
    }

    private function newCache(
        InstrumentedStore $store,
        InMemoryTableWriteTracker $tracker,
        array $policyOverrides = []
    ): Cache {
        $policy = array_merge([
            'ttlSeconds' => 60,
            'minIntervalSeconds' => 0,
            'allowStaleOnError' => false,
            'maxStaleSeconds' => 0,
            'disabled' => false,
        ], $policyOverrides);

        $setup = new Setup(
            $store,
            $tracker,
            new Serializer('php'),
            'test',
            $policy,
            [],
            1
        );

        return Cache::fromSetup('conn', $setup);
    }
}

final class InstrumentedStore implements CacheStoreInterface
{
    /** @var array<string,string> */
    public array $items = [];
    /** @var array<int,array{0:string,1:string}> */
    public array $log = [];

    public function fetch(string $key): ?string
    {
        $this->log[] = ['fetch', $key];

        return $this->items[$key] ?? null;
    }

    public function store(string $key, string $value, int $ttlSeconds): void
    {
        $this->log[] = ['store', $key];
        $this->items[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    public function resetLog(): void
    {
        $this->log = [];
    }

    public function findMetadataKey(): string
    {
        foreach (array_keys($this->items) as $key) {
            if (str_starts_with($key, 'm:')) {
                return $key;
            }
        }

        throw new RuntimeException('Metadata key not found');
    }
}
