<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use UDA\Config;
use UDA\Database;
use UDA\Driver;
use UDA\Exception\QueryException;
use UDA\Query\Abs as QueryBuilderBase;
use UDA\Query\Delete;
use UDA\Query\Dialect\Dialect;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Dialect\SQLite as SQLiteDialect;
use UDA\Query\Insert;
use UDA\Query\Select;
use UDA\Query\Sql as BuilderSql;
use UDA\Query\Sql;
use UDA\SQL\SqlMessage;
use UDA\Query\Update;
use UDA\Query\Upsert;
use UDA\Query\Dialect\DeleteState;
use UDA\Query\Dialect\InsertState;
use UDA\Query\Dialect\SelectState;
use UDA\Query\Dialect\UpdateState;
use UDA\Query\Dialect\UpsertState;
use UDA\Tracing\QueryTraceCollector;

final class ExplainTest extends TestCase
{
    /**
     * @dataProvider builderProvider
     */
    public function testBuilderTerminatorsExposeExplainVariants(
        Select|Insert|Update|Delete|Upsert $builder
    ): void {
        $this->assertIsArray($builder->explain());
        $this->assertIsArray($builder->explainAnalyze());
    }

    /**
     * @return iterable<string, array{0: Select|Insert|Update|Delete|Upsert}>
     */
    public static function builderProvider(): iterable
    {
        yield 'select' => [self::selectBuilder()];
        yield 'insert' => [self::insertBuilder()];
        yield 'update' => [self::updateBuilder()];
        yield 'delete' => [self::deleteBuilder()];
        yield 'upsert' => [self::upsertBuilder()];
    }

    public function testExplainReturnsPlanRows(): void
    {
        $db = self::sqliteDatabase(function (Database $db): void {
            $db->exec('CREATE TABLE explain_selects (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');
            $db->exec("INSERT INTO explain_selects (label) VALUES ('alpha'), ('beta')");
        });

        $plan = $db->select()
            ->select('id', 'label')
            ->from('explain_selects')
            ->explain();

        $this->assertNotEmpty($plan);
        $this->assertArrayHasKey('detail', $plan[0]);
    }

    public function testExplainAnalyzeSupportedDialect(): void
    {
        $db = self::sqliteDatabase(function (Database $db): void {
            $db->exec('CREATE TABLE explain_analyze (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');
        });

        self::overrideDriverDialect($db, self::analyzeCapableDialect());

        $plan = $db->select()
            ->select('id')
            ->from('explain_analyze')
            ->explainAnalyze();

        $this->assertNotEmpty($plan);
        $this->assertArrayHasKey('detail', $plan[0]);
    }

    public function testExplainTraceEvent(): void
    {
        $collector = new QueryTraceCollector();
        Database::clearTraceListeners();
        Database::addTraceListener($collector);

        $db = self::sqliteDatabase(function (Database $db): void {
            $db->exec('CREATE TABLE explain_trace (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');
        });

        $db->select()->select('id')->from('explain_trace')->explain();

        $traces = $collector->getTraces();
        $this->assertNotEmpty($traces);
        $trace = $traces[array_key_last($traces)];
        $this->assertSame('explain', $trace->operation);
        $this->assertFalse($trace->planCacheHit);
        $this->assertFalse($trace->statementCacheHit);
        $this->assertFalse($trace->resultCacheHit);

        Database::clearTraceListeners();
    }

    public function testRawSqlExplain(): void
    {
        $db = self::sqliteDatabase(function (Database $db): void {
            $db->exec('CREATE TABLE explain_raw (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT)');
        });

        $plans = $db->explain(BuilderSql::of('SELECT id FROM explain_raw', [], ['explain_raw']));

        $this->assertNotEmpty($plans);
        $this->assertArrayHasKey('detail', $plans[0]);
    }

