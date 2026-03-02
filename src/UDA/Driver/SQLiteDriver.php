<?php

declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  Driver
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/driver/sqlite
 * @since       1.0.0
 *
 * SQLite driver implementation
 */

namespace UDA\Driver;

use PDO;
use UDA\Cache\Setup;
use UDA\Driver as BaseDriver;

/**
 * SQLite driver implementation that extends the base Driver
 */
class SQLiteDriver extends BaseDriver
{
    /**
     * 
     * @param PDO $pdo The PDO connection
     * @param string $driverName The driver name
     * @param array $connectionConfig The connection configuration
     * @param string $connectionName The connection name
     * @param ?Setup $cacheSetup The cache setup
     */
    public function __construct(PDO $pdo, string $driverName, array $connectionConfig, string $connectionName, ?Setup $cacheSetup = null)
    {
        parent::__construct($pdo, $driverName, $connectionConfig, $connectionName, $cacheSetup);
    }
}
