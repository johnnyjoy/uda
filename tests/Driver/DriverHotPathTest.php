<?php

declare(strict_types=1);

namespace Tests\Driver;

use PDOStatement;
use PHPUnit\Framework\TestCase;
use UDA\Driver;
use UDA\Driver\SQLServer;
use UDA\Exception\NotSupportedException;
use UDA\Exception\QueryException;
use UDA\Query\Upsert;
use UDA\SQL\SqlMessage;

final class DriverHotPathTest extends TestCase
{
    private function makeDriver(): TestSqliteDriver
    {
        return TestSqliteDriver::create();
    }

    public function testRowsUsesSingleHotPath(): void
    {
        $driver = $this->makeDriver();
        $driver->resetLog();
        $rows = $driver->rows('SELECT id, name FROM items WHERE id = :id', ['id' => 1]);

        $this->assertSame(1, $driver->logCount());
        $this->assertCount(1, $rows);
        $this->assertSame('alpha', $rows[0]['name']);
    }

    public function testRowThrowsWhenMultipleRowsReturned(): void
    {
        $driver = $this->makeDriver();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('row() expects at most one row');

        $driver->row('SELECT * FROM items', []);
    }

    public function testValueThrowsWhenMultipleColumnsReturned(): void
    {
        $driver = $this->makeDriver();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('value() requires a single column result');

        $driver->value('SELECT id, name FROM items WHERE id = :id', ['id' => 1]);
    }

    public function testValuesAndListReturnFirstColumn(): void
    {
        $driver = $this->makeDriver();
        $vals = $driver->values('SELECT id, name FROM items ORDER BY id', []);
        $list = $driver->list('SELECT id, name FROM items ORDER BY id', []);

        $this->assertSame([1, 2], $vals);
        $this->assertSame($vals, $list);
    }

    public function testEachIteratesOverRows(): void
    {
        $driver = $this->makeDriver();
        $rows = [];
        $count = $driver->each('SELECT * FROM items ORDER BY id', [], function (array $row) use (&$rows) {
            $rows[] = $row['name'];
        });

        $this->assertSame(2, $count);
        $this->assertSame(['alpha', 'beta'], $rows);
    }

    public function testExecReturnsRowCount(): void
    {
        $driver = $this->makeDriver();
        $affected = $driver->exec('UPDATE items SET score = score + 1 WHERE id = :id', ['id' => 1]);

        $this->assertSame(1, $affected);
        $this->assertSame(11, $driver->value('SELECT score FROM items WHERE id = :id', ['id' => 1]));
    }

    public function testRejectsPositionalParameters(): void
    {
        $driver = $this->makeDriver();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Positional parameters are forbidden in public API');

        $driver->rows('SELECT * FROM items WHERE id = ?', ['id' => 1]);
    }

    public function testSqlMessageInputSupported(): void
    {
        $driver = $this->makeDriver();
        $sql = new SqlMessage('SELECT id FROM items WHERE name = :name', ['name' => 'alpha']);

        $values = $driver->values($sql);
        $this->assertSame([1], $values);
        $this->assertSame('SELECT id FROM items WHERE name = :name', $driver->lastSql());
    }

    public function testNestedTransactionsRollbackOnException(): void
    {
        $driver = $this->makeDriver();

        try {
            $driver->transaction(function (Driver $driver) {
                $driver->exec('INSERT INTO items (name, score) VALUES (:name, :score)', [
                    'name' => 'gamma',
                    'score' => 30,
                ]);

                $driver->transaction(function () {
                    throw new \RuntimeException('fail inner');
                });
            });
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('fail inner', $e->getMessage());
        }

        $count = $driver->value('SELECT COUNT(*) FROM items WHERE name = :name', ['name' => 'gamma']);
        $this->assertSame(0, (int)$count);
    }

    public function testSelectBuilderBinding(): void
    {
        $driver = $this->makeDriver();
        $select = $driver->select();

        $this->assertSame($driver, $select->driverInstance);
        $this->assertSame($driver->getBackendName(), $select->driverName);
    }

    public function testUpsertBuilderExecutes(): void
    {
        $driver = $this->makeDriver();

        $upsert = $driver->upsert()
            ->into('items')
            ->values(['id' => 1, 'name' => 'alpha', 'score' => 99])
            ->key(['id'])
            ->update(['score']);

        $affected = $upsert->exec();
        $this->assertSame(1, $affected);

        $score = $driver->value('SELECT score FROM items WHERE name = :name', ['name' => 'alpha']);
        $this->assertSame(99, $score);
    }

    public function testSqlServerUpsertNotSupported(): void
    {
        $driver = (new \ReflectionClass(SQLServer::class))->newInstanceWithoutConstructor();
        $upsert = $driver->upsert()
            ->into('demo_table')
            ->values(['id' => 1])
            ->key(['id'])
            ->update(['id']);

        $this->expectException(NotSupportedException::class);
        $driver->upsertExec($upsert);
    }
}

final class TestSqliteDriver extends Driver
{
    private array $log = [];

    private function __construct(array $config, ?string $connection)
    {
        parent::__construct($config, $connection);
        $this->seed();
    }

    public static function create(): self
    {
        return new self([
            'driver' => 'sqlite',
            'params' => ['path' => ':memory:'],
        ], 'test');
    }

    protected function buildDsn(array $params): string
    {
        $path = $params['path'] ?? ':memory:';

        return 'sqlite:' . $path;
    }

    protected function onConnect(): void
    {
        // SQLite requires no session configuration for tests
    }

    public function upsertExec(Upsert $query): int
    {
        $sql = $query->toSql();

        return $this->exec($this->toSqlMessage($sql), [], $sql->getCacheTables());
    }

    protected function executeInternal(string|SqlMessage $sql, array $params): PDOStatement
    {
        $this->log[] = $sql instanceof SqlMessage ? $sql->getQuery() : $sql;

        return parent::executeInternal($sql, $params);
    }

    public function resetLog(): void
    {
        $this->log = [];
    }

    public function logCount(): int
    {
        return count($this->log);
    }

    private function seed(): void
    {
        $pdo = $this->pdo;
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, score INT)');
        $stmt = $pdo->prepare('INSERT INTO items (name, score) VALUES (?, ?)');
        $stmt->execute(['alpha', 10]);
        $stmt->execute(['beta', 20]);
    }
}
