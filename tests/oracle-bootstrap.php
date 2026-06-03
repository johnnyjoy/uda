<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoload)) {
    require $autoload;
}

$baseDir = sys_get_temp_dir() . '/uda-oracle-tests-' . getmypid();

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$configFile = $baseDir . '/uda-oracle.json';

$config = [
    'defaults' => [
        'connection' => 'oracle',
    ],
    'connections' => [
        'oracle' => [
            'driver' => 'oracle',
            'params' => [
                'host' => getenv('UDA_ORACLE_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('UDA_ORACLE_PORT') ?: 1521),
                'service' => getenv('UDA_ORACLE_SERVICE') ?: 'FREEPDB1',
            ],
            'user' => getenv('UDA_ORACLE_USER') ?: 'uda_test',
            'pass' => getenv('UDA_ORACLE_PASSWORD') ?: 'uda_test_pw',
        ],
    ],
];

file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
putenv('UDA_CONFIG=' . $configFile);

define('UDA_ORACLE_TEST_CONFIG', $configFile);
