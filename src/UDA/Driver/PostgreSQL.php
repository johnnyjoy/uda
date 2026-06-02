<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/driver/postgresql
 * @since 1.0.0
 */

/*
 * Purpose: Provides PostgreSQL engine rules for the Driver domain.
 *
 * PostgreSQL supplies pure DSN, identifier quoting, and RETURNING clause
 * fragments. It does not own PDO or execute SQL.
 */

namespace UDA\Driver;

/**
 * PostgreSQL engine rules for the Driver domain.
 */
final class PostgreSQL
{
    /**
     * Builds a PostgreSQL DSN (Data Source Name) string from connection parameters.
     * Constructs a DSN in the format `pgsql:host=...;port=...;dbname=...` for use
     * with PDO. Omits optional fields (like SSL) if not provided in $params.
     *                                       - 'host': Database server hostname/IP (defaults omit)
     *                                       - 'port': Database port (defaults omit)
     *                                       - 'dbname': Database name (defaults omit)
     * // Returns: "pgsql:host=localhost;port=5432;dbname=mydb"
     * self::dsn([
     * 'host' => 'localhost',
     * 'port' => '5432',
     * 'dbname' => 'mydb'
     * ]);
     *
     * @return string The constructed DSN string
     *
     * @param  array<string, string> $params Connection parameters with optional keys:
     * @see \PDO::__construct() PDO DSN format requirements
     * @example
     */
    public static function dsn(array $params): string
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

        if (isset($params['sslmode']) && $params['sslmode'] !== '') {
            $parts[] = 'sslmode=' . $params['sslmode'];
        }

        $driver = array_shift($parts);
        $suffix = implode(';', $parts);

        return $suffix === '' ? ($driver . ':') : $driver . ':' . $suffix;
    }

    /**
     * Generates a PostgreSQL RETURNING clause for retrieving inserted/updated rows.
     * The RETURNING clause allows immediate access to modified data without a separate
     * SELECT query. This is especially useful for retrieving generated IDs or sequences.
     * // Insert and return generated data
     * $sql = "INSERT INTO users (name, email) VALUES ('Alice', 'alice@example.com')"
     * . $driver->buildReturningSql('users', ['id', 'created_at']);
     * $result = $driver->row($sql); // Returns ['id' => 1, 'created_at' => '2023-01-01']
     *
     * @param string        $table    The table name (used for documentation; not in output SQL)
     * @param array<string> $columns  Column names to return (e.g., ["id", "created_at"])
     *
     * @return string SQL fragment like ` RETURNING id, created_at`
     *
     * @throws \RuntimeException If column array is empty
     *
     * @see PDOStatement::fetch() Methods to process returned rows
     * @example
     */
    public static function returningSql(string $table, array $columns): string
    {
        $quotedCols = array_map([self::class, 'quoteIdentifier'], $columns);

        return ' RETURNING ' . implode(', ', $quotedCols);
    }

    /**
     * Quote a PostgreSQL identifier with ANSI double quotes.
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
