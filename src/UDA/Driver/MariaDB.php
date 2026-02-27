<?php

declare(strict_types=1);

/** @purpose MariaDB driver - MySQL-compatible with MariaDB enhancements */

namespace UDA\Driver;

use PDO;
use UDA\Driver;
use UDA\Cache\Setup;

final class MariaDB extends Driver
{
    /**
     * MariaDB uses mysql PDO driver
     * mysql:host=localhost;dbname=test
     */
    protected function buildDsn(array $params): string
    {
        $dsn = 'mysql:';
        
        if (isset($params['host'])) {
            $dsn .= 'host=' . $params['host'] . ';';
        }
        if (isset($params['port'])) {
            $dsn .= 'port=' . $params['port'] . ';';
        }
        if (isset($params['dbname'])) {
            $dsn .= 'dbname=' . $params['dbname'];
        }
        
        return rtrim($dsn, ';');
    }
    
    /**
     * MariaDB supports RETURNING in recent versions
     */
    public function buildReturningSql(string $table, array $columns): string
    {
        $quotedCols = array_map([$this, 'q'], $columns);
        return ' RETURNING ' . implode(', ', $quotedCols);
    }
}
