<?php

declare(strict_types=1);

namespace Tests\SqlServer;

use PHPUnit\Framework\TestCase;
use UDA\Database;

final class SqlServerCertTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlsrv')) {
            self::markTestSkipped('pdo_sqlsrv extension is required for SQL Server certification.');
        }

        if (!defined('UDA_SQLSERVER_TEST_CONFIG')) {
            self::markTestSkipped('SQL Server certification bootstrap was not loaded.');
        }
    }

    public function test_sqlserver_read_write_and_named_parameters(): void
    {
        $db = Database::connectWithConfig(UDA_SQLSERVER_TEST_CONFIG, 'mssql');

        $id = random_int(100_000, 999_999);
        $db->exec(
            'INSERT INTO dbo.uda_mssql_cert (id, name) VALUES (:id, :name)',
            ['id' => $id, 'name' => 'sqlserver']
        );

        self::assertSame('sqlserver', $db->value(
            'SELECT name FROM dbo.uda_mssql_cert WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function test_sqlserver_transaction_commits(): void
    {
        $db = Database::connectWithConfig(UDA_SQLSERVER_TEST_CONFIG, 'mssql');

        $id = random_int(100_000, 999_999);

        $db->transaction(function (Database $conn) use ($id): void {
            $conn->exec(
                'INSERT INTO dbo.uda_mssql_cert (id, name) VALUES (:id, :name)',
                ['id' => $id, 'name' => 'txn']
            );
        });

        self::assertSame('txn', $db->value(
            'SELECT name FROM dbo.uda_mssql_cert WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function test_sqlserver_upsert_builder_executes(): void
    {
        $db = Database::connectWithConfig(UDA_SQLSERVER_TEST_CONFIG, 'mssql');
        $id = random_int(100_000, 999_999);

        $db->upsert()
            ->into('uda_mssql_cert')
            ->values(['id' => $id, 'name' => 'upsert-a'])
            ->key(['id'])
            ->update(['name'])
            ->exec();

        $db->upsert()
            ->into('uda_mssql_cert')
            ->values(['id' => $id, 'name' => 'upsert-b'])
            ->key(['id'])
            ->update(['name'])
            ->exec();

        self::assertSame('upsert-b', $db->value(
            'SELECT name FROM dbo.uda_mssql_cert WHERE id = :id',
            ['id' => $id]
        ));
    }
}
