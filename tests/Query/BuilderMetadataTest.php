<?php

declare(strict_types=1);

namespace Tests\Query;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UDA\Query\Delete;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Insert;
use UDA\Query\Select;
use UDA\Query\Sql;
use UDA\Query\Update;
use UDA\Query\Upsert;
use UDA\Query\WhereBuilder;
use UDA\SQL\SqlMessage;

final class BuilderMetadataTest extends TestCase
{
    public function testSqlValueObjectMetadataDefaultsAndOverrides(): void
    {
        $default = Sql::of('SELECT 1');

        $this->assertSame('raw', $default->getStatementType());
        $this->assertFalse($default->hasWhereClause());
        $this->assertFalse($default->hasLimitClause());
        $this->assertFalse($default->isUnsafe());

        $custom = Sql::of(
            'SELECT 1',
            [],
            [],
            [],
            null,
            [],
            [],
            [
                'statementType' => 'select',
                'hasWhere' => true,
                'hasLimit' => true,
                'unsafe' => true,
            ]
        );

        $this->assertSame('select', $custom->getStatementType());
        $this->assertTrue($custom->hasWhereClause());
        $this->assertTrue($custom->hasLimitClause());
        $this->assertTrue($custom->isUnsafe());

        $clone = $custom->withGuardrailMetadata('delete', false, false, false);

        $this->assertSame('delete', $clone->getStatementType());
        $this->assertSame('select', $custom->getStatementType());

        $this->expectException(InvalidArgumentException::class);
        Sql::of('SELECT 1', [], [], [], null, [], [], ['statementType' => 'truncate']);
    }

    public function testSqlMessageMetadataClones(): void
    {
        $message = new SqlMessage('SELECT 1');

        $this->assertSame('raw', $message->getStatementType());
        $this->assertFalse($message->hasWhereClause());
        $this->assertFalse($message->hasLimitClause());
        $this->assertFalse($message->isUnsafe());

        $clone = $message->withGuardrailMetadata('update', true, false, true);

        $this->assertSame('update', $clone->getStatementType());
        $this->assertTrue($clone->hasWhereClause());
        $this->assertFalse($message->hasWhereClause());
        $this->assertTrue($clone->isUnsafe());
        $this->assertFalse($message->isUnsafe());

        $this->expectException(InvalidArgumentException::class);
        $message->withGuardrailMetadata('truncate', false, false, false);
    }

    public function testSelectMetadataTracksWhereLimitAndUnsafeClone(): void
    {
        $builder = $this->makeSelect()
            ->select('id')
            ->from('users');

        /** @var WhereBuilder $where */
        $where = $builder->where('id', 5);
        /** @var Select $builder */
        $builder = $where->end();
        $builder = $builder->limit(10);

        $unsafeBuilder = $builder->unsafe();

        $sql = $builder->toSql();
        $this->assertSame('select', $sql->getStatementType());
        $this->assertTrue($sql->hasWhereClause());
        $this->assertTrue($sql->hasLimitClause());
        $this->assertTrue($sql->isUnsafe());

        $unsafeSql = $unsafeBuilder->toSql();
        $this->assertTrue($unsafeSql->isUnsafe());
        $this->assertTrue($sql->isUnsafe());
    }

    public function testMutationBuildersAnnotateMetadata(): void
    {
        $insert = $this->makeInsert()
            ->into('users')
            ->set('name', 'Ada');

        $insertSql = $insert->toSql();
        $this->assertSame('insert', $insertSql->getStatementType());
        $this->assertFalse($insertSql->hasWhereClause());
        $this->assertFalse($insertSql->hasLimitClause());

        $update = $this->makeUpdate()
            ->table('users')
            ->set('name', 'Ada');
        /** @var WhereBuilder $updateChain */
        $updateChain = $update->where('id', 1);
        /** @var Update $update */
        $update = $updateChain->end();
        $updateSql = $update->toSql();
        $this->assertSame('update', $updateSql->getStatementType());
        $this->assertTrue($updateSql->hasWhereClause());

        $delete = $this->makeDelete()->table('users');
        /** @var WhereBuilder $deleteChain */
        $deleteChain = $delete->where('id', 1);
        /** @var Delete $delete */
        $delete = $deleteChain->end();
        $deleteSql = $delete->toSql();
        $this->assertSame('delete', $deleteSql->getStatementType());
        $this->assertTrue($deleteSql->hasWhereClause());

        $upsert = $this->makeUpsert()
            ->into('users')
            ->values(['id' => 1, 'name' => 'Ada'])
            ->key(['id'])
            ->update(['name']);
        $upsertSql = $upsert->toSql();
        $this->assertSame('upsert', $upsertSql->getStatementType());
        $this->assertFalse($upsertSql->hasWhereClause());
    }

    private function makeSelect(): Select
    {
        $builder = new Select();
        $builder->bindDialect(new PostgreSql());

        return $builder;
    }

    private function makeInsert(): Insert
    {
        $builder = new Insert();
        $builder->bindDialect(new PostgreSql());

        return $builder;
    }

    private function makeUpdate(): Update
    {
        $builder = new Update();
        $builder->bindDialect(new PostgreSql());

        return $builder;
    }

    private function makeDelete(): Delete
    {
        $builder = new Delete();
        $builder->bindDialect(new PostgreSql());

        return $builder;
    }

    private function makeUpsert(): Upsert
    {
        $builder = new Upsert();
        $builder->bindDialect(new PostgreSql());

        return $builder;
    }
}
