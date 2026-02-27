<?php

declare(strict_types=1);

/** @purpose DBLib driver (FreeTDS) - for SQL Server via dblib PDO extension */

namespace UDA\Driver;

use PDO;
use UDA\Driver;
use UDA\Cache\Setup;

final class Dblib extends Driver
{
    /**
     * DBLib format: dblib:host=server:port;dbname=database
     */
    protected function buildDsn(array $params): string
    {
        $host = $params['host'] ?? 'localhost';
        $port = isset($params['port']) ? ':' . $params['port'] : '';
        $dbname = $params['dbname'] ?? '';
        
        $dsn = "dblib:host={$host}{$port}";
        
        if ($dbname) {
            $dsn .= ";dbname={$dbname}";
        }
        
        return $dsn;
    }
    
    /**
     * DBLib uses LIMIT/OFFSET instead of TOP
     */
    public function limitOffset(int $limit, ?int $offset = null): string
    {
        if ($offset === null) {
            return "LIMIT {$limit}";
        }
        return "LIMIT {$limit} OFFSET {$offset}";
    }
}
