<?php

declare(strict_types=1);

echo "Testing basic PHP functionality\n";

// Test if we can include files directly
include_once __DIR__ . '/src/UDA/ConfigLoader.php';
include_once __DIR__ . '/src/UDA/Exceptions/DatabaseException.php';
include_once __DIR__ . '/src/UDA/Exceptions/ConnectionException.php';
include_once __DIR__ . '/src/UDA/Exceptions/ConfigException.php';

use UDA\ConfigLoader;

echo "Classes loaded successfully\n";