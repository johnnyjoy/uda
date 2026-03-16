<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;
use UDA\Config;
use UDA\Exception\ConfigException;
use UDA\Safety\GuardrailConfig;

final class GuardrailConfigTest extends TestCase
{
    private ?string $tempConfig = null;

    protected function setUp(): void
    {
        Config::clearForTests();
    }

    protected function tearDown(): void
    {
        Config::clearForTests();

        if ($this->tempConfig !== null && file_exists($this->tempConfig)) {
            unlink($this->tempConfig);
        }
    }

    public function testGuardrailDefaultsAppliedWhenMissing(): void
    {
        $path = $this->writeConfig([
            'defaults' => ['connection' => 'main'],
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                    'host' => ':memory:',
                ],
            ],
        ]);

        Config::init($path);

        $connection = Config::connection('main');
        $this->assertArrayHasKey('guardrailConfig', $connection);
        $this->assertInstanceOf(GuardrailConfig::class, $connection['guardrailConfig']);

        $defaults = GuardrailConfig::defaults();
        $this->assertSame($defaults->toArray(), $connection['guardrailConfig']->toArray());
        $this->assertSame($defaults->toArray(), Config::guardrailConfig('main')->toArray());
    }

    public function testGuardrailOverridesAndHelperMethods(): void
    {
        $path = $this->writeConfig([
            'defaults' => ['connection' => 'primary'],
            'connections' => [
                'primary' => [
                    'driver' => 'pgsql',
                    'guardrails' => [
                        'enabled' => true,
                        'productionMode' => true,
                        'updateRequiresWhere' => false,
                        'deleteRequiresWhere' => true,
                        'requireLimitOnWrites' => true,
                        'requireLimitOnWritesExcept' => ['Logs', '  AUDIT  ', ''],
                        'truncateBlocked' => true,
                    ],
                ],
            ],
        ]);

        Config::init($path);

        $config = Config::guardrailConfig('primary');
        $this->assertTrue($config->enabled);
        $this->assertTrue($config->productionMode);
        $this->assertFalse($config->updateRequiresWhere);
        $this->assertTrue($config->deleteRequiresWhere);
        $this->assertTrue($config->requireLimitOnWrites);
        $this->assertTrue($config->truncateBlocked);
        $this->assertSame(['logs', 'audit'], $config->requireLimitOnWritesExcept);

        $this->assertFalse($config->requiresWhere('update'));
        $this->assertTrue($config->requiresWhere('delete'));
        $this->assertTrue($config->requiresLimitOnWrites('users'));
        $this->assertFalse($config->requiresLimitOnWrites('LOGS'));
        $this->assertTrue($config->requiresLimitOnWrites());
        $this->assertTrue($config->isTableLimitExempt('Audit'));

        $this->assertSame($config, Config::guardrailConfig());
    }

    public function testGuardrailAccessorThrowsForUnknownConnection(): void
    {
        $path = $this->writeConfig([
            'defaults' => ['connection' => 'main'],
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                ],
            ],
        ]);

        Config::init($path);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("Connection 'missing' not found");

        Config::guardrailConfig('missing');
    }

    private function writeConfig(array $config): string
    {
        $path = sys_get_temp_dir() . '/uda-guardrail-config-' . uniqid('', true) . '.json';
        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT));

        $this->tempConfig = $path;

        return $path;
    }
}
