<?php

declare(strict_types=1);

namespace Tests\Metrics;

use PHPUnit\Framework\TestCase;
use UDA\Metrics\MetricsAggregator;
use UDA\Metrics\MetricsConfig;
use UDA\Metrics\SqlNormalizer;
use UDA\Tracing\QueryTrace;

final class MetricsAggregatorTest extends TestCase
{
    public function testAggregatesCountsAndLatency(): void
    {
        $aggregator = new MetricsAggregator(new MetricsConfig(enabled: true));
        $trace = $this->makeTrace(operation: 'rows', sql: 'SELECT 1', duration: 10.0);
        $aggregator->handle($trace);
        $aggregator->handle($trace);

        $snap = $aggregator->snapshot();
        $metric = $snap->metrics[0];
        $this->assertSame(2, $metric->count);
        $this->assertSame(20.0, $metric->totalLatencyMs);
        $this->assertSame(10.0, $metric->maxLatencyMs);
    }

    public function testSlowThresholdIncrementsSlowCount(): void
    {
        $aggregator = new MetricsAggregator(new MetricsConfig(enabled: true, slowQueryThresholdMs: 5));
        $aggregator->handle($this->makeTrace(sql: 'SELECT 1', duration: 10.0));

        $metric = $aggregator->snapshot()->metrics[0];
        $this->assertSame(1, $metric->slowCount);
    }

    public function testErrorFlagIncrementsErrorCount(): void
    {
        $aggregator = new MetricsAggregator(new MetricsConfig(enabled: true));
        $aggregator->handle($this->makeTrace(error: true));

        $metric = $aggregator->snapshot()->metrics[0];
        $this->assertSame(1, $metric->errorCount);
    }

    public function testTableActivityTracked(): void
    {
        $aggregator = new MetricsAggregator(new MetricsConfig(enabled: true, reportTables: true));
        $aggregator->handle($this->makeTrace(tables: ['employees', 'orders']));
        $aggregator->handle($this->makeTrace(tables: ['employees']));

        $snapshot = $aggregator->snapshot();
        $this->assertSame(['employees' => 2, 'orders' => 1], $snapshot->tableActivity);
        $this->assertSame(['employees', 'orders'], $snapshot->metrics[0]->tables);
    }

    public function testLruEviction(): void
    {
        $aggregator = new MetricsAggregator(new MetricsConfig(enabled: true, maxTrackedQueries: 1), new SqlNormalizer());
        $aggregator->handle($this->makeTrace(sql: 'SELECT 1'));
        $aggregator->handle($this->makeTrace(sql: 'SELECT 2', operation: 'rows'));

        $snapshot = $aggregator->snapshot();
        $this->assertCount(1, $snapshot->metrics);
    }

    private function makeTrace(
        string $operation = 'rows',
        string $sql = 'SELECT 1',
        array $parameters = [],
        array $tables = [],
        float $duration = 1.0,
        bool $error = false
    ): QueryTrace {
        return new QueryTrace(
            operation: $operation,
            sql: $sql,
            parameters: $parameters,
            dialect: 'sqlite',
            connection: 'default',
            executionTimeMs: $duration,
            rowCount: 1,
            planCacheHit: false,
            statementCacheHit: false,
            resultCacheHit: false,
            tables: $tables,
            slow: false,
            meta: ['fingerprint' => sha1($sql)],
            error: $error
        );
    }
}
