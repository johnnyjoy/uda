<?php

declare(strict_types=1);

/**
 * In-memory implementation of table write tracker for cache invalidation
 */

namespace UDA\Cache;

/**
 * In-memory implementation of table write tracker for cache invalidation
 */
final class InMemoryTableWriteTracker implements TableWriteTrackerInterface
{
    /** @var array<string, int> */
    private array $touches = [];

    /**
     * 
     * @param string $connectionName The connection name
     * @param string $tableName The table name
     * @return void
     */
    public function touch(string $connectionName, string $tableName): void
    {
        $key = $this->makeKey($connectionName, $tableName);
        $this->touches[$key] = time();
    }

    /**
     * 
     * @param string $connectionName The connection name
     * @param string $tableName The table name
     * @return ?int The timestamp or null
     */
    public function lastTouched(string $connectionName, string $tableName): ?int
    {
        $key = $this->makeKey($connectionName, $tableName);
        return $this->touches[$key] ?? null;
    }

    /**
     * 
     * @param string $connectionName The connection name
     * @param string $tableName The table name
     * @return string The key
     */
    private function makeKey(string $connectionName, string $tableName): string
    {
        return strtolower($connectionName) . ':' . strtolower($tableName);
    }
}
