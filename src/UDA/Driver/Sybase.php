<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/driver/sybase
 * @since 1.0.0
 */

/*
 * Purpose: Sybase ASE engine rules for the Driver domain.
 *
 * DSN uses the dblib: transport (Dblib::dsn). SQL fragments delegate to
 * SQLServer statics where T-SQL syntax matches. UDA\Driver performs new PDO().
 */

namespace UDA\Driver;

/**
 * Static rules for Sybase ASE connections.
 */
final class Sybase
{
    /**
     * Build a dblib PDO DSN for Sybase ASE.
     *
     * @param array<string,mixed> $params  Connection params keyed by config name.
     *
     * @return string PDO DSN string.
     */
    public static function dsn(array $params): string
    {
        return Dblib::dsn($params);
    }

    /**
     * @param string $identifier  Identifier value.
     *
     * @return string Quoted identifier.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        return SQLServer::quoteIdentifier($identifier);
    }

    /**
     * @param int $limit   Maximum number of rows.
     * @param int $offset  Number of rows to skip.
     *
     * @return string Pagination SQL fragment.
     */
    public static function limitOffset(int $limit, int $offset): string
    {
        return SQLServer::limitOffset($limit, $offset);
    }

    /**
     * @param string $name  Savepoint name.
     *
     * @return ?string Savepoint SQL fragment, or null when unsupported.
     */
    public static function savepointSql(string $name): ?string
    {
        return SQLServer::savepointSql($name);
    }

    /**
     * @param string $name  Savepoint name.
     *
     * @return ?string Savepoint SQL fragment, or null when unsupported.
     */
    public static function releaseSavepointSql(string $name): ?string
    {
        return SQLServer::releaseSavepointSql($name);
    }

    /**
     * @param string $name  Savepoint name.
     *
     * @return ?string Savepoint SQL fragment, or null when unsupported.
     */
    public static function rollbackSavepointSql(string $name): ?string
    {
        return SQLServer::rollbackSavepointSql($name);
    }
}
