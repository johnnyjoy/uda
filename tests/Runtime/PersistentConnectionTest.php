<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use UDA\Database;
use UDA\Driver;

/**
 * Persistent connections are intrinsic: every connection is persistent and it cannot be
 * overridden by consumer config (same posture as PDO::ATTR_ERRMODE). Checkout clears any
 * transaction a prior request left open on a pooled handle.
 */
final class PersistentConnectionTest extends TestCase
{
    /**
     * @return array<int,mixed>
     */
    private static function resolvedOptions(Driver $driver): array
    {
        $method = new ReflectionMethod(Driver::class, 'resolvePdoOptions');
        $method->setAccessible(true);

        /** @var array<int,mixed> $opts */
        $opts = $method->invoke($driver);

        return $opts;
    }

    public function test_connections_are_always_persistent(): void
    {
        Database::connect('alpha', UDA_TEST_CONFIG);
        $opts = self::resolvedOptions(Driver::connect('alpha'));

        self::assertTrue($opts[PDO::ATTR_PERSISTENT] ?? null);
    }

    public function test_persistence_is_not_overridable_by_options(): void
    {
        Database::connect('opt_override', UDA_TEST_CONFIG);
        $opts = self::resolvedOptions(Driver::connect('opt_override'));

        self::assertTrue($opts[PDO::ATTR_PERSISTENT] ?? null);
    }

    public function test_checkout_rolls_back_stray_transaction(): void
    {
        Database::connect('alpha', UDA_TEST_CONFIG);
        $driver = Driver::connect('alpha');

        $pdoProp = new ReflectionProperty(Driver::class, 'pdo');
        $pdoProp->setAccessible(true);

        /** @var PDO $pdo */
        $pdo = $pdoProp->getValue($driver);

        $pdo->beginTransaction();
        self::assertTrue($pdo->inTransaction());

        $reset = new ReflectionMethod(Driver::class, 'resetPersistentState');
        $reset->setAccessible(true);
        $reset->invoke($driver, $pdo);

        self::assertFalse($pdo->inTransaction());
    }
}
