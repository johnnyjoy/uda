<?php

declare(strict_types=1);

namespace Tests\Postgres\Cache;

use Tests\Postgres\PostgresTestCase;

final class RedisService implements CacheService
{
    public function bootOrSkip(PostgresTestCase $test): array
    {
        if (!class_exists('\\Redis')) {
            $test->markTestSkipped("Redis cache driver unavailable: ext-redis not installed");
        }

        $host = getenv('POSTGRES_REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('POSTGRES_REDIS_PORT') ?: 6379);
        $timeout = (float)(getenv('POSTGRES_REDIS_TIMEOUT') ?: 1.5);
        $serializer = getenv('POSTGRES_REDIS_SERIALIZER') ?: 'php';
        $auth = getenv('POSTGRES_REDIS_AUTH') ?: '';

        try {
            $client = new \Redis();
            $client->connect($host, $port, $timeout);
            if ($auth !== '') {
                $client->auth($auth);
            }
            $client->flushDB();
        } catch (\Throwable $exception) {
            $test->markTestSkipped("Redis cache driver unavailable: " . $exception->getMessage());
        }

        return [
            'store' => [
                'type' => 'redis',
                'host' => $host,
                'port' => $port,
                'timeout' => $timeout,
                'auth' => $auth !== '' ? $auth : null,
                'serializer' => $serializer,
            ],
        ];
    }
}