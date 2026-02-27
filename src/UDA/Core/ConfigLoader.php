<?php

declare(strict_types=1);

/** @purpose UDA\Core\ConfigLoader: Add detailed purpose here */

namespace UDA\Core;

use UDA\Exception\ConfigException;

/**
 * Configuration loader
 */
class ConfigLoader
{
    private const CONFIG_ENV = 'UDA_CONFIG';

    /**
     * Cached configuration by canonical path.
     *
     * @var array<string, array>
     */
    private static array $cachedConfigs = [];
    /**
     * Load configuration from file
     *
     * @param string|null $path Path to config file (uses UDA_CONFIG env var if null)
     * @return array Configuration array
     * @throws ConfigException
     */
    public static function load(?string $path = null): array
    {
        $configPath = self::resolveConfigPath($path);

        if (isset(self::$cachedConfigs[$configPath])) {
            return self::$cachedConfigs[$configPath];
        }

        if (!file_exists($configPath)) {
            throw new ConfigException("Configuration file not found: {$configPath}");
        }

        $content = file_get_contents($configPath);

        if ($content === false) {
            throw new ConfigException("Unable to read configuration file: {$configPath}");
        }

        $config = json_decode($content, true);

        if (!is_array($config)) {
            throw new ConfigException('Configuration file must contain a JSON object');
        }

        if (!isset($config['connections']) || !is_array($config['connections'])) {
            throw new ConfigException('Configuration must define a connections object');
        }

        self::$cachedConfigs[$configPath] = $config;
        return $config;
    }

    /**
     * Clear cached configuration entries.
     *
     * @param string|null $path Specific config path to flush, or null to flush all
     */
    public static function clearCache(?string $path = null): void
    {
        if ($path === null) {
            self::$cachedConfigs = [];
            return;
        }

        $key = self::canonicalPath($path);
        unset(self::$cachedConfigs[$key]);
    }

    /**
     * Resolve the config path, using the environment fallback if needed.
     *
     * @throws ConfigException
     */
    private static function resolveConfigPath(?string $path): string
    {
        $configPath = $path ?? getenv(self::CONFIG_ENV);

        if ($configPath === false || $configPath === null || $configPath === '') {
            throw new ConfigException('UDA_CONFIG environment variable not set');
        }

        return self::canonicalPath($configPath);
    }

    private static function canonicalPath(string $path): string
    {
        $real = realpath($path);
        return $real !== false ? $real : $path;
    }

    /**
     * Build DSN from connection configuration
     *
     * @param array $connection Connection configuration
     * @return string DSN string
     */
    public static function getDsn(array $connection): string
    {
        $driver = $connection['driver'] ?? '';

        if (isset($connection['dsn'])) {
            return $connection['dsn'];
        }

        $params = $connection['params'] ?? [];

        switch ($driver) {
            case 'pgsql':
                return 'pgsql:' . self::buildPgsqlDsn($params);
            case 'sqlite':
                return 'sqlite:' . ($params['path'] ?? $params['database'] ?? ':memory:');
            case 'mysql':
                return 'mysql:' . self::buildMysqlDsn($params);
            case 'sqlsrv':
                return 'sqlsrv:' . self::buildSqlsrvDsn($params);
            default:
                throw new ConfigException("Unsupported driver: {$driver}");
        }
    }

    /**
     * Build PostgreSQL DSN
     *
     * @param array $params Connection parameters
     * @return string DSN string
     */
    private static function buildPgsqlDsn(array $params): string
    {
        $dsnParts = [];

        if (isset($params['host'])) {
            $dsnParts[] = "host={$params['host']}";
        }

        if (isset($params['port'])) {
            $dsnParts[] = "port={$params['port']}";
        }

        if (isset($params['dbname'])) {
            $dsnParts[] = "dbname={$params['dbname']}";
        }

        if (isset($params['options'])) {
            $dsnParts[] = "options={$params['options']}";
        }

        return implode(';', $dsnParts);
    }

    /**
     * Build MySQL DSN
     *
     * @param array $params Connection parameters
     * @return string DSN string
     */
    private static function buildMysqlDsn(array $params): string
    {
        $dsnParts = [];

        if (isset($params['host'])) {
            $dsnParts[] = "host={$params['host']}";
        }

        if (isset($params['port'])) {
            $dsnParts[] = "port={$params['port']}";
        }

        if (isset($params['dbname'])) {
            $dsnParts[] = "dbname={$params['dbname']}";
        }

        if (isset($params['charset'])) {
            $dsnParts[] = "charset={$params['charset']}";
        }

        return implode(';', $dsnParts);
    }

    /**
     * Build SQL Server DSN
     *
     * @param array $params Connection parameters
     * @return string DSN string
     */
    private static function buildSqlsrvDsn(array $params): string
    {
        $dsnParts = [];

        if (isset($params['server'])) {
            $dsnParts[] = "server={$params['server']}";
        }

        if (isset($params['database'])) {
            $dsnParts[] = "database={$params['database']}";
        }

        return implode(';', $dsnParts);
    }

    /**
     * Get credentials from connection configuration
     *
     * @param array $connection Connection configuration
     * @return array [user, pass]
     */
    public static function getCredentials(array $connection): array
    {
        $user = $connection['user'] ?? '';
        $pass = $connection['pass'] ?? '';

        if (is_array($user) && isset($user['env'])) {
            $user = $_ENV[$user['env']] ?? getenv($user['env']) ?: '';
        } elseif (is_string($user) && str_starts_with($user, '{env:')) {
            $varName = substr($user, 5, -1);
            $user = $_ENV[$varName] ?? getenv($varName) ?: '';
        }

        if (is_array($pass) && isset($pass['env'])) {
            $pass = $_ENV[$pass['env']] ?? getenv($pass['env']) ?: '';
        } elseif (is_string($pass) && str_starts_with($pass, '{env:')) {
            $varName = substr($pass, 5, -1);
            $pass = $_ENV[$varName] ?? getenv($varName) ?: '';
        }

        return [$user, $pass];
    }
}
