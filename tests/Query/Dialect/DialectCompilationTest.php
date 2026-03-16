<?php

declare(strict_types=1);

namespace Tests\Query\Dialect;

use PHPUnit\Framework\TestCase;
use UDA\Exception\QueryException;
use UDA\Query\Dialect\Db2;
use UDA\Query\Dialect\MariaDb;
use UDA\Query\Dialect\Oracle;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Dialect\SQLite;
use UDA\Query\Dialect\SqlServer;
use UDA\Query\Dialect\Sybase;
use UDA\Query\Insert;
use UDA\Query\Select;
use UDA\Query\Upsert;

final class DialectCompilationTest extends TestCase
{
    public function testPostgresSelectAndUpsert(): void
    {
        $select = $this->makeSelect(new PostgreSql(), 'pgsql')
            ->select('id')
            ->from('users')
            ->limit(5)
            ->offset(10);

        $sql = $select->toSql();
        $this->assertStringContainsString('LIMIT :q1 OFFSET :q2', $sql->getQuery());

        $upsert = $this->makeUpsert(new PostgreSql(), 'pgsql')
            ->into('devices')
            ->values(['id' => 5, 'name' => 'sensor'])
            ->key(['id'])
            ->update(['name']);

        $upsertSql = $upsert->toSql()->getQuery();
        $this->assertStringContainsString('ON CONFLICT ("id") DO UPDATE', $upsertSql);
    }

    public function testSqliteUpsertUsesOnConflict(): void
    {
        $sql = $this->makeUpsert(new SQLite(), 'sqlite')
            ->into('inventory')
            ->values(['sku' => 'A1', 'qty' => 10])
            ->key(['sku'])
            ->update(['qty'])
            ->toSql()
            ->getQuery();

        $this->assertStringContainsString('ON CONFLICT ("sku") DO UPDATE', $sql);
    }

    public function testMariaDbUpsertVariants(): void
    {
        $ignoreSql = $this->makeUpsert(new MariaDb(), 'mariadb')
            ->into('inventory')
            ->values(['sku' => 'A1', 'qty' => 10])
            ->key(['sku'])
            ->doNothing()
            ->toSql()
            ->getQuery();

        $this->assertStringStartsWith('INSERT IGNORE', $ignoreSql);

        $updateSql = $this->makeUpsert(new MariaDb(), 'mariadb')
            ->into('inventory')
            ->values(['sku' => 'A1', 'qty' => 10])
            ->key(['sku'])
            ->update(['qty'])
            ->toSql()
            ->getQuery();

        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $updateSql);
    }

    public function testSqlServerPaginationRequiresOrderBy(): void
    {
        $builder = $this->makeSelect(new SqlServer(), 'sqlserver')
            ->select('id')
            ->from('users')
            ->limit(10);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('SQL Server requires ORDER BY');
        $builder->toSql();
    }

    public function testSqlServerPaginationUsesOffsetFetch(): void
    {
        $builder = $this->makeSelect(new SqlServer(), 'sqlserver')
            ->select('id')
            ->from('users')
            ->orderBy('id')
            ->offset(20)
            ->limit(10);

        $sql = $builder->toSql()->getQuery();
        $this->assertStringContainsString('OFFSET :q1 ROWS FETCH NEXT :q2 ROWS ONLY', $sql);
    }

    public function testSqlServerInsertReturningUsesOutputClause(): void
    {
        $sql = $this->makeInsert(new SqlServer(), 'sqlserver')
            ->into('customers')
            ->set('name', 'Alpha')
            ->returning('id', 'name')
            ->toSql()
            ->getQuery();

        $this->assertStringContainsString('OUTPUT INSERTED."id", INSERTED."name"', $sql);
    }

    public function testSybaseSharesSqlServerPagination(): void
    {
        $builder = $this->makeSelect(new Sybase(), 'sybase')
            ->select('id')
            ->from('users')
            ->orderBy('id')
            ->limit(5);

        $sql = $builder->toSql()->getQuery();
        $this->assertStringContainsString('OFFSET :q1 ROWS FETCH NEXT :q2 ROWS ONLY', $sql);
    }

    public function testOracleMergeUpsert(): void
    {
        $sql = $this->makeUpsert(new Oracle(), 'oracle')
            ->into('accounts')
            ->values(['id' => 1, 'status' => 'active'])
            ->key(['id'])
            ->update(['status'])
            ->toSql()
            ->getQuery();

        $this->assertStringContainsString('MERGE INTO', $sql);
        $this->assertStringContainsString('WHEN NOT MATCHED THEN INSERT', $sql);
    }

    public function testOracleInsertReturningMetadataWithoutClause(): void
    {
        $insert = $this->makeInsert(new Oracle(), 'oracle')
            ->into('users')
            ->set('id', 5)
            ->returning('id');

        $sql = $insert->toSql();

        $this->assertStringNotContainsString('RETURNING', $sql->getQuery());
        $this->assertSame(['id'], $sql->getReturningColumns());
    }

    public function testDb2DialectSupportsPaginationAndMerge(): void
    {
        $select = $this->makeSelect(new Db2(), 'db2')
            ->select('id')
            ->from('users')
            ->offset(5)
            ->limit(10);

        $sql = $select->toSql()->getQuery();
        $this->assertStringContainsString('OFFSET :q1 ROWS FETCH NEXT :q2 ROWS ONLY', $sql);

        $merge = $this->makeUpsert(new Db2(), 'db2')
            ->into('inventory')
            ->values(['sku' => 'A1', 'qty' => 5])
            ->key(['sku'])
            ->update(['qty'])
            ->toSql()
            ->getQuery();

        $this->assertStringContainsString('MERGE INTO', $merge);
        $this->assertStringContainsString('WHEN NOT MATCHED THEN INSERT', $merge);
    }

    public function testMariaDbReturningThrows(): void
    {
        $this->expectException(QueryException::class);

        $this->makeInsert(new MariaDb(), 'mariadb')
            ->into('users')
            ->set('name', 'alice')
            ->returning('id')
            ->toSql();
    }

    public function testDb2ReturningThrows(): void
    {
        $this->expectException(QueryException::class);

        $this->makeInsert(new Db2(), 'db2')
            ->into('users')
            ->set('name', 'alice')
            ->returning('id')
            ->toSql();
    }

    private function makeSelect($dialect, string $driverName): Select
    {
        $builder = new Select();
        $builder->driverName = $driverName;
        $builder->bindDialect($dialect);

        return $builder;
    }

    private function makeUpsert($dialect, string $driverName): Upsert
    {
        $builder = new Upsert();
        $builder->driverName = $driverName;
        $builder->bindDialect($dialect);

        return $builder;
    }

    private function makeInsert($dialect, string $driverName): Insert
    {
        $builder = new Insert();
        $builder->driverName = $driverName;
        $builder->bindDialect($dialect);

        return $builder;
    }
}
