<?php

declare(strict_types=1);

namespace Tests\Replay;

use Closure;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use UDA\Config;
use UDA\Database;
use UDA\Driver;
use UDA\Query\Sql as BuilderSql;
use UDA\Replay\QueryReplayer;
use UDA\Replay\ReplayBootstrapper;
use UDA\Retry\RetryConfig;
use UDA\Retry\RetryPolicy;
use UDA\Retry\TransientErrorClassifier;
use UDA\SQL\SqlMessage;

final class ReplayEndToEndTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/uda-replay-store-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            $files = glob($this->storageDir . '/*');

            if ($files !== false) {
                array_map('unlink', array_filter($files, 'is_file'));
            }

            @rmdir($this->storageDir);
        }

        Database::clearTraceListeners();
        ReplayBootstrapper::reset();
        Config::clearForTests();
    }

    public function testCaptureAndReplayFlow(): void
    {
        $captureDb = $this->bootDatabase(true);

        $captureDb->exec('CREATE TABLE logs (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');
        $captureDb->exec('INSERT INTO logs (label) VALUES (:label)', ['label' => 'first']);

        $stub = $this->installRetryStubDriver($captureDb, function (string $operation, int $attempt): void {
            if ($operation === 'rows' && $attempt === 1) {
                throw $this->deadlockException();
            }
        });
        $captureDb->setRetryPolicy($this->makeRetryPolicy());

        $captureDb->rows('SELECT * FROM logs');

        $this->assertSame(2, $stub->attempts);

        $file = glob($this->storageDir . '/queries-*.ndjson');
        $this->assertNotFalse($file);
        $this->assertNotEmpty($file);

        $entries = $this->readReplayEntries($file[0]);
        $retriedEntry = null;

        foreach ($entries as $entry) {
            if (($entry['sql'] ?? '') === 'SELECT * FROM logs') {
                $retriedEntry = $entry;
                break;
            }
        }

        $this->assertNotNull($retriedEntry, 'Captured replay entries should include SELECT * FROM logs');
        $metadata = $retriedEntry['metadata'] ?? [];
        $this->assertSame(2, $metadata['retryCount'] ?? null);
        $this->assertTrue($metadata['retried'] ?? false);
        $this->assertSame(['transient_error'], $metadata['retryReasons'] ?? []);

        Database::clearTraceListeners();
        ReplayBootstrapper::reset();
        Config::clearForTests();

        $replayDb = $this->bootDatabase(false);
        $replayer = new QueryReplayer($replayDb);
        $results = $replayer->runFile($file[0]);

        $this->assertNotEmpty($results);
    }

    private function bootDatabase(bool $enableReplay): Database
    {
        $config = [
            'defaults' => ['connection' => 'replay'],
            'replay' => [
                'enabled' => $enableReplay,
                'directory' => $this->storageDir,
            ],
            'connections' => [
                'replay' => [
                    'driver' => 'sqlite',
                    'params' => ['path' => ':memory:'],
                    'guardrails' => ['enabled' => false],
                ],
            ],
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'uda-replay-config-');
        $file = $tmp . '.json';
        rename($tmp, $file);
        file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT));

        $db = Database::connect($file);
        @unlink($file);

        return $db;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readReplayEntries(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        return array_values(array_map(
            static function (string $line): array {
                /** @var array<string,mixed> $decoded */
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                return $decoded;
            },
            $lines
        ));
    }

    private function installRetryStubDriver(Database $db, callable $beforeAttempt): ReplayRetryStubDriver
    {
        $ref = new ReflectionProperty(Database::class, 'driver');
        $ref->setAccessible(true);

        /** @var Driver $current */
        $current = $ref->getValue($db);

        $stub = new ReplayRetryStubDriver($current, $beforeAttempt);
        $ref->setValue($db, $stub);

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
}

final class ReplayRetryStubDriver extends Driver
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
