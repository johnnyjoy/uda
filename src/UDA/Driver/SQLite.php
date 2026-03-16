<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/driver/sqlite
 * @since 1.0.0
 */

/*
 * Purpose: Compatibility shim and alias for SQLite driver implementation in UDA.
 */

namespace UDA\Driver;

use UDA\Driver as Driver;
use UDA\Exception\ConfigException;
use UDA\Query\Upsert;

/**
 * Compatibility shim that aliases the SQLite driver implementation
 */
class SQLite extends Driver
{
    protected ?string $dbtype = 'sqlite';

    /**
     * Builds a SQLite DSN (Data Source Name) string from connection parameters.
     *
     * Constructs a DSN in the format `sqlite:/path/to/database` or `sqlite::memory:`.
     *
     * @param  array           $params Connection parameters (must contain 'path' or 'host')
     * @return string          The constructed DSN string
     * @throws ConfigException If required parameters are missing
     * @see PDO::__construct() PDO DSN format requirements
     * @see Driver::buildDsn() Generic DSN builder for other drivers
     */
    protected function buildDsn(array $params): string
    {
        $path = $params['path'] ?? $params['host'] ?? null;

        if ($path === null || $path === '') {
            throw new ConfigException('SQLite connection requires a "path" or "host" parameter');
        }

        // SQLite DSN format: sqlite:/absolute/path or sqlite::memory:
        return 'sqlite:' . $path;
    }

    protected function onConnect(): void
    {
        // SQLite doesn't require session-level configuration
    }

    public function upsertExec(Upsert $query): int
    {
        $sql = $query->toSql();

        return $this->exec($this->toSqlMessage($sql), [], $sql->getCacheTables());
    }
}
