<?php

declare(strict_types=1);

/** @purpose UDA\Driver\GenericDriver: Add detailed purpose here */

namespace UDA\Driver;

use PDO;
use UDA\Cache\Setup;
use UDA\Driver as BaseDriver;

class GenericDriver extends BaseDriver
{
    public function __construct(PDO $pdo, string $driverName, array $connectionConfig, string $connectionName, ?Setup $cacheSetup = null)
    {
        parent::__construct($pdo, $driverName, $connectionConfig, $connectionName, $cacheSetup);
    }
}
