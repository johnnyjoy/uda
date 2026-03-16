<?php

declare(strict_types=1);

namespace Tests\Query;

require_once __DIR__ . '/CountingDialect.php';

use PHPUnit\Framework\TestCase;
use UDA\Query\Delete;
use UDA\Query\Expr;
use UDA\Query\Insert;
use UDA\Query\Select;
use UDA\Query\Update;
use UDA\Query\Upsert;

final class BuilderMemoizationTest extends TestCase
{
    public function testSelectMemoizesCompilation(): void
    {
        $dialect = CountingDialect::postgres();
        $builder = $this->makeSelect($dialect)
            ->select('id')
            ->from('users');
        /** @var \UDA\Query\WhereBuilder $where */
        $where = $builder->where('status', 'active');
        $builder = $where->end();

        $first = $builder->toSql();
        $second = $builder->toSql();

        $this->assertSame($first, $second);
        $this->assertSame(1, $dialect->selectCompileCount());
    }

    public function testSelectMemoizationIsPerInstance(): void
    {
        $dialect = CountingDialect::postgres();
        $base = $this->makeSelect($dialect)
            ->select('id')
            ->from('users');

        /** @var \UDA\Query\WhereBuilder $firstChain */
        $firstChain = $base->where('status', 'active');
        $active = $firstChain->end();

        /** @var \UDA\Query\WhereBuilder $secondChain */
        $secondChain = $base->where('status', 'inactive');
        $inactive = $secondChain->end();

        $active->toSql();
        $inactive->toSql();

        $this->assertSame(2, $dialect->selectCompileCount());
    }

    public function testSelectMemoizationWithSubqueriesUnionsAndExpressions(): void
    {
        $dialect = CountingDialect::postgres();

        $sub = $this->makeSelect($dialect)
            ->select('accounts.id')
            ->from('accounts');
        /** @var \UDA\Query\WhereBuilder $subWhere */
        $subWhere = $sub->where('accounts.status', 'active');
        $sub = $subWhere->end();

        $union = $this->makeSelect($dialect)
            ->select('archived_users.id')
            ->from('archived_users');

        $expr = Expr::raw('COALESCE(users.last_login, :fallback)', ['fallback' => '1970-01-01'])->as('last_seen');

        $builder = $this->makeSelect($dialect)
            ->select('users.id', $expr)
            ->from('users')
            ->whereExists($sub)
            ->end()
            ->union($union);

        $sql = $builder->toSql();
        $this->assertSame(3, $dialect->selectCompileCount(), 'Parent + subquery + union compiled once each');

        $again = $builder->toSql();

        $this->assertSame($sql, $again);
        $this->assertSame(3, $dialect->selectCompileCount(), 'Memoized results prevent additional compilations');
        $this->assertStringContainsString('COALESCE(users.last_login, :q', $sql->getQuery());
        $this->assertStringContainsString('EXISTS', $sql->getQuery());
        $this->assertStringContainsString('UNION', $sql->getQuery());
    }

    public function testInsertMemoizesCompilation(): void
    {
        $dialect = CountingDialect::postgres();
        $builder = $this->makeInsert($dialect)
            ->into('users')
            ->set('name', 'Ada');

        $builder->toSql();
        $builder->toSql();

        $this->assertSame(1, $dialect->insertCompileCount());
    }

    public function testUpdateMemoizesCompilation(): void
    {
        $dialect = CountingDialect::postgres();
        $builder = $this->makeUpdate($dialect)
            ->table('users')
            ->set('status', 'inactive');
        /** @var \UDA\Query\WhereBuilder $chain */
        $chain = $builder->where('id', 5);
        $builder = $chain->end();

        $builder->toSql();
        $builder->toSql();

        $this->assertSame(1, $dialect->updateCompileCount());
    }

    public function testDeleteMemoizesCompilation(): void
    {
        $dialect = CountingDialect::postgres();
        $builder = $this->makeDelete($dialect)
            ->table('users');
        /** @var \UDA\Query\WhereBuilder $chain
         */
        $chain = $builder->where('id', 5);
        $builder = $chain->end();

        $builder->toSql();
        $builder->toSql();

        $this->assertSame(1, $dialect->deleteCompileCount());
    }

    public function testUpsertMemoizesCompilation(): void
    {
        $dialect = CountingDialect::postgres();
        $builder = $this->makeUpsert($dialect)
            ->into('users')
            ->values(['id' => 1, 'name' => 'Ada'])
            ->key(['id'])
            ->update(['name']);

        $builder->toSql();
        $builder->toSql();

        $this->assertSame(1, $dialect->upsertCompileCount());
    }

    private function makeSelect(CountingDialect $dialect): Select
    {
        $builder = new Select();
        $builder->bindDialect($dialect);

        return $builder;
    }

    private function makeInsert(CountingDialect $dialect): Insert
    {
        $builder = new Insert();
        $builder->bindDialect($dialect);

        return $builder;
    }

    private function makeUpdate(CountingDialect $dialect): Update
    {
        $builder = new Update();
        $builder->bindDialect($dialect);

        return $builder;
    }

    private function makeDelete(CountingDialect $dialect): Delete
    {
        $builder = new Delete();
        $builder->bindDialect($dialect);

        return $builder;
    }

    private function makeUpsert(CountingDialect $dialect): Upsert
    {
        $builder = new Upsert();
        $builder->bindDialect($dialect);

        return $builder;
    }
}
