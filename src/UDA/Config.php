<?php

declare(strict_types=1);

namespace UDA;

use UDA\Config\Snapshot;
use UDA\Config\Validator;
use UDA\Exception\ConfigException;

/**
 * @package UDA
 * @subpackage Core
 * @author James Dornan <james.dornan@uda.example.com>
 * @license MIT
 * @link https://docs.uda.example.com/core/config
 * @since 1.0.0
 */

/*
 * Purpose: Owns process-wide configuration state for UDA.
 *
 * Config loads one validated source into an immutable Snapshot and answers
 * connection, cache, and engine questions by connection name. Runtime domains
 * read configuration through this static API instead of holding raw config files.
 */

/**
 * Process-wide configuration gateway backed by an immutable snapshot.
 */
final class Config
{
    /**
     * Active validated snapshot for this process (immutable once set).
     *
     * @var Snapshot|null
     */
    private static ?Snapshot $snapshot = null;

    /**
     * Canonical file path used to initialize the snapshot.
     *
     * Used to enforce single-source initialization. If init() is called again with
     * a different source file, ConfigException is thrown.
     *
     * @var string|null
     */
    private static ?string $sourcePath = null;

    /**
     * Initialize configuration for this process.
     * Two routes:
     * - init() : loads from UDA_CONFIG environment variable
     * - init($filePath) : loads explicitly from the given JSON file path
     * Idempotence / single-source rule:
     * - First init wins for the process lifetime.
     * - Calling init() repeatedly with the same canonical path is a no-op.
     * - Calling init() with a different file path than originally used throws.
     * This is intended to be called by Database::connect() implicitly (lazy init),
     * or by bootstrapping code early in the process.
     *                         the file path is invalid, the file is unreadable,
     *                         the JSON is invalid, validation fails, or init is
     *                         attempted from a conflicting source.
     *
     * @param string|null $file  Optional explicit JSON config file path.
     *
     * @return void
     *
     * @throws ConfigException If the environment variable is missing/empty,
     */
    public static function init(?string $file = null): void
    {
        $path = ($file !== null)
            ? self::normalizePath($file)
            : self::pathFromEnv();

        // Already initialized?
        if (self::$snapshot !== null) {
            if (self::$sourcePath !== $path) {
                throw new ConfigException(
                    "Config already initialized from '" . self::$sourcePath . "', cannot re-init from '{$path}'"
                );
            }

            return; // same canonical path: no-op
        }

        self::$snapshot = self::loadAndValidate($path);
        self::$sourcePath = $path;
    }

    /**
     * Check if a connection exists.
     *
     * @param string $name  Connection name
     *
     * @return bool True if the connection exists, false otherwise
     */
    public static function hasConnection(string $name = 'default'): bool
    {
        $snap = self::requireSnapshot();
        return $snap->hasConnection($name);
    }

    /**
     * Get the configured default connection name.
     *
     * @return string String result.
     */
    public static function default(): string
    {
        return self::requireSnapshot()->getDefaultConnection() ?? 'default';
    }

    /**
     * Get the configured engine (SQL family) for a connection.
     *
     * After ingestion, this is the normalized canonical engine key (e.g. sqlserver, sybase).
     *
     * @param string|null $name  Connection name, or null/empty to use default
     *
     * @return string The engine key
     *
     * @throws ConfigException If configuration is not initialized or connection is missing
     */
    public static function engine(?string $name = 'default'): string
    {
        $snap = self::requireSnapshot();
        $resolved = self::resolveConnectionName($snap, $name);
        $conn = $snap->getConnection($resolved);

        if ($conn === null) {
            throw new ConfigException("Connection '{$resolved}' not found");
        }

        return (string) ($conn['engine'] ?? $conn['driver'] ?? '');
    }

    /**
     * @deprecated Use engine() — config key `driver` holds the engine identity.
     *
     * @param string|null $name  Connection name, or null/empty to use default
     *
     * @return string The engine key
     *
     * @throws ConfigException If configuration is not initialized and cannot be
     */
    public static function driver(?string $name = 'default'): string
    {
        return self::engine($name);
    }

