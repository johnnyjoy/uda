<?php

declare(strict_types=1);

namespace Tests\SqlServer;

use PHPUnit\Framework\TestCase;
use Tests\Integration\IntegrationTable;
use UDA\Database;

final class SqlServerIntegrationTest extends TestCase
{
    use IntegrationTable;

    private ?Database $db = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_dblib')) {
            self::markTestSkipped('pdo_dblib extension is required for SQL Server integration.');
        }

        if (!defined('UDA_SQLSERVER_TEST_CONFIG')) {
            self::markTestSkipped('SQL Server integration bootstrap was not loaded.');
        }

        $this->db = Database::connectWithConfig(UDA_SQLSERVER_TEST_CONFIG, 'mssql');
        $this->seedSqlServerTable($this->db);
    }

    public function test_sqlserver_read_write_and_named_parameters(): void
    {
        $id = $this->randomId();
        $this->db->exec(
            'INSERT INTO dbo.uda_mssql_ig (id, name, score) VALUES (:id, :name, :score)',
            ['id' => $id, 'name' => 'sqlserver', 'score' => 0]
        );

        self::assertSame('sqlserver', $this->db->value(
            'SELECT name FROM dbo.uda_mssql_ig WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function test_sqlserver_transaction_commits(): void
    {
        $id = $this->randomId();

        $this->db->transaction(function (Database $conn) use ($id): void {
            $conn->exec(
                'INSERT INTO dbo.uda_mssql_ig (id, name, score) VALUES (:id, :name, :score)',
                ['id' => $id, 'name' => 'txn', 'score' => 1]
            );
        });

        self::assertSame('txn', $this->db->value(
            'SELECT name FROM dbo.uda_mssql_ig WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function test_sqlserver_insert_returning_output(): void
    {
        $id = $this->randomId();
        $row = $this->db->insert()
            ->into('uda_mssql_ig')
            ->set('id', $id)
            ->set('name', 'output')
            ->set('score', 7)
            ->returning('name', 'score')
            ->row();

        self::assertSame('output', $row['name'] ?? null);
        self::assertSame(7, (int) ($row['score'] ?? 0));
    }

    public function test_sqlserver_upsert_builder_executes(): void
    {
        $id = $this->randomId();

        $this->db->upsert()
            ->into('uda_mssql_ig')
            ->values(['id' => $id, 'name' => 'upsert-a', 'score' => 1])
            ->key(['id'])
            ->update(['name', 'score'])
            ->exec();

        $this->db->upsert()
            ->into('uda_mssql_ig')
            ->values(['id' => $id, 'name' => 'upsert-b', 'score' => 2])
            ->key(['id'])
            ->update(['name', 'score'])
            ->exec();

        self::assertSame('upsert-b', $this->db->value(
            'SELECT name FROM dbo.uda_mssql_ig WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function test_sqlserver_pagination_limit_offset(): void
    {
        $rows = $this->db->select('id')
            ->from('uda_mssql_ig')
            ->orderBy('id')
            ->limit(3)
            ->offset(4)
            ->rows();

        self::assertSame([5, 6, 7], array_map(static fn (array $r): int => (int) $r['id'], $rows));
    }
}
