<?php

declare(strict_types=1);

namespace Tests\Integration;

use UDA\Database;

/**
 * Shared helpers for live-database integration tests.
 */
trait IntegrationTable
{
    protected function randomId(int $min = 100_000, int $max = 999_999): int
    {
        return random_int($min, $max);
    }

    protected function seedPgTable(Database $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS uda_pg_ig (id INTEGER PRIMARY KEY, name TEXT NOT NULL, score INTEGER NOT NULL DEFAULT 0)');
        $db->exec('DELETE FROM uda_pg_ig');
        for ($i = 1; $i <= 10; $i++) {
            $db->exec(
                'INSERT INTO uda_pg_ig (id, name, score) VALUES (:id, :name, :score)',
                ['id' => $i, 'name' => 'row' . $i, 'score' => $i * 10]
            );
        }
    }

    protected function seedMariaDbTable(Database $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS uda_mariadb_ig (
                id INT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                score INT NOT NULL DEFAULT 0
            )'
        );
        $db->exec('DELETE FROM uda_mariadb_ig');
        for ($i = 1; $i <= 10; $i++) {
            $db->exec(
                'INSERT INTO uda_mariadb_ig (id, name, score) VALUES (:id, :name, :score)',
                ['id' => $i, 'name' => 'row' . $i, 'score' => $i * 10]
            );
        }
    }

    protected function seedSqlServerTable(Database $db): void
    {
        $db->exec(
            'IF OBJECT_ID(N\'dbo.uda_mssql_ig\', N\'U\') IS NULL
            CREATE TABLE dbo.uda_mssql_ig (
                id INT NOT NULL PRIMARY KEY,
                name NVARCHAR(100) NOT NULL,
                score INT NOT NULL DEFAULT 0
            )'
        );
        $db->exec('DELETE FROM dbo.uda_mssql_ig');
        for ($i = 1; $i <= 10; $i++) {
            $db->exec(
                'INSERT INTO dbo.uda_mssql_ig (id, name, score) VALUES (:id, :name, :score)',
                ['id' => $i, 'name' => 'row' . $i, 'score' => $i * 10]
            );
        }
    }
}
