<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoload)) {
    require $autoload;
}

$baseDir = sys_get_temp_dir() . '/uda-firebird-tests-' . getmypid();

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$configFile = $baseDir . '/uda-firebird.json';

$config = [
    'defaults' => [
        'connection' => 'firebird',
    ],
    'connections' => [
        'firebird' => [
            'driver' => 'firebird',
            'params' => [
                'host' => getenv('FIREBIRD_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('FIREBIRD_PORT') ?: 3050),
                'database' => getenv('FIREBIRD_DATABASE') ?: '/var/lib/firebird/data/uda_test.fdb',
            ],
            'user' => getenv('FIREBIRD_USER') ?: 'uda_test',
            'pass' => getenv('FIREBIRD_PASSWORD') ?: '',
        ],
    ],
];

file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
putenv('UDA_CONFIG=' . $configFile);

define('UDA_FIREBIRD_TEST_CONFIG', $configFile);
