<?php

declare(strict_types=1);

namespace Tests\Postgres;

use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use ReflectionProperty;
use Throwable;
use UDA\Cache;
use UDA\Config;
use UDA\Database;
use UDA\Driver;
use UDA\Metrics\MetricsAggregator;
use UDA\Metrics\MetricsConfig;
use UDA\Query\Sql as BuilderSql;
use UDA\SQL\SqlMessage;
use UDA\Tracing\QueryTraceCollector;

abstract class PostgresTestCase extends TestCase
{
    private const CONNECTION_NAME = 'postgres_cert';

    /**
     * @template TValue
     * @param callable(Database $db):TValue $fn
     * @return TValue
     */
    protected function withPostgresDb(callable $fn, array $connectionOverride = []): mixed
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension required; install php-pgsql or enable in php.ini');
        }

        $connectionConfig = array_replace_recursive($this->baseConnectionConfig(), $connectionOverride);

        try {
            $db = $this->createDatabase($connectionConfig);
            $this->createSchema($db);
        } catch (Throwable $exception) {
            Config::clearForTests();

            if ($exception instanceof SkippedTest) {
                throw $exception;
            }

            $params = $connectionConfig['params'];
            $dsn = sprintf(
                '%s@%s:%s/%s',
                $params['username'],
                $params['host'],
                $params['port'],
                $params['database']
            );

            $this->markTestSkipped(sprintf('PostgreSQL unavailable (%s): %s', $dsn, $exception->getMessage()));
        }

        try {
            return $fn($db);
        } finally {
            Config::clearForTests();
        }
    }

    protected function registerTraceCollector(): QueryTraceCollector
    {
        $collector = new QueryTraceCollector();
        Database::addTraceListener($collector);

        return $collector;
    }

    protected function registerMetricsAggregator(bool $reportTables = true): MetricsAggregator
    {
        $aggregator = new MetricsAggregator(new MetricsConfig(enabled: true, reportTables: $reportTables));
        Database::addTraceListener($aggregator);

        return $aggregator;
    }

    protected function cacheController(Database $db): Cache
    {
        $property = new ReflectionProperty(Driver::class, 'cache');
        $property->setAccessible(true);

        /** @var Cache $cache */
        $cache = $property->getValue($this->getDriver($db));

        return $cache;
    }

    protected function consumeResultCacheHit(Database $db): bool
    {
        return $this->getDriver($db)->consumeResultCacheHit();
    }

    protected function cacheConfig(array $storeConfig): array
    {
        $defaults = [
            'store' => ['type' => 'array'],
            'serializer' => 'php',
            'namespace' => 'postgres-cert',
            'defaultPolicy' => [
                'ttlSeconds' => 60,
                'minIntervalSeconds' => 0,
                'allowStaleOnError' => false,
                'maxStaleSeconds' => 0,
                'disabled' => false,
            ],
        ];

        return [
            'cache' => array_replace_recursive($defaults, $storeConfig),
        ];
    }

    protected function createSchema(Database $db): void
    {
        $drops = [
            'audit_log',
            'tree_nodes',
            'transactions',
            'employees',
            'departments',
        ];

        foreach ($drops as $table) {
            $db->exec(sprintf('DROP TABLE IF EXISTS %s CASCADE', $table));
        }

        $db->exec(<<<'SQL'
CREATE TABLE departments (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE
)
SQL
        );

        $db->exec(<<<'SQL'
CREATE TABLE employees (
    id SERIAL PRIMARY KEY,
    department_id INT NOT NULL REFERENCES departments(id),
    employee_no TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    title TEXT NOT NULL,
    hired_at TIMESTAMPTZ NOT NULL,
    salary NUMERIC(12,2) NOT NULL
)
SQL
        );

        $db->exec(<<<'SQL'
CREATE TABLE audit_log (
    id BIGSERIAL PRIMARY KEY,
    employee_id INT REFERENCES employees(id),
    action TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
)
SQL
        );

        $db->exec(<<<'SQL'
CREATE TABLE tree_nodes (
    id SERIAL PRIMARY KEY,
    parent_id INT REFERENCES tree_nodes(id) ON DELETE CASCADE,
    name TEXT NOT NULL
)
SQL
        );

        $db->exec(<<<'SQL'
CREATE TABLE transactions (
    id BIGSERIAL PRIMARY KEY,
    account TEXT NOT NULL,
    amount NUMERIC(12,2) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
)
SQL
);

        $db->exec(<<<'SQL'
CREATE TABLE salaries (
    id BIGSERIAL PRIMARY KEY,
    employee_id INT NOT NULL REFERENCES employees(id),
    department_id INT NOT NULL REFERENCES departments(id),
    amount NUMERIC(12,2) NOT NULL,
    paid_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
)
SQL
);

        $this->seedFixtures($db);
        $this->resetSequence($db, 'salaries');
    }

    protected function seedFixtures(Database $db): void
    {
        $departments = [
            ['id' => 10, 'name' => 'Engineering'],
            ['id' => 20, 'name' => 'Operations'],
        ];

        foreach ($departments as $department) {
            $db->exec('INSERT INTO departments (id, name) VALUES (:id, :name)', $department);
        }

        $employees = [
            [
                'id' => 1,
                'department_id' => 10,
                'employee_no' => 'ENG-001',
                'name' => 'Ada Lovelace',
                'title' => 'Principal Engineer',
                'hired_at' => '2020-01-15 09:00:00+00',
                'salary' => 180000.00,
            ],
            [
                'id' => 2,
                'department_id' => 20,
                'employee_no' => 'OPS-010',
                'name' => 'Noel Ops',
                'title' => 'Operations Lead',
                'hired_at' => '2021-04-20 12:30:00+00',
                'salary' => 150000.00,
            ],
        ];

        foreach ($employees as $employee) {
            $db->exec(
                'INSERT INTO employees (id, department_id, employee_no, name, title, hired_at, salary) '
                . 'VALUES (:id, :department_id, :employee_no, :name, :title, :hired_at, :salary)',
                $employee
            );
        }

        $auditEntries = [
            ['employee_id' => 1, 'action' => 'created'],
            ['employee_id' => 2, 'action' => 'created'],
        ];

        foreach ($auditEntries as $entry) {
            $db->exec(
                'INSERT INTO audit_log (employee_id, action, created_at) VALUES (:employee_id, :action, NOW())',
                $entry
            );
        }

        $treeNodes = [
            ['id' => 1, 'parent_id' => null, 'name' => 'root'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'engineering'],
            ['id' => 3, 'parent_id' => 1, 'name' => 'operations'],
            ['id' => 4, 'parent_id' => 2, 'name' => 'platform'],
        ];

        foreach ($treeNodes as $node) {
            $db->exec(
                'INSERT INTO tree_nodes (id, parent_id, name) VALUES (:id, :parent_id, :name)',
                $node
            );
        }

        $transactions = [
            ['account' => 'ops', 'amount' => 1250.00],
            ['account' => 'eng', 'amount' => 5320.00],
            ['account' => 'ops', 'amount' => -210.50],
        ];

        foreach ($transactions as $transaction) {
            $db->exec(
                'INSERT INTO transactions (account, amount, created_at) VALUES (:account, :amount, NOW())',
                $transaction
            );
        }

        $this->resetSequence($db, 'departments');
        $this->resetSequence($db, 'employees');
        $this->resetSequence($db, 'tree_nodes');
        $this->resetSequence($db, 'transactions');
    }

    private function resetSequence(Database $db, string $table, string $column = 'id'): void
    {
        $sequence = sprintf('%s_%s_seq', $table, $column);
        $sql = sprintf(
            "SELECT setval('%s', (SELECT COALESCE(MAX(%s), 0) FROM %s))",
            $sequence,
            $column,
            $table
        );

        $db->exec($sql);
    }

    private function createDatabase(array $connection): Database
    {
        Config::clearForTests();

        $config = [
            'defaults' => ['connection' => self::CONNECTION_NAME],
            'connections' => [
                self::CONNECTION_NAME => $connection,
            ],
        ];

        $configPath = $this->writeTempConfig($config);

        try {
            return Database::connect($configPath);
        } finally {
            @unlink($configPath);
        }
    }

    private function baseConnectionConfig(): array
    {
        return [
            'driver' => 'pgsql',
            'params' => [
                'host' => $this->env('PGHOST', '127.0.0.1'),
                'port' => (int) $this->env('PGPORT', '5432'),
                'dbname' => $this->env('PGDATABASE', 'testdb'),
            ],
            'user' => $this->env('PGUSER', 'postgres'),
            'pass' => $this->env('PGPASSWORD', 'postgres'),
            'guardrails' => ['enabled' => false],
        ];
    }

    private function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function writeTempConfig(array $config): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'uda-postgres-config-');

        if ($temp === false) {
            self::fail('Unable to create temporary configuration file');
        }

        $path = $temp . '.json';
        rename($temp, $path);
        file_put_contents($path, (string) json_encode($config, JSON_PRETTY_PRINT));

        return $path;
    }

    protected function getDriver(Database $db): Driver
    {
        $property = new ReflectionProperty(Database::class, 'driver');
        $property->setAccessible(true);

        /** @var Driver $driver */
        $driver = $property->getValue($db);

        return $driver;
    }
}