    /**
     * Get the configured PDO transport for a connection.
     *
     * @param string|null $name  Connection name, or null/empty to use default
     *
     * @return string The transport key (e.g. sqlsrv, dblib, pgsql)
     *
     * @throws ConfigException If configuration is not initialized or connection is missing
     */
    public static function transport(?string $name = 'default'): string
    {
        $snap = self::requireSnapshot();
        $resolved = self::resolveConnectionName($snap, $name);
        $conn = $snap->getConnection($resolved);

        if ($conn === null) {
            throw new ConfigException("Connection '{$resolved}' not found");
        }

        if (!isset($conn['transport']) || !is_string($conn['transport'])) {
            throw new ConfigException("Connection '{$resolved}' missing normalized transport");
        }

        return $conn['transport'];
    }

    /**
     * Return the raw validated connection configuration.
     *
     * @param ?string $name  Name value.
     *
     * @return array<string,mixed>
     *
     * @throws ConfigException If the requested connection is not found.
     */
    public static function connection(?string $name = 'default'): array
    {
        $snap = self::requireSnapshot();
        $resolved = self::resolveConnectionName($snap, $name);
        $conn = $snap->getConnection($resolved);

        if ($conn === null) {
            throw new ConfigException("Connection '{$resolved}' not found");
        }

        return $conn;
    }

