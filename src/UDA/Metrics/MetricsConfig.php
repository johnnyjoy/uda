<?php

declare(strict_types=1);

namespace UDA\Metrics;

final class MetricsConfig
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly float $slowQueryThresholdMs = 0.0,
        public readonly int $maxTrackedQueries = 0,
        public readonly bool $reportTables = true
    ) {
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            slowQueryThresholdMs: (float) ($config['slowQueryThresholdMs'] ?? 0.0),
            maxTrackedQueries: (int) ($config['maxTrackedQueries'] ?? 0),
            reportTables: (bool) ($config['reportTables'] ?? true)
        );
    }
}
