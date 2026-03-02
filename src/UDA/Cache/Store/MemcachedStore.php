<?php

declare(strict_types=1);

/**
 * Memcached-based cache store implementation
 */

namespace UDA\Cache\Store;

use Memcached;
use RuntimeException;

/**
 * Memcached-based cache store implementation
 */
final class MemcachedStore implements CacheStoreInterface
{
    /**
     * 
     * @param Memcached $memcached The Memcached connection
     * @param string $prefix The key prefix
     * @param string $serializer The serializer to use
     * @throws RuntimeException If Memcached extension is not loaded or serializer is unsupported
     */
    public function __construct(private Memcached $memcached, private string $prefix = 'UDA:', private string $serializer = 'php')
    {
        if (!extension_loaded('memcached')) {
            throw new RuntimeException('ext-memcached is required for MemcachedStore');
        }

        $allowed = [
            'php' => Memcached::SERIALIZER_PHP,
            'igbinary' => defined('Memcached::SERIALIZER_IGBINARY') ? Memcached::SERIALIZER_IGBINARY : null,
        ];

        if (!isset($allowed[$this->serializer]) || $allowed[$this->serializer] === null) {
            throw new RuntimeException("Unsupported Memcached serializer: {$this->serializer}");
        }

        $this->memcached->setOption(Memcached::OPT_SERIALIZER, $allowed[$this->serializer]);
    }

    /**
     * 
     * @param string $key The cache key
     * @return ?string The cached value or null
     */
    public function fetch(string $key): ?string
    {
        $value = $this->memcached->get($this->prefix . $key);
        return $value === false ? null : $value;
    }

    /**
     * 
     * @param string $key The cache key
     * @param string $value The value to cache
     * @param int $ttlSeconds Time-to-live in seconds
     * @return void
     */
    public function store(string $key, string $value, int $ttlSeconds): void
    {
        $this->memcached->set($this->prefix . $key, $value, $ttlSeconds);
    }

    /**
     * 
     * @param string $key The cache key
     * @return void
     */
    public function delete(string $key): void
    {
        $this->memcached->delete($this->prefix . $key);
    }
}
