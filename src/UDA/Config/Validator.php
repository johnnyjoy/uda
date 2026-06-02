<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Config
 * @author James Dornan <james.dornan@uda.example.com>
 * @license MIT
 * @link https://docs.uda.example.com/config/validator
 * @since 1.0.0
 */

/*
 * Purpose: Validates decoded UDA configuration before Snapshot creation.
 *
 * Validator checks the root shape, default connection settings, and connection
 * entries so Config can publish one immutable process-wide snapshot.
 */

namespace UDA\Config;

use UDA\Exception\ConfigException;

/**
 * Validator for decoded configuration arrays.
 */
final class Validator
{
    /** @var array Validation errors */
    private array $errors = [];

    /**
     * Validate decoded config and return an immutable snapshot.
     *
     * @param array<string,mixed> $config  The decoded configuration.
     *
     * @return Snapshot The validated configuration snapshot.
     *
     * @throws ConfigException If validation fails.
     */
    public function validate(array $config): Snapshot
    {
        $this->errors = [];

        $this->validateTopLevel($config);

        $defaults = $this->validateAndExtractDefaults($config);
        $connections = $this->validateConnections($config);

        if (!empty($this->errors)) {
            throw new ConfigException('Validation failed: ' . implode(', ', $this->errors));
        }

        return new Snapshot($connections, $defaults);
    }

    /**
     * Ensure the root config has a connections map.
     *
     * @param array<string,mixed> $config  The decoded configuration.
     *
     * @return void No return value.
     */
    private function validateTopLevel(array $config): void
    {
        if (!isset($config['connections']) || !is_array($config['connections'])) {
            $this->errors[] = "Config must have 'connections' array";
        }
    }

    /**
     * Extract defaults and support the legacy top-level default key.
     *
     * @param array<string,mixed> $config  The decoded configuration.
     *
     * @return array<string,mixed> The validated default configuration values.
     */
    private function validateAndExtractDefaults(array $config): array
    {
        $defaults = $config['defaults'] ?? [];

        if (!is_array($defaults)) {
            $this->errors[] = "'defaults' must be an array if present";

            return [];
        }

        if (isset($defaults['connection']) && !is_string($defaults['connection']) && $defaults['connection'] !== null) {
            $this->errors[] = "defaults.connection must be string or null";
        }

        if (isset($defaults['options']) && !is_array($defaults['options'])) {
            $this->errors[] = "defaults.options must be an array";
        }

        if (isset($config['default']) && is_string($config['default']) && !isset($defaults['connection'])) {
            $defaults['connection'] = $config['default'];
        }

        return $defaults;
    }

    /**
     * Validate named connection entries.
     *
     * @param array<string,mixed> $config  The decoded configuration.
     *
     * @return array<string,array<string,mixed>> The validated connection configurations.
     */
    private function validateConnections(array $config): array
    {
        $connections = $config['connections'];
        $validated = [];

        foreach ($connections as $name => $connection) {
            if (!is_array($connection)) {
                $this->errors[] = "Connection '$name' must be an array";
                continue;
            }

            if (!isset($connection['driver']) || !is_string($connection['driver'])) {
                $this->errors[] = "Connection '$name' must have 'driver' as string";
                continue;
            }

            if (isset($connection['transport']) && !is_string($connection['transport'])) {
                $this->errors[] = "Connection '$name' 'transport' must be a string";
                continue;
            }

            if (isset($connection['cache']) && is_array($connection['cache'])) {
                $requireHints = $connection['cache']['require_table_hints'] ?? null;
                if ($requireHints !== null && !is_bool($requireHints)) {
                    $this->errors[] = "Connection '$name' cache.require_table_hints must be boolean";
                }
            }

            $validated[$name] = $connection;
        }

        return $validated;
    }
}
