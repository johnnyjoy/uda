<?php

declare(strict_types=1);

namespace Tests\Firebird;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Integration\IntegrationTable;
use UDA\Database;

final class FirebirdIntegrationTest extends TestCase
{
    use IntegrationTable;

    private ?Database $db = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_firebird')) {
            self::markTestSkipped('pdo_firebird extension is required for Firebird integration.');
        }

        if (!defined('UDA_FIREBIRD_TEST_CONFIG')) {
            self::markTestSkipped('Firebird integration bootstrap was not loaded.');
        }

        $this->db = Database::connect('firebird', UDA_FIREBIRD_TEST_CONFIG);
        $this->seedFirebirdTable($this->db);
    }

    public function test_firebird_read_write_and_named_parameters(): void
    {
        $id = $this->randomId();
        $this->db->exec(
            'INSERT INTO UDA_FB_IG (ID, NAME, SCORE) VALUES (:id, :name, :score)',
            ['id' => $id, 'name' => 'firebird', 'score' => 0]
        );

        self::assertSame('firebird', $this->db->value(
            'SELECT NAME FROM UDA_FB_IG WHERE ID = :id',
            ['id' => $id]
        ));
    }

    public function test_firebird_transaction_commits(): void
    {
        $id = $this->randomId();

        $this->db->transaction(function (Database $conn) use ($id): void {
            $conn->exec(
                'INSERT INTO UDA_FB_IG (ID, NAME, SCORE) VALUES (:id, :name, :score)',
                ['id' => $id, 'name' => 'txn', 'score' => 1]
            );
        });

        self::assertSame('txn', $this->db->value(
            'SELECT NAME FROM UDA_FB_IG WHERE ID = :id',
            ['id' => $id]
        ));
    }

    public function test_firebird_transaction_rolls_back_on_failure(): void
    {
        $id = $this->randomId();

        try {
            $this->db->transaction(function (Database $conn) use ($id): void {
                $conn->exec(
                    'INSERT INTO UDA_FB_IG (ID, NAME, SCORE) VALUES (:id, :name, :score)',
                    ['id' => $id, 'name' => 'rollback', 'score' => 2]
                );
                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertNull($this->db->value(
            'SELECT NAME FROM UDA_FB_IG WHERE ID = :id',
            ['id' => $id]
        ));
    }

    public function test_firebird_insert_returning_row(): void
    {
        $id = $this->randomId();
        $row = $this->db->insert()
            ->into('UDA_FB_IG')
            ->set('id', $id)
            ->set('name', 'returning')
            ->set('score', 42)
            ->returning('name', 'score')
            ->row();

        $name = $row['name'] ?? $row['NAME'] ?? null;
        $score = $row['score'] ?? $row['SCORE'] ?? null;

        self::assertSame('returning', $name);
        self::assertSame(42, (int) $score);
    }

    public function test_firebird_merge_upsert_updates_existing_row(): void
    {
        $this->db->upsert()
            ->into('UDA_FB_IG')
            ->values(['id' => 1, 'name' => 'updated', 'score' => 888])
            ->key(['id'])
            ->update(['name', 'score'])
            ->exec();

        self::assertSame('updated', $this->db->value('SELECT NAME FROM UDA_FB_IG WHERE ID = 1'));
        self::assertSame(888, (int) $this->db->value('SELECT SCORE FROM UDA_FB_IG WHERE ID = 1'));
    }

    public function test_firebird_pagination_limit_offset(): void
    {
        $rows = $this->db->select('id')
            ->from('UDA_FB_IG')
            ->orderBy('id')
            ->limit(3)
            ->offset(4)
            ->rows();

        self::assertSame(
            [5, 6, 7],
            array_map(static fn (array $r): int => (int) ($r['id'] ?? $r['ID']), $rows)
        );
    }
}
