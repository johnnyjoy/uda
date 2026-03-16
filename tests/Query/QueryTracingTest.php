<?php

declare(strict_types=1);

namespace Tests\Query;

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
use UDA\Tracing\QueryTraceListener;

final class QueryTracingTest extends TestCase
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

    public function testTraceEmitted(): void
    {
        $this->db->select()
            ->select('label')
            ->from('trace_items')
            ->limit(1)
            ->value();

        $trace = $this->lastTrace();
        $this->assertSame('value', $trace->operation);
        $this->assertSame(1, $trace->rowCount);
        $this->assertSame(['trace_items'], $trace->tables);
    }

    public function testExecutionTimeCaptured(): void
    {
        $builder = $this->db->select()
            ->select('label')
            ->from('trace_items');

        /** @var WhereBuilder $where */
        $where = $builder->where('id', 1);
        $where->end()->value();

        $trace = $this->lastTrace();
        $this->assertGreaterThan(0, $trace->executionTimeMs);
    }

    public function testParametersCaptured(): void
    {
        $builder = $this->db->select()
            ->select('label')
            ->from('trace_items');

        /** @var WhereBuilder $where */
        $where = $builder->where('id', 1);
        $where->end()->value();

        $trace = $this->lastTrace();
        $this->assertContains(1, array_values($trace->parameters), 'parameters should include predicate value');
    }

    public function testPlanCacheFlagSetOnCacheHit(): void
    {
        QueryPlanCache::clear();

        $builder = $this->db->select()
            ->select('label')
            ->from('trace_items');

        /** @var WhereBuilder $where */
        $where = $builder->where('id', 1);
        $builder = $where->end();

        $builder->value();
        $builder->value();

        $trace = $this->lastTrace();
        $this->assertTrue($trace->planCacheHit);
    }

    public function testStatementCacheHitRecorded(): void
    {
        $builder = $this->db->select()
            ->select('label')
            ->from('trace_items');

        /** @var WhereBuilder $where */
        $where = $builder->where('id', 2);
        $builder = $where->end();

        $builder->value();
        $builder->value();

        $trace = $this->lastTrace();
        $this->assertTrue($trace->statementCacheHit);
    }

    public function testListenerReceivesTrace(): void
    {
        $counter = 0;
        Database::addTraceListener(new class($counter) implements QueryTraceListener {
            public function __construct(private int &$counter)
            {
            }

            public function handle(QueryTrace $trace): void
            {
                $this->counter++;
            }
        });

        $builder = $this->db->select()->select('label')->from('trace_items');
        /** @var WhereBuilder $where */
        $where = $builder->where('id', 2);
        $where->end()->value();

        $this->assertGreaterThan(0, $counter);
    }

    public function testSlowQueryFlagTriggered(): void
    {
        $collector = new QueryTraceCollector();
        Database::clearTraceListeners();
        Database::addTraceListener($collector);
        Config::clearForTests();
        $db = $this->bootstrapDatabase(['enabled' => true, 'slow_query_ms' => 0.0001]);

        $builder = $db->select()->select('label')->from('trace_items');
        /** @var WhereBuilder $where */
        $where = $builder->where('id', 1);
        $where->end()->value();

        $trace = $this->lastTrace($collector);
        $this->assertTrue($trace->slow);
    }

    public function testParametersRedactedWhenConfigured(): void
    {
        $collector = new QueryTraceCollector();
        Database::clearTraceListeners();
        Database::addTraceListener($collector);
        Config::clearForTests();
        $db = $this->bootstrapDatabase(['enabled' => true, 'redact_parameters' => true]);

        $builder = $db->select()->select('label')->from('trace_items');
        /** @var WhereBuilder $where */
        $where = $builder->where('id', 3);
        $where->end()->value();

        $trace = $this->lastTrace($collector);
        $this->assertSame(['***'], array_values($trace->parameters));
    }

    public function testDatabaseEmitsRetryTracesWhenRetryPolicyRetries(): void
    {
        $attempts = 0;
        $this->installRetrySpyDriver($this->db, function () use (&$attempts): void {
            $attempts++;

            if ($attempts === 1) {
                throw $this->deadlockException();
            }
        });

        $policy = new RetryPolicy(
            new RetryConfig(enabled: true, baseDelayMs: 0, jitter: false),
            new TransientErrorClassifier(),
            sleeper: static function (): void {
            }
        );
        $this->db->setRetryPolicy($policy);

        $builder = $this->db->select()->select('label')->from('trace_items');
        /** @var WhereBuilder $where */
        $where = $builder->where('id', 1);
        $where->end()->value();

        $this->assertSame(2, $attempts);

        $retryAttempts = array_values(array_filter(
            $this->collector->getTraces(),
            static fn (QueryTrace $trace): bool => $trace->traceType === 'retry_attempt'
        ));
        $this->assertNotEmpty($retryAttempts, 'retry_attempt traces should be emitted for retries');

        $final = $this->lastTrace();
        $this->assertTrue($final->retried);
        $this->assertSame(2, $final->retryCount);
        $this->assertSame(['transient_error'], $final->retryReasons);
        $this->assertFalse($final->finalFailure);
    }

    private function installRetrySpyDriver(Database $db, callable $beforeAttempt): void
    {
        $ref = new ReflectionProperty(Database::class, 'driver');
        $ref->setAccessible(true);

        /** @var Driver $current */
        $current = $ref->getValue($db);
        $spy = new RetrySpyDriver($current, $beforeAttempt);

        $ref->setValue($db, $spy);
    }

    private function deadlockException(): PDOException
    {
        $exception = new PDOException('deadlock', 0);
        $exception->errorInfo = ['40001'];

        return $exception;
    }

    private function bootstrapDatabase(array $traceConfig = []): Database
    {
        $path = $this->writeConfig($traceConfig);
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

    private function writeConfig(array $traceConfig): string
    {
        $config = [
            'defaults' => ['connection' => 'trace_db'],
            'connections' => [
                'trace_db' => [
                    'driver' => 'sqlite',
                    'params' => ['path' => ':memory:'],
                    'trace' => $traceConfig,
                ],
            ],
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'uda-trace-');
        $path = $tmp . '.json';
        rename($tmp, $path);
        file_put_contents($path, (string) json_encode($config));

        return $path;
    }

    private function lastTrace(?QueryTraceCollector $collector = null): QueryTrace
    {
        $collector ??= $this->collector;
        $traces = $collector->getTraces();
        $this->assertNotEmpty($traces, 'No trace events captured');

        return $traces[array_key_last($traces)];
    }
}

