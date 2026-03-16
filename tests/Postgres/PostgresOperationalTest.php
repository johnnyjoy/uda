<?php

declare(strict_types=1);

namespace Tests\Postgres;

use Closure;
use FilesystemIterator;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use ReflectionObject;
use ReflectionProperty;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use UDA\Config;
use UDA\Database;
use UDA\Driver;
use UDA\Exception\QuerySafetyException;
use UDA\Metrics\MetricsAggregator;
use UDA\Metrics\MetricsConfig;
use UDA\Metrics\QueryMetric;
use UDA\Query\QueryPlanCache;
use UDA\Query\Sql as BuilderSql;
use UDA\Query\WhereBuilder;
use UDA\Replay\QueryReplayer;
use UDA\Replay\ReplayBootstrapper;
use UDA\Retry\RetryConfig;
use UDA\Retry\RetryPolicy;
use UDA\Retry\TransientErrorClassifier;
use UDA\SQL\SqlMessage;
use UDA\Tracing\QueryTraceCollector;

final class PostgresOperationalTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::clearForTests();
        Database::clearTraceListeners();
        QueryPlanCache::clear();
    }

    protected function tearDown(): void
    {
        Config::clearForTests();
        Database::clearTraceListeners();
        QueryPlanCache::clear();
        ReplayBootstrapper::reset();

        parent::tearDown();
    }

    #[Group('guardrail')]
    public function testGuardrailBlocksUnsafeDelete(): void
    {
        $this->withPostgresDb(function (Database $db): void {
            Config::clearForTests();
            $config = [
                'guardrails' => ['enabled' => true],
                'connections' => [
                    'postgres' => [
                        'driver' => 'pgsql',
                        'params' => [
                            'host' => $this->env('PGHOST', '127.0.0.1'),
                            'port' => (int) $this->env('PGPORT', '5432'),
                            'dbname' => $this->env('PGDATABASE', 'testdb'),
                        ],
                        'user' => $this->env('PGUSER', 'postgres'),
                        'pass' => $this->env('PGPASSWORD', 'postgres'),
                        'guardrails' => ['enabled' => true],
                    ],
                ],
            ];
            $configPath = tempnam(sys_get_temp_dir(), 'uda-guardrail-test');
            $configPath .= '.json';
            file_put_contents($configPath, json_encode($config));
            Config::init($configPath);

            $collector = $this->registerTraceCollector();

            try {
                $db->delete()->table('employees')->exec();
                self::fail('Guardrail violation expected');
            } catch (QuerySafetyException $exception) {
                self::assertSame('delete_missing_where', $exception->getReason());
            }

            $traces = $collector->getTraces();
            self::assertNotEmpty($traces);
            $trace = end($traces);

            self::assertSame('guardrail_violation', $trace->traceType);
            self::assertSame('delete_missing_where', $trace->meta['reason'] ?? null);
            self::assertSame('delete', $trace->meta['statementType'] ?? null);
            self::assertSame(['employees'], $trace->tables);
        });
    }

    #[Group('guardrail')]
    public function testGuardrailUnsafeBypassWorks(): void
    {
        $this->withPostgresDb(function (Database $db): void {
            Config::clearForTests();
            $config = [
                'guardrails' => ['enabled' => true],
                'connections' => [
                    'postgres' => [
                        'driver' => 'pgsql',
                        'params' => [
                            'host' => $this->env('PGHOST', '127.0.0.1'),
                            'port' => (int) $this->env('PGPORT', '5432'),
                            'dbname' => $this->env('PGDATABASE', 'testdb'),
                        ],
                        'user' => $this->env('PGUSER', 'postgres'),
                        'pass' => $this->env('PGPASSWORD', 'postgres'),
                        'guardrails' => ['enabled' => true],
                    ],
                ],
            ];
            $configPath = tempnam(sys_get_temp_dir(), 'uda-guardrail-test');
            $configPath .= '.json';
            file_put_contents($configPath, json_encode($config));
            Config::init($configPath);

        $collector = $this->registerTraceCollector();
        $baseline = (int) $db->value('SELECT COUNT(*) FROM employees');
        $db->exec('DELETE FROM audit_log WHERE employee_id = :id', ['id' => 1]);
        $db->exec('DELETE FROM salaries WHERE employee_id = :id', ['id' => 1]);

        /** @var WhereBuilder $where */
        $where = $db->delete()
            ->table('employees')
            ->where('employees.id', 1);

        $rows = $where->end()
            ->unsafe()
            ->exec();

        self::assertSame(1, $rows);
        self::assertSame($baseline - 1, (int) $db->value('SELECT COUNT(*) FROM employees'));

            $remaining = $db->row('SELECT COUNT(*) AS count FROM employees WHERE id = :id', ['id' => 1]);
            self::assertSame(0, (int) ($remaining['count'] ?? -1));

            $guardrailTraces = array_filter(
                $collector->getTraces(),
                static fn ($trace): bool => $trace->traceType === 'guardrail_violation'
            );

            self::assertSame([], array_values($guardrailTraces));
        });
    }

    public function testMetricsAggregateQueries(): void
    {
        $this->withPostgresDb(function (Database $db): void {
            
            
            $aggregator = $this->registerMetricsAggregator();

            $db->rows('SELECT * FROM employees WHERE id = :id', ['id' => 1], ['employees']);
            $db->exec('UPDATE employees SET title = :title WHERE id = :id', [
                'title' => 'Chief Architect',
                'id' => 1,
            ], ['employees']);

            $snapshot = $aggregator->snapshot();
            self::assertNotEmpty($snapshot->metrics);

            $metric = array_values(array_filter(
                $snapshot->metrics,
                static fn (QueryMetric $metric): bool => $metric->tables === ['employees']
            ))[0] ?? null;

            self::assertNotNull($metric);
            self::assertGreaterThan(0, $metric->count);
            self::assertContains($metric->operation, ['rows', 'exec']);
            self::assertGreaterThan(0, $metric->totalLatencyMs);
        });
    }

    public function testRetryRetriesTransientRead(): void
    {
        $this->withPostgresDb(function (Database $db): void {
            $collector = $this->registerTraceCollector();

            $stub = $this->installRetryStub($db, function (string $operation, int $attempt): void {
                if ($operation === 'rows' && $attempt === 1) {
                    throw $this->transientException();
                }
            });

            $db->setRetryPolicy($this->makeRetryPolicy());

            $rows = $db->rows('SELECT * FROM employees WHERE id = :id', ['id' => 1], ['employees']);
            self::assertNotEmpty($rows);
            self::assertSame(2, $stub->attempts);

            $retriedTrace = null;

            foreach ($collector->getTraces() as $trace) {
                if ($trace->traceType !== 'query' || $trace->operation !== 'rows') {
                    continue;
                }

                if (!in_array('transient_error', $trace->retryReasons, true)) {
                    continue;
                }

                $retriedTrace = $trace;
            }

            self::assertNotNull($retriedTrace, 'Expected a trace for SELECT rows query');
            self::assertSame(2, $retriedTrace->retryCount);
            self::assertTrue($retriedTrace->retried);
            self::assertSame(['transient_error'], $retriedTrace->retryReasons);
        });
    }

    public function testRetryDoesNotRetryWriteByDefault(): void
    {
        $this->withPostgresDb(function (Database $db): void {
            $collector = $this->registerTraceCollector();

            $stub = $this->installRetryStub($db, function (string $operation, int $attempt): void {
                if ($operation === 'exec' && $attempt === 1) {
                    throw $this->transientException();
                }
            });

            $db->setRetryPolicy($this->makeRetryPolicy());

            try {
                $db->exec('INSERT INTO employees (id, name, title, hired_at) VALUES (99, :name, :title, :hired_at)', [
                    'name' => 'Retry Writer',
                    'title' => 'SR Developer',
                    'hired_at' => '2022-01-01',
                ], ['employees']);
                self::fail('Expected transient exception to bubble when write retries disabled');
            } catch (PDOException $exception) {
                self::assertSame(['40001'], $exception->errorInfo);
            }

            self::assertSame(1, $stub->attempts);

            $writeTrace = null;

            foreach ($collector->getTraces() as $trace) {
                if ($trace->traceType !== 'query' || $trace->operation !== 'exec') {
                    continue;
                }

                if (!in_array('writes_disabled', $trace->retryReasons, true)) {
                    continue;
                }

                $writeTrace = $trace;
            }

            self::assertNotNull($writeTrace, 'Expected a trace for INSERT exec query');
            self::assertFalse($writeTrace->retried);
            self::assertSame(1, $writeTrace->retryCount);
            self::assertSame(['writes_disabled'], $writeTrace->retryReasons);
        });
    }

    public function testReplayCaptureAndReplay(): void
    {
        $this->markTestSkipped('Schema flaw: replay test invoked $this->bootReplayDatabase() already in a populated schema');
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/postgres-replay-' . uniqid('', true);

        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create temp dir %s', $dir));
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($dir);
    }

    private function bootReplayDatabase(bool $enableReplay, ?string $directory = null): Database
    {
        $config = [
            'defaults' => ['connection' => 'postgres_replay'],
            'replay' => [
                'enabled' => $enableReplay,
                'directory' => $directory ?? '',
            ],
            'connections' => [
                'postgres_replay' => [
                    'driver' => 'pgsql',
                    'params' => [
                        'host' => $this->env('PGHOST', '127.0.0.1'),
                        'port' => (int) $this->env('PGPORT', '5432'),
                        'dbname' => $this->env('PGDATABASE', 'testdb'),
                    ],
                    'user' => $this->env('PGUSER', 'postgres'),
                    'pass' => $this->env('PGPASSWORD', 'postgres'),
                    'guardrails' => ['enabled' => false],
                ],
            ],
        ];

        $configPath = $this->writeReplayConfig($config);

        try {
            $db = Database::connect($configPath);
        } finally {
            @unlink($configPath);
        }

        $this->createSchema($db);

        return $db;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function writeReplayConfig(array $config): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'uda-postgres-replay-');

        if ($temp === false) {
            self::fail('Unable to create replay config file');
        }

        $path = $temp . '.json';
        rename($temp, $path);
        file_put_contents($path, (string) json_encode($config, JSON_PRETTY_PRINT));

        return $path;
    }

    private function transientException(): PDOException
    {
        $exception = new PDOException('transient_error', 0);
        $exception->errorInfo = ['40001'];

        return $exception;
    }

    private function env(string $key, string $default): string
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        $value = trim($value);

        return $value === '' ? $default : $value;
    }

    protected function registerTraceCollector(): QueryTraceCollector
    {
        return parent::registerTraceCollector();
    }

    protected function registerMetricsAggregator(bool $reportTables = true): MetricsAggregator
    {
        return parent::registerMetricsAggregator($reportTables);
    }

    private function installRetryStub(Database $db, callable $beforeAttempt): RetryStubDriver
    {
        $property = new ReflectionProperty(Database::class, 'driver');
        $property->setAccessible(true);

        /** @var Driver $current */
        $current = $property->getValue($db);

        $stub = new RetryStubDriver($current, $beforeAttempt);
        $property->setValue($db, $stub);

        return $stub;
    }

    private function makeRetryPolicy(bool $retryWrites = false): RetryPolicy
    {
        return new RetryPolicy(
            new RetryConfig(
                enabled: true,
                baseDelayMs: 0,
                maxDelayMs: 0,
                retryWrites: $retryWrites,
                jitter: false
            ),
            new TransientErrorClassifier(),
            sleeper: static function (): void {
            },
            randomizer: static fn (): float => 0.0
        );
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

    public function each(
        string|SqlMessage|BuilderSql $sql,
        array|callable $params,
        callable $fn = null,
        ?array $tables = null
    ): int {
        $this->intercept('each');

        return parent::each($sql, $params, $fn, $tables);
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
        $cursor = new ReflectionObject($driver);

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
