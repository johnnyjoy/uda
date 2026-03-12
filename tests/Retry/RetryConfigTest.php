<?php

declare(strict_types=1);

namespace Tests\Retry;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UDA\Retry\RetryConfig;

final class RetryConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new RetryConfig();

        $this->assertFalse($config->enabled);
        $this->assertSame(3, $config->maxAttempts);
        $this->assertSame('exponential', $config->strategy);
        $this->assertSame(10, $config->baseDelayMs);
        $this->assertSame(500, $config->maxDelayMs);
        $this->assertFalse($config->retryWrites);
        $this->assertFalse($config->retryInTransactions);
        $this->assertTrue($config->jitter);
    }

    public function testFromArrayOverrides(): void
    {
        $config = RetryConfig::fromArray([
            'enabled' => true,
            'maxAttempts' => 5,
            'strategy' => 'fixed',
            'baseDelayMs' => 25,
            'maxDelayMs' => 100,
            'retryWrites' => true,
            'retryInTransactions' => true,
            'jitter' => false,
        ]);

        $this->assertTrue($config->enabled);
        $this->assertSame(5, $config->maxAttempts);
        $this->assertSame('fixed', $config->strategy);
        $this->assertSame(25, $config->baseDelayMs);
        $this->assertSame(100, $config->maxDelayMs);
        $this->assertTrue($config->retryWrites);
        $this->assertTrue($config->retryInTransactions);
        $this->assertFalse($config->jitter);
    }

    public function testValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryConfig(maxAttempts: 0);
    }

    public function testInvalidBaseDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryConfig(baseDelayMs: -1);
    }

    public function testInvalidMaxDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetryConfig::fromArray(['baseDelayMs' => 50, 'maxDelayMs' => 25]);
    }

    public function testInvalidStrategy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetryConfig::fromArray(['strategy' => 'quadratic']);
    }

    public function testStrategyIsNormalized(): void
    {
        $config = RetryConfig::fromArray(['strategy' => 'Exponential']);

        $this->assertSame('exponential', $config->strategy);
    }

    public function testStringBooleanParsing(): void
    {
        $config = RetryConfig::fromArray([
            'enabled' => 'false',
            'retryWrites' => '0',
            'retryInTransactions' => '1',
            'jitter' => 'no',
        ]);

        $this->assertFalse($config->enabled);
        $this->assertFalse($config->retryWrites);
        $this->assertTrue($config->retryInTransactions);
        $this->assertFalse($config->jitter);
    }
}
