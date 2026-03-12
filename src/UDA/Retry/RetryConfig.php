<?php

declare(strict_types=1);

namespace UDA\Retry;

use InvalidArgumentException;

final class RetryConfig
{
    private const VALID_STRATEGIES = ['fixed', 'linear', 'exponential'];

    public readonly string $strategy;

    public function __construct(
        public readonly bool $enabled = false,
        public readonly int $maxAttempts = 3,
        string $strategy = 'exponential',
        public readonly int $baseDelayMs = 10,
        public readonly int $maxDelayMs = 500,
        public readonly bool $retryWrites = false,
        public readonly bool $retryInTransactions = false,
        public readonly bool $jitter = true
    ) {
        $this->strategy = strtolower($strategy);
        $this->assertValid();
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: self::parseBool($config['enabled'] ?? false),
            maxAttempts: isset($config['maxAttempts']) ? (int) $config['maxAttempts'] : 3,
            strategy: (string) ($config['strategy'] ?? 'exponential'),
            baseDelayMs: isset($config['baseDelayMs']) ? (int) $config['baseDelayMs'] : 10,
            maxDelayMs: isset($config['maxDelayMs']) ? (int) $config['maxDelayMs'] : 500,
            retryWrites: self::parseBool($config['retryWrites'] ?? false),
            retryInTransactions: self::parseBool($config['retryInTransactions'] ?? false),
            jitter: self::parseBool($config['jitter'] ?? true)
        );
    }

    private function assertValid(): void
    {
        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be >= 1');
        }

        if ($this->baseDelayMs < 0) {
            throw new InvalidArgumentException('baseDelayMs must be >= 0');
        }

        if ($this->maxDelayMs < $this->baseDelayMs) {
            throw new InvalidArgumentException('maxDelayMs must be >= baseDelayMs');
        }

        if (!in_array($this->strategy, self::VALID_STRATEGIES, true)) {
            throw new InvalidArgumentException('strategy must be fixed, linear, or exponential');
        }
    }

    private static function parseBool(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($filtered !== null) {
                return $filtered;
            }
        }

        return (bool) $value;
    }
}
