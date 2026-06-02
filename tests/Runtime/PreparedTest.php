<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use UDA\Driver\Prepared;

final class PreparedTest extends TestCase
{
    public function test_reuses_prepared_statement_for_same_sql(): void
    {
        $prepared = new Prepared();
        $calls = 0;
        $prepare = function (string $sql) use (&$calls): \PDOStatement {
            $calls++;
            $pdo = new \PDO('sqlite::memory:');

            return $pdo->prepare($sql);
        };

        $first = $prepared->get('SELECT 1', $prepare);
        $second = $prepared->get('SELECT 1', $prepare);

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
    }

    public function test_clear_drops_cached_statements(): void
    {
        $prepared = new Prepared();
        $prepare = fn (string $sql): \PDOStatement => (new \PDO('sqlite::memory:'))->prepare($sql);

        $prepared->get('SELECT 1', $prepare);
        $prepared->clear();

        $calls = 0;
        $prepared->get('SELECT 1', function (string $sql) use (&$calls): \PDOStatement {
            $calls++;

            return (new \PDO('sqlite::memory:'))->prepare($sql);
        });

        self::assertSame(1, $calls);
    }

    public function test_evicts_oldest_when_capacity_exceeded(): void
    {
        $prepared = new Prepared(2);
        $prepare = fn (string $sql): \PDOStatement => (new \PDO('sqlite::memory:'))->prepare($sql);

        $prepared->get('SELECT 0', $prepare);
        $prepared->get('SELECT 1', $prepare);
        $prepared->get('SELECT 2', $prepare);

        $calls = 0;
        $third = $prepared->get('SELECT 2', function (string $sql) use (&$calls): \PDOStatement {
            $calls++;

            return (new \PDO('sqlite::memory:'))->prepare($sql);
        });
        $miss = $prepared->get('SELECT 0', function (string $sql) use (&$calls): \PDOStatement {
            $calls++;

            return (new \PDO('sqlite::memory:'))->prepare($sql);
        });

        self::assertSame(1, $calls);
        self::assertNotSame($third, $miss);
    }
}
