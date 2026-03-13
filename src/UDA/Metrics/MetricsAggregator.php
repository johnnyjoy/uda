<?php

declare(strict_types=1);

namespace UDA\Metrics;

use UDA\Tracing\QueryTrace;
use UDA\Tracing\QueryTraceListener;

final class MetricsAggregator implements QueryTraceListener
{
    /** @var array<string,QueryMetricAccumulator> */
    private array $metrics = [];

    /** @var array<string,int> */
    private array $touchIndex = [];

    /** @var array<string,int> */
    private array $tableActivity = [];

    private int $touchCounter = 0;

    public function __construct(
        private readonly MetricsConfig $config,
        private readonly SqlNormalizer $normalizer = new SqlNormalizer()
    ) {
    }

    public function handle(QueryTrace $trace): void
    {
        if (!$this->config->enabled || $trace->traceType !== 'query') {
            return;
        }

        $normalized = $this->normalizer->normalize($trace->sql, $trace->meta['fingerprint'] ?? null);
        $key = $trace->connection . '|' . $trace->operation . '|' . $normalized['fingerprint'];

        $metric = $this->metrics[$key] ??= new QueryMetricAccumulator(
            key: $key,
            connection: $trace->connection,
            operation: $trace->operation,
            fingerprint: $normalized['fingerprint'],
            sql: $normalized['sql']
        );

        $metric->count++;
        $metric->totalLatencyMs += $trace->executionTimeMs;
        $metric->maxLatencyMs = max($metric->maxLatencyMs, $trace->executionTimeMs);

        if ($trace->error) {
            $metric->errorCount++;
        }

        if ($this->config->slowQueryThresholdMs > 0 && $trace->executionTimeMs >= $this->config->slowQueryThresholdMs) {
            $metric->slowCount++;
        }

        if ($this->config->reportTables && $trace->tables !== []) {
            $metric->recordTables($trace->tables);

            foreach ($trace->tables as $table) {
                $this->tableActivity[$table] = ($this->tableActivity[$table] ?? 0) + 1;
            }
        }

        $this->touchIndex[$key] = ++$this->touchCounter;
        $this->evictIfNeeded();
    }

    public function snapshot(): MetricsSnapshot
    {
        $metrics = array_map(static fn (QueryMetricAccumulator $acc): QueryMetric => $acc->snapshot(), $this->metrics);
        $tables = $this->tableActivity;
        arsort($tables);

        return new MetricsSnapshot(array_values($metrics), $tables);
    }

    public function exportJson(): string
    {
        return $this->snapshot()->toJson();
    }

    public function reset(): void
    {
        $this->metrics = [];
        $this->touchIndex = [];
        $this->tableActivity = [];
        $this->touchCounter = 0;
    }

    private function evictIfNeeded(): void
    {
        $limit = $this->config->maxTrackedQueries;

        if ($limit <= 0 || count($this->metrics) <= $limit) {
            return;
        }

        asort($this->touchIndex);
        $excess = count($this->metrics) - $limit;

        foreach (array_slice($this->touchIndex, 0, $excess, true) as $key => $_) {
            unset($this->metrics[$key], $this->touchIndex[$key]);
        }
    }
}
