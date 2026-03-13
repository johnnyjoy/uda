<?php

declare(strict_types=1);

namespace Tests\Retry;

use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Select;
use UDA\Query\Sql as BuilderSql;
use UDA\Retry\RetryConfig;
use UDA\Retry\RetryDecision;
use UDA\Retry\RetryPolicy;
use UDA\Retry\TransientErrorClassifier;
use UDA\SQL\SqlMessage;

final class RetryPolicyTest extends TestCase
{
    public function testDisabledPolicyBypassesRetry(): void
    {
        $policy = new RetryPolicy(new RetryConfig(enabled: false), new TransientErrorClassifier());
        $sql = $this->sql();
        $attempts = 0;

        $result = $policy->execute($sql, 'rows', false, function () use (&$attempts) {
            $attempts++;

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(1, $attempts);
        self::assertNull($policy->getLastMetadata());
    }

    public function testRetriesTransientReadUntilSuccess(): void
    {
        $log = [];
        $policy = new RetryPolicy(
            new RetryConfig(enabled: true, baseDelayMs: 5, jitter: false),
            new TransientErrorClassifier(),
            function (int $milliseconds) use (&$log): void {
                $log[] = $milliseconds;
            }
        );
        $sql = $this->sql();
        $attempts = 0;

        $result = $policy->execute($sql, 'rows', false, function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw $this->transient();
            }

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(2, $attempts);
        self::assertSame([10], $log);

        $meta = $policy->getLastMetadata();
        self::assertNotNull($meta);
        self::assertSame(2, $meta['retryCount']);
        self::assertTrue($meta['retried']);
        self::assertFalse($meta['finalFailure']);
        self::assertSame(['transient_error'], $meta['retryReasons']);
    }

    public function testNonTransientErrorNotRetried(): void
    {
        $policy = $this->policy();
        $sql = $this->sql();

        $this->expectException(RuntimeException::class);

        try {
            $policy->execute($sql, 'rows', false, function () {
                throw new RuntimeException('boom');
            });
        } finally {
            $meta = $policy->getLastMetadata();
            self::assertSame(['non_transient'], $meta['retryReasons']);
            self::assertFalse($meta['retried']);
            self::assertTrue($meta['finalFailure']);
        }
    }

    public function testHonorsMaxAttempts(): void
    {
        $policy = $this->policy(config: new RetryConfig(enabled: true, maxAttempts: 2, baseDelayMs: 1, jitter: false));
        $sql = $this->sql();

        $this->expectException(PDOException::class);

        try {
            $policy->execute($sql, 'rows', false, function () {
                throw $this->transient();
            });
        } finally {
            $meta = $policy->getLastMetadata();
            self::assertSame(2, $meta['retryCount']);
            self::assertTrue($meta['finalFailure']);
            self::assertSame(['transient_error', 'max_attempts'], $meta['retryReasons']);
        }
    }

    public function testWriteRetryDisabledByDefault(): void
    {
        $policy = $this->policy();
        $sql = $this->sql('insert');

        $this->expectException(PDOException::class);

        try {
            $policy->execute($sql, 'exec', false, function () {
                throw $this->transient();
            });
        } finally {
            $meta = $policy->getLastMetadata();
            self::assertSame(['writes_disabled'], $meta['retryReasons']);
            self::assertFalse($meta['retried']);
            self::assertTrue($meta['finalFailure']);
        }
    }

    public function testWriteRetryEnabledWithOptIn(): void
    {
        $policy = $this->policy(config: new RetryConfig(enabled: true, retryWrites: true, baseDelayMs: 0, jitter: false));
        $sql = $this->sql('insert', true);
        $attempts = 0;

        $result = $policy->execute($sql, 'exec', false, function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw $this->transient();
            }

            return 1;
        });

        self::assertSame(1, $result);
        self::assertSame(2, $attempts);
    }

    public function testTransactionRetryBlocked(): void
    {
        $policy = $this->policy();
        $sql = $this->sql();

        $this->expectException(PDOException::class);

        try {
            $policy->execute($sql, 'rows', true, function () {
                throw $this->transient();
            });
        } finally {
            $meta = $policy->getLastMetadata();
            self::assertSame(['transaction_blocked'], $meta['retryReasons']);
            self::assertTrue($meta['finalFailure']);
        }
    }

    public function testQueryOptOutDisablesRetry(): void
    {
        $policy = $this->policy();
        $sql = $this->sql(retryAllowed: false);

        $this->expectException(PDOException::class);

        try {
            $policy->execute($sql, 'rows', false, function () {
                throw $this->transient();
            });
        } finally {
            self::assertSame(['query_opted_out'], $policy->getLastMetadata()['retryReasons']);
            self::assertTrue($policy->getLastMetadata()['finalFailure']);
        }
    }

