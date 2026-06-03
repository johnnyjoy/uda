<?php

declare(strict_types=1);

namespace Tests\Postgres;

use PHPUnit\Framework\TestCase;
use UDA\Database;

final class PostgresIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('pdo_pgsql extension is required for PostgreSQL integration.');
        }

        if (!defined('UDA_POSTGRES_TEST_CONFIG')) {
            self::markTestSkipped('PostgreSQL integration bootstrap was not loaded.');
        }
    }

    public function test_postgres_read_write_and_named_parameter_execution(): void
    {
        $db = Database::connect('pgsql', UDA_POSTGRES_TEST_CONFIG);
        $db->exec('CREATE TABLE IF NOT EXISTS uda_pg_ig (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $id = random_int(1000, 999999);
        $db->exec(
            'INSERT INTO uda_pg_ig (id, name) VALUES (:id, :name)',
            ['id' => $id, 'name' => 'postgres']
        );

        self::assertSame('postgres', $db->value(
            'SELECT name FROM uda_pg_ig WHERE id = :id',
            ['id' => $id]
        ));
    }
}
