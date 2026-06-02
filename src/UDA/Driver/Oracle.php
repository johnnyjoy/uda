<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/driver/oracle
 * @since 1.0.0
 */

/*
 * Purpose: Provides Oracle engine rules for the Driver domain.
 *
 * Oracle supplies pure PDO_OCI string and buffer rules. Driver owns the
 * specialized RETURNING INTO execution path because that path binds PDO
 * output parameters and must stay inside the single execution domain.
 */

namespace UDA\Driver;

use UDA\Exception\ConfigException;
use UDA\Exception\QueryException;

/**
 * Static engine rules for Oracle PDO_OCI connections.
 */
final class Oracle
{
    private const RETURNING_BUFFER_LENGTH = 4000;

    /**
     * Build an Oracle PDO DSN from normalized connection params.
     *
     * @param array<string,mixed> $params  Connection params keyed by config name.
     *
     * @return string PDO DSN string.
     *
     * @throws ConfigException If host/service configuration is incomplete.
     */
    public static function dsn(array $params): string
    {
        if (isset($params['dbname'])) {
            return 'oci:dbname=' . $params['dbname'];
        }

        $host = $params['host'] ?? null;
        $service = $params['service'] ?? ($params['sid'] ?? null);
        $port = (int)($params['port'] ?? 1521);

        if ($host === null || $service === null) {
            throw new ConfigException('Oracle configuration requires host and service (or sid)');
        }

        return sprintf('oci:dbname=//%s:%d/%s', $host, $port, $service);
    }

    /**
     * Quote an Oracle identifier using uppercase double-quoted form.
     *
     * @param string $identifier  Identifier value.
     *
     * @return string Quoted identifier.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        $clean = strtoupper(trim($identifier));
        $escaped = str_replace('"', '""', $clean);

        return '"' . $escaped . '"';
    }

    /**
     * Build Oracle FETCH pagination.
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
     * Append Oracle RETURNING INTO SQL for Driver-managed output bindings.
     *
     * @param string            $baseQuery     Base SQL statement.
     * @param array<int,string> $columns       Returning column names.
     * @param array<int,string> $placeholders  Output parameter placeholders.
     *
     * @return string RETURNING SQL fragment.
     */
    public static function returningIntoSql(string $baseQuery, array $columns, array $placeholders): string
    {
        $quotedColumns = array_map(fn (string $col): string => self::quoteIdentifier(strtoupper($col)), $columns);

        return $baseQuery . ' RETURNING ' . implode(', ', $quotedColumns) . ' INTO ' . implode(', ', $placeholders);
    }

    /**
     * Return the default output buffer length for Oracle RETURNING bindings.
     *
     * @return int Integer result.
     */
    public static function returningBufferLength(): int
    {
        return self::RETURNING_BUFFER_LENGTH;
    }
}
