<?php

declare(strict_types=1);

namespace UDA\Cache\Store;

use PHPUnit\Framework\TestCase;

final class CacheStoreTest extends TestCase
{
    public function testInterfaceCompatibility(): void
    {
        self::assertTrue(method_exists(CacheStore::class, 'create'));
        self::assertTrue(method_exists(CacheStore::class, 'get'));
        self::assertTrue(method_exists(CacheStore::class, 'set'));
        self::assertTrue(method_exists(CacheStore::class, 'clear'));
    }
}