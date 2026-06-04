<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use UDA\Config;
use UDA\Config\Snapshot;
use UDA\Database;
use UDA\Driver;

/**
 * Opt-in persistent connections: flag mapping, option precedence, and checkout safety.
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

    public function test_connections_are_persistent_by_default(): void
    {
        Database::connect('alpha', UDA_TEST_CONFIG);
        $opts = self::resolvedOptions(Driver::connect('alpha'));

        self::assertTrue($opts[PDO::ATTR_PERSISTENT] ?? null);
    }

    public function test_explicit_persistent_flag_maps_to_pdo_attr_persistent(): void
    {
        Database::connect('persist', UDA_TEST_CONFIG);
        $opts = self::resolvedOptions(Driver::connect('persist'));

        self::assertTrue($opts[PDO::ATTR_PERSISTENT] ?? null);
    }

    public function test_persistent_false_opts_out(): void
    {
        Database::connect('persist_disabled', UDA_TEST_CONFIG);
        $opts = self::resolvedOptions(Driver::connect('persist_disabled'));

        self::assertFalse((bool) ($opts[PDO::ATTR_PERSISTENT] ?? false));
    }

    public function test_explicit_option_overrides_persistent_flag(): void
    {
        Database::connect('persist_off', UDA_TEST_CONFIG);
        $opts = self::resolvedOptions(Driver::connect('persist_off'));

        self::assertFalse($opts[PDO::ATTR_PERSISTENT] ?? null);
    }

    public function test_config_persistent_accessor_reads_flag(): void
    {
        Database::connect('persist', UDA_TEST_CONFIG);

        self::assertTrue(Config::persistent('persist'));
        self::assertTrue(Config::persistent('alpha'));
        self::assertFalse(Config::persistent('persist_disabled'));
    }

    public function test_checkout_rolls_back_stray_transaction(): void
    {
        Database::connect('persist', UDA_TEST_CONFIG);
        $driver = Driver::connect('persist');

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

    public function test_persistent_is_the_default_when_unset(): void
    {
        $snapshot = new Snapshot(
            ['plain' => ['driver' => 'sqlite']],
            [],
        );

        self::assertTrue($snapshot->getPersistent('plain'));
    }

    public function test_defaults_persistent_is_inherited_by_connections(): void
    {
        $snapshot = new Snapshot(
            ['plain' => ['driver' => 'sqlite']],
            ['persistent' => true],
        );

        self::assertTrue($snapshot->getPersistent('plain'));
    }

    public function test_connection_persistent_overrides_defaults(): void
    {
        $snapshot = new Snapshot(
            ['off' => ['driver' => 'sqlite', 'persistent' => false]],
            ['persistent' => true],
        );

        self::assertFalse($snapshot->getPersistent('off'));
    }
}
