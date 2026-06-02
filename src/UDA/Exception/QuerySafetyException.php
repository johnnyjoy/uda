<?php

declare(strict_types=1);

/**
 * @license MIT
 */

namespace UDA\Exception;

use Throwable;

/*
 * Purpose: Represents query guardrail violations before SQL reaches PDO.
 */

/**
 * Query exception carrying the specific safety reason that failed.
 */
class QuerySafetyException extends QueryException
{
    /**
     * Create a guardrail exception with a stable machine-readable reason.
     *
     * @param ?Throwable $previous  Previous exception.
     * @param string     $reason    Machine-readable safety failure reason.
     */
    public function __construct(private readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct("Guardrail violation: {$this->reason}", 0, $previous);
    }

    /**
     * Return the guardrail reason that triggered the exception.
     *
     * @return string String result.
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
