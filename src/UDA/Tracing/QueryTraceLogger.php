<?php

declare(strict_types=1);

namespace UDA\Tracing;

/**
 * Basic logging listener that writes trace summaries.
 */
final class QueryTraceLogger implements QueryTraceListener
{
    /** @var callable */
    private $writer;

    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer ?? static function (string $message, QueryTrace $trace): void {
            $suffix = $trace->slow ? ' [SLOW]' : '';
            error_log($message . $suffix);
        };
    }

    public function handle(QueryTrace $trace): void
    {
        $message = sprintf(
            '[%s][%s][%0.2fms][rows:%d] %s',
            $trace->connection,
            $trace->dialect,
            $trace->executionTimeMs,
            $trace->rowCount,
            $trace->sql
        );

        ($this->writer)($message, $trace);
    }
}
