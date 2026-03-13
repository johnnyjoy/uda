<?php

declare(strict_types=1);

namespace UDA\Tracing;

interface QueryTraceListener
{
    public function handle(QueryTrace $trace): void;
}
