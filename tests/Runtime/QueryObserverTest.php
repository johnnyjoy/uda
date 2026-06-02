<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use UDA\Cache;
use UDA\Database;
use UDA\Exception\QueryException;
use UDA\Query\Observer;

final class QueryObserverTest extends TestCase
{
    /** @var list<Observer> */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->seen = [];
        Database::setQueryObserver(function (Observer $o): void {
            $this->seen[] = $o;
        });
    }

    protected function tearDown(): void
    {
        Database::setQueryObserver(null);
        Cache::clear();
    }

    public function test_pdo_execute_is_observed(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $db->row('SELECT 1 AS n', []);

        self::assertCount(1, $this->seen);
        self::assertSame('alpha', $this->seen[0]->connection);
        self::assertStringContainsString('SELECT 1', $this->seen[0]->sql);
        self::assertFalse($this->seen[0]->cacheHit);
        self::assertNull($this->seen[0]->error);
        self::assertGreaterThanOrEqual(0, $this->seen[0]->durationMs);
    }

    public function test_cache_hit_is_observed_without_second_pdo_execute(): void
    {
        $db = Database::connect('cached', UDA_TEST_CONFIG);
        $sql = 'SELECT name FROM cache_items WHERE id = :id';
        $params = ['id' => 42];

        $db->rows($sql, $params, ['cache_items']);
        $this->seen = [];
        $db->rows($sql, $params, ['cache_items']);

        self::assertCount(1, $this->seen);
        self::assertTrue($this->seen[0]->cacheHit);
        self::assertLessThan(1.0, $this->seen[0]->durationMs);
    }

    public function test_failed_execute_observed_with_error(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);

        try {
            $db->exec('UPDATE no_such_table SET x = 1');
        } catch (QueryException) {
        }

        self::assertCount(1, $this->seen);
        self::assertInstanceOf(QueryException::class, $this->seen[0]->error);
    }

    public function test_null_observer_adds_no_observations(): void
    {
        Database::setQueryObserver(null);
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $db->row('SELECT 1 AS n', []);

        self::assertSame([], $this->seen);
    }
}
