<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Driver;

final class CacheStatisticsTest extends TestCase
{
    private Driver $driver;
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = __DIR__ . '/../config/cache-config.json';
        $this->driver = Database::connect('cache_test', $this->configPath);
        $this->driver->exec('DROP TABLE IF EXISTS items');
        $this->driver->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    }

    public function testStatisticsTrackHitsAndMisses(): void
    {
        $scope = $this->driver->cache(null, ['items']);
        $this->driver->exec('INSERT INTO items (name) VALUES ("alpha")');

        $scope->rows('SELECT name FROM items');
        $scope->rows('SELECT name FROM items');

        $stats = $this->driver->getCacheStatistics();
        $this->assertNotNull($stats);
        $this->assertGreaterThanOrEqual(1, $stats->hits());
        $this->assertGreaterThanOrEqual(1, $stats->misses());
        $this->assertGreaterThanOrEqual(1, $stats->dbExecutions());
        $this->assertGreaterThanOrEqual(0.0, $stats->averageStoreDuration());
        $this->assertGreaterThanOrEqual(0.0, $stats->averageDbDuration());

        $this->driver->exec('DROP TABLE items');
        $result = $scope->rows('SELECT name FROM items');
        $this->assertSame('alpha', $result[0]['name']);

        $this->assertGreaterThanOrEqual(0, $stats->staleHits());
    }
}
