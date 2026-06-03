<?php

declare(strict_types=1);

namespace Tests\Db2;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Integration\IntegrationTable;
use UDA\Database;
use UDA\Exception\QueryException;

final class Db2IntegrationTest extends TestCase
{
    use IntegrationTable;

    private ?Database $db = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_ibm')) {
            self::markTestSkipped('pdo_ibm extension is required for DB2 integration.');
        }

        if (!defined('UDA_DB2_TEST_CONFIG')) {
            self::markTestSkipped('DB2 integration bootstrap was not loaded.');
        }

        $this->db = Database::connect('db2', UDA_DB2_TEST_CONFIG);
        $this->seedDb2Table($this->db);
    }

    public function test_db2_read_write_and_named_parameters(): void
    {
        $id = $this->randomId();
        $this->db->exec(
            'INSERT INTO UDA_DB2_IG (ID, NAME, SCORE) VALUES (:id, :name, :score)',
            ['id' => $id, 'name' => 'db2', 'score' => 0]
        );

        self::assertSame('db2', $this->db->value(
            'SELECT NAME FROM UDA_DB2_IG WHERE ID = :id',
            ['id' => $id]
        ));
    }

    public function test_db2_transaction_commits(): void
    {
        $id = $this->randomId();

        $this->db->transaction(function (Database $conn) use ($id): void {
            $conn->exec(
                'INSERT INTO UDA_DB2_IG (ID, NAME, SCORE) VALUES (:id, :name, :score)',
                ['id' => $id, 'name' => 'txn', 'score' => 1]
            );
        });

        self::assertSame('txn', $this->db->value(
            'SELECT NAME FROM UDA_DB2_IG WHERE ID = :id',
            ['id' => $id]
        ));
    }

    public function test_db2_transaction_rolls_back_on_failure(): void
    {
        $id = $this->randomId();

        try {
            $this->db->transaction(function (Database $conn) use ($id): void {
                $conn->exec(
                    'INSERT INTO UDA_DB2_IG (ID, NAME, SCORE) VALUES (:id, :name, :score)',
                    ['id' => $id, 'name' => 'rollback', 'score' => 2]
                );
                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertNull($this->db->value(
            'SELECT NAME FROM UDA_DB2_IG WHERE ID = :id',
            ['id' => $id]
        ));
    }

    public function test_db2_merge_upsert_updates_existing_row(): void
    {
        $this->db->upsert()
            ->into('uda_db2_ig')
            ->values(['id' => 1, 'name' => 'updated', 'score' => 888])
            ->key(['id'])
            ->update(['name', 'score'])
            ->exec();

        self::assertSame('updated', $this->db->value('SELECT NAME FROM UDA_DB2_IG WHERE ID = 1'));
        self::assertSame(888, (int) $this->db->value('SELECT SCORE FROM UDA_DB2_IG WHERE ID = 1'));
    }

    public function test_db2_pagination_limit_offset(): void
    {
        $rows = $this->db->select('id')
            ->from('uda_db2_ig')
            ->orderBy('id')
            ->limit(3)
            ->offset(4)
            ->rows();

        self::assertSame([5, 6, 7], array_map(static fn (array $r): int => (int) ($r['id'] ?? $r['ID']), $rows));
    }

    public function test_db2_insert_returning_throws_before_pdo(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('does not support RETURNING clauses.');

        $this->db->insert()
            ->into('uda_db2_ig')
            ->set('id', 99_001)
            ->set('name', 'nope')
            ->set('score', 0)
            ->returning('id')
            ->row();
    }
}
