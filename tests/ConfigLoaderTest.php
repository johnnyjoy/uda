<?php

declare(strict_types=1);

namespace UniversalDataAbstraction\Tests;

use PHPUnit\Framework\TestCase;
use UDA\Core\ConfigLoader;
use UDA\Exception\ConfigException;

class ConfigLoaderTest extends TestCase
{
    public function testLoadConfigFromFile(): void
    {
        // Test with a valid config file
        $config = ConfigLoader::load(__DIR__ . '/../config/example-config.json');

        $this->assertArrayHasKey('connections', $config);
        $this->assertArrayHasKey('main_pg', $config['connections']);
        $this->assertArrayHasKey('audit_sqlite', $config['connections']);
        $this->assertArrayHasKey('client_0123', $config['connections']);
    }

    public function testLoadConfigWithInvalidPath(): void
    {
        $this->expectException(ConfigException::class);
        ConfigLoader::load('/non/existent/file.php');
    }

    public function testGetDsnFromParams(): void
    {
        $config = [
            'driver' => 'sqlite',
            'params' => [
                'path' => '/tmp/test.db'
            ]
        ];

        $dsn = ConfigLoader::getDsn($config);
        $this->assertEquals('sqlite:/tmp/test.db', $dsn);
    }

    public function testGetDsnFromDsn(): void
    {
        $config = [
            'dsn' => 'mysql:host=localhost;dbname=test'
        ];

        $dsn = ConfigLoader::getDsn($config);
        $this->assertEquals('mysql:host=localhost;dbname=test', $dsn);
    }

    public function testGetCredentials(): void
    {
        $config = [
            'user' => ['env' => 'TEST_USER'],
            'pass' => ['env' => 'TEST_PASS']
        ];

        // Mock environment variables
        putenv('TEST_USER=testuser');
        putenv('TEST_PASS=testpass');
        $_ENV['TEST_USER'] = 'testuser';
        $_ENV['TEST_PASS'] = 'testpass';

        [$user, $pass] = ConfigLoader::getCredentials($config);
        $this->assertEquals('testuser', $user);
        $this->assertEquals('testpass', $pass);
    }

    public function testCacheIsClearedOnDemand(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'uda-config-');
        if ($tmpFile === false) {
            $this->fail('Unable to create temporary config file');
        }

        $initial = [
            'connections' => [
                'cache-test' => ['driver' => 'sqlite', 'params' => ['path' => ':memory:']]
            ]
        ];
        file_put_contents($tmpFile, json_encode($initial));

        $loadedFirst = ConfigLoader::load($tmpFile);
        $this->assertSame('sqlite', $loadedFirst['connections']['cache-test']['driver']);

        $updated = $initial;
        $updated['connections']['cache-test']['driver'] = 'pgsql';
        file_put_contents($tmpFile, json_encode($updated));

        $cached = ConfigLoader::load($tmpFile);
        $this->assertSame('sqlite', $cached['connections']['cache-test']['driver']);

        ConfigLoader::clearCache($tmpFile);
        $reloaded = ConfigLoader::load($tmpFile);
        $this->assertSame('pgsql', $reloaded['connections']['cache-test']['driver']);

        unlink($tmpFile);
    }
}
