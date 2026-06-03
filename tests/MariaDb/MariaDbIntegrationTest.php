<?php

declare(strict_types=1);

namespace Tests\MariaDb;

use PHPUnit\Framework\TestCase;
use UDA\Database;

final class MariaDbIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('pdo_mysql extension is required for MariaDB integration.');
        }

        if (!defined('UDA_MARIADB_TEST_CONFIG')) {
            self::markTestSkipped('MariaDB integration bootstrap was not loaded.');
        }
    }

    public function test_mariadb_read_write_and_named_parameters(): void
    {
        $db = Database::connect('mariadb', UDA_MARIADB_TEST_CONFIG);
        $db->exec(
            'CREATE TABLE IF NOT EXISTS uda_mariadb_ig (
                id INT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            )'
        );

        $id = random_int(100_000, 999_999);
        $db->exec(
            'INSERT INTO uda_mariadb_ig (id, name) VALUES (:id, :name)',
            ['id' => $id, 'name' => 'mariadb']
        );

        self::assertSame('mariadb', $db->value(
            'SELECT name FROM uda_mariadb_ig WHERE id = :id',
            ['id' => $id]
        ));
    }
}
