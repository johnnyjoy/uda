<?php

declare(strict_types=1);

namespace UDA\Tracing;

use UDA\Replay\ReplayConfig;

final class ReplayCaptureListener implements QueryTraceListener
{
    /** @var callable|null */
    private $clock;

    public function __construct(
        private readonly ReplayStorageInterface $storage,
        private readonly ReplayConfig $config,
        ?callable $clock = null
    ) {
        $this->clock = $clock;
    }

    public function handle(QueryTrace $trace): void
    {
        if ($trace->traceType !== 'query') {
            return;
        }

        $timestamp = $this->clock !== null ? ($this->clock)() : time();

        $metadata = [
            'statementType' => $trace->meta['statementType'] ?? null,
            'fingerprint' => $trace->meta['fingerprint'] ?? null,
        ];

        if ($trace->retryCount !== null) {
            $metadata['retryCount'] = $trace->retryCount;
        }

        if ($trace->retried) {
            $metadata['retried'] = true;
        }

        if ($trace->finalFailure) {
            $metadata['finalFailure'] = true;
        }

        if ($trace->retryReasons !== []) {
            $metadata['retryReasons'] = $trace->retryReasons;
        }

        $snapshot = new ReplaySnapshot(
            connection: $trace->connection,
            dialect: $trace->dialect,
            operation: $trace->operation,
            sql: $trace->sql,
            params: $this->applyMask($trace->parameters),
            tables: $trace->tables,
            durationMs: $trace->executionTimeMs,
            rowCount: $trace->rowCount,
            timestamp: (int) $timestamp,
            metadata: array_filter($metadata, static fn ($value) => $value !== null)
        );

        $this->storage->persist($snapshot);
    }

    public function __destruct()
    {
        $this->storage->close();
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function applyMask(array $params): array
    {
        if ($this->config->maskParameters === []) {
            return $params;
        }

        foreach ($this->config->maskParameters as $key) {
            if (array_key_exists($key, $params)) {
                $params[$key] = '***';
            }
        }

        return $params;
    }
}
