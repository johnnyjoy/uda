<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use UDA\Core\ConfigLoader;
use UDA\Exception\ConfigException;

try {
    // Test loading a config file
    $config = ConfigLoader::load(__DIR__ . '/config/example-config.json');
    echo "Config loaded successfully!\n";
    echo "Connections: " . implode(', ', array_keys($config['connections'])) . "\n";

    // Test DSN building
    $connection = $config['connections']['main_pg'];
    $dsn = ConfigLoader::getDsn($connection);
    echo "PG DSN: " . $dsn . "\n";

    $connection = $config['connections']['audit_sqlite'];
    $dsn = ConfigLoader::getDsn($connection);
    echo "SQLite DSN: " . $dsn . "\n";

    echo "All tests passed!\n";
} catch (ConfigException $e) {
    echo "Config error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
