<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Cache
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/cache/interfaces
 * @since 1.0.0
 */

/*
 * Purpose: Interface for tracking table write operations for cache invalidation.
 */

namespace UDA\Cache;

/**
 * Interface for tracking table write operations for cache invalidation
 */
interface TableWriteTrackerInterface
{
    /**
     *
     * @param  string $connectionName The connection name
     * @param  string $tableName      The table name
     * @return void
     */
    public function touch(string $connectionName, string $tableName): void;

    /**
     *
     * @param  string $connectionName The connection name
     * @param  string $tableName      The table name
     * @return ?int   The timestamp or null
     */
    public function lastTouched(string $connectionName, string $tableName): ?int;
}
