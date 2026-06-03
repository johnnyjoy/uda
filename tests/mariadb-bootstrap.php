<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoload)) {
    require $autoload;
}

$baseDir = sys_get_temp_dir() . '/uda-mariadb-tests-' . getmypid();

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$configFile = $baseDir . '/uda-mariadb.json';

$config = [
    'defaults' => [
        'connection' => 'mariadb',
    ],
    'connections' => [
        'mariadb' => [
            'driver' => 'mariadb',
            'params' => [
                'host' => getenv('MARIADB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('MARIADB_PORT') ?: 3306),
                'dbname' => getenv('MARIADB_DATABASE') ?: 'testdb',
            ],
            'user' => getenv('MARIADB_USER') ?: 'root',
            'pass' => getenv('MARIADB_PASSWORD') ?: 'root',
        ],
    ],
];

file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
putenv('UDA_CONFIG=' . $configFile);

define('UDA_MARIADB_TEST_CONFIG', $configFile);
