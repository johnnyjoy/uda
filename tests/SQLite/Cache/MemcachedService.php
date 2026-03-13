<?php

declare(strict_types=1);

namespace Tests\SQLite\Cache;

use Tests\SQLite\SQLiteTestCase;
use Throwable;

final class MemcachedService implements CacheService
{
    public function bootOrSkip(SQLiteTestCase $test): array
    {
        if (!class_exists('\Memcached')) {
            $test->markTestSkipped('ext-memcached is not installed.');
        }

        $host = getenv('SQLITE_MEMCACHED_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('SQLITE_MEMCACHED_PORT') ?: 11211);
        $prefix = getenv('SQLITE_MEMCACHED_PREFIX') ?: 'sqlite-cert:';

        $class = 'Memcached';
        /** @var \Memcached $client */
        $client = new $class();

        try {
            $client->addServer($host, $port);
            $client->flush();
        } catch (Throwable $exception) {
            $test->markTestSkipped('Memcached service unavailable: ' . $exception->getMessage());
        }

        $store = [
            'type' => 'memcached',
            'host' => $host,
            'port' => $port,
            'prefix' => $prefix,
            'serializer' => getenv('SQLITE_MEMCACHED_SERIALIZER') ?: 'php',
        ];

        return [
            'store' => $store,
            'serializer' => getenv('SQLITE_MEMCACHED_CACHE_SERIALIZER') ?: 'php',
            'namespace' => getenv('SQLITE_MEMCACHED_NAMESPACE') ?: 'sqlite-cert',
        ];
    }
}
