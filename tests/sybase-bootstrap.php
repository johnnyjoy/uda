<?php

declare(strict_types=1);

/**
 * Bootstrap for Sybase live integration tests (disabled unless UDA_SYBASE_LIVE=1).
 *
 *   UDA_SYBASE_LIVE=1 vendor/bin/phpunit --bootstrap tests/sybase-bootstrap.php tests/Sybase
 */

$autoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoload)) {
    require $autoload;
}

$baseDir = sys_get_temp_dir() . '/uda-sybase-tests-' . getmypid();

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$configFile = $baseDir . '/uda-sybase.json';

$config = [
    'defaults' => [
        'connection' => 'sybase',
    ],
    'connections' => [
        'sybase' => [
            'driver' => 'sybase',
            'transport' => 'dblib',
            'params' => [
                'host' => getenv('SYBASE_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('SYBASE_PORT') ?: 5000),
                'dbname' => getenv('SYBASE_DATABASE') ?: 'master',
            ],
            'user' => getenv('SYBASE_USER') ?: 'sa',
            'pass' => getenv('SYBASE_PASSWORD') ?: 'Sybase_UDA_CI1',
            'init_sql' => [
                <<<'SQL'
IF OBJECT_ID(N'dbo.uda_sybase_ig', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.uda_sybase_ig (
        id INT NOT NULL PRIMARY KEY,
        name NVARCHAR(100) NOT NULL,
        score INT NOT NULL DEFAULT 0
    );
END
SQL,
            ],
        ],
    ],
];

file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
putenv('UDA_CONFIG=' . $configFile);

define('UDA_SYBASE_TEST_CONFIG', $configFile);
