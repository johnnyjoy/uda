<?php

declare(strict_types=1);

namespace UDA\Tracing;

/**
 * Simple in-memory listener for tests and profiling.
 */
final class QueryTraceCollector implements QueryTraceListener
{
    /** @var list<QueryTrace> */
    private array $traces = [];

    public function handle(QueryTrace $trace): void
    {
        $this->traces[] = $trace;
    }

    /**
     * @return list<QueryTrace>
     */
    public function getTraces(): array
    {
        return $this->traces;
    }

    public function clear(): void
    {
        $this->traces = [];
    }
}
