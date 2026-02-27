<?php
declare(strict_types=1);

/**
 * @purpose Compatibility shim – creates concrete Driver instances.
 *
 * The original library used a DriverFactory class to encapsulate driver
 * instantiation logic.  The refactor removed it, but other parts of the code
 * (Database::createDriver) still reference `DriverFactory::create`.  This file
 * restores that functionality in a minimal form sufficient for the test
 * suite.
 */

namespace UDA;

use PDO;
use UDA\Cache\Setup;

final class DriverFactory
{
    /**
     * @purpose Instantiate a concrete driver based on the driver name.
     *
     * @param string      $driverName        e.g. 'sqlite', 'pgsql', etc.
     * @param PDO         $pdo               Established PDO connection.
     * @param array       $connectionConfig  Configuration array for the connection.
     * @param string      $connectionName    Name of the connection.
     * @param Setup|null  $cacheSetup        Optional cache configuration.
     *
     * @return Driver The instantiated driver (subclass of abstract Driver).
     */
    public static function create(string $driverName, PDO $pdo, array $connectionConfig, string $connectionName, ?Setup $cacheSetup = null): Driver
    {
        return match ($driverName) {
            'sqlite' => new Driver\SQLite($pdo, $driverName, $connectionConfig, $connectionName, $cacheSetup),
            'pgsql', 'postgresql' => new Driver\PostgreSQL($pdo, $driverName, $connectionConfig, $connectionName, $cacheSetup),
            'sqlsrv', 'mssql', 'sqlserver' => new Driver\SQLServer($pdo, $driverName, $connectionConfig, $connectionName, $cacheSetup),
            'mysql', 'mariadb' => new Driver\MariaDB($pdo, $driverName, $connectionConfig, $connectionName, $cacheSetup),
            'dblib' => new Driver\Dblib($pdo, $driverName, $connectionConfig, $connectionName, $cacheSetup),
            default => new Driver\GenericDriver($pdo, $driverName, $connectionConfig, $connectionName, $cacheSetup),
        };
    }
}
