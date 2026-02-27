<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use UDA\ConnectionManager;
use UDA\Exceptions\ConfigException;

// Set up the environment variable to point to our config
putenv('UDA_CONFIG=' . __DIR__ . '/config/example-config.json');

try {
    // Create connection manager from environment
    $manager = ConnectionManager::fromEnv();

    echo "Connection manager created successfully!\n";

    // Get connection names
    $names = $manager->getConnectionNames();
    echo "Available connections: " . implode(', ', $names) . "\n";

    echo "Configuration system is working correctly!\n";

} catch (ConfigException $e) {
    echo "Configuration error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
