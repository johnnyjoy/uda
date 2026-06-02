<?php

declare(strict_types=1);

namespace Tests\Cache;

use PHPUnit\Framework\TestCase;
use UDA\Cache;
use UDA\Config\Validator;
use UDA\Database;
use UDA\Exception\ConfigException;
use UDA\Exception\QueryException;

final class RequireTableHintsTest extends TestCase
{
    public function test_hintless_raw_read_fails_when_require_table_hints_enabled(): void
    {
        Cache::clear();

        $db = Database::connect('cached_strict', UDA_TEST_CONFIG);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('table hints');

        $db->rows('SELECT id FROM cache_items WHERE id = :id', ['id' => 1]);
    }

    public function test_raw_read_with_hints_succeeds_when_require_table_hints_enabled(): void
    {
        Cache::clear();

        $db = Database::connect('cached_strict', UDA_TEST_CONFIG);
        $id = random_int(1000, 999999);

        $db->exec(
            'INSERT INTO cache_items (id, name) VALUES (:id, :name)',
            ['id' => $id, 'name' => 'ok'],
            ['cache_items']
        );

        self::assertSame(
            [['id' => $id]],
            $db->rows(
                'SELECT id FROM cache_items WHERE id = :id',
                ['id' => $id],
                ['cache_items']
            )
        );
    }

    public function test_builder_reads_unaffected_by_require_table_hints(): void
    {
        Cache::clear();

        $db = Database::connect('cached_strict', UDA_TEST_CONFIG);
        $id = random_int(1000, 999999);

        $db->insert()
            ->into('cache_items')
            ->set('id', $id)
            ->set('name', 'builder')
            ->exec();

        self::assertSame(
            $id,
            $db->select('id')
                ->from('cache_items')
                ->where('id', $id)
                ->end()
                ->value()
        );
    }

    public function test_require_table_hints_must_be_boolean_in_config(): void
    {
        $validator = new Validator();

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('require_table_hints');

        $validator->validate([
            'connections' => [
                'bad' => [
                    'driver' => 'sqlite',
                    'params' => ['path' => ':memory:'],
                    'cache' => [
                        'store' => ['type' => 'array'],
                        'require_table_hints' => 'yes',
                    ],
                ],
            ],
        ]);
    }
}
