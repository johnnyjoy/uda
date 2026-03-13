<?php

declare(strict_types=1);

namespace Tests\Retry;

use Closure;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use UDA\Config;
use UDA\Database;
use UDA\Driver;
use UDA\Query\QueryPlanCache;
use UDA\Query\Sql as BuilderSql;
use UDA\Query\WhereBuilder;
use UDA\Retry\RetryConfig;
use UDA\Retry\RetryPolicy;
use UDA\Retry\TransientErrorClassifier;
use UDA\SQL\SqlMessage;
use UDA\Tracing\QueryTrace;
use UDA\Tracing\QueryTraceCollector;

final class RetryIntegrationTest extends TestCase
{
    private Database $db;
    private QueryTraceCollector $collector;

    protected function setUp(): void
    {
        QueryPlanCache::enable();
        QueryPlanCache::clear();
        Config::clearForTests();
        Database::clearTraceListeners();

        $this->collector = new QueryTraceCollector();
        Database::addTraceListener($this->collector);
        $this->db = $this->bootstrapDatabase();
    }

    protected function tearDown(): void
    {
        Database::clearTraceListeners();
        Config::clearForTests();
    }

    public function testReadQueriesRetryOnceAndEmitTraceMetadata(): void
    {
        $stub = $this->installStubDriver(function (string $operation, int $attempt): void {
            if ($operation === 'row' && $attempt === 1) {
                throw $this->deadlockException();
            }
        });

        $this->db->setRetryPolicy($this->makeRetryPolicy());

        $builder = $this->db->select()
            ->select('label')
            ->from('trace_items');
        /** @var WhereBuilder $where */
        $where = $builder->where('id', 1);
        $row = $where->end()->row();

        $this->assertNotNull($row);
        $this->assertSame('alpha', $row['label']);
        $this->assertSame(2, $stub->attempts);

        $retryAttempts = array_values(array_filter(
            $this->collector->getTraces(),
            static fn (QueryTrace $trace): bool => $trace->traceType === 'retry_attempt'
        ));
        $this->assertNotEmpty($retryAttempts);

        $final = $this->lastTrace();
        $this->assertTrue($final->retried);
        $this->assertSame(2, $final->retryCount);
        $this->assertSame(['transient_error'], $final->retryReasons);
        $this->assertFalse($final->finalFailure);
    }

    public function testWritesAreNotRetriedByDefault(): void
    {
        $stub = $this->installStubDriver(function (string $operation, int $attempt): void {
            if ($operation === 'exec') {
                throw $this->deadlockException();
            }
        });

        $this->db->setRetryPolicy($this->makeRetryPolicy());

        $this->expectException(PDOException::class);

        try {
            $this->db->exec(
                'UPDATE trace_items SET label = :label WHERE id = :id',
                ['label' => 'delta', 'id' => 1]
            );
        } finally {
            $this->assertSame(1, $stub->attempts);

            $retryAttempts = array_values(array_filter(
                $this->collector->getTraces(),
                static fn (QueryTrace $trace): bool => $trace->traceType === 'retry_attempt'
            ));
            $this->assertCount(0, $retryAttempts);
        }
    }

    public function testTransactionsAreNotRetriedByDefault(): void
    {
        $stub = $this->installStubDriver(function (string $operation, int $attempt): void {
            if ($operation === 'row') {
                throw $this->deadlockException();
            }
        });

        $this->db->setRetryPolicy($this->makeRetryPolicy());

        $this->expectException(PDOException::class);

        try {
            $this->db->transaction(function (): void {
                $this->db->row('SELECT * FROM trace_items WHERE id = :id', ['id' => 1]);
            });
        } finally {
            $this->assertSame(1, $stub->attempts);

            $retryAttempts = array_values(array_filter(
                $this->collector->getTraces(),
                static fn (QueryTrace $trace): bool => $trace->traceType === 'retry_attempt'
            ));
            $this->assertCount(0, $retryAttempts, 'Transactions should not emit retry attempts when disabled');
        }
    }

