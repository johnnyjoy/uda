<?php

declare(strict_types=1);

namespace UDA\Tracing;

/**
 * Immutable payload describing a single query execution.
 */
final class QueryTrace
{
    /**
     * @param array<string,mixed> $parameters
     * @param array<int,string>   $tables
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $sql,
        public readonly array $parameters,
        public readonly string $dialect,
        public readonly string $connection,
        public readonly float $executionTimeMs,
        public readonly int $rowCount,
        public readonly bool $planCacheHit,
        public readonly bool $statementCacheHit,
        public readonly bool $resultCacheHit,
        public readonly array $tables,
        public readonly bool $slow,
        public readonly string $traceType = 'query',
        public readonly array $meta = [],
        public readonly bool $error = false,
        public readonly ?int $retryCount = null,
        public readonly bool $retried = false,
        public readonly bool $finalFailure = false,
        public readonly array $retryReasons = []
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'sql' => $this->sql,
            'parameters' => $this->parameters,
            'dialect' => $this->dialect,
            'connection' => $this->connection,
            'executionTimeMs' => $this->executionTimeMs,
            'rowCount' => $this->rowCount,
            'planCacheHit' => $this->planCacheHit,
            'statementCacheHit' => $this->statementCacheHit,
            'resultCacheHit' => $this->resultCacheHit,
            'tables' => $this->tables,
            'slow' => $this->slow,
            'traceType' => $this->traceType,
            'meta' => $this->meta,
            'error' => $this->error,
            'retryCount' => $this->retryCount,
            'retried' => $this->retried,
            'finalFailure' => $this->finalFailure,
            'retryReasons' => $this->retryReasons,
        ];
    }
}