    public function testJitterStaysWithinBounds(): void
    {
        $delays = [];
        $randomizer = $this->randomizerSequence([0.0, 1.0]);
        $policy = new RetryPolicy(
            new RetryConfig(enabled: true, maxAttempts: 3, baseDelayMs: 100, jitter: true),
            new TransientErrorClassifier(),
            function (int $milliseconds) use (&$delays): void {
                $delays[] = $milliseconds;
            },
            $randomizer
        );
        $sql = $this->sql();
        $attempts = 0;

        $this->expectException(PDOException::class);

        try {
            $policy->execute($sql, 'rows', false, function () use (&$attempts) {
                $attempts++;
                throw $this->transient();
            });
        } finally {
            self::assertCount(2, $delays);
            self::assertSame(100, $delays[0]);
            self::assertSame(400, $delays[1]);
        }
    }

    public function testExplainOperationsRetryByDefault(): void
    {
        $policy = $this->policy(config: new RetryConfig(enabled: true, baseDelayMs: 0, jitter: false));
        $sql = $this->sql();
        $attempts = 0;

        $result = $policy->execute($sql, 'explain', false, function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw $this->transient();
            }

            return ['ok'];
        });

        self::assertSame(['ok'], $result);
        self::assertSame(2, $attempts);
        $meta = $policy->getLastMetadata();
        self::assertNotNull($meta);
        self::assertTrue($meta['retried']);
    }

    public function testExplainOperationsHonorOverrides(): void
    {
        $policy = $this->policy();
        $sql = $this->sql(retryAllowed: false);

        $this->expectException(PDOException::class);

        try {
            $policy->execute($sql, 'explain_analyze', false, function () {
                throw $this->transient();
            });
        } finally {
            $meta = $policy->getLastMetadata();
            self::assertSame(['query_opted_out'], $meta['retryReasons']);
            self::assertFalse($meta['retried']);
            self::assertTrue($meta['finalFailure']);
        }
    }

    public function testEachDoesNotRetryAfterProgress(): void
    {
        $policy = $this->policy();
        $sql = $this->sql();
        $progress = false;

        $this->expectException(PDOException::class);

        try {
            $policy->execute(
                $sql,
                'each',
                false,
                function () use (&$progress) {
                    $progress = true;
                    throw $this->transient();
                },
                eachProgress: function () use (&$progress): bool {
                    return $progress;
                }
            );
        } finally {
            $meta = $policy->getLastMetadata();
            self::assertSame(['each_progress'], $meta['retryReasons']);
            self::assertFalse($meta['retried']);
            self::assertTrue($meta['finalFailure']);
        }
    }

    public function testRetryAttemptEmitterReceivesDecision(): void
    {
        $policy = $this->policy(config: new RetryConfig(enabled: true, baseDelayMs: 5, jitter: false));
        $sql = $this->sql();
        $captured = [];

        $result = $policy->execute(
            $sql,
            'rows',
            false,
            function () {
                static $attempt = 0;
                $attempt++;

                if ($attempt === 1) {
                    throw $this->transient();
                }

                return 'ok';
            },
            function (RetryDecision $decision) use (&$captured): void {
                $captured[] = [$decision->attempt, $decision->delayMs, $decision->reason];
            }
        );

        self::assertSame('ok', $result);
        self::assertSame([[2, 10.0, 'transient_error']], $captured);
    }

    public function testBuilderAllowRetryPropagatesToSql(): void
    {
        $builder = (new Select())
            ->allowRetry()
            ->from('employees');
        $builder->driverName = 'pgsql';
        $builder->bindDialect(new PostgreSql());

        $sql = $builder->toSql();

        self::assertTrue($sql->getRetryAllowed());

        $raw = BuilderSql::of('SELECT 1')->noRetry();
        self::assertFalse($raw->getRetryAllowed());
    }

    private function policy(
        ?RetryConfig $config = null,
        ?callable $randomizer = null
    ): RetryPolicy {
        $log = [];
        $sleeper = function (int $milliseconds) use (&$log): void {
            $log[] = $milliseconds;
        };

        $policy = new RetryPolicy(
            $config ?? new RetryConfig(enabled: true, baseDelayMs: 1, jitter: false),
            new TransientErrorClassifier(),
            $sleeper,
            $randomizer
        );

        return $policy;
    }

    private function sql(string $statementType = 'select', ?bool $retryAllowed = null): SqlMessage
    {
        return new SqlMessage(
            'SELECT 1',
            [],
            [],
            [],
            null,
            [],
            [],
            $statementType,
            false,
            false,
            false,
            null,
            $retryAllowed
        );
    }

    private function transient(): PDOException
    {
        $exception = new PDOException('deadlock', 0);
        $exception->errorInfo = ['40001'];

        return $exception;
    }

    private function randomizerSequence(array $values): callable
    {
        return function () use (&$values): float {
            return (float) array_shift($values);
        };
    }
}
