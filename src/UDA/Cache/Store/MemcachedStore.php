<?php

declare(strict_types=1);

/** @purpose UDA\Cache\Store\MemcachedStore: Add detailed purpose here */

namespace UDA\Cache\Store;

use Memcached;
use RuntimeException;

final class MemcachedStore implements CacheStoreInterface
{
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

    public function fetch(string $key): ?string
    {
        $value = $this->memcached->get($this->prefix . $key);
        return $value === false ? null : $value;
    }

    public function store(string $key, string $value, int $ttlSeconds): void
    {
        $this->memcached->set($this->prefix . $key, $value, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        $this->memcached->delete($this->prefix . $key);
    }
}
