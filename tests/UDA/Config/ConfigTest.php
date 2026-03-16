<?php

/**
 * Purpose: Tests for UDA\Config, verifying file-only configuration,
 * environment variable loading, and immutable snapshot behavior.
 *
 * @package UDA\Tests\Config
 * @subpackage Tests
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/tests/config
 * @since 1.0.0
 */

/*
 * Purpose: Validates Config implementation against architectural requirements.
 */

declare(strict_types=1);

namespace UDA\Tests\Config;

use PHPUnit\Framework\TestCase;
use UDA\Config;
use UDA\Exception\ConfigException;

final class ConfigTest extends TestCase
{
    private string $tempConfigFile;

    protected function setUp(): void
    {
        Config::clearForTests();
    }

    protected function tearDown(): void
    {
        Config::clearForTests();

        if (isset($this->tempConfigFile) && file_exists($this->tempConfigFile)) {
            unlink($this->tempConfigFile);
        }
    }

    private function createTempConfig(array $config): string
    {
        $this->tempConfigFile = sys_get_temp_dir() . '/uda-test-config-' . uniqid() . '.json';
        file_put_contents($this->tempConfigFile, json_encode($config, JSON_PRETTY_PRINT));

        return $this->tempConfigFile;
    }

    public function testFileOnlyConfigWithExplicitPath(): void
    {
        $config = [
            'defaults' => [
                'connection' => 'main'
            ],
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                    'host' => ':memory:'
                ]
            ]
        ];

        $path = $this->createTempConfig($config);
        Config::init($path);

        $connection = Config::connection('main');
        $this->assertSame('sqlite', $connection['driver']);
        $this->assertSame(':memory:', $connection['host']);
    }

    public function testFileOnlyConfigFailsWithNonJsonExtension(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config file must have .json extension');

        Config::init('invalid.yaml');
    }

    public function testEnvRouteRequiresUdaConfigEnvVar(): void
    {
        // UDA_CONFIG not set
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('UDA_CONFIG is not set');

        Config::init();
    }

    public function testSamePathReinitIsNoOp(): void
    {
        $config = [
            'connections' => [
                'main' => ['driver' => 'sqlite']
            ]
        ];

        $path = $this->createTempConfig($config);

        Config::init($path);
        $names1 = Config::connectionNames();

        // Reinitialize with same path - should be no-op
        Config::init($path);
        $names2 = Config::connectionNames();

        $this->assertSame($names1, $names2);
    }

    public function testConflictingReinitFails(): void
    {
        $config1 = [
            'connections' => [
                'main' => ['driver' => 'sqlite']
            ]
        ];

        $config2 = [
            'connections' => [
                'alt' => ['driver' => 'pgsql']
            ]
        ];

        $path1 = $this->createTempConfig($config1);
        $path2 = $this->createTempConfig($config2);

        Config::init($path1);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("cannot re-init from");

        Config::init($path2);
    }

    public function testMissingDefaultConnectionFails(): void
    {
        $config = [
            'connections' => [
                'main' => ['driver' => 'sqlite']
            ]
            // No default specified
        ];

        $path = $this->createTempConfig($config);
        Config::init($path);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('No default connection configured');

        Config::connection();
    }

    public function testNamedAndDefaultConnectionResolution(): void
    {
        $config = [
            'defaults' => [
                'connection' => 'defaultdb'
            ],
            'connections' => [
                'defaultdb' => ['driver' => 'sqlite', 'alias' => 'default'],
                'otherdb' => ['driver' => 'pgsql', 'alias' => 'other']
            ]
        ];

        $path = $this->createTempConfig($config);
        Config::init($path);

        // Named connection
        $named = Config::connection('otherdb');
        $this->assertSame('pgsql', $named['driver']);

        // Default connection (null)
        $default = Config::connection();
        $this->assertSame('sqlite', $default['driver']);

        // Default connection (empty string)
        $defaultEmpty = Config::connection('');
        $this->assertSame('sqlite', $defaultEmpty['driver']);
    }

    public function testConnectionReturnsCanonicalSanitizedData(): void
    {
        $config = [
            'defaults' => [
                'connection' => 'main'
            ],
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                    'host' => ':memory:',
                    'options' => [
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
                    ]
                ]
            ]
        ];

        $path = $this->createTempConfig($config);
        Config::init($path);

        $connection = Config::connection();

        // Should contain only validated keys (no extra processing needed)
        $this->assertArrayHasKey('driver', $connection);
        $this->assertArrayHasKey('host', $connection);
        $this->assertArrayHasKey('options', $connection);

        // Should NOT contain DSN (that's Driver's responsibility)
        $this->assertArrayNotHasKey('dsn', $connection);

        // Options should be properly structured
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $connection['options'][\PDO::ATTR_ERRMODE]);
    }

    public function testConfigIsImmutableAfterInit(): void
    {
        $config = [
            'connections' => [
                'main' => ['driver' => 'sqlite']
            ]
        ];

        $path = $this->createTempConfig($config);
        Config::init($path);

        $original = Config::connection('main');

        // Modify the temp file
        $config['connections']['main']['driver'] = 'pgsql';
        file_put_contents($this->tempConfigFile, json_encode($config, JSON_PRETTY_PRINT));

        // Connection data should still be original (immutable snapshot)
        $afterMod = Config::connection('main');
        $this->assertSame('sqlite', $afterMod['driver']);
    }
}
