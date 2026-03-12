<?php

declare(strict_types=1);

namespace Tests\SQLite;

use Closure;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use ReflectionProperty;
use Throwable;
use UDA\Config;
use UDA\Database;
use UDA\Driver;
use UDA\Query\Sql as BuilderSql;
use UDA\SQL\SqlMessage;

abstract class SQLiteTestCase extends TestCase
{
    private const CONNECTION_NAME = 'sqlite_cert';

    /**
     * @template TValue
     * @param callable(Database $db):TValue $fn
     * @return TValue
     */
    protected function withMemoryDb(callable $fn): mixed
    {
        return $this->runWithDatabase(':memory:', $fn);
    }

    /**
     * @template TValue
     * @param callable(Database $db):TValue $fn
     * @return TValue
     */
    protected function withTempDb(callable $fn): mixed
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'uda-sqlite-db-');

        if ($tempPath === false) {
            self::fail('Unable to create temporary SQLite database path');
        }

        try {
            return $this->runWithDatabase($tempPath, $fn);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    protected function createSchema(Database $db): void
    {
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('DROP TABLE IF EXISTS salaries');
        $db->exec('DROP TABLE IF EXISTS contractors');
        $db->exec('DROP TABLE IF EXISTS employees');

        $db->exec(<<<'SQL'
CREATE TABLE employees (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    title TEXT NOT NULL,
    hired_at TEXT NOT NULL
)
SQL
        );

        $db->exec(<<<'SQL'
CREATE TABLE contractors (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    company TEXT NOT NULL,
    hourly_rate REAL NOT NULL
)
SQL
        );

        $db->exec(<<<'SQL'
CREATE TABLE salaries (
    id INTEGER PRIMARY KEY,
    employee_id INTEGER NULL,
    contractor_id INTEGER NULL,
    amount REAL NOT NULL,
    paid_at TEXT NOT NULL,
    FOREIGN KEY(employee_id) REFERENCES employees(id),
    FOREIGN KEY(contractor_id) REFERENCES contractors(id)
)
SQL
        );

        $this->seedFixtures($db);
    }

    protected function seedFixtures(Database $db): void
    {
        $employees = [
            ['id' => 1, 'name' => 'Ada Lovelace', 'title' => 'Principal Engineer', 'hired_at' => '2020-01-15'],
            ['id' => 2, 'name' => 'Bex Stone', 'title' => 'Engineering Manager', 'hired_at' => '2021-06-01'],
        ];

        foreach ($employees as $employee) {
            $db->exec(
                'INSERT INTO employees (id, name, title, hired_at) VALUES (:id, :name, :title, :hired_at)',
                $employee
            );
        }

        $db->exec(
            'INSERT INTO contractors (id, name, company, hourly_rate) VALUES (:id, :name, :company, :hourly_rate)',
            ['id' => 101, 'name' => 'Chloe Ops', 'company' => 'SRE Collective', 'hourly_rate' => 150.00]
        );

        $db->exec(
            'INSERT INTO salaries (id, employee_id, contractor_id, amount, paid_at) '
            . 'VALUES (:id, :employee_id, :contractor_id, :amount, :paid_at)',
            ['id' => 5001, 'employee_id' => 1, 'contractor_id' => null, 'amount' => 125000, 'paid_at' => '2023-03-31']
        );
        $db->exec(
            'INSERT INTO salaries (id, employee_id, contractor_id, amount, paid_at) '
            . 'VALUES (:id, :employee_id, :contractor_id, :amount, :paid_at)',
            ['id' => 5002, 'employee_id' => 2, 'contractor_id' => null, 'amount' => 132500, 'paid_at' => '2023-03-31']
        );
        $db->exec(
            'INSERT INTO salaries (id, employee_id, contractor_id, amount, paid_at) '
            . 'VALUES (:id, :employee_id, :contractor_id, :amount, :paid_at)',
            ['id' => 5003, 'employee_id' => null, 'contractor_id' => 101, 'amount' => 12000, 'paid_at' => '2023-03-15']
        );
    }

    protected function retryStubDriver(Database $db, ?callable $beforeAttempt = null, ?callable $afterAttempt = null): SQLiteRetryDriverStub
    {
        $driver = $this->extractDriver($db);

        $stub = new SQLiteRetryDriverStub(
            $driver,
            $beforeAttempt ?? static function (string $operation, int $attempt): void {
            },
            $afterAttempt ?? null
        );

        $this->swapDriver($db, $stub);

        return $stub;
    }

    /**
     * @template TValue
     * @param callable(Database $db):TValue $fn
     * @return TValue
     */
    private function runWithDatabase(string $path, callable $fn): mixed
    {
        $db = $this->createDatabase($path);
        $this->createSchema($db);

        return $fn($db);
    }

    private function createDatabase(string $path): Database
    {
        Config::clearForTests();

        $config = [
            'defaults' => ['connection' => self::CONNECTION_NAME],
            'connections' => [
                self::CONNECTION_NAME => [
                    'driver' => 'sqlite',
                    'params' => ['path' => $path],
                ],
            ],
        ];

        $configPath = $this->writeTempConfig($config);

        try {
            return Database::connect($configPath);
        } finally {
            @unlink($configPath);
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    private function writeTempConfig(array $config): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'uda-sqlite-config-');

        if ($temp === false) {
            self::fail('Unable to create temporary configuration file');
        }

        $configPath = $temp . '.json';
        rename($temp, $configPath);
        file_put_contents($configPath, (string) json_encode($config, JSON_PRETTY_PRINT));

        return $configPath;
    }

    private function extractDriver(Database $db): Driver
    {
        $property = new ReflectionProperty(Database::class, 'driver');
        $property->setAccessible(true);

        /** @var Driver $driver */
        $driver = $property->getValue($db);

        return $driver;
    }

    private function swapDriver(Database $db, Driver $stub): void
    {
        $property = new ReflectionProperty(Database::class, 'driver');
        $property->setAccessible(true);
        $property->setValue($db, $stub);
    }
}

