<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Exception\QueryException;
use UDA\Exception\QuerySafetyException;
use UDA\Query\Delete;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Insert;
use UDA\Query\Sql;
use UDA\Query\Update;
use UDA\Query\Upsert;
use UDA\Query\WhereBuilder;
use UDA\Safety\GuardrailConfig;
use UDA\Safety\QueryGuardrails;
use UDA\SQL\SqlMessage;

final class WriteBuilderTest extends TestCase
{
    public function testInsertBuilderGeneratesNamedParameters(): void
    {
        $insert = $this->makeInsert()
            ->into('orders')
            ->set('name', 'alpha')
            ->set('total', 42);

        $sql = $insert->toSql();

        $this->assertSame('INSERT INTO "orders" ("name", "total") VALUES (:q1, :q2)', $sql->getQuery());
        $this->assertSame(['q1' => 'alpha', 'q2' => 42], $sql->getParams());
        $this->assertSame(['orders'], $sql->getCacheTables());
    }

    public function testInsertBuilderSupportsBulkValues(): void
    {
        $insert = $this->makeInsert()
            ->into('audit_log')
            ->rows([
                ['name' => 'alpha', 'status' => 'open'],
                ['name' => 'bravo', 'status' => 'closed'],
            ]);

        $sql = $insert->toSql();

        $this->assertSame(
            'INSERT INTO "audit_log" ("name", "status") VALUES (:q1, :q2), (:q3, :q4)',
            $sql->getQuery()
        );
        $this->assertSame(
            ['q1' => 'alpha', 'q2' => 'open', 'q3' => 'bravo', 'q4' => 'closed'],
            $sql->getParams()
        );
    }

    public function testUpdateBuilderBuildsDeterministicWhereClause(): void
    {
        $update = $this->makeUpdate()
            ->table('users')
            ->set('status', 'archived');

        /** @var WhereBuilder $where */
        $where = $update->where('id', 10);
        $where->and('archived_at')->lte('2024-01-01');
        $update = $where->end();

        $sql = $update->toSql();

        $this->assertSame(
            'UPDATE "users" SET "status" = :q3 WHERE "id" = :q1 AND "archived_at" <= :q2',
            $sql->getQuery()
        );
        $this->assertSame(
            ['q1' => 10, 'q2' => '2024-01-01', 'q3' => 'archived'],
            $sql->getParams()
        );
    }

    public function testUpdateGuardrailRequiresWhereUnlessUnsafe(): void
    {
        $config = GuardrailConfig::fromArray(['enabled' => true]);

        $update = $this->makeUpdate()
            ->table('users')
            ->set('status', 'archived');

        $sql = $update->toSql();

        try {
            QueryGuardrails::validate($this->toSqlMessage($sql), $config, 'exec');
            $this->fail('Guardrail violation expected for missing UPDATE where clause');
        } catch (QuerySafetyException $exception) {
            $this->assertSame('update_missing_where', $exception->getReason());
        }

        $unsafeSql = (clone $update)->unsafe()->toSql();

        QueryGuardrails::validate($this->toSqlMessage($unsafeSql), $config, 'exec');
        $this->assertTrue($unsafeSql->isUnsafe());
    }

    public function testDeleteBuilderRequiresTableBeforeSql(): void
    {
        $delete = $this->makeDelete()
            ->table('sessions');

        /** @var WhereBuilder $where */
        $where = $delete->where('expired', true);
        $delete = $where->end();

        $sql = $delete->toSql();

        $this->assertSame('DELETE FROM "sessions" WHERE "expired" = :q1', $sql->getQuery());
        $this->assertSame(['q1' => true], $sql->getParams());
    }

    public function testDeleteGuardrailRequiresWhereUnlessUnsafe(): void
    {
        $config = GuardrailConfig::fromArray(['enabled' => true]);

        $delete = $this->makeDelete()
            ->table('sessions');

        $sql = $delete->toSql();

        try {
            QueryGuardrails::validate($this->toSqlMessage($sql), $config, 'exec');
            $this->fail('Guardrail violation expected for missing DELETE where clause');
        } catch (QuerySafetyException $exception) {
            $this->assertSame('delete_missing_where', $exception->getReason());
        }

        $unsafeSql = (clone $delete)->unsafe()->toSql();

        QueryGuardrails::validate($this->toSqlMessage($unsafeSql), $config, 'exec');
        $this->assertTrue($unsafeSql->isUnsafe());
    }

    public function testDeleteMissingTableThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('No table defined for delete query');

        $this->makeDelete()->toSql();
    }

    public function testUpsertBuilderGeneratesDoUpdateClause(): void
    {
        $upsert = $this->makeUpsert()
            ->into('devices')
            ->values([
                'external_id' => 'sensor-1',
                'name' => 'Thermostat',
            ])
            ->key(['external_id'])
            ->update(['name']);

        $sql = $upsert->toSql();

        $this->assertSame(
            'INSERT INTO "devices" ("external_id", "name") VALUES (:q1, :q2) '
            . 'ON CONFLICT ("external_id") DO UPDATE SET "name" = EXCLUDED."name"',
            $sql->getQuery()
        );
        $this->assertSame(['devices'], $sql->getCacheTables());
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

    private function toSqlMessage(Sql $sql): SqlMessage
    {
        return new SqlMessage(
            $sql->getQuery(),
            $sql->getParams(),
            $sql->getCacheTables(),
            $sql->getReturningColumns(),
            $sql->getInsertTable(),
            $sql->getInsertColumns(),
            $sql->getValuePlaceholders(),
            $sql->getStatementType(),
            $sql->hasWhereClause(),
            $sql->hasLimitClause(),
            $sql->isUnsafe()
        );
    }
}
