<?php

declare(strict_types=1);

namespace Tests\Postgres;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Postgres\Cache\CacheService;
use Tests\Postgres\Cache\MemcachedService;
use Tests\Postgres\Cache\RedisService;
use UDA\Config;
use UDA\Database;
use UDA\Tracing\QueryTrace;
use UDA\Tracing\QueryTraceCollector;

final class PostgresCacheTest extends PostgresTestCase
{
    #[DataProvider('cacheProvider')]
    public function testReadsUseCacheAndEmitTables(string $label, CacheService $service): void
    {
        $serviceConfig = $service->bootOrSkip($this);

        $this->withPostgresDb(function (Database $db) use ($label, $serviceConfig): void {
            Config::clearForTests();
            $baseConfig = $this->baseConnectionConfig();
            $config = [
                'defaults' => ['connection' => self::CONNECTION_NAME],
                'connections' => [
                    self::CONNECTION_NAME => array_replace_recursive([
                        'driver' => 'pgsql',
                        'params' => $baseConfig['params'],
                        'user' => $baseConfig['user'],
                        'pass' => $baseConfig['pass'],
                        'cache' => $this->postgresCacheConfig($serviceConfig),
                    ], $baseConfig),
                ],
            ];
            $configPath = $this->writeTempConfig($config);
            Config::init($configPath);

            $collector = $this->registerTraceCollector();

            $sql = 'SELECT name, title FROM employees WHERE id = :id';
            $params = ['id' => 1];
            $tableHints = ['employees'];

            $db->rows($sql, $params, $tableHints);
            $firstTrace = $this->lastTrace($collector);
            self::assertFalse($firstTrace->resultCacheHit,
                sprintf('[%s] first SELECT should hit DB', $label));
            $this->assertTraceTables($firstTrace, $tableHints, $label);

            $db->rows($sql, $params, $tableHints);
            $secondTrace = $this->lastTrace($collector);
            self::assertTrue($secondTrace->resultCacheHit,
                sprintf('[%s] second SELECT should hit cache', $label));
            $this->assertTraceTables($secondTrace, $tableHints, $label);
        });
    }

    #[DataProvider('cacheProvider')]
    public function testWriteInvalidatesCachedEntry(string $label, CacheService $service): void
    {
        $serviceConfig = $service->bootOrSkip($this);

        $this->withPostgresDb(function (Database $db) use ($label, $serviceConfig): void {
            Config::clearForTests();
            $baseConfig = $this->baseConnectionConfig();
            $config = [
                'defaults' => ['connection' => self::CONNECTION_NAME],
                'connections' => [
                    self::CONNECTION_NAME => array_replace_recursive([
                        'driver' => 'pgsql',
                        'params' => $baseConfig['params'],
                        'user' => $baseConfig['user'],
                        'pass' => $baseConfig['pass'],
                        'cache' => $this->postgresCacheConfig($serviceConfig),
                    ], $baseConfig),
                ],
            ];
            $configPath = $this->writeTempConfig($config);
            Config::init($configPath);

            $collector = $this->registerTraceCollector();

            $baseline = $db->rows('SELECT name FROM employees WHERE id = :id', ['id' => 1], ['employees']);
            self::assertNotEmpty($baseline);
            $traceBefore = $this->lastTrace($collector);
            self::assertFalse($traceBefore->resultCacheHit,
                sprintf('[%s] SELECT before write should hit DB', $label));
            $this->assertTraceTables($traceBefore, ['employees'], $label);
            $collector->clear();

            $newTitle = 'Senior IC';
            $db->exec(
                'UPDATE employees SET title = :title WHERE id = :id',
                ['title' => $newTitle, 'id' => 1],
                ['employees']
            );

            $updated = $db->rows('SELECT title FROM employees WHERE id = :id', ['id' => 1], ['employees']);
            self::assertSame($newTitle, $updated[0]['title'] ?? '');
            $traceAfter = $this->lastTrace($collector);
            self::assertFalse($traceAfter->resultCacheHit,
                sprintf('[%s] SELECT after UPDATE should invalidate cache and hit DB', $label));
            $this->assertTraceTables($traceAfter, ['employees'], $label);
        });
    }

