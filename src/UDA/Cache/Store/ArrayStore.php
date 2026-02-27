<?php

declare(strict_types=1);

/** @purpose UDA\Cache\Store\ArrayStore: Add detailed purpose here */

namespace UDA\Cache\Store;

use DateTimeImmutable;

final class ArrayStore implements CacheStoreInterface
{
    /** @var array<string, array{value:string, expires:DateTimeImmutable|null}> */
    private array $items = [];

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

    public function store(string $key, string $value, int $ttlSeconds): void
    {
        $expires = $ttlSeconds > 0 ? (new DateTimeImmutable())->modify("+{$ttlSeconds} seconds") : null;
        $this->items[$key] = ['value' => $value, 'expires' => $expires];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }
}
