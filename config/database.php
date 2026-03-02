<?php

declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  Config
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/config/database
 * @since       1.0.0
 *
 * Database Configuration
 *
 * This file contains database connection configuration for the Universal Data Abstraction system.
 * Connection information is loaded from a configuration file specified by an environment variable.
 */

namespace UniversalDataAbstraction\Config;

/**
 * Load database configuration from a file specified by an environment variable
 *
 * @return array Configuration array with database connection details
 */
function loadDatabaseConfig(): array
{
    // Get the config file path from environment variable
    $configPath = $_ENV['DB_CONFIG_PATH'] ?? __DIR__ . '/database.json';

    // Check if file exists
    if (!file_exists($configPath)) {
        throw new \RuntimeException("Database configuration file not found at: {$configPath}");
    }

    // Read and decode the JSON configuration
    $configContent = file_get_contents($configPath);
    if ($configContent === false) {
        throw new \RuntimeException("Failed to read database configuration file: {$configPath}");
    }

    $config = json_decode($configContent, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \RuntimeException("Invalid JSON in database configuration file: " . json_last_error_msg());
    }

    return $config;
}

/**
 * Generate PDO DSN string from configuration
 *
 * @param array $config Database configuration
 * @param string $connectionName Connection name
 * @return string PDO DSN string
 */
function generateDsn(array $config, string $connectionName): string
{
    $connection = $config['connections'][$connectionName] ?? null;

    if (!$connection) {
        throw new \InvalidArgumentException("Database connection '{$connectionName}' not found");
    }

    $driver = $connection['driver'];

    switch ($driver) {
        case 'mysql':
            return "mysql:host={$connection['host']};port={$connection['port']};dbname={$connection['database']};charset={$connection['charset']}";

        case 'pgsql':
            return "pgsql:host={$connection['host']};port={$connection['port']};dbname={$connection['database']};charset={$connection['charset']}";

        case 'sqlite':
            return "sqlite:{$connection['database']}";

        case 'sqlsrv':
            return "sqlsrv:Server={$connection['server']};Database={$connection['database']}";

        case 'oci':
            return "oci:dbname=//{$connection['host']}:{$connection['port']}/{$connection['database']};charset={$connection['charset']}";

        default:
            throw new \InvalidArgumentException("Unsupported database driver: {$driver}");
    }
}

/**
 * Create PDO connection from configuration
 *
 * @param array $config Database configuration
 * @param string $connectionName Connection name
 * @return \PDO PDO connection object
 */
function createConnection(array $config, string $connectionName): \PDO
{
    $connection = $config['connections'][$connectionName] ?? null;

    if (!$connection) {
        throw new \InvalidArgumentException("Database connection '{$connectionName}' not found");
    }

    $dsn = generateDsn($config, $connectionName);
    $username = $connection['username'] ?? '';
    $password = $connection['password'] ?? '';

    $options = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ];

    return new \PDO($dsn, $username, $password, $options);
}