<?php

declare(strict_types=1);

/** @purpose UDA\Cache\Store\RedisStore: Add detailed purpose here */

namespace UDA\Cache\Store;

use Redis;
use RuntimeException;

final class RedisStore implements CacheStoreInterface
{
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

    public function fetch(string $key): ?string
    {
        $value = $this->redis->get($this->prefix . $key);
        return $value === false ? null : $value;
    }

    public function store(string $key, string $value, int $ttlSeconds): void
    {
        $prefixed = $this->prefix . $key;
        if ($ttlSeconds > 0) {
            $this->redis->setex($prefixed, $ttlSeconds, $value);
        } else {
            $this->redis->set($prefixed, $value);
        }
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }
}
