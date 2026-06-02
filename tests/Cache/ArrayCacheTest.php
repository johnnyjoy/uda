<?php

declare(strict_types=1);

namespace Tests\Cache;

use PHPUnit\Framework\TestCase;
use UDA\Cache;
use UDA\Database;

final class ArrayCacheTest extends TestCase
{
    public function test_metadata_first_cache_invalidates_after_write_touch(): void
    {
        Cache::clear();

        $db = Database::connect('cached', UDA_TEST_CONFIG);
        $id = random_int(1000, 999999);

        $db->exec(
            'INSERT INTO cache_items (id, name) VALUES (:id, :name)',
            ['id' => $id, 'name' => 'before'],
            ['cache_items']
        );

        $sql = 'SELECT name FROM cache_items WHERE id = :id';

        self::assertSame([['name' => 'before']], $db->rows($sql, ['id' => $id], ['cache_items']));
        self::assertSame([['name' => 'before']], $db->rows($sql, ['id' => $id], ['cache_items']));

        sleep(1);

        $db->exec(
            'UPDATE cache_items SET name = :name WHERE id = :id',
            ['id' => $id, 'name' => 'after'],
            ['cache_items']
        );

        self::assertSame([['name' => 'after']], $db->rows($sql, ['id' => $id], ['cache_items']));
    }

    public function test_cache_entries_are_scoped_by_result_shape(): void
    {
        Cache::clear();

        $db = Database::connect('cached', UDA_TEST_CONFIG);
        $id = random_int(1000, 999999);

        $db->exec(
            'INSERT INTO cache_items (id, name) VALUES (:id, :name)',
            ['id' => $id, 'name' => 'shape'],
            ['cache_items']
        );

        $sql = 'SELECT id, name FROM cache_items WHERE id = :id';

        self::assertSame([['id' => $id, 'name' => 'shape']], $db->rows($sql, ['id' => $id], ['cache_items']));
        self::assertSame(['id' => $id, 'name' => 'shape'], $db->row($sql, ['id' => $id], ['cache_items']));
        self::assertSame([$id, 'shape'], $db->list($sql, ['id' => $id], ['cache_items']));
        self::assertSame([$id], $db->values($sql, ['id' => $id], ['cache_items']));
    }

    public function test_flush_removes_cached_payload_for_connection(): void
    {
        Cache::clear();

        $db = Database::connect('cached', UDA_TEST_CONFIG);
        $id = random_int(1000, 999999);
        $sql = 'SELECT name FROM cache_items WHERE id = :id';

        $db->exec(
            'INSERT INTO cache_items (id, name) VALUES (:id, :name)',
            ['id' => $id, 'name' => 'before'],
            ['cache_items']
        );

        self::assertSame([['name' => 'before']], $db->rows($sql, ['id' => $id], ['cache_items']));
        self::assertSame([['name' => 'before']], $db->rows($sql, ['id' => $id], ['cache_items']));

        $db->exec(
            'UPDATE cache_items SET name = :name WHERE id = :id',
            ['id' => $id, 'name' => 'after'],
            []
        );

        self::assertSame([['name' => 'before']], $db->rows($sql, ['id' => $id], ['cache_items']));

        $db->flushCache();

        self::assertSame([['name' => 'after']], $db->rows($sql, ['id' => $id], ['cache_items']));
    }

    public function test_flush_via_cache_does_not_drop_process_local_clients(): void
    {
        Cache::clear();

        $db = Database::connect('cached', UDA_TEST_CONFIG);
        $id = random_int(1000, 999999);
        $sql = 'SELECT name FROM cache_items WHERE id = :id';

        $db->exec(
            'INSERT INTO cache_items (id, name) VALUES (:id, :name)',
            ['id' => $id, 'name' => 'kept'],
            ['cache_items']
        );

        $db->rows($sql, ['id' => $id], ['cache_items']);
        Cache::flush('cached');

        self::assertSame([['name' => 'kept']], $db->rows($sql, ['id' => $id], ['cache_items']));
    }
}
