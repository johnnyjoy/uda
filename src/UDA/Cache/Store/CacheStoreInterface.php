<?php

declare(strict_types=1);

/** @purpose UDA\Cache\Store\CacheStoreInterface: Add detailed purpose here */

namespace UDA\Cache\Store;

interface CacheStoreInterface
{
    public function fetch(string $key): ?string;
    public function store(string $key, string $value, int $ttlSeconds): void;
    public function delete(string $key): void;
}
