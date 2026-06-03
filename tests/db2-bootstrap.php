<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoload)) {
    require $autoload;
}

$baseDir = sys_get_temp_dir() . '/uda-db2-tests-' . getmypid();

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$configFile = $baseDir . '/uda-db2.json';

$config = [
    'defaults' => [
        'connection' => 'db2',
    ],
    'connections' => [
        'db2' => [
            'driver' => 'db2',
            'params' => [
                'host' => getenv('DB2_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB2_PORT') ?: 50000),
                'dbname' => getenv('DB2_DATABASE') ?: 'testdb',
            ],
            'user' => getenv('DB2_USER') ?: 'db2inst1',
            'pass' => getenv('DB2_PASSWORD') ?: '',
        ],
    ],
];

file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
putenv('UDA_CONFIG=' . $configFile);

define('UDA_DB2_TEST_CONFIG', $configFile);
