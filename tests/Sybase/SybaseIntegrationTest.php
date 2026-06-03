<?php

declare(strict_types=1);

namespace Tests\Sybase;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Integration\IntegrationTable;
use UDA\Database;
use UDA\Exception\QueryException;

/**
 * Live ASE integration — disabled by default (no SAP license in CI).
 *
 * Run when ASE is available:
 *
 *   UDA_SYBASE_LIVE=1 vendor/bin/phpunit --bootstrap tests/sybase-bootstrap.php tests/Sybase
 */
#[Group('sybase-live')]
final class SybaseIntegrationTest extends TestCase
{
    use IntegrationTable;

    private ?Database $db = null;

    protected function setUp(): void
    {
        if (!self::sybaseLiveEnabled()) {
            self::markTestSkipped(
                'Sybase live integration is disabled (no ASE license in this repo). '
                . 'Set UDA_SYBASE_LIVE=1 and bootstrap tests/sybase-bootstrap.php when ASE is available.'
            );
        }

        if (!extension_loaded('pdo_dblib')) {
            self::markTestSkipped('pdo_dblib extension is required for Sybase integration.');
        }

        if (!defined('UDA_SYBASE_TEST_CONFIG')) {
            self::markTestSkipped('Sybase integration bootstrap was not loaded.');
        }

        $this->db = Database::connectWithConfig(UDA_SYBASE_TEST_CONFIG, 'sybase');
        $this->seedSybaseTable($this->db);
    }

    public function test_sybase_read_write_and_named_parameters(): void
    {
        $id = $this->randomId();
        $this->db->exec(
            'INSERT INTO dbo.uda_sybase_ig (id, name, score) VALUES (:id, :name, :score)',
            ['id' => $id, 'name' => 'sybase', 'score' => 0]
        );

        self::assertSame('sybase', $this->db->value(
            'SELECT name FROM dbo.uda_sybase_ig WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function test_sybase_transaction_commits(): void
    {
        $id = $this->randomId();

        $this->db->transaction(function (Database $conn) use ($id): void {
            $conn->exec(
                'INSERT INTO dbo.uda_sybase_ig (id, name, score) VALUES (:id, :name, :score)',
                ['id' => $id, 'name' => 'txn', 'score' => 1]
            );
        });

        self::assertSame('txn', $this->db->value(
            'SELECT name FROM dbo.uda_sybase_ig WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function test_sybase_insert_returning_output(): void
    {
        $id = $this->randomId();
        $row = $this->db->insert()
            ->into('uda_sybase_ig')
            ->set('id', $id)
            ->set('name', 'output')
            ->set('score', 7)
            ->returning('name', 'score')
            ->row();

        self::assertSame('output', $row['name'] ?? null);
        self::assertSame(7, (int) ($row['score'] ?? 0));
    }

    public function test_sybase_pagination_limit_offset(): void
    {
        $rows = $this->db->select('id')
            ->from('uda_sybase_ig')
            ->orderBy('id')
            ->limit(3)
            ->offset(4)
            ->rows();

        self::assertSame([5, 6, 7], array_map(static fn (array $r): int => (int) $r['id'], $rows));
    }

    public function test_sybase_upsert_builder_throws(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Sybase dialect does not support UPSERT builders.');

        $this->db->upsert()
            ->into('uda_sybase_ig')
            ->values(['id' => 1, 'name' => 'nope', 'score' => 0])
            ->key(['id'])
            ->toSql();
    }

    private static function sybaseLiveEnabled(): bool
    {
        $flag = getenv('UDA_SYBASE_LIVE');

        return $flag !== false && $flag !== '' && $flag !== '0';
    }
}