    private function bootstrapDatabase(): Database
    {
        $path = $this->writeConfig();
        $db = Database::connect($path);
        @unlink($path);

        $db->exec('CREATE TABLE trace_items (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');

        foreach (['alpha', 'beta', 'gamma'] as $index => $label) {
            $db->exec(
                'INSERT INTO trace_items (id, label) VALUES (:id, :label)',
                ['id' => $index + 1, 'label' => $label]
            );
        }

        return $db;
    }

    private function writeConfig(): string
    {
        $config = [
            'defaults' => ['connection' => 'retry_db'],
            'connections' => [
                'retry_db' => [
                    'driver' => 'sqlite',
                    'params' => ['path' => ':memory:'],
                    'guardrails' => ['enabled' => false],
                ],
            ],
        ];

        $temp = tempnam(sys_get_temp_dir(), 'uda-retry-');
        $path = $temp . '.json';
        rename($temp, $path);
        file_put_contents($path, (string) json_encode($config));

        return $path;
    }

    private function installStubDriver(callable $beforeAttempt): RetryStubDriver
    {
        $ref = new ReflectionProperty(Database::class, 'driver');
        $ref->setAccessible(true);

        /** @var Driver $current */
        $current = $ref->getValue($this->db);

        $stub = new RetryStubDriver($current, $beforeAttempt);
        $ref->setValue($this->db, $stub);

        return $stub;
    }

    private function makeRetryPolicy(): RetryPolicy
    {
        return new RetryPolicy(
            new RetryConfig(enabled: true, baseDelayMs: 0, maxDelayMs: 0, jitter: false),
            new TransientErrorClassifier(),
            sleeper: static function (): void {
            },
            randomizer: static fn (): float => 0.0
        );
    }

    private function deadlockException(): PDOException
    {
        $exception = new PDOException('deadlock', 0);
        $exception->errorInfo = ['40001'];

        return $exception;
    }

    private function lastTrace(?QueryTraceCollector $collector = null): QueryTrace
    {
        $collector ??= $this->collector;
        $traces = $collector->getTraces();
        $this->assertNotEmpty($traces, 'No trace events captured');

        return $traces[array_key_last($traces)];
    }
}

final class RetryStubDriver extends Driver
{
    public int $attempts = 0;

    /** @var Closure */
    private Closure $beforeAttempt;

    public function __construct(Driver $driver, callable $beforeAttempt)
    {
        $this->beforeAttempt = Closure::fromCallable($beforeAttempt);
        $this->cloneStateFrom($driver);
    }

    protected function onConnect(): void
    {
        // Not called when cloning from existing driver.
    }

    protected function buildDsn(array $params): string
    {
        return '';
    }

    private function intercept(string $operation): void
    {
        $this->attempts++;
        ($this->beforeAttempt)($operation, $this->attempts);
    }

    public function rows(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        $this->intercept('rows');

        return parent::rows($sql, $params, $tables);
    }

    public function row(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): ?array
    {
        $this->intercept('row');

        return parent::row($sql, $params, $tables);
    }

    public function value(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): mixed
    {
        $this->intercept('value');

        return parent::value($sql, $params, $tables);
    }

    public function values(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        $this->intercept('values');

        return parent::values($sql, $params, $tables);
    }

    public function list(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        $this->intercept('list');

        return parent::list($sql, $params, $tables);
    }

    public function exec(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): int
    {
        $this->intercept('exec');

        return parent::exec($sql, $params, $tables);
    }

    public function returning(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        $this->intercept('returning');

        return parent::returning($sql, $params, $tables);
    }

    public function each(string|SqlMessage|BuilderSql $sql, array|callable $params, callable $fn = null): int
    {
        $this->intercept('each');

        return parent::each($sql, $params, $fn);
    }

    public function explain(string|SqlMessage|BuilderSql $sql): array
    {
        $this->intercept('explain');

        return parent::explain($sql);
    }

    public function explainAnalyze(string|SqlMessage|BuilderSql $sql): array
    {
        $this->intercept('explain_analyze');

        return parent::explainAnalyze($sql);
    }

    private function cloneStateFrom(Driver $driver): void
    {
        $cursor = new \ReflectionObject($driver);

        while ($cursor !== false) {
            foreach ($cursor->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                $property->setAccessible(true);
                $property->setValue($this, $property->getValue($driver));
            }

            $cursor = $cursor->getParentClass();
        }
    }
}
