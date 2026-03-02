<?php

declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  Config
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/config/validator
 * @since       1.0.0
 *
 * This file validates JSON configuration files for database connections, ensuring
 * structural correctness, proper data types, and required fields. It performs
 * comprehensive validation on connection configurations, default settings, and
 * driver-specific parameters, producing validated Snapshot objects that guarantee
 * configuration integrity throughout the UDA system. The validator prevents
 * runtime errors by catching configuration issues early in the setup process.
 */

namespace UDA\Config;

use UDA\Exception\ConfigException;

/**
 * Config validator that ensures JSON structure and types are correct
 */
final class Validator
{
    /** @var array Validation errors */
    private array $errors = [];
    
    /**
     * 
     * @param array $config The configuration to validate
     * @return Snapshot The validated configuration snapshot
     * @throws ConfigException If validation fails
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
        
        return new Snapshot(
            $defaults['connection'] ?? null,
            $connections,
            $defaults
        );
    }
    
    /**
     * 
     * @param array $config The configuration to validate
     * @return void
     */
    private function validateTopLevel(array $config): void
    {
        if (!isset($config['connections']) || !is_array($config['connections'])) {
            $this->errors[] = "Config must have 'connections' array";
        }
    }
    
    /**
     * 
     * @param array $config The configuration to validate
     * @return array The validated default configuration values
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
        
        return $defaults;
    }
    
    /**
     * 
     * @param array $config The configuration to validate
     * @return array The validated connection configurations
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
            
            $validated[$name] = $connection;
        }
        
        return $validated;
    }
}
