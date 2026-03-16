<?php

declare(strict_types=1);

namespace UDA\Exception;

use Throwable;

class QuerySafetyException extends QueryException
{
    public function __construct(private readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct("Guardrail violation: {$this->reason}", 0, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
