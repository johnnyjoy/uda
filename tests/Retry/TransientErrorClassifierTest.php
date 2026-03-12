<?php

declare(strict_types=1);

namespace Tests\Retry;

use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UDA\Driver;
use UDA\Retry\TransientErrorClassifier;

final class TransientErrorClassifierTest extends TestCase
{
    public function testDriverAffirmativeOverride(): void
    {
        $driver = $this->mockDriver(returnValue: true);
        $classifier = new TransientErrorClassifier();

        $this->assertTrue($classifier->isTransient(new RuntimeException('boom'), $driver));
    }

    public function testDriverNegativeOverride(): void
    {
        $driver = $this->mockDriver(returnValue: false);
        $classifier = new TransientErrorClassifier();

        $pdoException = new PDOException('deadlock');
        $pdoException->errorInfo = ['40001'];

        $this->assertFalse($classifier->isTransient($pdoException, $driver));
    }

    public function testDriverNullAllowsFallback(): void
    {
        $driver = $this->mockDriver(returnValue: null);
        $classifier = new TransientErrorClassifier();

        $pdoException = new PDOException('deadlock');
        $pdoException->errorInfo = ['40p01'];

        $this->assertTrue($classifier->isTransient($pdoException, $driver));
    }

    public function testSqlStateClassification(): void
    {
        $classifier = new TransientErrorClassifier();
        $exception = new PDOException('deadlock');
        $exception->errorInfo = ['HYT00'];

        $this->assertTrue($classifier->isTransient($exception));
    }

    public function testSqlStateInsideWrappedException(): void
    {
        $classifier = new TransientErrorClassifier();
        $pdo = new PDOException('deadlock');
        $pdo->errorInfo = ['40001'];
        $wrapped = new RuntimeException('query failed', 0, $pdo);

        $this->assertTrue($classifier->isTransient($wrapped));
    }

    public function testSqlStateNonTransient(): void
    {
        $classifier = new TransientErrorClassifier();
        $exception = new PDOException('syntax');
        $exception->errorInfo = ['42000'];

        $this->assertFalse($classifier->isTransient($exception));
    }

    public function testMessageFallback(): void
    {
        $classifier = new TransientErrorClassifier();
        $exception = new RuntimeException('Connection reset by peer');

        $this->assertTrue($classifier->isTransient($exception));
    }

    public function testNonTransientMessage(): void
    {
        $classifier = new TransientErrorClassifier();

        $this->assertFalse($classifier->isTransient(new RuntimeException('syntax error near select')));
    }

    private function mockDriver(?bool $returnValue): Driver
    {
        $driver = $this->getMockBuilder(Driver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isTransientError'])
            ->getMockForAbstractClass();

        $driver->method('isTransientError')->willReturn($returnValue);

        return $driver;
    }
}
