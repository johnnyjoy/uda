<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Config
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/config/snapshot
 * @since 1.0.0
 */

/*
 * Purpose: Provides an immutable, validated snapshot of database connection configurations for UDA.
 */

namespace UDA\Config;

use PDO;
use UDA\Exception\ConfigException;

/**
 * Immutable validated configuration snapshot that stores connections and defaults
 */
final class Snapshot
{
    /** @var array Default configuration values */
    private array $defaults;

    /** @var array Connection configurations */
    private array $connections;

    /**
     *
     * @param ?string $defaultConnection The default connection name
     * @param array   $connections       The connection configurations
     * @param array   $defaults          The default configuration values
     */
    public function __construct(
        ?string $defaultConnection,
        array $connections,
        array $defaults = []
    ) {
        if ($defaultConnection === null) {
            $defaultConnection = $defaults['connection'] ?? null;
        }

        $this->defaults = $this->validateDefaults($defaults);
        $this->connections = $this->validateConnections($connections);

        $this->defaults['connection'] = $defaultConnection;
    }

    /**
     *
     * @param  array           $defaults The default configuration values
     * @return array           The validated default configuration values
     * @throws ConfigException If defaults are invalid
     */
    private function validateDefaults(array $defaults): array
    {
        if (isset($defaults['connection']) && !is_string($defaults['connection']) && $defaults['connection'] !== null) {
            throw new ConfigException('Default connection must be a string or null');
        }

        if (isset($defaults['options']) && !is_array($defaults['options'])) {
            throw new ConfigException('Default options must be an array');
        }

        return $defaults;
    }

    /**
     *
     * @param  array           $connections The connection configurations
     * @return array           The validated connection configurations
     * @throws ConfigException If connections are invalid
     */
    private function validateConnections(array $connections): array
    {
        $validated = [];

        foreach ($connections as $name => $config) {
            if (!is_array($config)) {
                throw new ConfigException("Connection '$name' must be an array");
            }

            if (!isset($config['driver']) || !is_string($config['driver'])) {
                throw new ConfigException("Connection '$name' missing 'driver' as string");
            }

            $validated[$name] = $config;
        }

        return $validated;
    }

    /**
     * Retrieves the configured default connection name.
     *
     * Returns null if no default connection was specified in the configuration.
     * The default connection is used when calling Database methods without specifying
     * a connection name.
     *
     * @return ?string The default connection name or null if unspecified
     *
     * @see Snapshot::getConnection() To access specific connection configurations
     * @see Database::connect() Database initialization with default connection
     * @example
     * if ($snapshot->getDefaultConnection() === null) {
     * throw new ConfigException("No default connection configured");
     * }
     */
    public function getDefaultConnection(): ?string
    {
        return $this->defaults['connection'] ?? null;
    }

    /**
     *
     * @return array The connection names
     */
    public function getConnectionNames(): array
    {
        return array_keys($this->connections);
    }

    /**
     *
     * @param  string $name The connection name
     * @return ?array The connection configuration or null
     */
    public function getConnection(string $name): ?array
    {
        return $this->connections[$name] ?? null;
    }

    /**
     *
     * @return array The default PDO options
     */
    public function getDefaultOptions(): array
    {
        // Base library defaults
        $base = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        // Merge user-provided defaults (if any) – user values override base
        $user = $this->defaults['options'] ?? [];

        return array_merge($base, $user);
    }

    /**
     *
     * @param  string $name The connection name
     * @return bool   True if the connection exists, false otherwise
     */
    public function hasConnection(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    /**
     *
     * @param  string          $connectionName The connection name
     * @return array           The merged PDO options
     * @throws ConfigException If the connection is not found
     */
    public function getMergedOptions(string $connectionName): array
    {
        $connection = $this->getConnection($connectionName);

        if ($connection === null) {
            throw new ConfigException("Connection '$connectionName' not found");
        }

        $defaults = $this->getDefaultOptions();
        $overrides = $connection['options'] ?? [];

        // Preserve numeric keys while allowing overrides to replace defaults
        return $overrides + $defaults;
    }
}
