<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Query\Dialect\Cubrid;

/**
 * Confirms CUBRID dialect capability flags without requiring a live database.
 */
final class CubridCapabilitiesTest extends TestCase
{
    private Cubrid $dialect;

    protected function setUp(): void
    {
        $this->dialect = new Cubrid();
    }

    public function test_name(): void
    {
        self::assertSame('CUBRID', $this->dialect->name());
    }

    public function test_supports_upsert(): void
    {
        self::assertTrue($this->dialect->supportsUpsert());
    }

    public function test_no_returning(): void
    {
        self::assertFalse($this->dialect->supportsReturning());
    }

    public function test_no_merge(): void
    {
        self::assertFalse($this->dialect->supportsMerge());
    }
}
