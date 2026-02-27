<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Driver;
use UDA\Exception\QueryException;

final class CacheBehaviorTest extends TestCase
{
    private Driver $driver;
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = __DIR__ . '/../config/cache-config.json';
        $this->driver = Database::connect('cache_test', $this->configPath);
        $this->resetSchema();
    }

    private function resetSchema(): void
    {
        foreach (['items', 'tags', 'disabled_items'] as $table) {
            $this->driver->exec("DROP TABLE IF EXISTS {$table}");
        }

        $this->driver->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $this->driver->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL)');
        $this->driver->exec('CREATE TABLE disabled_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    }

    private function seedItemsAndTags(): void
    {
        $this->driver->exec('INSERT INTO items (name) VALUES ("alpha")');
        $this->driver->exec('INSERT INTO tags (label) VALUES ("alpha-tag")');
        $this->driver->exec('INSERT INTO disabled_items (name) VALUES ("denied")');
    }

    public function testCacheServesCachedRowAfterDrop(): void
    {
        $scope = $this->driver->cache(null, ['items']);
        $this->seedItemsAndTags();

        $first = $scope->rows('SELECT id, name FROM items');
        $this->driver->exec('DROP TABLE items');

        $second = $scope->rows('SELECT id, name FROM items');

        $this->assertSame($first, $second);
    }

    public function testCacheExpiresAfterTtl(): void
    {
        $scope = $this->driver->cache(null, ['items']);
        $this->seedItemsAndTags();

        $scope->rows('SELECT name FROM items');
        $this->driver->exec('DROP TABLE items');
        usleep(3_100_000); // TTL for items is 3 seconds

        $this->expectException(QueryException::class);
        $scope->rows('SELECT name FROM items');
    }

    public function testCacheDisabledByTableRule(): void
    {
        $scope = $this->driver->cache(null, ['disabled_items']);
        $this->driver->exec('INSERT INTO disabled_items (name) VALUES ("no-cache")');

        $this->assertCount(1, $scope->rows('SELECT name FROM disabled_items'));
        $this->driver->exec('DROP TABLE disabled_items');

        $this->expectException(QueryException::class);
        $scope->rows('SELECT name FROM disabled_items');
    }

    public function testStaleOnErrorReturnsCachedResult(): void
    {
        $scope = $this->driver->cache(null, ['items']);
        $this->seedItemsAndTags();

        $initial = $scope->rows('SELECT name FROM items');
        $this->driver->exec('DROP TABLE items');

        $this->assertSame($initial, $scope->rows('SELECT name FROM items'));
    }

    public function testWriteInvalidatesCache(): void
    {
        $scope = $this->driver->cache(null, ['items']);
        $this->driver->exec('INSERT INTO items (name) VALUES ("first")');

        $initial = $scope->rows('SELECT name FROM items ORDER BY name');

        $this->driver->insert()
            ->into('items')
            ->set('name', 'second')
            ->exec();

        $updated = $scope->rows('SELECT name FROM items ORDER BY name');
        $this->assertCount(2, $updated);
        $this->assertSame(['first', 'second'], array_column($updated, 'name'));
    }

    public function testPerTablePolicyUsesMinimumTtl(): void
    {
        $scope = $this->driver->cache(null, ['items', 'tags']);
        $this->seedItemsAndTags();

        $scope->rows('SELECT items.name, tags.label FROM items JOIN tags ON tags.id = items.id');
        $this->driver->exec('DROP TABLE items');
        $this->driver->exec('DROP TABLE tags');
        usleep(1_200_000); // tags TTL is 1 second, so cached entry should expire

        $this->expectException(QueryException::class);
        $scope->rows('SELECT items.name FROM items JOIN tags ON tags.id = items.id');
    }

    public function testCacheHandlesAllQueryMethods(): void
    {
        $scope = $this->driver->cache(null, ['items']);
        $this->driver->exec('INSERT INTO items (name) VALUES ("alpha")');

        $row = $scope->row('SELECT id, name FROM items WHERE name = :name', ['name' => 'alpha']);
        $this->assertSame('alpha', $row['name']);

        $rows = $scope->rows('SELECT name FROM items');
        $this->assertSame(['alpha'], array_column($rows, 'name'));

        $value = $scope->value('SELECT name FROM items WHERE id = :id', ['id' => $row['id']]);
        $this->assertSame('alpha', $value);

        $values = $scope->values('SELECT name FROM items');
        $this->assertSame(['alpha'], $values);

        $list = $scope->list('SELECT name FROM items');
        $this->assertSame(['alpha'], $list);

        $this->driver->exec('DROP TABLE items');

        $this->assertEquals($row, $scope->row('SELECT id, name FROM items WHERE name = :name', ['name' => 'alpha']));
    }

    public function testBuilderQueryUsesCacheAndInvalidatesOnTouch(): void
    {
        $scope = $this->driver->cache(null, ['items']);
        $this->driver->exec('INSERT INTO items (name) VALUES ("builder")');

        $builder = $this->driver->select()
            ->from('items')
            ->where('name', 'builder');

        $first = $scope->rows($builder->toSql());

        $this->driver->exec('DELETE FROM items');

        $second = $scope->rows($builder->toSql());
        $this->assertSame($first, $second);

        $this->driver->insert()
            ->into('items')
            ->set('name', 'builder2')
            ->exec();

        $updated = $scope->rows('SELECT name FROM items');
        $this->assertSame(['builder2'], array_column($updated, 'name'));
    }
}
