<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoload)) {
    require $autoload;
}

$baseDir = sys_get_temp_dir() . '/uda-sqlserver-tests-' . getmypid();

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$configFile = $baseDir . '/uda-sqlserver.json';

$config = [
    'defaults' => [
        'connection' => 'mssql',
    ],
    'connections' => [
        'mssql' => [
            'driver' => 'sqlserver',
            'transport' => 'sqlsrv',
            'params' => [
                'host' => getenv('MSSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('MSSQL_PORT') ?: 1433),
                'dbname' => getenv('MSSQL_DATABASE') ?: 'master',
                'trust_server_certificate' => true,
            ],
            'user' => getenv('MSSQL_USER') ?: 'sa',
            'pass' => getenv('MSSQL_PASSWORD') ?: 'Your_Str0ng!Passw0rd123',
            'init_sql' => [
                <<<'SQL'
IF OBJECT_ID(N'dbo.uda_mssql_cert', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.uda_mssql_cert (
        id INT NOT NULL PRIMARY KEY,
        name NVARCHAR(100) NOT NULL
    );
END
SQL,
            ],
        ],
    ],
];

file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
putenv('UDA_CONFIG=' . $configFile);

define('UDA_SQLSERVER_TEST_CONFIG', $configFile);