    /**
     * Check if caching is enabled for a connection and tables.
     *                         initialized from env; if the requested connection is not found.
     *
     * @param string|null $name    Connection name, or null/empty to use default
     * @param array       $tables  Table names referenced in the query
     *
     * @return bool True if caching is enabled for all tables, false otherwise
     *
     * @throws ConfigException If configuration is not initialized and cannot be
     */
    public static function hasCache(string $name, array $tables): bool
    {
        $snap = self::requireSnapshot();
        $resolved = self::resolveConnectionName($snap, $name);

        $conn = $snap->getConnection($resolved);

        if ($conn === null) {
            throw new ConfigException("Connection '{$resolved}' not found");
        }

        // Check global cache configuration
        $globalCacheEnabled = self::cacheStore($resolved) !== 'off';
        if (!$globalCacheEnabled) {
            return false;
        }

        // Check connection-specific cache configuration
        $cacheConfig = $conn['cache'] ?? null;
        if (!is_array($cacheConfig)) {
            return true; // Global cache enabled, no connection-specific config
        }

        // Check if any table is disabled
        $tableRules = $cacheConfig['tables'] ?? [];
        if (is_array($tableRules)) {
            foreach ($tables as $table) {
                $tableRule = $tableRules[$table] ?? null;
                if (is_array($tableRule) && !empty($tableRule['disable'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get the cache store type for a connection.
     *                         initialized from env; if the requested connection is not found.
     *
     * @param string|null $name  Connection name, or null/empty to use default
     *
     * @return string The cache store type ('redis', 'memcached', 'array', 'off')
     *
     * @throws ConfigException If configuration is not initialized and cannot be
     */
    public static function cacheStore(?string $name = null): string
    {
        $snap = self::requireSnapshot();
        $resolved = self::resolveConnectionName($snap, $name);

        $conn = $snap->getConnection($resolved);

        if ($conn === null) {
            throw new ConfigException("Connection '{$resolved}' not found");
        }

        $cache = $conn['cache'] ?? null;

        if (!is_array($cache)) {
            return 'off';
        }

        $store = $cache['store'] ?? null;
        if (!is_array($store)) {
            return 'off';
        }

        $driver = $store['type'] ?? 'off';
        return is_string($driver) ? $driver : 'off';
    }

    /**
     * Whether raw SQL reads must include explicit table hints when cache is enabled.
     *
     * @param string|null $name  Connection name, or null/empty to use default.
     *
     * @return bool True when hintless raw SQL reads must fail loud.
     *
     * @throws ConfigException If configuration is not initialized.
     */
    public static function cacheRequireTableHints(?string $name = null): bool
    {
        $cache = self::cacheConfig($name);

        return !empty($cache['require_table_hints']);
    }

    /**
     * Get the username for a connection.
     *                         initialized from env; if the requested connection is not found.
     *
     * @param string|null $name  Connection name, or null/empty to use default
     *
     * @return string The username
     *
     * @throws ConfigException If configuration is not initialized and cannot be
     */
    public static function username(string $name = 'default'): string
    {
        $snap = self::requireSnapshot();
        $resolved = self::resolveConnectionName($snap, $name);

        $conn = $snap->getConnection($resolved);

        if ($conn === null) {
            throw new ConfigException("Connection '{$resolved}' not found");
        }

        return $conn['user'] ?? $conn['username'] ?? '';
    }

    /**
     * Get the password for a connection.
     *                         initialized from env; if the requested connection is not found.
     *
     * @param string|null $name  Connection name, or null/empty to use default
     *
     * @return string The password
     *
     * @throws ConfigException If configuration is not initialized and cannot be
     */
    public static function password(?string $name = 'default'): string
    {
        $snap = self::requireSnapshot();
        $resolved = self::resolveConnectionName($snap, $name);
        $conn = $snap->getConnection($resolved);

        if ($conn === null) {
            throw new ConfigException("Connection '{$resolved}' not found");
        }

        return $conn['pass'] ?? $conn['password'] ?? '';
    }

    /**
     * Get the PDO options for a connection.
     *                         initialized from env; if the requested connection is not found.
     *
     * @param string|null $name  Connection name, or null/empty to use default
     *
     * @return array The PDO options
     *
     * @throws ConfigException If configuration is not initialized and cannot be
     */
    public static function pdoOptions(?string $name = 'default'): array
    {
        $snap = self::requireSnapshot();
        $resolved = self::resolveConnectionName($snap, $name);
        $conn = $snap->getConnection($resolved);

        if ($conn === null) {
            throw new ConfigException("Connection '{$resolved}' not found");
        }

        $options = $conn['options'] ?? [];
        return is_array($options) ? $options : [];
    }

    /**
     * Get the init SQL statements for a connection.
     *                         initialized from env; if the requested connection is not found.
     *
     * @param string|null $name  Connection name, or null/empty to use default
     *
     * @return array The init SQL statements
     *
     * @throws ConfigException If configuration is not initialized and cannot be
     */
    public static function initSql(?string $name = 'default'): array
    {
        $snap = self::requireSnapshot();
        $resolved = self::resolveConnectionName($snap, $name);
        $conn = $snap->getConnection($resolved);

        if ($conn === null) {
            throw new ConfigException("Connection '{$resolved}' not found");
        }

        $initSql = $conn['init_sql'] ?? [];

        if (!is_array($initSql)) {
            return [];
        }

        // Ensure we only return a flat list of strings
        return array_filter($initSql, 'is_string');
    }

    /**
     * Return all configured connection names.
     * Intended for diagnostics and tooling.
     *
     * @return array<int,string> The connection names
     *
     * @throws ConfigException If configuration is not initialized and env boot fails.
     */
    public static function connectionNames(): array
    {
        return self::requireSnapshot()->getConnectionNames();
    }

    /**
     * Cache namespace.
     *
     * @param ?string $name  Name value.
     *
     * @return string String result.
     */
    public static function cacheNamespace(?string $name = 'default'): string
    {
        $cache = self::cacheConfig($name);

        return is_string($cache['namespace'] ?? null) ? $cache['namespace'] : 'UDA';
    }

    /**
     * Cache host.
     *
     * @param ?string $name  Name value.
     *
     * @return string String result.
     */
    public static function cacheHost(?string $name = 'default'): string
    {
        $store = self::cacheStoreConfig($name);

        return is_string($store['host'] ?? null) ? $store['host'] : '127.0.0.1';
    }

    /**
     * Cache port.
     *
     * @param ?string $name  Name value.
     *
     * @return int Integer result.
     */
    public static function cachePort(?string $name = 'default'): int
    {
        $store = self::cacheStoreConfig($name);
        $type = strtolower((string)($store['type'] ?? ''));

        return (int)($store['port'] ?? ($type === 'memcached' ? 11211 : 6379));
    }

    /**
     * Cache timeout.
     *
     * @param ?string $name  Name value.
     *
     * @return float Floating point result.
     */
    public static function cacheTimeout(?string $name = 'default'): float
    {
        $store = self::cacheStoreConfig($name);

        return (float)($store['timeout'] ?? 1.5);
    }

    /**
     * Cache database.
     *
     * @param ?string $name  Name value.
     *
     * @return int Integer result.
     */
    public static function cacheDatabase(?string $name = 'default'): int
    {
        $store = self::cacheStoreConfig($name);

        return (int)($store['database'] ?? 0);
    }

    /**
     * Require an initialized snapshot, lazily initializing from env if needed.
     * Design choice:
     * - We allow lazy init because the default production path is environment-driven.
     * - If UDA_CONFIG is missing/invalid, we throw ConfigException.
     *
     * @return Snapshot
     *
     * @throws ConfigException If init from environment fails.
     */
    private static function requireSnapshot(): Snapshot
    {
        if (self::$snapshot === null) {
            self::init(); // env route
        }

        /** @var Snapshot $snap */
        $snap = self::$snapshot;

        return $snap;
    }

    /**
     * Resolve connection name.
     *
     * @param Snapshot $snapshot  Validated configuration snapshot.
     * @param ?string  $name      Name value.
     *
     * @return string String result.
     */
    private static function resolveConnectionName(Snapshot $snapshot, ?string $name): string
    {
        if ($name === null || $name === '') {
            return $snapshot->getDefaultConnection() ?? 'default';
        }

        return $name;
    }

    /**
     * Cache config.
     *
     * @param ?string $name  Name value.
     *
     * @return array<string,mixed>
     *
     * @throws ConfigException If the operation fails.
     */
    private static function cacheConfig(?string $name): array
    {
        $snap = self::requireSnapshot();
        $resolved = self::resolveConnectionName($snap, $name);
        $conn = $snap->getConnection($resolved);

        if ($conn === null) {
            throw new ConfigException("Connection '{$resolved}' not found");
        }

        $cache = $conn['cache'] ?? [];

        return is_array($cache) ? $cache : [];
    }

    /**
     * Cache store config.
     *
     * @param ?string $name  Name value.
     *
     * @return array<string,mixed>
     */
    private static function cacheStoreConfig(?string $name): array
    {
        $cache = self::cacheConfig($name);
        $store = $cache['store'] ?? [];

        return is_array($store) ? $store : [];
    }

    /**
     * Read and validate the UDA_CONFIG environment variable.
     *
     * @return string Canonicalized and validated file path.
     *
     * @throws ConfigException If UDA_CONFIG is unset/empty or invalid.
     */
    private static function pathFromEnv(): string
    {
        $path = getenv('UDA_CONFIG');

        if ($path === false) {
            throw new ConfigException('UDA_CONFIG is not set');
        }

        $path = trim($path);

        if ($path === '') {
            throw new ConfigException('UDA_CONFIG is empty');
        }

        return self::normalizePath($path);
    }

    /**
     * Normalize and validate a config file path.
     * Validates:
     * - non-empty
     * - .json extension
     * - file exists and is readable
     * Canonicalization:
     * - resolves realpath() when possible
     *
     * @param string $path  File path.
     *
     * @return string Canonical validated file path.
     *
     * @throws ConfigException If path invalid, missing, unreadable, or wrong extension.
     */
    private static function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new ConfigException('Config file path is empty');
        }

        if (!str_ends_with(strtolower($path), '.json')) {
            throw new ConfigException("Config file must have .json extension: {$path}");
        }

        $real = realpath($path);

        if ($real !== false) {
            $path = $real;
        }

        if (!is_file($path)) {
            throw new ConfigException("Config file not found: {$path}");
        }

        if (!is_readable($path)) {
            throw new ConfigException("Config file not readable: {$path}");
        }

        return $path;
    }

    /**
     * Load and validate a config JSON file into an immutable Snapshot.
     *                         or schema validation fails.
     *
     * @param string $path  Canonical validated config file path.
     *
     * @return Snapshot Validated immutable snapshot.
     *
     * @throws ConfigException If file read fails, JSON parse fails, root is not an object,
     */
    private static function loadAndValidate(string $path): Snapshot
    {
        $json = file_get_contents($path);

        if ($json === false) {
            throw new ConfigException("Failed to read config file: {$path}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException("Invalid JSON in config file: {$path}", 0, $e);
        }

        // JSON object decodes to array when assoc=true.
        if (!is_array($decoded)) {
            throw new ConfigException("Config root must be a JSON object: {$path}");
        }

        $validator = new Validator();

        $snapshot = $validator->validate($decoded);

        return $snapshot;
    }
}
