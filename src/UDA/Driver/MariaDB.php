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
 * Purpose: Provides MariaDB/MySQL engine rules for the Driver domain.
 *
 * MariaDB supplies pure DSN, identifier quoting, and RETURNING clause
 * fragments. It does not own PDO or execute SQL.
 */

namespace UDA\Driver;

/**
 * Static engine rules for MariaDB and MySQL PDO connections.
 */
final class MariaDB
{
    /**
     * Build a MySQL-compatible PDO DSN from normalized connection params.
     *
     * @param array<string,mixed> $params  Connection params keyed by config name.
     *
     * @return string PDO DSN string.
     */
    public static function dsn(array $params): string
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
     * Quote a MariaDB/MySQL identifier with backticks.
     *
     * @param string $identifier  Identifier value.
     *
     * @return string Quoted identifier.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        $clean = trim($identifier);
        $escaped = str_replace('`', '``', $clean);

        return '`' . $escaped . '`';
    }

    /**
     * Build a MariaDB RETURNING clause for supported versions.
     *
     * @param string            $table    Target table name.
     * @param array<int,string> $columns  Column names to return.
     *
     * @return string RETURNING SQL fragment.
     */
    public static function returningSql(string $table, array $columns): string
    {
        $quotedCols = array_map([self::class, 'quoteIdentifier'], $columns);

        return ' RETURNING ' . implode(', ', $quotedCols);
    }
}
