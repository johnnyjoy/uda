<?php

declare(strict_types=1);

namespace Tests\SQLite;

use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Exception\QueryException;

final class WhereExecTest extends TestCase
{
    public function test_update_exec_without_explicit_end(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $id = random_int(1000, 999999);

        $db->insert()->into('users')->set('id', $id)->set('name', 'Before')->exec();

        $affected = $db->update()
            ->table('users')
            ->set('name', 'After')
            ->where('id', $id)
            ->exec();

        self::assertSame(1, $affected);
        self::assertSame('After', $db->select('name')->from('users')->where('id', $id)->end()->value());
    }

    public function test_delete_exec_without_explicit_end(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $id = random_int(1000, 999999);

        $db->insert()->into('users')->set('id', $id)->set('name', 'Doomed')->exec();

        $affected = $db->delete()
            ->table('users')
            ->where('id', $id)
            ->exec();

        self::assertSame(1, $affected);
        self::assertNull($db->select()->from('users')->where('id', $id)->end()->row());
    }

    public function test_explicit_end_before_exec_still_works(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $id = random_int(1000, 999999);

        $db->insert()->into('users')->set('id', $id)->set('name', 'Keep')->exec();

        $affected = $db->delete()
            ->table('users')
            ->where('id', $id)
            ->end()
            ->exec();

        self::assertSame(1, $affected);
    }

    public function test_select_where_exec_is_rejected(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);

        $this->expectException(QueryException::class);

        $db->select()->from('users')->where('id', 1)->exec();
    }
}
