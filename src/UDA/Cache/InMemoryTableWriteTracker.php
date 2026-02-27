<?php

declare(strict_types=1);

/** @purpose UDA\Cache\InMemoryTableWriteTracker: Add detailed purpose here */

namespace UDA\Cache;

final class InMemoryTableWriteTracker implements TableWriteTrackerInterface
{
    /** @var array<string, int> */
    private array $touches = [];

    public function touch(string $connectionName, string $tableName): void
    {
        $key = $this->makeKey($connectionName, $tableName);
        $this->touches[$key] = time();
    }

    public function lastTouched(string $connectionName, string $tableName): ?int
    {
        $key = $this->makeKey($connectionName, $tableName);
        return $this->touches[$key] ?? null;
    }

    private function makeKey(string $connectionName, string $tableName): string
    {
        return strtolower($connectionName) . ':' . strtolower($tableName);
    }
}
