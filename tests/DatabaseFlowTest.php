<?php

declare(strict_types=1);

namespace UniversalDataAbstraction;

use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Driver;
use UDA\SQL\SqlMessage;

final class DatabaseFlowTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = __DIR__ . '/../config/example-config.json';
    }

    private function prepareDriver(): Driver
    {
        $driver = Database::connect('audit_sqlite', $this->configPath);
        $driver->exec('DROP TABLE IF EXISTS items');
        $driver->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL)');
        return $driver;
    }

    public function testSqliteDriverExecutesQueries(): void
    {
        $driver = $this->prepareDriver();

        $driver->exec('INSERT INTO items (name) VALUES (:name)', ['name' => 'sasha']);

        $row = $driver->row('SELECT id, name FROM items WHERE name = :name', ['name' => 'sasha']);
        $this->assertIsArray($row);
        $this->assertSame('sasha', $row['name']);

        $value = $driver->value('SELECT name FROM items WHERE id = :id', ['id' => 1]);
        $this->assertSame('sasha', $value);

        $names = $driver->values('SELECT name FROM items');
        $this->assertEquals(['sasha'], $names);

        $count = 0;
        $driver->each('SELECT name FROM items', [], function (array $item) use (&$count): void {
            $this->assertArrayHasKey('name', $item);
            $count++;
        });

        $this->assertSame(1, $count);
    }

    public function testDriverFragmentsProduceSafeSql(): void
    {
        $driver = $this->prepareDriver();

        $orderBy = $driver->orderByAllowed('name', ['name' => true], 'desc');
        $this->assertSame('ORDER BY "name" DESC', $orderBy);

        $limit = $driver->limitOffset(5, 10);
        $this->assertInstanceOf(SqlMessage::class, $limit);

        $inList = $driver->inList(['a', 'b'], 'item');
        $this->assertInstanceOf(SqlMessage::class, $inList);

        $empty = $driver->inList([], 'item');
        $this->assertSame('1=0', $empty->getQuery());
    }

    public function testSelectQueryBuilderCrud(): void
    {
        $driver = $this->prepareDriver();

        $driver->insert()->into('items')->set('name', 'alice')->exec();
        $driver->insert()->into('items')->set('name', 'bob')->exec();

        $rows = $driver->select()
            ->from('items')
            ->select('name')
            ->orderBy('name', 'ASC', ['name' => true])
            ->rows();

        $this->assertCount(2, $rows);

        $updated = $driver->update()
            ->table('items')
            ->set('name', 'charlie')
            ->where('name', 'alice')
            ->exec();

        $this->assertSame(1, $updated);

        $count = $driver->select()
            ->from('items')
            ->where('name', 'charlie')
            ->count();

        $this->assertSame(1, $count);

        $driver->delete()
            ->table('items')
            ->where('name', 'bob')
            ->exec();

        $remaining = $driver->select()
            ->from('items')
            ->select('name')
            ->rows();

        $this->assertCount(1, $remaining);
        $this->assertSame('charlie', $remaining[0]['name']);
    }

    public function testUpsertQueryBuilder(): void
    {
        $driver = $this->prepareDriver();

        $driver->upsert()
            ->into('items')
            ->values(['name' => 'delta'])
            ->key(['name'])
            ->exec();

        $driver->upsert()
            ->into('items')
            ->values(['name' => 'delta'])
            ->key(['name'])
            ->doNothing()
            ->exec();

        $rows = $driver->select()
            ->from('items')
            ->select('name')
            ->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('delta', $rows[0]['name']);
    }
}
