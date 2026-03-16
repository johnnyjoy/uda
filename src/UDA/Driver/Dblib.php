<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/driver/dblib
 * @since 1.0.0
 */

/*
 * Purpose: Implements SQL Server connectivity via the dblib PDO extension for FreeTDS compatibility.
 */

namespace UDA\Driver;

use UDA\Driver as BaseDriver;
use UDA\Exception\QueryException;

final class Dblib extends BaseDriver
{
    /**
     * DBLib format: dblib:host=server:port;dbname=database
     */
    protected ?string $dbtype = 'dblib';

    protected function buildDsn(array $params): string
    {
        $host = $params['host'] ?? 'localhost';
        $port = isset($params['port']) ? ':' . $params['port'] : '';
        $dbname = $params['dbname'] ?? '';

        $dsn = "dblib:host={$host}{$port}";

        if ($dbname) {
            $dsn .= ";dbname={$dbname}";
        }

        return $dsn;
    }

    protected function quoteIdentifier(string $identifier): string
    {
        $clean = trim($identifier);
        $escaped = str_replace(']', ']]', $clean);

        return '[' . $escaped . ']';
    }

    public function limitOffset(int $limit, int $offset): string
    {
        if ($limit < 0 || $offset < 0) {
            throw new QueryException('LIMIT/OFFSET must be non-negative');
        }

        return sprintf('OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $offset, $limit);
    }

    protected function savepointSql(string $name): ?string
    {
        return 'SAVE TRANSACTION ' . $name;
    }

    protected function releaseSavepointSql(string $name): ?string
    {
        return null;
    }

    protected function rollbackSavepointSql(string $name): ?string
    {
        return 'ROLLBACK TRANSACTION ' . $name;
    }

    protected function onConnect(): void
    {
        // DB-Library driver does not require special session setup
    }
}
