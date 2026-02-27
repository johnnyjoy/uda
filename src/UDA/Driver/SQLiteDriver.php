<?php

declare(strict_types=1);

/** @purpose UDA\Driver\SQLiteDriver: Add detailed purpose here */

namespace UDA\Driver;

use PDO;
use UDA\Cache\Setup;
use UDA\Driver as BaseDriver;

class SQLiteDriver extends BaseDriver
{
    public function __construct(PDO $pdo, string $driverName, array $connectionConfig, string $connectionName, ?Setup $cacheSetup = null)
    {
        parent::__construct($pdo, $driverName, $connectionConfig, $connectionName, $cacheSetup);
    }
}