    public function testExplainAnalyzeUnsupportedThrows(): void
    {
        $db = self::sqliteDatabase(function (Database $db): void {
            $db->exec('CREATE TABLE explain_unsupported (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT)');
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('SQLite');

        $db->select()->select('id')->from('explain_unsupported')->explainAnalyze();
    }

    public function testExplainOnInsertHasNoSideEffect(): void
    {
        $db = self::sqliteDatabase(function (Database $db): void {
            $db->exec('CREATE TABLE explain_targets (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT)');
        });

        $insert = $db->insert()
            ->into('explain_targets')
            ->set('body', 'alpha');

        $plan = $insert->explain();

        $this->assertIsArray($plan);
        $count = (int) $db->value('SELECT COUNT(*) FROM explain_targets');
        $this->assertSame(0, $count, 'EXPLAIN against insert should not mutate table data');
    }

    private static function selectBuilder(): Select
    {
        $builder = new Select();
        self::attachDriver($builder);
        $builder = $builder->select('id');
        $builder = $builder->from('users');

        return $builder;
    }

    private static function insertBuilder(): Insert
    {
        $builder = new Insert();
        self::attachDriver($builder);
        $builder = $builder->into('users');
        $builder = $builder->set('name', 'alpha');

        return $builder;
    }

    private static function updateBuilder(): Update
    {
        $builder = new Update();
        self::attachDriver($builder);
        $builder = $builder->table('users');
        $builder = $builder->set('status', 'archived');

        return $builder;
    }

    private static function deleteBuilder(): Delete
    {
        $builder = new Delete();
        self::attachDriver($builder);
        $builder = $builder->table('users');

        return $builder;
    }

    private static function upsertBuilder(): Upsert
    {
        $builder = new Upsert();
        self::attachDriver($builder);
        $builder = $builder->into('users');
        $builder = $builder->values(['id' => 1, 'name' => 'alpha']);
        $builder = $builder->key(['id']);
        $builder = $builder->update(['name']);

        return $builder;
    }

    private static function sqliteDatabase(?callable $setup = null): Database
    {
        Config::clearForTests();

        $config = [
            'defaults' => ['connection' => 'explain_tests'],
            'connections' => [
                'explain_tests' => [
                    'driver' => 'sqlite',
                    'params' => ['path' => ':memory:'],
                ],
            ],
        ];

        $temp = tempnam(sys_get_temp_dir(), 'uda-explain-');
        $configPath = $temp . '.json';
        rename($temp, $configPath);
        file_put_contents($configPath, (string) json_encode($config));

        $db = Database::connect($configPath);
        @unlink($configPath);

        if ($setup !== null) {
            $setup($db);
        }

        return $db;
    }

    private static function overrideDriverDialect(Database $db, Dialect $dialect): void
    {
        $driverRef = new ReflectionProperty(Database::class, 'driver');
        $driverRef->setAccessible(true);
        /** @var Driver $driver */
        $driver = $driverRef->getValue($db);

        $dialectRef = new ReflectionProperty(Driver::class, 'dialectInstance');
        $dialectRef->setAccessible(true);
        $dialectRef->setValue($driver, $dialect);

        $db->overrideDialectForPlanCache($dialect);
    }

    private static function analyzeCapableDialect(): Dialect
    {
        $inner = new SQLiteDialect();

        return new class($inner) extends Dialect {
            public function __construct(private SQLiteDialect $inner)
            {
            }

            public function name(): string
            {
                return $this->inner->name();
            }

            public function compileSelect(SelectState $state): Sql
            {
                return $this->inner->compileSelect($state);
            }

            public function compileInsert(InsertState $state): Sql
            {
                return $this->inner->compileInsert($state);
            }

            public function compileUpdate(UpdateState $state): Sql
            {
                return $this->inner->compileUpdate($state);
            }

            public function compileDelete(DeleteState $state): Sql
            {
                return $this->inner->compileDelete($state);
            }

            public function compileUpsert(UpsertState $state): Sql
            {
                return $this->inner->compileUpsert($state);
            }

            public function supportsReturning(): bool
            {
                return $this->inner->supportsReturning();
            }

            public function supportsWritableCte(): bool
            {
                return $this->inner->supportsWritableCte();
            }

            public function supportsRecursiveWritableCte(): bool
            {
                return $this->inner->supportsRecursiveWritableCte();
            }

            public function supportsCteMaterializationHints(): bool
            {
                return $this->inner->supportsCteMaterializationHints();
            }

            public function supportsExplain(): bool
            {
                return $this->inner->supportsExplain();
            }

            public function supportsExplainAnalyze(): bool
            {
                return true;
            }

            public function supportsUpsert(): bool
            {
                return $this->inner->supportsUpsert();
            }

            public function buildExplainSql(SqlMessage $sql, bool $analyze): iterable
            {
                if ($analyze) {
                    yield new SqlMessage(
                        'EXPLAIN QUERY PLAN ' . $sql->getQuery(),
                        $sql->getParams(),
                        $sql->getCacheTables()
                    );

                    return;
                }

                yield from $this->inner->buildExplainSql($sql, false);
            }
        };
    }

    private static function attachDriver(QueryBuilderBase $builder): void
    {
        $builder->bindDialect(new PostgreSql());
        $builder->driverInstance = new FakeExplainDriver();
        $builder->driverName = 'pgsql';
    }
}

final class FakeExplainDriver extends Driver
{
    public function __construct()
    {
        $this->dbtype = 'pgsql';
    }

    protected function onConnect(): void
    {
        // No-op for tests.
    }

    protected function runExplain(string|SqlMessage|BuilderSql $sql, bool $analyze): array
    {
        [$message] = $this->normalizeToSqlMessage($sql, []);

        return [['query' => $message->getQuery(), 'analyze' => $analyze]];
    }

    protected function buildDsn(array $params): string
    {
        return 'sqlite::memory:';
    }
}
