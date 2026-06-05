<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoload)) {
    require $autoload;
}

$baseDir = sys_get_temp_dir() . '/uda-cubrid-tests-' . getmypid();

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$configFile = $baseDir . '/uda-cubrid.json';

$config = [
    'defaults' => [
        'connection' => 'cubrid',
    ],
    'connections' => [
        'cubrid' => [
            'driver' => 'cubrid',
            'params' => [
                'host'   => getenv('CUBRID_HOST') ?: '127.0.0.1',
                'port'   => (int) (getenv('CUBRID_PORT') ?: 33000),
                'dbname' => getenv('CUBRID_DB') ?: 'testdb',
            ],
            'user' => getenv('CUBRID_USER') ?: 'dba',
            'pass' => getenv('CUBRID_PASS') ?: '',
        ],
    ],
];

file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
putenv('UDA_CONFIG=' . $configFile);

define('UDA_CUBRID_TEST_CONFIG', $configFile);
