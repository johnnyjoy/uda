<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use UDA\Driver\Prepared;

final class PreparedTest extends TestCase
{
    private static function prepare(string $sql): \PDOStatement
    {
        return (new \PDO('sqlite::memory:'))->prepare($sql);
    }

    public function test_reuses_prepared_statement_for_same_sql(): void
    {
        $prepared = new Prepared();

        self::assertNull($prepared->get('SELECT 1'));

        $stmt = self::prepare('SELECT 1');
        $prepared->put('SELECT 1', $stmt);

        self::assertSame($stmt, $prepared->get('SELECT 1'));
    }

    public function test_clear_drops_cached_statements(): void
    {
        $prepared = new Prepared();
        $prepared->put('SELECT 1', self::prepare('SELECT 1'));
        $prepared->clear();

        self::assertNull($prepared->get('SELECT 1'));
    }

    public function test_evicts_oldest_when_capacity_exceeded(): void
    {
        $prepared = new Prepared(2);
        $prepared->put('SELECT 0', self::prepare('SELECT 0'));
        $prepared->put('SELECT 1', self::prepare('SELECT 1'));
        $prepared->put('SELECT 2', self::prepare('SELECT 2'));

        self::assertNotNull($prepared->get('SELECT 2'));
        self::assertNull($prepared->get('SELECT 0'), 'Oldest entry should be evicted past capacity.');
    }
}
