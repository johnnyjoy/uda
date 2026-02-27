<?php

declare(strict_types=1);
/**
 * @purpose Provides Database::connect() factory to return configured Driver instances.
 *
 * This façade supplies a static `connect()` method that loads the application
 * configuration, resolves the appropriate connection name (falling back to
 * defaults), builds a PDO instance, creates the cache setup (if caching is
 * enabled) and finally returns a concrete `Driver` implementation. It
 * encapsulates all boot‑strapping logic so callers never interact with the
 * lower‑level config loader or driver factories directly.
 */

namespace UDA;

use PDO;
use PDOException;
use UDA\Cache\Factory as CacheFactory;
use UDA\Core\ConfigLoader;
use UDA\Exception\ConnectionException;
use UDA\Exception\ConfigException;

final class Database
{
    public static function connect(?string $name = null, ?string $configFile = null, ?array $options = null): Driver
    {
        $config = ConfigLoader::load($configFile);
        $connectionName = self::resolveConnectionName($config, $name);
        $connection = $config['connections'][$connectionName] ?? null;

        if (!is_array($connection)) {
            throw new ConfigException("Connection '{$connectionName}' not found in configuration");
        }

        return self::createDriver($connectionName, $connection, $config, $options);
    }

    private static function resolveConnectionName(array $config, ?string $name): string
    {
        if ($name !== null) {
            return $name;
        }

        if (isset($config['default']) && is_string($config['default'])) {
            return $config['default'];
        }

        if (isset($config['defaults']['connection']) && is_string($config['defaults']['connection'])) {
            return $config['defaults']['connection'];
        }

        throw new ConfigException('No connection name provided and no default configured');
    }

    private static function resolveOptions(array $config, array $connection): array
    {
        $defaults = $config['defaults']['options'] ?? [];
        $overrides = $connection['options'] ?? [];

        return array_merge($defaults, $overrides);
    }

    private static function createDriver(string $connectionName, array $connection, array $config, ?array $options = null): Driver
    {
        $driverName = $connection['driver'] ?? '';

        if ($driverName === '') {
            throw new ConfigException("Connection '{$connectionName}' missing driver");
        }

        $dsn = ConfigLoader::getDsn($connection);
        [$user, $pass] = ConfigLoader::getCredentials($connection);
        $options = array_replace([
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ], self::resolveOptions($config, $connection));

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $ex) {
            throw new ConnectionException("Failed to connect to '{$connectionName}': " . $ex->getMessage(), 0, $ex);
        }

        $cacheOverride = $options['cache'] ?? null;
        $cacheSetup = CacheFactory::fromConfig($config, $connection, $connectionName, $cacheOverride);
        return Driver::fromConfig($connectionName, $connection, $pdo, $cacheSetup);
    }
}
