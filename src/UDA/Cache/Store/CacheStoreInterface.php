<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Cache\Store
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/cache/interfaces
 * @since 1.0.0
 */

/*
 * Purpose: Interface for cache store implementations in UDA.
 */

namespace UDA\Cache\Store;

/**
 * Interface for cache store implementations
 */
interface CacheStoreInterface
{
    /**
     *
     * @param  string  $key The cache key
     * @return ?string The cached value or null
     */
    public function fetch(string $key): ?string;

    /**
     *
     * @param  string $key        The cache key
     * @param  string $value      The value to cache
     * @param  int    $ttlSeconds Time-to-live in seconds
     * @return void
     */
    public function store(string $key, string $value, int $ttlSeconds): void;

    /**
     *
     * @param  string $key The cache key
     * @return void
     */
    public function delete(string $key): void;
}
