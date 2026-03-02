<?php

declare(strict_types=1);

/**
 * Redis-based cache store implementation
 */

namespace UDA\Cache\Store;

use Redis;
use RuntimeException;

/**
 * Redis-based cache store implementation
 */
final class RedisStore implements CacheStoreInterface
{
    /**
     * 
     * @param Redis $redis The Redis connection
     * @param string $prefix The key prefix
     * @param string $serializer The serializer to use
     * @throws RuntimeException If Redis extension is not loaded or serializer is unsupported
     */
    public function __construct(private Redis $redis, private string $prefix = 'UDA:', private string $serializer = 'php')
    {
        if (!extension_loaded('redis')) {
            throw new RuntimeException('ext-redis is required for RedisStore');
        }

        switch ($this->serializer) {
            case 'igbinary':
                if (!defined('Redis::SERIALIZER_IGBINARY')) {
                    throw new RuntimeException('Redis serializer IGBINARY is not supported in this PHP build');
                }
                $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
                break;
            case 'php':
                if (defined('Redis::SERIALIZER_PHP')) {
                    $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
                }
                break;
            default:
                throw new RuntimeException("Unsupported Redis serializer: {$this->serializer}");
        }
    }

    /**
     * 
     * @param string $key The cache key
     * @return ?string The cached value or null
     */
    public function fetch(string $key): ?string
    {
        $value = $this->redis->get($this->prefix . $key);
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
        $prefixed = $this->prefix . $key;
        if ($ttlSeconds > 0) {
            $this->redis->setex($prefixed, $ttlSeconds, $value);
        } else {
            $this->redis->set($prefixed, $value);
        }
    }

    /**
     * 
     * @param string $key The cache key
     * @return void
     */
    public function delete(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }
}
