<?php

declare(strict_types=1);

/** @purpose UDA\Cache\TableWriteTrackerInterface: Add detailed purpose here */

namespace UDA\Cache;

interface TableWriteTrackerInterface
{
    public function touch(string $connectionName, string $tableName): void;
    public function lastTouched(string $connectionName, string $tableName): ?int;
}
