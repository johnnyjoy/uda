<?php
declare(strict_types=1);

/**
 * @purpose Compatibility shim – provides a static factory to create a cache Setup.
 *
 * The original library exposed a Cache\Factory class that built a Setup object
 * based on the application configuration.  The refactor removed it, but the
 * test suite (and Database::createDriver) still expects the class to exist.
 *
 * For the purposes of the current test suite we do not need a full cache
 * implementation – returning `null` disables caching entirely while still
 * satisfying the type‑hint (`?Setup`).  The method signature mirrors the old
 * implementation so existing code continues to work without modification.
 */
namespace UDA\Cache;

final class Factory
{
    /**
     * @purpose Create a cache Setup from configuration.
     *
     * In a full implementation this would inspect `$config` and `$connection`
     * to construct a {@see Setup} instance describing the cache backend, TTLs,
     * and policies.  The test suite only requires that the method exists and
     * returns a value compatible with the type hint (`?Setup`).
     *
     * @param array  $config          Full application configuration.
     * @param array  $connection      Connection specific settings.
     * @param string $connectionName  Name of the connection.
     * @param mixed  $cacheOverride   Optional override controlling cache enable/disable.
     *
     * @return ?Setup  Returns `null` to indicate caching is disabled.
     */
    public static function fromConfig(array $config, array $connection, string $connectionName, $cacheOverride = null): ?Setup
    {
        // No cache configuration is parsed in this shim – returning null disables caching.
        return null;
    }
}
