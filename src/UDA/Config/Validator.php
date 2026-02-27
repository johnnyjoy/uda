<?php

declare(strict_types=1);

/** @purpose Config validation - ensures JSON structure and types are correct */

namespace UDA\Config;

use UDA\Exception\ConfigException;

final class Validator
{
    private array $errors = [];
    
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
    
    private function validateTopLevel(array $config): void
    {
        if (!isset($config['connections']) || !is_array($config['connections'])) {
            $this->errors[] = "Config must have 'connections' array";
        }
    }
    
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
