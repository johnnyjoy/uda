#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Optional wall-clock probe for prepared-statement LRU (production code path only).
 *
 * Measures repeated value() calls with LRU enabled — the same behavior as runtime.
 * Compare before/after LRU changes by running this script on each revision; there
 * is no in-process bypass switch in Driver.
 *
 * Usage (PHP >= 8.2, from repo root):
 *   php tools/benchmark-prepared-lru.php
 *   UDA_BENCHMARK_ITERATIONS=200000 php tools/benchmark-prepared-lru.php
 *
 * Env:
 *   UDA_CONFIG                 Optional. If unset, a temporary SQLite config is created.
 *   UDA_BENCHMARK_CONN         Connection name (default: lr_bench or defaults.connection).
 *   UDA_BENCHMARK_ITERATIONS   Default 100000.
 *   UDA_BENCHMARK_WARMUP       Default 500.
 *   UDA_BENCHMARK_DISTINCT     Default 8 (number of literal SELECT shapes rotated).
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Run from repo root with composer install (missing vendor/autoload.php).\n");
    exit(1);
}

require $autoload;

use UDA\Config;
use UDA\Database;

$iterations = max(1000, (int) (getenv('UDA_BENCHMARK_ITERATIONS') ?: 100_000));
$warmup = max(0, (int) (getenv('UDA_BENCHMARK_WARMUP') ?: 500));
$distinct = max(1, min(64, (int) (getenv('UDA_BENCHMARK_DISTINCT') ?: 8)));

$existing = getenv('UDA_CONFIG');

if (is_string($existing) && $existing !== '' && is_file($existing)) {
    $configFile = $existing;
    $conn = getenv('UDA_BENCHMARK_CONN');

    if ($conn === false || $conn === '') {
        $conn = 'default';
    }
} else {
    $base = sys_get_temp_dir() . '/uda-bench-lru-' . bin2hex(random_bytes(4));
    mkdir($base, 0777, true);
    $dbPath = $base . '/bench.sqlite';
    $config = [
        'defaults' => ['connection' => 'lr_bench'],
        'connections' => [
            'lr_bench' => [
                'driver' => 'sqlite',
                'params' => ['path' => $dbPath],
            ],
        ],
    ];
    $configFile = $base . '/uda.json';
    file_put_contents($configFile, json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    putenv('UDA_CONFIG=' . $configFile);
    $conn = 'lr_bench';
}

Config::init($configFile);

$queries = [];

for ($i = 0; $i < $distinct; $i++) {
    $queries[] = 'SELECT ' . $i . ' AS n';
}

$db = Database::connect($conn, $configFile);
$qCount = count($queries);

for ($i = 0; $i < $warmup; $i++) {
    $db->value($queries[$i % $qCount]);
}

$t0 = hrtime(true);

for ($i = 0; $i < $iterations; $i++) {
    $db->value($queries[$i % $qCount]);
}

$sec = (hrtime(true) - $t0) / 1e9;
$ops = $iterations / $sec;

fwrite(STDOUT, "Prepared statement LRU timing (production path)\n");
fwrite(STDOUT, sprintf("  config: %s\n", $configFile));
fwrite(STDOUT, sprintf("  connection: %s\n", $conn));
fwrite(STDOUT, sprintf("  iterations: %d  warmup: %d  distinct SQL: %d\n", $iterations, $warmup, $distinct));
fwrite(STDOUT, sprintf("  elapsed: %8.4fs  (%s ops/sec)\n", $sec, number_format($ops, 0, '.', '')));
fwrite(STDOUT, "\nCompare across git revisions or DB targets; Driver has no LRU bypass env var.\n");

exit(0);
