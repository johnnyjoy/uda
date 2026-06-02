<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @author James Dornan <james.dornan@uda.example.com>
 * @license MIT
 * @link https://docs.uda.example.com/driver/sqlserver
 * @since 1.0.0
 */

/*
 * Purpose: Provides SQL Server engine rules for the Driver domain.
 *
 * SQL Server supplies pure DSN, identifier quoting, pagination, OUTPUT, and
 * savepoint SQL fragments. It does not own PDO or execute SQL.
 */

namespace UDA\Driver;

use UDA\Exception\QueryException;

/**
 * Static engine rules for PDO SQL Server connections.
 */
final class SQLServer
{
    /**
     * Build a SQL Server PDO DSN from normalized connection params.
     *
     * @param array<string,mixed> $params  Connection params keyed by config name.
     *
     * @return string PDO DSN string.
     */
    public static function dsn(array $params): string
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
     * Quote a SQL Server identifier with bracket syntax.
     *
     * @param string $identifier  Identifier value.
     *
     * @return string Quoted identifier.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        $clean = trim($identifier);
        $escaped = str_replace(']', ']]', $clean);

        return '[' . $escaped . ']';
    }

    /**
     * Build SQL Server pagination using OFFSET/FETCH.
     *
     * @param int $limit   Maximum number of rows.
     * @param int $offset  Number of rows to skip.
     *
     * @return string Pagination SQL fragment.
     *
     * @throws QueryException If the operation fails.
     */
    public static function limitOffset(int $limit, int $offset): string
    {
        if ($limit < 0 || $offset < 0) {
            throw new QueryException('LIMIT/OFFSET must be non-negative');
        }

        return sprintf('OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $offset, $limit);
    }

    /**
     * Build SQL Server OUTPUT INSERTED syntax for emitted rows.
     *
     * @param string            $table    Target table name.
     * @param array<int,string> $columns  Column names to return.
     *
     * @return string RETURNING SQL fragment.
     */
    public static function returningSql(string $table, array $columns): string
    {
        $quotedCols = array_map([self::class, 'quoteIdentifier'], $columns);

        return ' OUTPUT INSERTED.' . implode(', INSERTED.', $quotedCols);
    }

    /**
     * Build a SQL Server savepoint statement.
     *
     * @param string $name  Name value.
     *
     * @return ?string Savepoint SQL fragment, or null when unsupported.
     */
    public static function savepointSql(string $name): ?string
    {
        return 'SAVE TRANSACTION ' . $name;
    }

    /**
     * SQL Server has no explicit savepoint release statement.
     *
     * @param string $name  Name value.
     *
     * @return ?string Savepoint SQL fragment, or null when unsupported.
     */
    public static function releaseSavepointSql(string $name): ?string
    {
        // SQL Server does not support releasing savepoints explicitly
        return null;
    }

    /**
     * Build a rollback statement for a named savepoint.
     *
     * @param string $name  Name value.
     *
     * @return ?string Savepoint SQL fragment, or null when unsupported.
     */
    public static function rollbackSavepointSql(string $name): ?string
    {
        return 'ROLLBACK TRANSACTION ' . $name;
    }

}
