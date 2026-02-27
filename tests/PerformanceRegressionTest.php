<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Driver;

final class PerformanceRegressionTest extends TestCase
{
    private Driver $driver;
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = __DIR__ . '/../config/cache-config.json';
        $this->driver = Database::connect('cache_test', $this->configPath);
        $this->recreateSchema();
    }

    private function recreateSchema(): void
    {
        $this->driver->exec('DROP TABLE IF EXISTS items');
        $this->driver->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    }

    private function populate(int $count): void
    {
        $this->driver->exec('DELETE FROM items');
        for ($i = 0; $i < $count; $i++) {
            $this->driver->exec('INSERT INTO items (name) VALUES (:name)', ['name' => 'item_' . $i]);
        }
    }

    private function measure(string $label, int $iterations, callable $fn): float
    {
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $fn();
        }
        $duration = microtime(true) - $start;
        printf("%s: %d iterations, %.3fms total, %.3fms avg\n", $label, $iterations, $duration * 1000, ($duration / $iterations) * 1000);
        return $duration;
    }

    public function testRawSelectPerformance(): void
    {
        $this->populate(500);

        $duration = $this->measure('raw select', 200, function () {
            $this->driver->rows('SELECT id, name FROM items WHERE name LIKE :name', ['name' => 'item_%']);
        });
        $this->assertGreaterThan(0.0, $duration);
    }

    public function testBuilderSelectPerformance(): void
    {
        $this->populate(500);

        $duration = $this->measure('builder select', 150, function () {
            $this->driver->select()->from('items')->where('name', 'item_0')->rows();
        });
        $this->assertGreaterThan(0.0, $duration);
    }

    public function testInsertUpdateDeletePerformance(): void
    {
        $duration = $this->measure('batch insert', 100, function () {
            $this->driver->insert()->into('items')->set('name', 'perf')->exec();
            $this->driver->exec('DELETE FROM items WHERE name = :name', ['name' => 'perf']);
        });
        $this->assertGreaterThan(0.0, $duration);
    }

    public function testCacheScopePerformance(): void
    {
        $scope = $this->driver->cache(null, ['items']);
        $this->populate(100);

        $duration = $this->measure('cache read', 100, function () use ($scope) {
            $scope->rows('SELECT name FROM items ORDER BY id LIMIT 1');
        });
        $this->assertGreaterThan(0.0, $duration);
    }

    public function testPerformanceStatisticsStayHealthy(): void
    {
        $scope = $this->driver->cache(null, ['items']);
        $this->populate(10);

        $scope->rows('SELECT name FROM items WHERE id = :id', ['id' => 1]);
        $scope->rows('SELECT name FROM items WHERE id = :id', ['id' => 1]);
        $scope->rows('SELECT name FROM items WHERE id = :id', ['id' => 2]);

        $stats = $this->driver->getCacheStatistics();
        $this->assertNotNull($stats);
        $this->assertGreaterThanOrEqual(1, $stats->hits(), 'cache hits should increment');
        $this->assertGreaterThanOrEqual(1, $stats->misses(), 'cache misses should increment');
        $this->assertGreaterThanOrEqual(0, $stats->staleHits());
        $this->assertGreaterThanOrEqual(1, $stats->dbExecutions());
        $this->assertLessThan(0.01, $stats->averageStoreDuration() + 1e-12);
        $this->assertLessThan(0.05, $stats->averageDbDuration() + 1e-12);
    }
}
