<?php

declare(strict_types=1);

namespace Tests\SQLite\Cache;

use Tests\SQLite\SQLiteTestCase;
use Throwable;

final class RedisService implements CacheService
{
    public function bootOrSkip(SQLiteTestCase $test): array
    {
        if (!class_exists('\Redis')) {
            $test->markTestSkipped('ext-redis is not installed.');
        }

        $host = getenv('SQLITE_REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('SQLITE_REDIS_PORT') ?: 6379);
        $timeout = (float) (getenv('SQLITE_REDIS_TIMEOUT') ?: 1.5);
        $prefix = getenv('SQLITE_REDIS_PREFIX') ?: 'sqlite-cert:';
        $serializer = getenv('SQLITE_REDIS_SERIALIZER') ?: 'php';
        $auth = getenv('SQLITE_REDIS_AUTH') ?: '';

        $redisClass = 'Redis';
        $client = new $redisClass();

        try {
            $client->connect($host, $port, $timeout);

            if ($auth !== '') {
                $client->auth($auth);
            }
        } catch (Throwable $exception) {
            $test->markTestSkipped('Redis service unavailable: ' . $exception->getMessage());
        }

        try {
            $client->flushDB();
        } catch (Throwable $exception) {
            $test->markTestSkipped('Unable to reset Redis state: ' . $exception->getMessage());
        }

        $store = [
            'type' => 'redis',
            'host' => $host,
            'port' => $port,
            'timeout' => $timeout,
            'prefix' => $prefix,
            'serializer' => $serializer,
        ];

        if ($auth !== '') {
            $store['auth'] = $auth;
        }

        return [
            'store' => $store,
            'serializer' => getenv('SQLITE_REDIS_CACHE_SERIALIZER') ?: 'php',
            'namespace' => getenv('SQLITE_REDIS_NAMESPACE') ?: 'sqlite-cert',
        ];
    }
}
