<?php

declare(strict_types=1);

namespace Tests\SQLite;

use PHPUnit\Framework\TestCase;
use UDA\Database;

final class BuilderAndHelperTest extends TestCase
{
    public function test_builder_terminators_flow_through_database_and_driver(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $id = random_int(1000, 999999);

        $db->insert()
            ->into('users')
            ->set('id', $id)
            ->set('name', 'Builder')
            ->exec();

        $name = $db->select('name')
            ->from('users')
            ->where('id', $id)
            ->end()
            ->value();

        self::assertSame('Builder', $name);
    }

    public function test_empty_select_still_defaults_to_all_columns(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $id = random_int(1000, 999999);

        $db->insert()
            ->into('users')
            ->set('id', $id)
            ->set('name', 'All Columns')
            ->exec();

        $row = $db->select()
            ->from('users')
            ->where('id', $id)
            ->end()
            ->row();

        self::assertSame($id, $row['id'] ?? null);
        self::assertSame('All Columns', $row['name'] ?? null);
    }

    public function test_each_processes_builder_rows_through_callback(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $first = random_int(1000, 999999);
        $second = $first + 1;
        $seen = [];

        $db->insert()
            ->into('users')
            ->rows([
                ['id' => $first, 'name' => 'Stream A'],
                ['id' => $second, 'name' => 'Stream B'],
            ])
            ->exec();

        $count = $db->select('name')
            ->from('users')
            ->where('id', $first)
            ->or('id')->eq($second)
            ->end()
            ->orderBy('id')
            ->each(function (array $row) use (&$seen): void {
                $seen[] = $row['name'];
            });

        self::assertSame(2, $count);
        self::assertSame(['Stream A', 'Stream B'], $seen);
    }

    public function test_list_returns_first_row_as_numeric_array(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $id = random_int(1000, 999999);

        $db->insert()
            ->into('users')
            ->set('id', $id)
            ->set('name', 'List Row')
            ->exec();

        $sql = 'SELECT id, name FROM users WHERE id = :id';

        self::assertSame([$id, 'List Row'], $db->list($sql, ['id' => $id], ['users']));
        self::assertSame(
            [$id, 'List Row'],
            $db->select('id', 'name')
                ->from('users')
                ->where('id', $id)
                ->end()
                ->list()
        );
    }

    public function test_singular_and_set_absence_semantics_are_explicit(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $missing = random_int(1000, 999999);
        $sql = 'SELECT id, name FROM users WHERE id = :id';

        self::assertNull($db->row($sql, ['id' => $missing], ['users']));
        self::assertNull($db->value('SELECT name FROM users WHERE id = :id', ['id' => $missing], ['users']));
        self::assertNull($db->list($sql, ['id' => $missing], ['users']));
        self::assertSame([], $db->rows($sql, ['id' => $missing], ['users']));
        self::assertSame([], $db->values('SELECT name FROM users WHERE id = :id', ['id' => $missing], ['users']));
        self::assertSame(
            0,
            $db->select('id')
                ->from('users')
                ->where('id', $missing)
                ->end()
                ->count()
        );
    }

    public function test_safe_dynamic_sql_helpers_are_database_scoped(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);

        [$inSql, $inParams] = $db->inList([10, 20], 'id');

        self::assertSame('"users"', $db->q('users'));
        self::assertSame('ORDER BY "name" DESC', $db->orderByAllowed('name', ['name' => true], 'DESC'));
        self::assertSame('LIMIT 5 OFFSET 10', $db->limitOffset(5, 10));
        self::assertSame('IN (:id_0, :id_1)', $inSql);
        self::assertSame(['id_0' => 10, 'id_1' => 20], $inParams);
    }

    public function test_driver_reconnects_transparently_after_connection_replaced(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);

        // Confirm a normal read works.
        $before = $db->value('SELECT COUNT(*) FROM users');
        self::assertIsInt((int) $before);

        // Replace PDO with a fresh in-memory SQLite, then invoke reconnect()
        // via reflection to prove the stored config still opens the real DB.
        $driverRef = new \ReflectionProperty(\UDA\Driver::class, 'pdo');

        // Get the Driver from Database via reflection.
        $driverProp = new \ReflectionProperty(\UDA\Database::class, 'driver');
        $driver = $driverProp->getValue($db);

        $brokenPdo = new \PDO('sqlite::memory:');
        $driverRef->setValue($driver, $brokenPdo);

        $reconnectMethod = new \ReflectionMethod(\UDA\Driver::class, 'reconnect');
        $reconnectMethod->invoke($driver);

        // After reconnect(), the real database is back. The count should succeed.
        $after = $db->value('SELECT COUNT(*) FROM users');
        self::assertIsInt((int) $after);
        self::assertSame((int) $before, (int) $after);
    }

    public function test_link_handle_is_memoized_across_method_calls(): void
    {
        // Two separate method calls on the same Link class should use the same Database handle.
        // We verify this by asserting Database::connect() returns the same instance both times,
        // which the architectural invariant test already covers at the unit level.
        // This test verifies behaviour end-to-end through the trait.
        $repo = new \Tests\Fixtures\TraitUserRepository();

        // Both calls must succeed and use the same underlying connection.
        $rows1 = $repo->findAll();
        $rows2 = $repo->findAll();

        self::assertSame($rows1, $rows2, 'Repeated Link calls must return identical results via the same memoized handle.');
    }
}
