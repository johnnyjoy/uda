<?php

/**
 * Purpose: Tests for Database::connect argument parsing and API compliance.
 *
 * Verifies the five supported connect call forms per Work Order 004.
 * Ensures Database returns Database, not Driver.
 *
 * @package UDA\Tests\Database
 * @subpackage Tests
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/tests/database
 * @since 1.0.0
 */

/*
 * Purpose: Validates Database::connect API against Work Order 004 requirements.
 */

declare(strict_types=1);

namespace UDA\Tests\Database;

use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Exception\ConfigException;

final class DatabaseConnectTest extends TestCase
{
    private string $tempConfigFile;

    protected function setUp(): void
    {
        // Clear any existing config state
        \UDA\Config::clearForTests();
    }

    protected function tearDown(): void
    {
        \UDA\Config::clearForTests();

        if (isset($this->tempConfigFile) && file_exists($this->tempConfigFile)) {
            unlink($this->tempConfigFile);
        }
    }

    private function createTempConfig(array $config): string
    {
        $this->tempConfigFile = sys_get_temp_dir() . '/uda-test-db-' . uniqid() . '.json';
        file_put_contents($this->tempConfigFile, json_encode($config, JSON_PRETTY_PRINT));

        return $this->tempConfigFile;
    }

    public function testNoArgsUsesEnvConfigAndDefaultConnection(): void
    {
        $config = [
            'defaults' => [
                'connection' => 'main'
            ],
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                    'params' => ['path' => ':memory:']
                ]
            ]
        ];

        $path = $this->createTempConfig($config);
        putenv('UDA_CONFIG=' . $path);

        try {
            $db = Database::connect();
            $this->assertInstanceOf(Database::class, $db);
        } finally {
            putenv('UDA_CONFIG');
        }
    }

    public function testNamedConnectionOnly(): void
    {
        $config = [
            'connections' => [
                'main' => ['driver' => 'sqlite', 'params' => ['path' => ':memory:']]
            ]
        ];

        $path = $this->createTempConfig($config);
        putenv('UDA_CONFIG=' . $path);

        try {
            $db = Database::connect('main');
            $this->assertInstanceOf(Database::class, $db);
        } finally {
            putenv('UDA_CONFIG');
        }
    }

    public function testConfigFileOnly(): void
    {
        $config = [
            'defaults' => [
                'connection' => 'main'
            ],
            'connections' => [
                'main' => ['driver' => 'sqlite', 'params' => ['path' => ':memory:']]
            ]
        ];

        $path = $this->createTempConfig($config);

        // With explicit config file, should work
        $db = Database::connect($path);
        $this->assertInstanceOf(Database::class, $db);
    }

    public function testNamedConnectionAndConfigFile(): void
    {
        $config = [
            'connections' => [
                'main' => ['driver' => 'sqlite', 'params' => ['path' => ':memory:']],
                'other' => ['driver' => 'sqlite', 'params' => ['path' => ':memory:']]
            ]
        ];

        $path = $this->createTempConfig($config);
        $db = Database::connect('main', $path);

        $this->assertInstanceOf(Database::class, $db);
    }

    public function testConfigFileAndNamedConnection(): void
    {
        $config = [
            'connections' => [
                'main' => ['driver' => 'sqlite', 'params' => ['path' => ':memory:']],
                'other' => ['driver' => 'sqlite', 'params' => ['path' => ':memory:']]
            ]
        ];

        $path = $this->createTempConfig($config);
        $db = Database::connect($path, 'main');

        $this->assertInstanceOf(Database::class, $db);
    }

    public function testDatabaseReturnsDatabaseNotDriver(): void
    {
        $config = [
            'connections' => [
                'main' => ['driver' => 'sqlite', 'params' => ['path' => ':memory:']]
            ]
        ];

        $path = $this->createTempConfig($config);
        $db = Database::connect('main', $path);

        // Must return Database, not Driver
        $this->assertInstanceOf(Database::class, $db);
        $this->assertNotInstanceOf(\UDA\Driver::class, $db);

        // Verify Database has public methods
        $this->assertTrue(method_exists($db, 'rows'));
        $this->assertTrue(method_exists($db, 'row'));
        $this->assertTrue(method_exists($db, 'value'));
        $this->assertTrue(method_exists($db, 'select'));
        $this->assertTrue(method_exists($db, 'insert'));
    }

    public function testDefaultResolutionInsideConfigNotDatabase(): void
    {
        $config = [
            'defaults' => [
                'connection' => 'main'
            ],
            'connections' => [
                'main' => ['driver' => 'sqlite', 'params' => ['path' => ':memory:']]
            ]
        ];

        $path = $this->createTempConfig($config);
        $db = Database::connect($path);

        $this->assertInstanceOf(Database::class, $db);

        // Test missing default throws ConfigException
        $config2 = [
            'connections' => [
                'main' => ['driver' => 'sqlite', 'params' => ['path' => ':memory:']]
            ]
        ];

        $path2 = sys_get_temp_dir() . '/uda-test-nodefault-' . uniqid() . '.json';
        file_put_contents($path2, json_encode($config2, JSON_PRETTY_PRINT));

        \UDA\Config::clearForTests();

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('No default connection configured');

        try {
            Database::connect($path2);
        } finally {
            unlink($path2);
        }
    }

    public function testArgumentParsingPositionIndependent(): void
    {
        $config = [
            'connections' => [
                'main' => ['driver' => 'sqlite', 'params' => ['path' => ':memory:']],
                'other' => ['driver' => 'sqlite', 'params' => ['path' => ':memory:']]
            ]
        ];

        $path = $this->createTempConfig($config);

        // Both orders should work
        $db1 = Database::connect('main', $path);
        $db2 = Database::connect($path, 'main');

        $this->assertInstanceOf(Database::class, $db1);
        $this->assertInstanceOf(Database::class, $db2);
    }
}
