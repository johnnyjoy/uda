<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @license MIT
 * @link https://github.com/johnnyjoy/uda/blob/master/docs/driver.md
 * @since 1.0.0
 */

/*
 * Purpose: dblib: PDO DSN builder for DBLib transport.
 *
 * Used when engine sqlserver selects transport dblib, or when building Sybase
 * connections via Driver\Sybase::dsn(). SQL fragment rules live on the engine
 * class (SQLServer or Sybase), not here.
 */

namespace UDA\Driver;

/**
 * DBLib PDO transport — DSN construction only.
 */
final class Dblib
{
    /**
     * Build a DBLib PDO DSN from normalized connection params.
     *
     * @param array<string,mixed> $params  Connection params keyed by config name.
     *
     * @return string PDO DSN string.
     */
    public static function dsn(array $params): string
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
}
