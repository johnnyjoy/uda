<?php

declare(strict_types=1);

/** @purpose Integration test - verify Config system works end-to-end */

namespace UniversalDataAbstraction\Tests;

use PHPUnit\Framework\TestCase;
use UDA\Config;
use UDA\Config\Snapshot;
use UDA\Exception\ConfigException;

final class ConfigIntegrationTest extends TestCase
{
    private static array $testConfig;
    private static string $testConfigPath;
    
    public static function setUpBeforeClass(): void
    {
        self::$testConfig = [
            'defaults' => [
                'connection' => 'sqlite',
                'options' => [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            ],
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'dsn' => 'sqlite::memory:'
                ],
                'mysql' => [
                    'driver' => 'mysql',
                    'params' => [
                        'host' => 'localhost',
                        'dbname' => 'test'
                    ],
                    'user' => 'root',
                    'pass' => '',
                    'options' => [
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                ]
            ]
        ];
        
        self::$testConfigPath = sys_get_temp_dir() . '/uda_test_config.json';
        file_put_contents(self::$testConfigPath, json_encode(self::$testConfig, JSON_PRETTY_PRINT));
        
        // Load config once
        Config::load(self::$testConfigPath);
    }
    
    public static function tearDownAfterClass(): void
    {
        if (file_exists(self::$testConfigPath)) {
            unlink(self::$testConfigPath);
        }
        Config::clear();
    }
    
    public function testLoadsValidConfig(): void
    {
        $snapshot = Config::snapshot();
        
        $this->assertInstanceOf(Snapshot::class, $snapshot);
        $this->assertEquals('sqlite', $snapshot->getDefaultConnection());
    }
    
    public function testGetConnection(): void
    {
        $sqlite = Config::connection('sqlite');
        
        $this->assertEquals('sqlite', $sqlite['driver']);
        $this->assertEquals('sqlite::memory:', $sqlite['dsn']);
    }
    
    public function testGetDefaultConnection(): void
    {
        $default = Config::connection();
        
        $this->assertEquals('sqlite', $default['driver']);
    }
    
    public function testGetConnectionNames(): void
    {
        $names = Config::connectionNames();
        
        $this->assertCount(2, $names);
        $this->assertContains('sqlite', $names);
        $this->assertContains('mysql', $names);
    }
    
    public function testHasConnection(): void
    {
        $this->assertTrue(Config::hasConnection('sqlite'));
        $this->assertTrue(Config::hasConnection('mysql'));
        $this->assertFalse(Config::hasConnection('postgres'));
    }
    
    public function testGetMergedOptions(): void
    {
        // SQLite uses defaults only
        $sqliteOptions = Config::options('sqlite');
        $this->assertContains(PDO::ATTR_ERRMODE, $sqliteOptions);
        $this->assertEquals(PDO::ERRMODE_EXCEPTION, $sqliteOptions[PDO::ATTR_ERRMODE]);
        
        // MySQL has overrides
        $mysqlOptions = Config::options('mysql');
        $this->assertContains(PDO::ATTR_EMULATE_PREPARES, $mysqlOptions);
        $this->assertFalse($mysqlOptions[PDO::ATTR_EMULATE_PREPARES]);
    }
    
    public function testThrowsOnMissingConnection(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("Connection 'missing' not found");
        
        Config::connection('missing');
    }
    
    public function testThrowsOnInvalidConfig(): void
    {
        $invalidPath = sys_get_temp_dir() . '/uda_invalid_config.json';
        file_put_contents($invalidPath, json_encode(['invalid' => 'structure']));
        
        try {
            Config::load($invalidPath);
            $this->fail('Should have thrown ConfigException');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('must have', $e->getMessage());
        } finally {
            unlink($invalidPath);
        }
    }
    
    public function testCanClearAndReload(): void
    {
        Config::clear();
        $this->assertNull(Config::snapshot()->getDefaultConnection());
        
        Config::load(self::$testConfigPath);
        $this->assertEquals('sqlite', Config::defaultConnection());
    }
}
