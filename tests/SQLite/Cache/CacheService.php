<?php

declare(strict_types=1);

namespace Tests\SQLite\Cache;

use Tests\SQLite\SQLiteTestCase;

interface CacheService
{
    /**
     * Boot the cache service or skip the current test if unavailable.
     *
     * @return array cache configuration fragment merged into connection config
     */
    public function bootOrSkip(SQLiteTestCase $test): array;
}
