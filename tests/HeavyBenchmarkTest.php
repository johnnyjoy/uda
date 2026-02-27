<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Driver;

final class HeavyBenchmarkTest extends TestCase
{
    private Driver $driver;
    private string $configPath;
    private const PROFILE_ROWS = 5000;
    private const EVENT_ROWS = 2500;

    protected function setUp(): void
    {
        if (!filter_var(getenv('RUN_HEAVY_BENCH'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Heavy benchmarks disabled (set RUN_HEAVY_BENCH=1)');
        }

        $this->configPath = __DIR__ . '/../config/cache-config.json';
        $this->driver = Database::connect('cache_test', $this->configPath);
        $this->driver->exec('DROP TABLE IF EXISTS heavy_profiles');
        $this->driver->exec('DROP TABLE IF EXISTS heavy_events');
        $this->driver->exec('CREATE TABLE heavy_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, score INTEGER NOT NULL)');
        $this->driver->exec('CREATE TABLE heavy_events (id INTEGER PRIMARY KEY AUTOINCREMENT, profile_id INTEGER NOT NULL, detail TEXT NOT NULL, logged_at INTEGER NOT NULL)');

        $this->seedProfiles(static::PROFILE_ROWS);
        $this->seedEvents(static::EVENT_ROWS);
    }

    private function seedProfiles(int $count): void
    {
        $this->driver->exec('BEGIN TRANSACTION');
        for ($i = 0; $i < $count; $i++) {
            $this->driver->insert()
                ->into('heavy_profiles')
                ->set('name', 'profile' . $i)
                ->set('score', $i % 200)
                ->exec();
        }
        $this->driver->exec('COMMIT');
    }

    private function seedEvents(int $count): void
    {
        $this->driver->exec('BEGIN TRANSACTION');
        for ($i = 0; $i < $count; $i++) {
            $this->driver->insert()
                ->into('heavy_events')
                ->set('profile_id', ($i % static::PROFILE_ROWS) + 1)
                ->set('detail', 'event' . $i)
                ->set('logged_at', time() - $i)
                ->exec();
        }
        $this->driver->exec('COMMIT');
    }

    private function log(string $message): void
    {
        printf("[heavy] %s\n", $message);
    }

    private function measure(string $label, callable $fn): float
    {
        $start = microtime(true);
        $fn();
        $duration = microtime(true) - $start;
        printf("[heavy] %s took %.3f sec\n", $label, $duration);
        return $duration;
    }

    /**
     * @dataProvider heavyQueryProvider
     */
    public function testHeavyBenchmarkOperations(string $label, callable $operation): void
    {
        $this->log($label);
        $duration = $this->measure($label, fn() => $operation($this->driver));
        $this->assertGreaterThan(0.0, $duration);

        $stats = $this->driver->getCacheStatistics();
        $this->assertNotNull($stats);
        $this->assertGreaterThanOrEqual(0, $stats->dbExecutions());
        $this->assertGreaterThanOrEqual(0.0, $stats->averageDbDuration());
    }

    public static function heavyQueryProvider(): array
    {
        return [
            ['aggregate join', function (Driver $driver): void {
                $driver->rows(
                    'SELECT p.score, COUNT(e.id) AS events, AVG(p.score) AS avg_score FROM heavy_profiles p JOIN heavy_events e ON e.profile_id = p.id WHERE p.score BETWEEN :min AND :max GROUP BY p.id',
                    [':min' => 20, ':max' => 180]
                );
            }],
            ['windowed select', function (Driver $driver): void {
                $driver->rows(
                    'SELECT id, name, score, (SELECT COUNT(*) FROM heavy_events e WHERE e.profile_id = p.id) AS events FROM heavy_profiles p ORDER BY events DESC LIMIT 500', []
                );
            }],
            ['multi-step update', function (Driver $driver): void {
                $driver->update()
                    ->table('heavy_profiles')
                    ->set('score', 100)
                    ->where('score', 50)
                    ->exec();
            }],
            ['delete low scores', function (Driver $driver): void {
                $driver->delete()
                    ->table('heavy_profiles')
                    ->where('score', 0)
                    ->exec();
            }],
            ['upsert profile', function (Driver $driver): void {
                $driver->upsert()
                    ->into('heavy_profiles')
                    ->values(['name' => 'upsert-profile', 'score' => 42])
                    ->key(['name'])
                    ->update(['score'])
                    ->exec();
            }],
        ];
    }
}
