<?php

declare(strict_types=1);

namespace UDA\Retry;

use Throwable;
use UDA\Driver;
use UDA\SQL\SqlMessage;

final class RetryPolicy
{
    /** @var array<string,bool> */
    private const READ_OPERATIONS = [
        'rows' => true,
        'row' => true,
        'value' => true,
        'values' => true,
        'list' => true,
        'each' => true,
        'explain' => true,
        'explain_analyze' => true,
    ];

    private ?Driver $driver = null;

    /** @var callable */
    private $sleeper;

    /** @var callable */
    private $randomizer;

    /** @var array{retryCount:int,retried:bool,finalFailure:bool,retryReasons:array<int,string>}|null */
    private ?array $lastMetadata = null;

    public function __construct(
        private readonly RetryConfig $config,
        private readonly TransientErrorClassifier $classifier,
        ?callable $sleeper = null,
        ?callable $randomizer = null
    ) {
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            if ($milliseconds <= 0) {
                return;
            }

            usleep($milliseconds * 1000);
        };

        $this->randomizer = $randomizer ?? static function (): float {
            $max = mt_getrandmax();

            if ($max <= 0) {
                return 1.0;
            }

            return mt_rand() / $max;
        };
    }

    public function bindDriver(?Driver $driver): void
    {
        $this->driver = $driver;
    }

    public function execute(
        SqlMessage $sql,
        string $operation,
        bool $inTransaction,
        callable $attempt,
        ?callable $onRetryAttempt = null,
        ?callable $eachProgress = null
    ): mixed {
        $this->lastMetadata = null;

        if (!$this->config->enabled) {
            return $attempt();
        }

        $eligibility = $this->determineEligibility($sql, $operation, $inTransaction);

        if (!$eligibility['allowed']) {
            try {
                $result = $attempt();
                $this->lastMetadata = $this->buildMetadata(1, false, false, $eligibility['reason']);

                return $result;
            } catch (Throwable $exception) {
                $this->lastMetadata = $this->buildMetadata(1, false, true, $eligibility['reason']);

                throw $exception;
            }
        }

        $attemptNumber = 0;
        $reasons = [];

        while (true) {
            $attemptNumber++;

            try {
                $result = $attempt();
                $this->lastMetadata = $this->finalizeMetadata($attemptNumber, $reasons, false);

                return $result;
            } catch (Throwable $exception) {
                $decision = $this->evaluateDecision($exception, $attemptNumber, $operation, $eachProgress);

                if (!$decision->shouldRetry) {
                    if ($decision->reason !== '') {
                        $reasons[] = $decision->reason;
                    }

                    $this->lastMetadata = $this->finalizeMetadata($attemptNumber, $reasons, true);

                    throw $exception;
                }

                $reasons[] = $decision->reason;

                if ($onRetryAttempt !== null) {
                    $onRetryAttempt($decision, $exception);
                }

                if ($decision->delayMs > 0) {
                    ($this->sleeper)((int) round($decision->delayMs));
                }
            }
        }
    }

    /**
     * @return array{retryCount:int,retried:bool,finalFailure:bool,retryReasons:array<int,string>}|null
     */
    public function getLastMetadata(): ?array
    {
        return $this->lastMetadata;
    }

    private function determineEligibility(SqlMessage $sql, string $operation, bool $inTransaction): array
    {
        if ($inTransaction && !$this->config->retryInTransactions) {
            return ['allowed' => false, 'reason' => 'transaction_blocked'];
        }

        $override = $sql->getRetryAllowed();

        if ($override === false) {
            return ['allowed' => false, 'reason' => 'query_opted_out'];
        }

        $isWrite = $this->isWriteOperation($sql, $operation);

        if ($isWrite) {
            if (!$this->config->retryWrites) {
                return ['allowed' => false, 'reason' => 'writes_disabled'];
            }

            if ($override !== true) {
                return ['allowed' => false, 'reason' => 'write_requires_opt_in'];
            }

            return ['allowed' => true, 'reason' => null];
        }

        if (isset(self::READ_OPERATIONS[$operation])) {
            return ['allowed' => true, 'reason' => null];
        }

        if ($override === true) {
            return ['allowed' => true, 'reason' => null];
        }

        return ['allowed' => false, 'reason' => 'operation_disallowed'];
    }

    private function evaluateDecision(
        Throwable $exception,
        int $attempt,
        string $operation,
        ?callable $eachProgress
    ): RetryDecision {
        if ($operation === 'each' && $eachProgress !== null && $eachProgress()) {
            return new RetryDecision(false, $attempt, 0.0, 'each_progress');
        }

        if (!$this->classifier->isTransient($exception, $this->driver)) {
            return new RetryDecision(false, $attempt, 0.0, 'non_transient');
        }

        if ($attempt >= $this->config->maxAttempts) {
            return new RetryDecision(false, $attempt, 0.0, 'max_attempts');
        }

        $delay = $this->computeDelayMs($attempt + 1);

        return new RetryDecision(true, $attempt + 1, $delay, 'transient_error');
    }

    private function computeDelayMs(int $attempt): float
    {
        $base = match ($this->config->strategy) {
            'fixed' => $this->config->baseDelayMs,
            'linear' => $this->config->baseDelayMs * $attempt,
            default => $this->config->baseDelayMs * (2 ** ($attempt - 1)),
        };

        $base = min($this->config->maxDelayMs, max(0, $base));

        if (!$this->config->jitter || $base <= 0) {
            return $base;
        }

        $rand = ($this->randomizer)();
        $ratio = 0.5 + (max(0.0, min(1.0, (float) $rand)) * 0.5);

        return $base * $ratio;
    }

    private function isWriteOperation(SqlMessage $sql, string $operation): bool
    {
        if ($operation === 'exec' || $operation === 'returning') {
            return true;
        }

        return in_array($sql->getStatementType(), ['insert', 'update', 'delete', 'upsert'], true);
    }

    private function finalizeMetadata(int $attempts, array $reasons, bool $finalFailure): array
    {
        return [
            'retryCount' => $attempts,
            'retried' => $attempts > 1,
            'finalFailure' => $finalFailure,
            'retryReasons' => $reasons,
        ];
    }

    private function buildMetadata(int $attempts, bool $retried, bool $finalFailure, ?string $reason): array
    {
        $reasons = [];

        if ($reason !== null) {
            $reasons[] = $reason;
        }

        return [
            'retryCount' => $attempts,
            'retried' => $retried,
            'finalFailure' => $finalFailure,
            'retryReasons' => $reasons,
        ];
    }
}
