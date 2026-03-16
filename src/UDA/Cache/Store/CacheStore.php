<?php

declare(strict_types=1);

namespace UDA\Cache\Store;

interface CacheStore
{
    /**
     * Factory that always returns a Store:
     * - Returns impl Store if extension present
     * - Falls back to `NoOpStore::create()` otherwise
     *
     * @param  array<string,mixed> $config Non-empty; must contain
     *                                     ['host', 'port', 'timeout', ...]
     * @return self                Valid store instance
     */
    public static function create(array $config): CacheStore;

    /**
     * @param  string                   $key Non-empty
     * @return array<string,mixed>|null Serialized cache entry or null
     */
    public function get(string $key): ?array;

    /**
     * @param string              $key   Non-empty
     * @param array<string,mixed> $value
     * @param int|null            $ttl   Seconds until expiry; 0 = forever
     */
    public function set(string $key, array $value, ?int $ttl = null): void;

    public function clear(): void;
}