final class RetrySpyDriver extends Driver
{
    /** @var \Closure */
    private \Closure $beforeAttempt;

    public function __construct(Driver $driver, callable $beforeAttempt)
    {
        $this->beforeAttempt = \Closure::fromCallable($beforeAttempt);
        $this->cloneStateFrom($driver);
    }

    protected function onConnect(): void
    {
        // Never invoked for cloned drivers.
    }

    protected function buildDsn(array $params): string
    {
        return '';
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

    private function intercept(): void
    {
        ($this->beforeAttempt)();
    }

    public function rows(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        $this->intercept();

        return parent::rows($sql, $params, $tables);
    }

    public function row(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): ?array
    {
        $this->intercept();

        return parent::row($sql, $params, $tables);
    }

    public function exec(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): int
    {
        $this->intercept();

        return parent::exec($sql, $params, $tables);
    }

    public function returning(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        $this->intercept();

        return parent::returning($sql, $params, $tables);
    }

    public function each(string|SqlMessage|BuilderSql $sql, array|callable $params, callable $fn = null): int
    {
        $this->intercept();

        return parent::each($sql, $params, $fn);
    }

    public function explain(string|SqlMessage|BuilderSql $sql): array
    {
        $this->intercept();

        return parent::explain($sql);
    }

    public function explainAnalyze(string|SqlMessage|BuilderSql $sql): array
    {
        $this->intercept();

        return parent::explainAnalyze($sql);
    }
}
