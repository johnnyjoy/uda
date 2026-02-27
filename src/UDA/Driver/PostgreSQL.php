<?php

declare(strict_types=1);

/** @purpose PostgreSQL driver - handles PostgreSQL-specific DSN building and dialect */

namespace UDA\Driver;

use PDO;
use UDA\Driver;
use UDA\Driver\SqlHelper;
use UDA\Cache\Setup;

final class PostgreSQL extends Driver
{
    /**
     * PostgreSQL DSN format: pgsql:host=hostname;dbname=database;port=5432
     */
    protected function buildDsn(array $params): string
    {
        $parts = ['pgsql'];
        
        if (isset($params['host'])) {
            $parts[] = 'host=' . $params['host'];
        }
        if (isset($params['dbname'])) {
            $parts[] = 'dbname=' . $params['dbname'];
        }
        if (isset($params['port'])) {
            $parts[] = 'port=' . $params['port'];
        }
        
        return implode(';', $parts);
    }
    
    /**
     * PostgreSQL uses RETURNING for INSERT...RETURNING
     */
    public function buildReturningSql(string $table, array $columns): string
    {
        $quotedCols = array_map([$this, 'q'], $columns);
        return ' RETURNING ' . implode(', ', $quotedCols);
    }
    
    /**
     * PostgreSQL supports SAVEPOINTs natively
     */
    protected function createSavepointName(): string
    {
        $this->savepointCounter++;
        return 'uda_sp_' . $this->savepointCounter;
    }
}
