<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoload)) {
    require $autoload;
}

$baseDir = sys_get_temp_dir() . '/uda-tests-' . getmypid();

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$configFile = $baseDir . '/uda.json';
$connections = [
    'alpha' => $baseDir . '/alpha.sqlite',
    'beta' => $baseDir . '/beta.sqlite',
    'cached' => $baseDir . '/cached.sqlite',
    'cached_strict' => $baseDir . '/cached_strict.sqlite',
    'opt_override' => $baseDir . '/opt_override.sqlite',
];

$config = [
    'defaults' => [
        'connection' => 'alpha',
    ],
    'connections' => [
        'alpha' => [
            'driver' => 'sqlite',
            'params' => ['path' => $connections['alpha']],
            'init_sql' => [
                'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
            ],
        ],
        'beta' => [
            'driver' => 'sqlite',
            'params' => ['path' => $connections['beta']],
            'init_sql' => [
                'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
            ],
        ],
        'cached' => [
            'driver' => 'sqlite',
            'params' => ['path' => $connections['cached']],
            'init_sql' => [
                'CREATE TABLE IF NOT EXISTS cache_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
            ],
            'cache' => [
                'namespace' => 'UDA_TEST',
                'store' => ['type' => 'array'],
            ],
        ],
        'cached_strict' => [
            'driver' => 'sqlite',
            'params' => ['path' => $connections['cached_strict']],
            'init_sql' => [
                'CREATE TABLE IF NOT EXISTS cache_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
            ],
            'cache' => [
                'namespace' => 'UDA_TEST_STRICT',
                'store' => ['type' => 'array'],
                'require_table_hints' => true,
            ],
        ],
        'opt_override' => [
            'driver' => 'sqlite',
            'options' => [PDO::ATTR_PERSISTENT => false],
            'params' => ['path' => $connections['opt_override']],
            'init_sql' => [
                'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
            ],
        ],
    ],
];

file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
putenv('UDA_CONFIG=' . $configFile);

define('UDA_TEST_CONFIG', $configFile);
define('UDA_TEST_BASE_DIR', $baseDir);
