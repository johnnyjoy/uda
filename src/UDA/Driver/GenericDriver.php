<?php

declare(strict_types=1);

/**
 * Generic database driver fallback implementation. Provides basic database connectivity for unsupported or experimental database drivers, implementing core Driver functionality with minimal assumptions about database-specific features while maintaining the core UDA execution contract.
 *
 * PURPOSE: Generic database driver fallback implementation. Provides basic database connectivity for unsupported or experimental database drivers, implementing core Driver functionality with minimal assumptions about database-specific features while maintaining the core UDA execution contract
 */

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
