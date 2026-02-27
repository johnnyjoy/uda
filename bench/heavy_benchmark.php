<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use UDA\Database;

$configPath = __DIR__ . '/../config/cache-config.json';
$rows = (int) ($_ENV['BENCH_ROWS'] ?? 200_000);
$events = (int) ($_ENV['BENCH_EVENTS'] ?? 50_000);

$driver = Database::connect('cache_test', $configPath);
$driver->exec('DROP TABLE IF EXISTS bench_profiles');
$driver->exec('DROP TABLE IF EXISTS bench_events');
$driver->exec(
    'CREATE TABLE bench_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, score INTEGER NOT NULL)'
);
$driver->exec(
    'CREATE TABLE bench_events (id INTEGER PRIMARY KEY AUTOINCREMENT, profile_id INTEGER NOT NULL, detail TEXT NOT NULL, logged_at INTEGER NOT NULL)'
);

function measure(string $label, callable $fn): float
{
    $start = microtime(true);
    $fn();
    $duration = microtime(true) - $start;
    printf("%s: %.3f sec\n", $label, $duration);
    return $duration;
}

measure('Insert profiles (' . number_format($rows) . ')', function () use ($driver, $rows): void {
    $driver->exec('BEGIN TRANSACTION');
    for ($i = 0; $i < $rows; $i++) {
        $driver->insert()->into('bench_profiles')->set('name', 'profile' . $i)->set('score', $i % 100)->exec();
    }
    $driver->exec('COMMIT');
});

measure('Insert events (' . number_format($events) . ')', function () use ($driver, $events): void {
    $driver->exec('BEGIN TRANSACTION');
    for ($i = 0; $i < $events; $i++) {
        $driver->insert()
            ->into('bench_events')
            ->set('profile_id', ($i % 2000) + 1)
            ->set('detail', 'event' . $i)
            ->set('logged_at', time() - $i)
            ->exec();
    }
    $driver->exec('COMMIT');
});

measure('Complex join (aggregate) [10 iterations]', function () use ($driver): void {
    for ($i = 0; $i < 10; $i++) {
        $driver->rows(
            'SELECT p.name, AVG(p.score) AS avg_score, COUNT(e.id) AS events FROM bench_profiles p LEFT JOIN bench_events e ON e.profile_id = p.id WHERE p.score BETWEEN :min AND :max GROUP BY p.id LIMIT 100',
            [':min' => ($i % 100), ':max' => 99]
        );
    }
});

measure('Window + nested query [10 iterations]', function () use ($driver): void {
    for ($i = 0; $i < 10; $i++) {
        echo '.';
        $driver->rows(
            'SELECT p.id, p.name, (SELECT COUNT(*) FROM bench_events e WHERE e.profile_id = p.id) AS total_events FROM bench_profiles p ORDER BY total_events DESC LIMIT 200',
            []
        );
    }
    echo PHP_EOL;
});

$stats = $driver->getCacheStatistics();
if ($stats !== null) {
    echo "Cache statistics:\n";
    foreach ($stats->toArray() as $key => $value) {
        printf("  %s: %s\n", $key, $value);
    }
}

measure('Upsert cycle (100)', function () use ($driver): void {
    for ($i = 0; $i < 100; $i++) {
        $driver->upsert()
            ->into('bench_profiles')
            ->values(['name' => 'profile_upsert', 'score' => $i % 50])
            ->key(['name'])
            ->update(['score'])
            ->exec();
    }
});
