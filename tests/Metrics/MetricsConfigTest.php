<?php

declare(strict_types=1);

namespace Tests\Metrics;

use PHPUnit\Framework\TestCase;
use UDA\Metrics\MetricsConfig;

final class MetricsConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new MetricsConfig();

        $this->assertFalse($config->enabled);
        $this->assertSame(0.0, $config->slowQueryThresholdMs);
        $this->assertSame(0, $config->maxTrackedQueries);
        $this->assertTrue($config->reportTables);
    }

    public function testFromArrayOverrides(): void
    {
        $config = MetricsConfig::fromArray([
            'enabled' => true,
            'slowQueryThresholdMs' => 75,
            'maxTrackedQueries' => 1000,
            'reportTables' => false,
        ]);

        $this->assertTrue($config->enabled);
        $this->assertSame(75.0, $config->slowQueryThresholdMs);
        $this->assertSame(1000, $config->maxTrackedQueries);
        $this->assertFalse($config->reportTables);
    }
}
