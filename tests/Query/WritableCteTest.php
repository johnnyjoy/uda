<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Exception\QueryException;
use UDA\Query\Delete;
use UDA\Query\Dialect\MariaDb;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Dialect\SQLite;
use UDA\Query\Insert;
use UDA\Query\Select;
use UDA\Query\Update;

final class WritableCteTest extends TestCase
{
    public function testInsertSelectWithCteCompiles(): void
    {
        $dialect = new SQLite();

        $recent = $this->makeSelect($dialect)
            ->select('id', 'first_name', 'last_name')
            ->from('staging_employees');
        /** @var \UDA\Query\WhereBuilder $recentWhere */
        $recentWhere = $recent->where('import_batch_id', 42);
        $recent = $recentWhere->end();

        $insert = $this->makeInsert($dialect)
            ->with('recent', $recent)
            ->into('employees')
            ->columns('id', 'first_name', 'last_name')
            ->select(
                $this->makeSelect($dialect)
                    ->select('id', 'first_name', 'last_name')
                    ->from('recent')
            );

        $sql = $insert->toSql();

        $this->assertStringContainsString('WITH "recent" AS (SELECT "id", "first_name", "last_name" FROM "staging_employees" WHERE "import_batch_id" = :q1)', $sql->getQuery());
        $this->assertStringContainsString('INSERT INTO "employees" ("id", "first_name", "last_name") SELECT "id", "first_name", "last_name" FROM "recent"', $sql->getQuery());
        $this->assertSame(['q1'], array_keys($sql->getParams()));
        $this->assertSame([42], array_values($sql->getParams()));
    }

    public function testUpdateWithCteAndParameterMerging(): void
    {
        $dialect = new SQLite();

        $raises = $this->makeSelect($dialect)
            ->select('employee_id', 'new_salary')
            ->from('salary_adjustments');
        /** @var \UDA\Query\WhereBuilder $raisesWhere */
        $raisesWhere = $raises->where('batch_id', 7);
        $raises = $raisesWhere->end();

        $builder = $this->makeUpdate($dialect)
            ->with('raises', $raises)
            ->table('employees')
            ->set('salary', 1234);
        /** @var \UDA\Query\WhereBuilder $where */
        $where = $builder->whereRaw('id IN (SELECT employee_id FROM raises)');
        $builder = $where->end();

        $sql = $builder->toSql();

        $this->assertStringContainsString('WITH "raises" AS', $sql->getQuery());
        $this->assertStringContainsString('UPDATE "employees" SET "salary" = :q2', $sql->getQuery());
        $this->assertStringContainsString('WHERE id IN (SELECT employee_id FROM raises)', $sql->getQuery());
        $this->assertSame(['q1', 'q2'], array_keys($sql->getParams()));
        $this->assertSame([7, 1234], array_values($sql->getParams()));
    }

    public function testDeleteWithCteSubquery(): void
    {
        $dialect = new SQLite();

        $expired = $this->makeSelect($dialect)
            ->select('id')
            ->from('sessions');
        /** @var \UDA\Query\WhereBuilder $where */
        $where = $expired->where('expires_at', '2024-01-01', '<');
        $expired = $where->end();

        $delete = $this->makeDelete($dialect)
            ->with('expired', $expired)
            ->table('sessions');
        /** @var \UDA\Query\WhereBuilder $deleteWhere */
        $deleteWhere = $delete->whereRaw('id IN (SELECT id FROM expired)');
        $delete = $deleteWhere->end();

        $sql = $delete->toSql();

        $this->assertStringContainsString('WITH "expired" AS (SELECT "id" FROM "sessions" WHERE "expires_at" < :q1)', $sql->getQuery());
        $this->assertStringContainsString('DELETE FROM "sessions" WHERE id IN (SELECT id FROM expired)', $sql->getQuery());
        $this->assertSame(['q1'], array_keys($sql->getParams()));
        $this->assertSame(['2024-01-01'], array_values($sql->getParams()));
    }

    public function testWritableCteImmutability(): void
    {
        $dialect = new SQLite();
        $base = $this->makeInsert($dialect)
            ->into('employees')
            ->set('name', 'Ada');

        $recent = $this->makeSelect($dialect)
            ->select('id')
            ->from('staging');

        $derived = $base->with('recent', $recent);

        $baseSql = $base->toSql();
        $derivedSql = $derived->toSql();

        $this->assertStringNotContainsString('WITH', $baseSql->getQuery());
        $this->assertStringContainsString('WITH', $derivedSql->getQuery());
    }

    public function testUnsupportedDialectThrows(): void
    {
        $dialect = new MariaDb();

        $cte = $this->makeSelect(new SQLite())
            ->select('id')
            ->from('foo');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('MariaDB dialect does not support CTE clauses for INSERT statements.');

        $this->makeInsert($dialect)
            ->with('recent', $cte)
            ->into('employees')
            ->columns('id')
            ->select(
                $this->makeSelect($dialect)
                    ->select('id')
                    ->from('recent')
            )
            ->toSql();
    }

    public function testPostgresWritableCteAlsoCompiles(): void
    {
        $dialect = new PostgreSql();

        $cte = $this->makeSelect($dialect)
            ->select('id')
            ->from('numbers');
        /** @var \UDA\Query\WhereBuilder $where */
        $where = $cte->where('value', 10, '>');
        $cte = $where->end();

        $update = $this->makeUpdate($dialect)
            ->with('filtered', $cte)
            ->table('numbers')
            ->set('value', 5);
        $updateWhere = $update->whereRaw('id IN (SELECT id FROM filtered)');
        $sql = $updateWhere->end()->toSql();

        $this->assertStringContainsString('WITH "filtered" AS', $sql->getQuery());
        $this->assertStringContainsString('UPDATE "numbers" SET "value" = :q2', $sql->getQuery());
    }

    private function makeSelect($dialect): Select
    {
        $builder = new Select();
        $builder->bindDialect($dialect);

        return $builder;
    }

    private function makeInsert($dialect): Insert
    {
        $builder = new Insert();
        $builder->bindDialect($dialect);

        return $builder;
    }

    private function makeUpdate($dialect): Update
    {
        $builder = new Update();
        $builder->bindDialect($dialect);

        return $builder;
    }

    private function makeDelete($dialect): Delete
    {
        $builder = new Delete();
        $builder->bindDialect($dialect);

        return $builder;
    }
}
