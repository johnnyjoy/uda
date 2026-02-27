<?php

declare(strict_types=1);

/** @purpose SQL Server driver (via sqlsrv extension) - handles MSSQL-specific DSN and dialect */

namespace UDA\Driver;

use PDO;
use UDA\Driver;
use UDA\Cache\Setup;

final class SQLServer extends Driver
{
    /**
     * SQL Server uses sqlsrv extension format
     * sqlsrv:Server=hostname,port;Database=dbname
     */
    protected function buildDsn(array $params): string
    {
        $server = $params['host'] ?? 'localhost';
        $port = isset($params['port']) ? ',' . $params['port'] : '';
        $dbname = $params['dbname'] ?? '';
        
        $dsn = "sqlsrv:Server={$server}{$port}";
        
        if ($dbname) {
            $dsn .= ";Database={$dbname}";
        }
        
        return $dsn;
    }
    
    /**
     * SQL Server uses OUTPUT INSERTED for returning
     */
    public function buildReturningSql(string $table, array $columns): string
    {
        $quotedCols = array_map([$this, 'q'], $columns);
        return ' OUTPUT INSERTED.' . implode(', INSERTED.', $quotedCols);
    }
    
    /**
     * SQL Server supports SAVEPOINTs
     */
    protected function createSavepointName(): string
    {
        $this->savepointCounter++;
        return 'uda_sp_' . $this->savepointCounter;
    }
}
