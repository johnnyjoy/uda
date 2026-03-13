<?php

declare(strict_types=1);

namespace UDA\Replay;

use UDA\Config;
use UDA\Database;
use UDA\Exception\ConfigException;
use UDA\Tracing\ReplayCaptureListener;
use UDA\Tracing\ReplayStorageInterface;
use UDA\Tracing\Storage\FileReplayStorage;

final class ReplayBootstrapper
{
    private static bool $registered = false;

    public static function boot(): void
    {
        if (self::$registered) {
            return;
        }

        $config = Config::replay();

        if (!$config->enabled) {
            return;
        }

        $storage = self::createStorage($config);
        Database::addTraceListener(new ReplayCaptureListener($storage, $config));
        self::$registered = true;
    }

    public static function reset(): void
    {
        self::$registered = false;
    }

    private static function createStorage(ReplayConfig $config): ReplayStorageInterface
    {
        return match ($config->backend) {
            'file' => new FileReplayStorage(
                directory: self::resolveDirectory($config->directory),
                maxSqlLength: $config->maxSqlLength,
                maxParamSize: $config->maxParamSize,
                enabled: true
            ),
            default => throw new ConfigException('Unsupported replay backend: ' . $config->backend),
        };
    }

    private static function resolveDirectory(string $directory): string
    {
        if ($directory === '') {
            return self::resolveDirectory('storage/replay');
        }

        if (str_starts_with($directory, DIRECTORY_SEPARATOR)) {
            return $directory;
        }

        if (preg_match('/^[A-Za-z]:\\\\/', $directory) === 1) {
            return $directory;
        }

        return getcwd() . DIRECTORY_SEPARATOR . $directory;
    }
}
