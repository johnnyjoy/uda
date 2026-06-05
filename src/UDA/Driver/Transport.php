<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @license MIT
 * @since 1.0.0
 */

namespace UDA\Driver;

/*
 * Purpose: Normalize config engine and PDO transport keys (no PDO, no execution).
 */

/**
 * Canonical engine + transport resolution for connection config.
 */
final class Transport
{
    /**
     * @param ?string $engine  Config driver value or alias.
     */
    public static function engineKey(?string $engine): string
    {
        return match (strtolower(trim((string) $engine))) {
            'mysql', 'mariadb' => 'mariadb',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            'sqlsrv', 'sqlserver' => 'sqlserver',
            'dblib', 'sybase' => 'sybase',
            'sqlite' => 'sqlite',
            'oci', 'oracle' => 'oracle',
            'db2' => 'db2',
            'firebird', 'interbase' => 'firebird',
            'cubrid' => 'cubrid',
            default => strtolower(trim((string) $engine)),
        };
    }

    /**
     * @param ?string $transport  Config transport value or alias.
     */
    public static function transportKey(?string $transport): string
    {
        return match (strtolower(trim((string) $transport))) {
            'sqlsrv', 'sqlserver' => 'sqlsrv',
            'dblib', 'sybase' => 'dblib',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            'mysql', 'mariadb' => 'mysql',
            'oci', 'oracle' => 'oci',
            'firebird' => 'firebird',
            'sqlite' => 'sqlite',
            default => strtolower(trim((string) $transport)),
        };
    }

    /**
     * @param string $engine  Canonical or alias engine key.
     */
    public static function defaultTransport(string $engine): string
    {
        return match (self::engineKey($engine)) {
            'sqlite' => 'sqlite',
            'pgsql' => 'pgsql',
            'mariadb' => 'mysql',
            'sqlserver' => 'sqlsrv',
            'sybase' => 'dblib',
            'oracle' => 'oci',
            'firebird' => 'firebird',
            default => self::engineKey($engine),
        };
    }

    /**
     * @return array{0:string,1:string} [engine, transport]
     */
    public static function resolve(string $driver, ?string $transport = null): array
    {
        $engine = self::engineKey($driver);
        $resolvedTransport = ($transport !== null && trim($transport) !== '')
            ? self::transportKey($transport)
            : self::defaultTransport($engine);

        return [$engine, $resolvedTransport];
    }
}
