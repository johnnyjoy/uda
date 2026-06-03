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
 * Purpose: Db2 LUW engine rules — DSN (pdo_ibm), identifier quoting, pagination fragments.
 */

/**
 * Db2 engine rules for the Driver domain.
 */
final class Db2
{
    /**
     * Build a PDO IBM DSN from normalized connection params.
     *
     * Accepts either a full or partial DSN in `params['dsn']` (e.g. `DSN=SAMPLE` for
     * db2cli.ini sections) or inline `dbname` + `host` + optional `port`.
     *
     * @param array<string,mixed> $params
     */
    public static function dsn(array $params): string
    {
        if (isset($params['dsn'])) {
            $fragment = trim((string) $params['dsn']);

            if (str_starts_with(strtolower($fragment), 'ibm:')) {
                return $fragment;
            }

            return 'ibm:' . $fragment;
        }

        $database = $params['dbname'] ?? ($params['database'] ?? null);
        $host = $params['host'] ?? null;
        $port = (int) ($params['port'] ?? 50000);

        if ($database === null || $host === null) {
            throw new ConfigException('Db2 configuration requires dbname and host (or dsn)');
        }

        return sprintf(
            'ibm:DATABASE=%s;HOSTNAME=%s;PORT=%d;PROTOCOL=TCPIP',
            $database,
            $host,
            $port,
        );
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
}
