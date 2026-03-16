<?php

declare(strict_types=1);

namespace UDA;

use UDA\Config\Snapshot;
use UDA\Config\Validator;
use UDA\Exception\ConfigException;
use UDA\Replay\ReplayConfig;
use UDA\Safety\GuardrailConfig;

/**
 * @package UDA
 * @subpackage Core
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/core/config
 * @since 1.0.0
 */

/*
 * Purpose: Owns the process-wide configuration state for UDA and exposes a static query API backed by an immutable Snapshot.
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
     * @var array<string,GuardrailConfig>
     */
    private static array $guardrailConfigCache = [];

    private static ?ReplayConfig $replayConfig = null;

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
     *
     * Two routes:
     * - init() : loads from UDA_CONFIG environment variable
     * - init($filePath) : loads explicitly from the given JSON file path
     *
     * Idempotence / single-source rule:
     * - First init wins for the process lifetime.
     * - Calling init() repeatedly with the same canonical path is a no-op.
     * - Calling init() with a different file path than originally used throws.
     *
     * This is intended to be called by Database::connect() implicitly (lazy init),
     * or by bootstrapping code early in the process.
     *
     * @param string|null $file Optional explicit JSON config file path.
     *
     * @return void
     *
     * @throws ConfigException If the environment variable is missing/empty,
     *                         the file path is invalid, the file is unreadable,
     *                         the JSON is invalid, validation fails, or init is
     *                         attempted from a conflicting source.
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
     * Get an effective (merged, canonical) connection configuration.
     *
     * Default resolution:
     * - If $name is null/empty, the Snapshot's configured default connection is used.
     * - If no default is configured, ConfigException is thrown.
     *
     * This method is the only place where "default connection" resolution occurs.
     * Caller code must not implement default resolution.
     *
     * Returned data must be treated as canonical by downstream domains; it should
     * not require lowercasing, trimming, type-casting, or schema checks outside Config.
     *
     * @param string|null $name Connection name, or null/empty to use default.
     *
     * @return array<string,mixed> Canonical connection configuration.
     *
     * @throws ConfigException If configuration is not initialized and cannot be
     *                         initialized from env; if default is required but missing;
     *                         or if the requested connection is not found.
     */
    public static function connection(?string $name = null): array
    {
        $snap = self::requireSnapshot();
        $resolved = self::resolveConnectionName($snap, $name);

        $conn = $snap->getConnection($resolved);

        if ($conn === null) {
            throw new ConfigException("Connection '{$resolved}' not found");
        }

        $conn['guardrailConfig'] = self::guardrailConfigForConnection($resolved, $conn);

        return $conn;
    }

    public static function resolvedConnectionName(?string $name = null): string
    {
        $snap = self::requireSnapshot();

        return self::resolveConnectionName($snap, $name);
    }

    /**
     * Return all configured connection names.
     *
     * Intended for diagnostics and tooling.
     *
     * @return array<int,string>
     *
     * @throws ConfigException If configuration is not initialized and env boot fails.
     */
    public static function connectionNames(): array
    {
        return self::requireSnapshot()->getConnectionNames();
    }

    public static function guardrailConfig(?string $name = null): GuardrailConfig
    {
        $snap = self::requireSnapshot();
        $resolved = self::resolveConnectionName($snap, $name);

        $conn = $snap->getConnection($resolved);

        if ($conn === null) {
            throw new ConfigException("Connection '{$resolved}' not found");
        }

        return self::guardrailConfigForConnection($resolved, $conn);
    }

    public static function replay(): ReplayConfig
    {
        self::requireSnapshot();

        if (self::$replayConfig === null) {
            self::$replayConfig = ReplayConfig::defaults();
        }

        return self::$replayConfig;
    }

    /**
     * Clears configuration state.
     *
     * This is intended strictly for tests. Production code should not reset global
     * configuration mid-process.
     *
     * @return void
     */
    public static function clearForTests(): void
    {
        self::$snapshot = null;
        self::$sourcePath = null;
        self::$guardrailConfigCache = [];
        self::$replayConfig = null;
    }

    /**
     * Require an initialized snapshot, lazily initializing from env if needed.
     *
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
     *
     * Validates:
     * - non-empty
     * - .json extension
     * - file exists and is readable
     *
     * Canonicalization:
     * - resolves realpath() when possible
     *
     * @param string $path File path.
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
     *
     * @param string $path Canonical validated config file path.
     *
     * @return Snapshot Validated immutable snapshot.
     *
     * @throws ConfigException If file read fails, JSON parse fails, root is not an object,
     *                         or schema validation fails.
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

        self::$replayConfig = ReplayConfig::fromArray($decoded['replay'] ?? []);

        return $snapshot;
    }

    private static function resolveConnectionName(Snapshot $snap, ?string $name): string
    {
        $resolved = ($name !== null && $name !== '')
            ? $name
            : $snap->getDefaultConnection();

        if ($resolved === null || $resolved === '') {
            throw new ConfigException('No default connection configured');
        }

        if (!$snap->hasConnection($resolved)) {
            throw new ConfigException("Connection '{$resolved}' not found");
        }

        return $resolved;
    }

    /**
     * @param array<string,mixed> $connection
     */
    private static function guardrailConfigForConnection(string $name, array $connection): GuardrailConfig
    {
        if (isset(self::$guardrailConfigCache[$name])) {
            return self::$guardrailConfigCache[$name];
        }

        $existing = $connection['guardrailConfig'] ?? null;
        if ($existing instanceof GuardrailConfig) {
            $config = $existing;
        } else {
            $guardrails = $connection['guardrails'] ?? [];

            if (!is_array($guardrails)) {
                $guardrails = [];
            }

            $config = GuardrailConfig::fromArray($guardrails);
        }

        self::$guardrailConfigCache[$name] = $config;

        return $config;
    }
}
