<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/driver/sqlserver
 * @since 1.0.0
 */

/*
 * Purpose: Implements SQL Server-specific database connectivity and SQL dialect support.
 */

namespace UDA\Driver;

use UDA\Driver as BaseDriver;
use UDA\Exception\QueryException;

final class SQLServer extends BaseDriver
{
    protected ?string $dbtype = 'sqlsrv';

    /**
     * SQL Server uses sqlsrv extension format
     * sqlsrv:Server=hostname,port;Database=dbname
     */
    protected function buildDsn(array $params): string
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

    /**
     * SQL Server uses OUTPUT INSERTED for returning
     */
    public function buildReturningSql(string $table, array $columns): string
    {
        $quotedCols = array_map([$this, 'q'], $columns);

        return ' OUTPUT INSERTED.' . implode(', INSERTED.', $quotedCols);
    }

    /**
     * SQL Server supports SAVEPOINTs
     */
    protected function savepointSql(string $name): ?string
    {
        return 'SAVE TRANSACTION ' . $name;
    }

    protected function releaseSavepointSql(string $name): ?string
    {
        // SQL Server does not support releasing savepoints explicitly
        return null;
    }

    protected function rollbackSavepointSql(string $name): ?string
    {
        return 'ROLLBACK TRANSACTION ' . $name;
    }

    protected function onConnect(): void
    {
        // SQL Server specific session initialization can be added here
    }
}
