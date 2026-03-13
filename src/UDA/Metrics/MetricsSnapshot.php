<?php

declare(strict_types=1);

namespace UDA\Metrics;

final class MetricsSnapshot
{
    /**
     * @param array<int,QueryMetric> $metrics
     * @param array<string,int> $tableActivity
     */
    public function __construct(
        public readonly array $metrics,
        public readonly array $tableActivity
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'metrics' => array_map(static function (QueryMetric $metric): array {
                return [
                    'key' => $metric->key,
                    'connection' => $metric->connection,
                    'operation' => $metric->operation,
                    'fingerprint' => $metric->fingerprint,
                    'sql' => $metric->sql,
                    'count' => $metric->count,
                    'errors' => $metric->errorCount,
                    'slowCount' => $metric->slowCount,
                    'avgLatency' => $metric->averageLatency(),
                    'maxLatency' => $metric->maxLatencyMs,
                    'tables' => $metric->tables,
                ];
            }, $this->metrics),
            'tables' => $this->tableActivity,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
