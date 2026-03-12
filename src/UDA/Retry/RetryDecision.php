<?php

declare(strict_types=1);

namespace UDA\Retry;

final class RetryDecision
{
    public function __construct(
        public readonly bool $shouldRetry,
        public readonly int $attempt,
        public readonly float $delayMs,
        public readonly string $reason
    ) {
    }
}
