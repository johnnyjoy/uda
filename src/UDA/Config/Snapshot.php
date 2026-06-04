<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Config
 * @license MIT
 * @link https://github.com/johnnyjoy/uda/blob/master/docs/configuration.md
 * @since 1.0.0
 */

/*
 * Purpose: Stores validated configuration data for the active UDA process.
 *
 * Snapshot is immutable after construction and is read only through Config.
 * It normalizes default connection behavior and keeps connection definitions
 * indexed by connection name.
 */

namespace UDA\Config;

use PDO;
use UDA\Driver;
use UDA\Exception\ConfigException;

/**
 * Immutable validated configuration snapshot.
 */
final class Snapshot
{
    /** @var array Default configuration values */
    private array $defaults;

    /** @var array Connection configurations */
    private array $connections;

    /**
     * Create a validated snapshot from connection and default arrays.
     *
     * @param array<string,array<string,mixed>> $connections  Connection configurations keyed by name.
     * @param array<string,mixed>               $defaults     Default configuration values.
     */
    public function __construct(
        array $connections,
        array $defaults = [],
    ) {
        $this->defaults = $this->validateDefaults($defaults);
        $this->connections = $this->validateConnections($connections);

        $this->defaults['connection'] = $this->defaults['connection'] ?? 'default';
    }

    /**
     * Validate default values before storing them in the snapshot.
     *
     * @param array<string,mixed> $defaults  The default configuration values.
     *
     * @return array<string,mixed> The validated default configuration values.
     *
     * @throws ConfigException If defaults are invalid.
     */
    private function validateDefaults(array $defaults): array
    {
        if (isset($defaults['connection']) && !is_string($defaults['connection']) && $defaults['connection'] !== null) {
            throw new ConfigException('Default connection must be a string or null');
        }

        if (isset($defaults['options']) && !is_array($defaults['options'])) {
            throw new ConfigException('Default options must be an array');
        }

        if (isset($defaults['persistent']) && !is_bool($defaults['persistent'])) {
            throw new ConfigException('Default persistent must be a boolean');
        }

        return $defaults;
    }

    /**
     * Validate named connection configurations.
     *
     * @param array<string,mixed> $connections  The connection configurations.
     *
     * @return array<string,array<string,mixed>> The validated connection configurations.
     *
     * @throws ConfigException If connections are invalid.
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

            if (isset($config['transport']) && !is_string($config['transport'])) {
                throw new ConfigException("Connection '$name' 'transport' must be a string");
            }

            if (isset($config['persistent']) && !is_bool($config['persistent'])) {
                throw new ConfigException("Connection '$name' 'persistent' must be a boolean");
            }

            $rawDriver = $config['driver'];
            $rawTransport = isset($config['transport']) ? $config['transport'] : null;

            if (!empty($config['trace'])) {
                Driver::warnDriverAlias($name, $rawDriver, $rawTransport);
            }

            [$engine, $transport] = Driver::resolveEngineTransport($rawDriver, $rawTransport);

            $config['engine'] = $engine;
            $config['transport'] = $transport;
            $config['driver'] = $engine;

            $validated[$name] = $config;
        }

        return $validated;
    }

    /**
     * Retrieves the configured default connection name.
     * Returns null if no default connection was specified in the configuration.
     * The default connection is used when calling Database methods without specifying
     * a connection name.
     * if ($snapshot->getDefaultConnection() === null) {
     * throw new ConfigException("No default connection configured");
     * }
     *
     * @return ?string The default connection name or null if unspecified
     *
     * @see Snapshot::getConnection() To access specific connection configurations
     * @see Database::connect() Database initialization with default connection
     * @example
     */
    public function getDefaultConnection(): ?string
    {
        return $this->defaults['connection'] ?? null;
    }

    /**
     * @return array The connection names
     */
    public function getConnectionNames(): array
    {
        return array_keys($this->connections);
    }

    /**
     * @param string $name  The connection name
     *
     * @return ?array The connection configuration or null
     */
    public function getConnection(?string $name = 'default'): ?array
    {
        $name = $name ?? $this->getDefaultConnection() ?? 'default';

        return $this->connections[$name] ?? null;
    }

    /**
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
     * @param string $name  The connection name
     *
     * @return bool True if the connection exists, false otherwise
     */
    public function hasConnection(?string $name = 'default'): bool
    {
        return isset($this->connections[$name]);
    }

    /**
     * Return merged options.
     *
     * @param ?string $name            Name value.
     * @param string  $connectionName  The connection name
     *
     * @return array The merged PDO options
     *
     * @throws ConfigException If the connection is not found
     */
    public function getMergedOptions(?string $name = 'default'): array
    {
        $conn = $this->getConnection($name);

        if ($conn === null) {
            throw new ConfigException("Connection '$name' not found");
        }

        $defaults = $this->getDefaultOptions();
        $overrides = $conn['options'] ?? [];

        // Preserve numeric keys while allowing overrides to replace defaults
        return $overrides + $defaults;
    }

    /**
     * Whether persistent PDO connections are used for a connection.
     *
     * Persistent connections are the default. Resolution: the connection's own
     * `persistent` flag, then `defaults.persistent`, then true. Set
     * `"persistent": false` to opt a connection (or all connections, via
     * defaults) out. This is sugar for `PDO::ATTR_PERSISTENT`; an explicit
     * `options[PDO::ATTR_PERSISTENT]` still takes precedence at the Driver layer.
     *
     * @param ?string $name  The connection name.
     *
     * @return bool
     */
    public function getPersistent(?string $name = 'default'): bool
    {
        $conn = $this->getConnection($name);

        if (is_array($conn) && isset($conn['persistent'])) {
            return (bool) $conn['persistent'];
        }

        return (bool) ($this->defaults['persistent'] ?? true);
    }
}
