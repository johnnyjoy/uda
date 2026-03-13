<?php

declare(strict_types=1);

namespace Tests\Replay;

use PHPUnit\Framework\TestCase;
use UDA\Replay\ReplayConfig;
use UDA\Tracing\QueryTrace;
use UDA\Tracing\ReplayCaptureListener;
use UDA\Tracing\ReplaySnapshot;
use UDA\Tracing\ReplayStorageInterface;

final class ReplayCaptureListenerTest extends TestCase
{
    public function testListenerPersistsSnapshot(): void
    {
        $storage = new InMemoryStorage();
        $listener = new ReplayCaptureListener($storage, ReplayConfig::fromArray(['enabled' => true]), static fn () => 1710094101);

        $trace = $this->makeTrace(
            parameters: ['id' => 42],
            retry: [
                'retryCount' => 2,
                'retried' => true,
                'finalFailure' => false,
                'retryReasons' => ['transient_error'],
            ]
        );
        $listener->handle($trace);

        $snapshot = $storage->lastSnapshot;
        $this->assertInstanceOf(ReplaySnapshot::class, $snapshot);
        $this->assertSame('default', $snapshot->connection);
        $this->assertSame('postgres', $snapshot->dialect);
        $this->assertSame('rows', $snapshot->operation);
        $this->assertSame(['employees'], $snapshot->tables);
        $this->assertSame(['id' => 42], $snapshot->params);
        $this->assertSame(1710094101, $snapshot->timestamp);
        $this->assertSame(2, $snapshot->metadata['retryCount']);
        $this->assertTrue($snapshot->metadata['retried']);
        $this->assertSame(['transient_error'], $snapshot->metadata['retryReasons']);
    }

    public function testMaskingReplacesConfiguredKeys(): void
    {
        $storage = new InMemoryStorage();
        $listener = new ReplayCaptureListener(
            $storage,
            ReplayConfig::fromArray(['enabled' => true, 'maskParameters' => ['token']])
        );

        $trace = $this->makeTrace(parameters: ['token' => 'abc123', 'id' => 1]);
        $listener->handle($trace);

        $this->assertSame('***', $storage->lastSnapshot?->params['token']);
        $this->assertSame(1, $storage->lastSnapshot?->params['id']);
    }

    private function makeTrace(array $parameters = [], ?array $retry = null): QueryTrace
    {
        $retry ??= [
            'retryCount' => null,
            'retried' => false,
            'finalFailure' => false,
            'retryReasons' => [],
        ];

        return new QueryTrace(
            operation: 'rows',
            sql: 'SELECT * FROM employees WHERE id = :id',
            parameters: $parameters,
            dialect: 'postgres',
            connection: 'default',
            executionTimeMs: 2.5,
            rowCount: 1,
            planCacheHit: false,
            statementCacheHit: false,
            resultCacheHit: false,
            tables: ['employees'],
            slow: false,
            retryCount: $retry['retryCount'],
            retried: $retry['retried'],
            finalFailure: $retry['finalFailure'],
            retryReasons: $retry['retryReasons']
        );
    }
}

final class InMemoryStorage implements ReplayStorageInterface
{
    public ?ReplaySnapshot $lastSnapshot = null;

    public function persist(ReplaySnapshot $snapshot): void
    {
        $this->lastSnapshot = $snapshot;
    }

    public function flush(): void
    {
    }

    public function close(): void
    {
    }
}
