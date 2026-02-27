<?php

declare(strict_types=1);

/** @purpose Immutable validated configuration snapshot - stores connections and defaults */

namespace UDA\Config;

use UDA\Exception\ConfigException;
use PDO;

final class Snapshot
{
    private array $defaults;
    private array $connections;
    
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
    
    public function getDefaultConnection(): ?string
    {
        return $this->defaults['connection'] ?? null;
    }
    
    public function getConnectionNames(): array
    {
        return array_keys($this->connections);
    }
    
    public function getConnection(string $name): ?array
    {
        return $this->connections[$name] ?? null;
    }
    
    public function getDefaultOptions(): array
    {
        // Base library defaults
        $base = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];
        // Merge user-provided defaults (if any) – user values override base
        $user = $this->defaults['options'] ?? [];
        return array_merge($base, $user);
    }
    
    public function hasConnection(string $name): bool
    {
        return isset($this->connections[$name]);
    }
    
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
