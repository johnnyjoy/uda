<?php

declare(strict_types=1);

/** @purpose Cache-Driver integration bridge - keeps cache logic out of Driver */

namespace UDA\Driver;

use UDA\Cache\Setup;
use UDA\Cache\Infra;
use UDA\Cache\Statistics;
use UDA\Cache\Scope;

final class CacheBridge
{
    private ?Infra $infra;
    private ?Scope $scope;
    private ?Statistics $statistics;
    private string $connectionName;
    
    public function __construct(string $connectionName, ?Setup $setup = null)
    {
        $this->connectionName = $connectionName;
        if ($setup !== null) {
            $this->infra = $setup->getInfra();
            $this->scope = $this->infra->buildScope($this);
            $this->statistics = $setup->getStatistics();
        }
    }
    
    public function hasCache(): bool
    {
        return $this->scope !== null;
    }
    
    public function getScope(): ?Scope
    {
        return $this->scope;
    }
    
    public function getStatistics(): ?Statistics
    {
        return $this->statistics;
    }
    
    public function touchTables(array $tables): void
    {
        if ($this->infra === null) {
            return;
        }
        
        foreach ($tables as $table) {
            $this->infra->getTracker()->touch($this->connectionName, $table);
        }
    }
    
    public function readThrough(string $method, string $sql, array $params, ?array $tables): mixed
    {
        if ($this->scope === null) {
            throw new QueryException('Caching not configured');
        }
        
        return $this->scope->{$method}($sql, $params, $tables);
    }
}