final class SQLiteRetryDriverStub extends Driver
{
    private Closure $before;
    private ?Closure $after;
    public int $attempts = 0;

    public function __construct(Driver $driver, callable $before, ?callable $after = null)
    {
        $this->before = Closure::fromCallable($before);
        $this->after = $after !== null ? Closure::fromCallable($after) : null;
        $this->cloneStateFrom($driver);
    }

    protected function onConnect(): void
    {
        // Cloned driver never issues a new connection.
    }

    protected function buildDsn(array $params): string
    {
        return '';
    }

    public function rows(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        return $this->wrap(__FUNCTION__, fn () => parent::rows($sql, $params, $tables));
    }

    public function row(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): ?array
    {
        return $this->wrap(__FUNCTION__, fn () => parent::row($sql, $params, $tables));
    }

    public function value(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): mixed
    {
        return $this->wrap(__FUNCTION__, fn () => parent::value($sql, $params, $tables));
    }

    public function values(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        return $this->wrap(__FUNCTION__, fn () => parent::values($sql, $params, $tables));
    }

    public function list(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        return $this->wrap(__FUNCTION__, fn () => parent::list($sql, $params, $tables));
    }

    public function each(string|SqlMessage|BuilderSql $sql, array|callable $params, callable $fn = null): int
    {
        return $this->wrap(__FUNCTION__, fn () => parent::each($sql, $params, $fn));
    }

    public function exec(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): int
    {
        return $this->wrap(__FUNCTION__, fn () => parent::exec($sql, $params, $tables));
    }

    public function returning(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        return $this->wrap(__FUNCTION__, fn () => parent::returning($sql, $params, $tables));
    }

    public function explain(string|SqlMessage|BuilderSql $sql): array
    {
        return $this->wrap(__FUNCTION__, fn () => parent::explain($sql));
    }

    public function explainAnalyze(string|SqlMessage|BuilderSql $sql): array
    {
        return $this->wrap(__FUNCTION__, fn () => parent::explainAnalyze($sql));
    }

    private function wrap(string $operation, callable $callback): mixed
    {
        $this->attempts++;
        ($this->before)($operation, $this->attempts);

        try {
            $result = $callback();
            $this->after($operation, $result);

            return $result;
        } catch (Throwable $exception) {
            $this->after($operation, $exception);

            throw $exception;
        }
    }

    private function after(string $operation, mixed $result): void
    {
        if ($this->after === null) {
            return;
        }

        ($this->after)($operation, $this->attempts, $result);
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
