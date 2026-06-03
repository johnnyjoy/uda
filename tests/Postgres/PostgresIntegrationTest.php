<?php

declare(strict_types=1);

namespace Tests\Postgres;

use PHPUnit\Framework\TestCase;
use Tests\Integration\IntegrationTable;
use UDA\Database;

final class PostgresIntegrationTest extends TestCase
{
    use IntegrationTable;

    private ?Database $db = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('pdo_pgsql extension is required for PostgreSQL integration.');
        }

        if (!defined('UDA_POSTGRES_TEST_CONFIG')) {
            self::markTestSkipped('PostgreSQL integration bootstrap was not loaded.');
        }

        $this->db = Database::connect('pgsql', UDA_POSTGRES_TEST_CONFIG);
        $this->seedPgTable($this->db);
    }

    public function test_postgres_read_write_and_named_parameter_execution(): void
    {
        $id = $this->randomId();
        $this->db->exec(
            'INSERT INTO uda_pg_ig (id, name, score) VALUES (:id, :name, :score)',
            ['id' => $id, 'name' => 'postgres', 'score' => 0]
        );

        self::assertSame('postgres', $this->db->value(
            'SELECT name FROM uda_pg_ig WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function test_postgres_transaction_commits(): void
    {
        $id = $this->randomId();

        $this->db->transaction(function (Database $conn) use ($id): void {
            $conn->exec(
                'INSERT INTO uda_pg_ig (id, name, score) VALUES (:id, :name, :score)',
                ['id' => $id, 'name' => 'txn', 'score' => 1]
            );
        });

        self::assertSame('txn', $this->db->value(
            'SELECT name FROM uda_pg_ig WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function test_postgres_insert_returning_row(): void
    {
        $id = $this->randomId();
        $row = $this->db->insert()
            ->into('uda_pg_ig')
            ->set('id', $id)
            ->set('name', 'returning')
            ->set('score', 42)
            ->returning('name', 'score')
            ->row();

        self::assertSame('returning', $row['name'] ?? null);
        self::assertSame(42, (int) ($row['score'] ?? 0));
    }

    public function test_postgres_upsert_on_conflict(): void
    {
        $this->db->upsert()
            ->into('uda_pg_ig')
            ->values(['id' => 1, 'name' => 'updated', 'score' => 999])
            ->key(['id'])
            ->update(['name', 'score'])
            ->exec();

        self::assertSame('updated', $this->db->value('SELECT name FROM uda_pg_ig WHERE id = 1'));
        self::assertSame(999, (int) $this->db->value('SELECT score FROM uda_pg_ig WHERE id = 1'));
    }

    public function test_postgres_pagination_limit_offset(): void
    {
        $rows = $this->db->select('id')
            ->from('uda_pg_ig')
            ->orderBy('id')
            ->limit(3)
            ->offset(4)
            ->rows();

        self::assertSame([5, 6, 7], array_map(static fn (array $r): int => (int) $r['id'], $rows));
    }
}
