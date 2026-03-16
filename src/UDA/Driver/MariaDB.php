<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/driver/mariadb
 * @since 1.0.0
 */

/*
 * Purpose: Provides MariaDB-specific database connectivity and SQL dialect support.
 */

namespace UDA\Driver;

use UDA\Driver as BaseDriver;

final class MariaDB extends BaseDriver
{
    protected ?string $dbtype = 'mysql';

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

    protected function quoteIdentifier(string $identifier): string
    {
        $clean = trim($identifier);
        $escaped = str_replace('`', '``', $clean);

        return '`' . $escaped . '`';
    }

    /**
     * MariaDB supports RETURNING in recent versions
     */
    public function buildReturningSql(string $table, array $columns): string
    {
        $quotedCols = array_map([$this, 'q'], $columns);

        return ' RETURNING ' . implode(', ', $quotedCols);
    }

    protected function onConnect(): void
    {
        // MariaDB does not require special session setup by default
    }
}
