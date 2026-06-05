<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @license MIT
 * @since 1.0.0
 */

/*
 * Purpose: Provides CUBRID engine rules for the Driver domain.
 *
 * Supplies DSN construction and identifier quoting. Does not own PDO or execute SQL.
 * CUBRID uses MySQL-compatible backtick quoting and has no RETURNING clause support.
 */

namespace UDA\Driver;

/**
 * Static engine rules for CUBRID PDO connections.
 */
final class Cubrid
{
    /**
     * Build a CUBRID PDO DSN from normalized connection params.
     *
     * @param array<string,mixed> $params  Connection params keyed by config name.
     *
     * @return string PDO DSN string.
     */
    public static function dsn(array $params): string
    {
        $dsn = 'cubrid:';

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
     * Quote a CUBRID identifier with backticks.
     *
     * CUBRID uses MySQL-compatible backtick quoting.
     *
     * @param string $identifier  Identifier value.
     *
     * @return string Quoted identifier.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        $clean   = trim($identifier);
        $escaped = str_replace('`', '``', $clean);

        return '`' . $escaped . '`';
    }
}
