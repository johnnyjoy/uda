<?php

declare(strict_types=1);

namespace Tests\Metrics;

use PHPUnit\Framework\TestCase;
use UDA\Metrics\QueryMetric;

final class QueryMetricTest extends TestCase
{
    public function testAverageLatency(): void
    {
        $metric = new QueryMetric(
            key: 'default|select|f1',
            connection: 'default',
            operation: 'rows',
            fingerprint: 'f1',
            sql: 'SELECT 1',
            count: 4,
            errorCount: 0,
            totalLatencyMs: 20.0,
            maxLatencyMs: 10.0,
            slowCount: 1,
            tables: ['foo'],
            tableHits: 4
        );

        $this->assertSame(5.0, $metric->averageLatency());
    }
}
