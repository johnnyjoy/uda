<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoload)) {
    require $autoload;
}

$baseDir = sys_get_temp_dir() . '/uda-postgres-tests-' . getmypid();

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$configFile = $baseDir . '/uda-postgres.json';

$config = [
    'defaults' => [
        'connection' => 'pgsql',
    ],
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'params' => [
                'host' => getenv('PGHOST') ?: '127.0.0.1',
                'port' => (int) (getenv('PGPORT') ?: 5432),
                'dbname' => getenv('PGDATABASE') ?: 'testdb',
            ],
            'user' => getenv('PGUSER') ?: 'postgres',
            'pass' => getenv('PGPASSWORD') ?: 'postgres',
        ],
    ],
];

file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
putenv('UDA_CONFIG=' . $configFile);

define('UDA_POSTGRES_TEST_CONFIG', $configFile);