    #[DataProvider('cacheProvider')]
    public function testReturningQueriesAreCached(string $label, CacheService $service): void
    {
        $serviceConfig = $service->bootOrSkip($this);

        $this->withPostgresDb(function (Database $db) use ($label, $serviceConfig): void {
            Config::clearForTests();
            $baseConfig = $this->baseConnectionConfig();
            $config = [
                'defaults' => ['connection' => self::CONNECTION_NAME],
                'connections' => [
                    self::CONNECTION_NAME => array_replace_recursive([
                        'driver' => 'pgsql',
                        'params' => $baseConfig['params'],
                        'user' => $baseConfig['user'],
                        'pass' => $baseConfig['pass'],
                        'cache' => $this->postgresCacheConfig($serviceConfig),
                    ], $baseConfig),
                ],
            ];
            $configPath = $this->writeTempConfig($config);
            Config::init($configPath);

            $collector = $this->registerTraceCollector();
            $transactionId = (int) $db->value(
                'INSERT INTO transactions (account, amount) VALUES (:account, :amount) RETURNING id',
                ['account' => 'cache', 'amount' => 42.20],
                ['transactions']
            );
            self::assertGreaterThan(0, $transactionId);

            $firstTrace = $this->lastTrace($collector);
            self::assertFalse($firstTrace->resultCacheHit,
                sprintf('[%s] INSERT RETURNING should hit DB', $label));
            $this->assertTraceTables($firstTrace, ['transactions'], $label);
            $collector->clear();

            $cached = $db->returning(
                'SELECT account, amount FROM transactions WHERE id = :id',
                ['id' => $transactionId],
                ['transactions']
            );
            self::assertSame([
                'account' => 'cache',
                'amount' => 42.20,
            ], array_map('strval', $cached[0] ?? []));

            $secondTrace = $this->lastTrace($collector);
            self::assertFalse($secondTrace->resultCacheHit,
                sprintf('[%s] SELECT should hit DB (RETURNING fingerprint bisects cache key)', $label));
        });
    }

    #[DataProvider('cacheProvider')]
    public function testBuilderQueriesAreCached(string $label, CacheService $service): void
    {
        $serviceConfig = $service->bootOrSkip($this);

        $this->withPostgresDb(function (Database $db) use ($label, $serviceConfig): void {
            Config::clearForTests();
            $baseConfig = $this->baseConnectionConfig();
            $config = [
                'defaults' => ['connection' => self::CONNECTION_NAME],
                'connections' => [
                    self::CONNECTION_NAME => array_replace_recursive([
                        'driver' => 'pgsql',
                        'params' => $baseConfig['params'],
                        'user' => $baseConfig['user'],
                        'pass' => $baseConfig['pass'],
                        'cache' => $this->postgresCacheConfig($serviceConfig),
                    ], $baseConfig),
                ],
            ];
            $configPath = $this->writeTempConfig($config);
            Config::init($configPath);

            $collector = $this->registerTraceCollector();

            $db->select()
                ->select('name')
                ->from('employees')
                ->where('id', 1)
                ->rows();

            $firstTrace = $this->lastTrace($collector);
            self::assertFalse($firstTrace->resultCacheHit,
                sprintf('[%s] First builder SELECT should hit DB', $label));
            $this->assertTraceTables($firstTrace, ['employees'], $label);

            $db->select()
                ->select('name')
                ->from('employees')
                ->where('id', 1)
                ->rows();

            $secondTrace = $this->lastTrace($collector);
            self::assertTrue($secondTrace->resultCacheHit,
                sprintf('[%s] Second builder SELECT should hit cache', $label));
            $this->assertTraceTables($secondTrace, ['employees'], $label);
        });
    }

    public static function cacheProvider(): array
    {
        return [
            ['redis', new RedisService()],
            ['memcached', new MemcachedService()],
        ];
    }

    private function postgresCacheConfig(array $serviceConfig): array
    {
        $defaults = [
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

        return array_replace_recursive($defaults, $serviceConfig);
    }

    private function lastTrace(QueryTraceCollector $collector): QueryTrace
    {
        $traces = $collector->getTraces();
        self::assertNotEmpty($traces, 'expected at least one trace');

        return $traces[array_key_last($traces)];
    }

    private function assertTraceTables(QueryTrace $trace, array $tableHints, string $label): void
    {
        self::assertSame($tableHints, $trace->tables,
            sprintf('[%s] trace tables mismatch', $label));
        self::assertArrayHasKey('fingerprint', $trace->meta,
            sprintf('[%s] trace metadata missing fingerprint', $label));
    }
}
