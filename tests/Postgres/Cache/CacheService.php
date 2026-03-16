<?php

declare(strict_types=1);

namespace Tests\Postgres\Cache;

use Tests\Postgres\PostgresTestCase;

interface CacheService
{
    /**
     * @return array<string,mixed>
     */
    public function bootOrSkip(PostgresTestCase $test): array;
}