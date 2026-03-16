<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Cache\Store
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/cache/implementations
 * @since 1.0.0
 */

/*
 * Purpose: In-memory array-based cache store implementation for UDA.
 */

namespace UDA\Cache\Store;

use DateTimeImmutable;

/**
 * In-memory array-based cache store implementation
 */
final class ArrayStore implements CacheStoreInterface
{
    /** @var array<string, array{value:string, expires:DateTimeImmutable|null}> */
    private array $items = [];

    /**
     *
     * @param  string  $key The cache key
     * @return ?string The cached value or null
     */
    public function fetch(string $key): ?string
    {
        if (!isset($this->items[$key])) {
            return null;
        }

        $entry = $this->items[$key];

        if ($entry['expires'] !== null && $entry['expires'] <= new DateTimeImmutable()) {
            unset($this->items[$key]);

            return null;
        }

        return $entry['value'];
    }

    /**
     *
     * @param  string $key        The cache key
     * @param  string $value      The value to cache
     * @param  int    $ttlSeconds Time-to-live in seconds
     * @return void
     */
    public function store(string $key, string $value, int $ttlSeconds): void
    {
        $expires = $ttlSeconds > 0 ? (new DateTimeImmutable())->modify("+{$ttlSeconds} seconds") : null;
        $this->items[$key] = ['value' => $value, 'expires' => $expires];
    }

    /**
     *
     * @param  string $key The cache key
     * @return void
     */
    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }
}
