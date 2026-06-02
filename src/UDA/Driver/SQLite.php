<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @license MIT
 * @link https://github.com/johnnyjoy/uda/blob/master/docs/driver.md
 * @since 1.0.0
 */

/*
 * Purpose: Provides SQLite engine rules for the Driver domain.
 *
 * SQLite supplies pure DSN and identifier quoting rules. It does not own
 * PDO or execute SQL.
 */

namespace UDA\Driver;

use UDA\Exception\ConfigException;

/**
 * SQLite engine rules for the Driver domain.
 */
final class SQLite
{
    /**
     * Builds a SQLite DSN (Data Source Name) string from connection parameters.
     * Constructs a DSN in the format `sqlite:/path/to/database` or `sqlite::memory:`.
     *
     * @param array $params  Connection parameters (must contain 'path' or 'host')
     *
     * @return string The constructed DSN string
     *
     * @throws ConfigException If required parameters are missing
     *
     * @see PDO::__construct() PDO DSN format requirements
     * @see \PDO::__construct() PDO DSN format requirements
     */
    public static function dsn(array $params): string
    {
        $path = $params['path'] ?? $params['host'] ?? null;

        if ($path === null || $path === '') {
            throw new ConfigException('SQLite connection requires a "path" or "host" parameter');
        }

        // SQLite DSN format: sqlite:/absolute/path or sqlite::memory:
        return 'sqlite:' . $path;
    }

    /**
     * Quote a SQLite identifier with ANSI double quotes.
     *
     * @param string $identifier  Identifier value.
     *
     * @return string Quoted identifier.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        $clean = trim($identifier);
        $escaped = str_replace('"', '""', $clean);

        return '"' . $escaped . '"';
    }
}
