<?php

require_once 'vendor/autoload.php';

use UDA\Core\ConfigLoader;
use UDA\Exception\ConfigException;

echo "Testing ConfigLoader...\n";

try {
    // Test with example config
    $config = ConfigLoader::load(__DIR__ . '/config/example-config.json');
    echo "Config loaded successfully\n";
    print_r($config);
} catch (ConfigException $e) {
    echo "ConfigException: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

echo "Test complete.\n";
