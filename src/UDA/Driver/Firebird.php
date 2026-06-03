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
 * Purpose: Firebird engine rules — DSN (pdo_firebird), identifier quoting, pagination fragments.
 */

/**
 * Firebird engine rules for the Driver domain.
 */
final class Firebird
{
    /**
     * Build a PDO Firebird DSN from normalized connection params.
     *
     * Accepts a full DSN in `params['dsn']` or builds
     * `firebird:dbname={host}/{port}:{database}` (path or alias).
     *
     * For TCP connections, `{database}` must be the path **on the Firebird server**
     * (e.g. `/var/lib/firebird/data/app.fdb` in the official Docker image), not a
     * bare filename on the PHP client host.
     *
     * @param array<string,mixed> $params
     */
    public static function dsn(array $params): string
    {
        if (isset($params['dsn'])) {
            $fragment = trim((string) $params['dsn']);

            if (str_starts_with(strtolower($fragment), 'firebird:')) {
                return $fragment;
            }

            return 'firebird:' . $fragment;
        }

        $database = $params['database'] ?? ($params['dbname'] ?? ($params['path'] ?? null));
        $host = $params['host'] ?? null;

        if ($database === null || $host === null) {
            throw new ConfigException('Firebird configuration requires host and database (or dsn)');
        }

        $port = (int) ($params['port'] ?? 3050);

        return sprintf('firebird:dbname=%s/%d:%s', $host, $port, $database);
    }

    public static function quoteIdentifier(string $identifier): string
    {
        $clean = trim($identifier);
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
