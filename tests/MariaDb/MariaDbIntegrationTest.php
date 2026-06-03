<?php

declare(strict_types=1);

namespace Tests\MariaDb;

use PHPUnit\Framework\TestCase;
use Tests\Integration\IntegrationTable;
use UDA\Database;
use UDA\Exception\QueryException;

final class MariaDbIntegrationTest extends TestCase
{
    use IntegrationTable;

    private ?Database $db = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('pdo_mysql extension is required for MariaDB integration.');
        }

        if (!defined('UDA_MARIADB_TEST_CONFIG')) {
            self::markTestSkipped('MariaDB integration bootstrap was not loaded.');
        }

        $this->db = Database::connect('mariadb', UDA_MARIADB_TEST_CONFIG);
        $this->seedMariaDbTable($this->db);
    }

    public function test_mariadb_read_write_and_named_parameters(): void
    {
        $id = $this->randomId();
        $this->db->exec(
            'INSERT INTO uda_mariadb_ig (id, name, score) VALUES (:id, :name, :score)',
            ['id' => $id, 'name' => 'mariadb', 'score' => 0]
        );

        self::assertSame('mariadb', $this->db->value(
            'SELECT name FROM uda_mariadb_ig WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function test_mariadb_transaction_commits(): void
    {
        $id = $this->randomId();

        $this->db->transaction(function (Database $conn) use ($id): void {
            $conn->exec(
                'INSERT INTO uda_mariadb_ig (id, name, score) VALUES (:id, :name, :score)',
                ['id' => $id, 'name' => 'txn', 'score' => 1]
            );
        });

        self::assertSame('txn', $this->db->value(
            'SELECT name FROM uda_mariadb_ig WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function test_mariadb_upsert_on_duplicate_key(): void
    {
        $this->db->upsert()
            ->into('uda_mariadb_ig')
            ->values(['id' => 1, 'name' => 'updated', 'score' => 888])
            ->key(['id'])
            ->update(['name', 'score'])
            ->exec();

        self::assertSame('updated', $this->db->value('SELECT name FROM uda_mariadb_ig WHERE id = 1'));
        self::assertSame(888, (int) $this->db->value('SELECT score FROM uda_mariadb_ig WHERE id = 1'));
    }

    public function test_mariadb_pagination_limit_offset(): void
    {
        $rows = $this->db->select('id')
            ->from('uda_mariadb_ig')
            ->orderBy('id')
            ->limit(3)
            ->offset(4)
            ->rows();

        self::assertSame([5, 6, 7], array_map(static fn (array $r): int => (int) $r['id'], $rows));
    }

    public function test_mariadb_insert_returning_throws_before_pdo(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('does not support RETURNING clauses.');

        $this->db->insert()
            ->into('uda_mariadb_ig')
            ->set('id', 99_001)
            ->set('name', 'nope')
            ->set('score', 0)
            ->returning('id')
            ->row();
    }
}
