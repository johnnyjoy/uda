<?php

declare(strict_types=1);

namespace UDA\Metrics;

final class QueryMetricAccumulator
{
    public int $count = 0;
    public int $errorCount = 0;
    public float $totalLatencyMs = 0.0;
    public float $maxLatencyMs = 0.0;
    public int $slowCount = 0;
    public int $tableHits = 0;

    /** @var array<string,bool> */
    private array $tableSet = [];

    public function __construct(
        public readonly string $key,
        public readonly string $connection,
        public readonly string $operation,
        public readonly string $fingerprint,
        public readonly string $sql
    ) {
    }

    /**
     * @param array<int,string> $tables
     */
    public function recordTables(array $tables): void
    {
        if ($tables === []) {
            return;
        }

        foreach ($tables as $table) {
            $normalized = (string) $table;
            $this->tableSet[$normalized] = true;
        }

        $this->tableHits += count($tables);
    }

    public function snapshot(): QueryMetric
    {
        return new QueryMetric(
            key: $this->key,
            connection: $this->connection,
            operation: $this->operation,
            fingerprint: $this->fingerprint,
            sql: $this->sql,
            count: $this->count,
            errorCount: $this->errorCount,
            totalLatencyMs: $this->totalLatencyMs,
            maxLatencyMs: $this->maxLatencyMs,
            slowCount: $this->slowCount,
            tables: array_keys($this->tableSet),
            tableHits: $this->tableHits
        );
    }
}
