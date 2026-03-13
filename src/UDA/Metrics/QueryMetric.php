<?php

declare(strict_types=1);

namespace UDA\Metrics;

final class QueryMetric
{
    public function __construct(
        public readonly string $key,
        public readonly string $connection,
        public readonly string $operation,
        public readonly string $fingerprint,
        public readonly string $sql,
        public readonly int $count,
        public readonly int $errorCount,
        public readonly float $totalLatencyMs,
        public readonly float $maxLatencyMs,
        public readonly int $slowCount,
        /** @var array<int,string> */
        public readonly array $tables,
        public readonly int $tableHits
    ) {
    }

    public function averageLatency(): float
    {
        return $this->count > 0 ? $this->totalLatencyMs / $this->count : 0.0;
    }
}
