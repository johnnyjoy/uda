<?php

declare(strict_types=1);

/**
 * Interface for tracking table write operations for cache invalidation
 */

namespace UDA\Cache;

/**
 * Interface for tracking table write operations for cache invalidation
 */
interface TableWriteTrackerInterface
{
    /**
     * 
     * @param string $connectionName The connection name
     * @param string $tableName The table name
     * @return void
     */
    public function touch(string $connectionName, string $tableName): void;

    /**
     * 
     * @param string $connectionName The connection name
     * @param string $tableName The table name
     * @return ?int The timestamp or null
     */
    public function lastTouched(string $connectionName, string $tableName): ?int;
}
