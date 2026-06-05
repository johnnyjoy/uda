<?php

declare(strict_types=1);

namespace Tests\Cubrid;

use PHPUnit\Framework\TestCase;
use Tests\Integration\IntegrationTable;
use UDA\Database;
use UDA\Exception\QueryException;

final class CubridIntegrationTest extends TestCase
{
    use IntegrationTable;

    private ?Database $db = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_cubrid')) {
            self::markTestSkipped('pdo_cubrid extension is required for CUBRID integration.');
        }

        if (!defined('UDA_CUBRID_TEST_CONFIG')) {
            self::markTestSkipped('CUBRID integration bootstrap was not loaded.');
        }

        $this->db = Database::connect('cubrid', UDA_CUBRID_TEST_CONFIG);
        $this->seedCubridTable($this->db);
    }

    public function test_cubrid_read_write_and_named_parameters(): void
    {
        $id = $this->randomId();
        $this->db->exec(
            'INSERT INTO uda_cubrid_ig (id, name, score) VALUES (:id, :name, :score)',
            ['id' => $id, 'name' => 'cubrid', 'score' => 0]
        );

        self::assertSame('cubrid', $this->db->value(
            'SELECT name FROM uda_cubrid_ig WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function test_cubrid_transaction_commits(): void
    {
        $id = $this->randomId();

        $this->db->transaction(function (Database $conn) use ($id): void {
            $conn->exec(
                'INSERT INTO uda_cubrid_ig (id, name, score) VALUES (:id, :name, :score)',
                ['id' => $id, 'name' => 'txn', 'score' => 1]
            );
        });

        self::assertSame('txn', $this->db->value(
            'SELECT name FROM uda_cubrid_ig WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function test_cubrid_upsert_on_duplicate_key(): void
    {
        $this->db->upsert()
            ->into('uda_cubrid_ig')
            ->values(['id' => 1, 'name' => 'updated', 'score' => 888])
            ->key(['id'])
            ->update(['name', 'score'])
            ->exec();

        self::assertSame('updated', $this->db->value('SELECT name FROM uda_cubrid_ig WHERE id = 1'));
        self::assertSame(888, (int) $this->db->value('SELECT score FROM uda_cubrid_ig WHERE id = 1'));
    }

    public function test_cubrid_pagination_limit_offset(): void
    {
        $rows = $this->db->select('id')
            ->from('uda_cubrid_ig')
            ->orderBy('id')
            ->limit(3)
            ->offset(4)
            ->rows();

        self::assertSame([5, 6, 7], array_map(static fn (array $r): int => (int) $r['id'], $rows));
    }

    public function test_cubrid_insert_returning_throws(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('does not support RETURNING clauses.');

        $this->db->insert()
            ->into('uda_cubrid_ig')
            ->set('id', 99_001)
            ->set('name', 'nope')
            ->set('score', 0)
            ->returning('id')
            ->row();
    }
}
