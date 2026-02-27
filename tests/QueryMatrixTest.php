<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Driver;
use UDA\SQL\SqlMessage;

final class QueryMatrixTest extends TestCase
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
        $this->driver->exec('DROP TABLE IF EXISTS items');
        $this->driver->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    }

    private function logCase(string $label, string $source): void
    {
        fwrite(STDOUT, sprintf("Running %s test (source=%s)\n", $label, $source));
    }

    /**
     * @dataProvider rawMethodProvider
     */
    public function testRawQueryMatrix(string $method, string $sql, array $params, string $targetType): void
    {
        $this->driver->exec('INSERT INTO items (name) VALUES ("alpha")');
        $this->logCase($method, 'raw');

        $result = $this->driver->{$method}($sql, $params);

        match ($targetType) {
            'row' => $this->assertIsArray($result),
            'rows' => $this->assertIsArray($result),
            'value' => $this->assertSame('alpha', $result),
            'values' => $this->assertSame(['alpha'], $result),
            'list' => $this->assertSame(['alpha'], $result),
        };
    }

    public static function rawMethodProvider(): array
    {
        return [
            ['row', 'SELECT id, name FROM items WHERE name = :name', ['name' => 'alpha'], 'row'],
            ['rows', 'SELECT name FROM items', [], 'rows'],
            ['value', 'SELECT name FROM items WHERE id = :id', ['id' => 1], 'value'],
            ['values', 'SELECT name FROM items', [], 'values'],
            ['list', 'SELECT name FROM items', [], 'list'],
        ];
    }

    /**
     * @dataProvider builderMethodProvider
     */
    public function testBuilderMatrix(string $label, callable $builderFactory): void
    {
        $this->logCase($label, 'builder');
        $this->driver->exec('INSERT INTO items (name) VALUES ("alpha")');

        $result = $builderFactory($this->driver);

        if (is_int($result)) {
            $this->assertGreaterThanOrEqual(0, $result);
        } elseif (is_array($result)) {
            $this->assertNotEmpty($result);
        } elseif ($result instanceof SqlMessage) {
            $this->assertNotEmpty($this->driver->rows($result));
        }
    }

    public static function builderMethodProvider(): array
    {
        return [
            ['select', fn(Driver $d) => $d->select()->from('items')->rows()],
            ['insert', fn(Driver $d) => $d->insert()->into('items')->set('name', 'beta')->exec()],
            ['update', fn(Driver $d) => $d->update()->table('items')->set('name', 'gamma')->where('name', 'alpha')->exec()],
            ['delete', fn(Driver $d) => $d->delete()->table('items')->where('name', 'alpha')->exec()],
            ['count', fn(Driver $d) => $d->select()->from('items')->count()],
        ];
    }
}
