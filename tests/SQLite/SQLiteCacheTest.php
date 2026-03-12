<?php

declare(strict_types=1);

namespace Tests\SQLite;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\SQLite\Cache\MemcachedService;
use Tests\SQLite\Cache\RedisService;

final class SQLiteCacheTest extends SQLiteTestCase
{
    #[DataProvider('cacheProvider')]
    public function testCachePlaceholder(string $label, object $service): void
    {
        self::fail('placeholder until cache helpers are implemented: ' . $label);
    }

    public static function cacheProvider(): array
    {
        return [
            ['redis', new RedisService()],
            ['memcached', new MemcachedService()],
        ];
    }
}
