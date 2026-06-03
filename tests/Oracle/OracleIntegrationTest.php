<?php

declare(strict_types=1);

namespace Tests\Oracle;

use PHPUnit\Framework\TestCase;
use UDA\Database;

final class OracleIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_oci')) {
            self::markTestSkipped('pdo_oci extension is required for Oracle integration.');
        }

        if (!defined('UDA_ORACLE_TEST_CONFIG')) {
            self::markTestSkipped('Oracle integration bootstrap was not loaded.');
        }
    }

    public function test_oracle_read_write_and_named_parameters(): void
    {
        $db = Database::connect('oracle', UDA_ORACLE_TEST_CONFIG);
        $db->exec(
            'CREATE TABLE uda_oracle_ig (
                id NUMBER PRIMARY KEY,
                name VARCHAR2(100) NOT NULL
            )'
        );

        $id = random_int(100_000, 999_999);
        $db->exec(
            'INSERT INTO uda_oracle_ig (id, name) VALUES (:id, :name)',
            ['id' => $id, 'name' => 'oracle']
        );

        self::assertSame('oracle', $db->value(
            'SELECT name FROM uda_oracle_ig WHERE id = :id',
            ['id' => $id]
        ));
    }
}
