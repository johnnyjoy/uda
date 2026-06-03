<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @license MIT
 * @since 1.0.0
 */

namespace UDA\Driver;

use UDA\Exception\ConfigException;
use UDA\Exception\QueryException;

/*
 * Purpose: Oracle engine rules — DSN, quoting, pagination, RETURNING INTO SQL fragments.
 *
 * RETURNING INTO execution lives in Driver\Oracle\Returning; use leading \ from that namespace.
 */

/**
 * Oracle engine rules for the Driver domain.
 */
final class Oracle
{
    private const RETURNING_BUFFER_LENGTH = 4000;

    /**
     * @param array<string,mixed> $params
     */
    public static function dsn(array $params): string
    {
        if (isset($params['dbname'])) {
            return 'oci:dbname=' . $params['dbname'];
        }

        $host = $params['host'] ?? null;
        $service = $params['service'] ?? ($params['sid'] ?? null);
        $port = (int) ($params['port'] ?? 1521);

        if ($host === null || $service === null) {
            throw new ConfigException('Oracle configuration requires host and service (or sid)');
        }

        return sprintf('oci:dbname=//%s:%d/%s', $host, $port, $service);
    }

    public static function quoteIdentifier(string $identifier): string
    {
        $clean = strtoupper(trim($identifier));
        $escaped = str_replace('"', '""', $clean);

        return '"' . $escaped . '"';
    }

    /**
     * @throws QueryException
     */
    public static function limitOffset(int $limit, int $offset): string
    {
        if ($limit < 0 || $offset < 0) {
            throw new QueryException('LIMIT/OFFSET must be non-negative');
        }

        if ($offset === 0) {
            return sprintf('FETCH FIRST %d ROWS ONLY', $limit);
        }

        return sprintf('OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $offset, $limit);
    }

    /**
     * @param array<int,string> $columns
     * @param array<int,string> $placeholders
     */
    public static function returningIntoSql(string $baseQuery, array $columns, array $placeholders): string
    {
        $quotedColumns = array_map(
            fn (string $col): string => self::quoteIdentifier(strtoupper($col)),
            $columns,
        );

        return $baseQuery . ' RETURNING ' . implode(', ', $quotedColumns) . ' INTO ' . implode(', ', $placeholders);
    }

    public static function returningBufferLength(): int
    {
        return self::RETURNING_BUFFER_LENGTH;
    }
}
