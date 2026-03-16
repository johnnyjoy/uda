<?php

declare(strict_types=1);

namespace Tests\Postgres\Cache;

use Tests\Postgres\PostgresTestCase;

final class MemcachedService implements CacheService
{
    public function bootOrSkip(PostgresTestCase $test): array
    {
        if (!class_exists('\\Memcached')) {
            $test->markTestSkipped("Memcached cache driver unavailable: ext-memcached not installed");
        }

        $host = getenv('POSTGRES_MEMCACHED_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('POSTGRES_MEMCACHED_PORT') ?: 11211);
        $timeout = (float)(getenv('POSTGRES_MEMCACHED_TIMEOUT') ?: 0.0);
        $serializer = getenv('POSTGRES_MEMCACHED_SERIALIZER') ?: 'php';
        $persistent = getenv('POSTGRES_MEMCACHED_PERSISTENT_ID') ?: '';

        try {
            $client = new \Memcached($persistent !== '' ? $persistent : null);
            $client->addServer($host, $port);
            $client->setOption(\Memcached::OPT_COMPRESSION, false);

            // Test connectivity
            if ($client->set("__uda_test_conn_key_", 1, 1) === false) {
                $test->markTestSkipped("Memcached connectivity failed");
            }
            $client->delete("__uda_test_conn_key_");
            $client->flush();
        } catch (\Throwable $exception) {
            $test->markTestSkipped("Memcached cache driver unavailable: " . $exception->getMessage());
        }

        return [
            'store' => [
                'type' => 'memcached',
                'host' => $host,
                'port' => $port,
                'serializer' => $serializer,
            ],
        ];
    }
}