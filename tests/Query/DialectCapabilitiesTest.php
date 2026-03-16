<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Exception\QueryException;
use UDA\Query\Dialect\Db2;
use UDA\Query\Dialect\MariaDb;
use UDA\Query\Dialect\Oracle;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Dialect\SQLite;
use UDA\Query\Dialect\SqlServer;
use UDA\Query\Dialect\DeleteState;
use UDA\Query\Dialect\InsertState;
use UDA\Query\Dialect\SelectState;
use UDA\Query\Dialect\UpdateState;
use UDA\Query\Dialect\UpsertState;
use UDA\Query\Expr;
use UDA\Query\Insert;
use UDA\Query\Select;
use UDA\Query\Upsert;
use UDA\Query\Sql;

final class DialectCapabilitiesTest extends TestCase
{
    public function testInsertReturningThrowsWhenDialectLacksSupport(): void
    {
        $builder = new Insert();
        $builder->bindDialect(new MariaDb());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('does not support RETURNING');

        $builder->into('employees')->returning('id');
    }

    public function testRecursiveCteFailsImmediatelyWhenDialectDisallows(): void
    {
        $dialect = new ProxyDialect(new PostgreSql(), ['recursiveCte' => false]);

        $builder = new Select();
        $builder->bindDialect($dialect);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('does not support RECURSIVE CTE');

        $builder->withRecursive('recent', (new Select())->select('1')->from('dual'));
    }

    public function testWindowFunctionsBlockedWhenCapabilityMissing(): void
    {
        $dialect = new ProxyDialect(new SQLite(), ['window' => false]);

        $builder = new Select();
        $builder->bindDialect($dialect);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('window functions');

        $builder
            ->select(Expr::rowNumber()->over()->as('rank'))
            ->from('employees')
            ->toSql();
    }

    public function testMergeCapabilityFlags(): void
    {
        $this->assertTrue((new Oracle())->supportsMerge());
        $this->assertTrue((new SqlServer())->supportsMerge());
        $this->assertFalse((new MariaDb())->supportsMerge());
        $this->assertFalse((new PostgreSql())->supportsMerge());
    }

    public function testUpsertBuilderRespectsDialectCapability(): void
    {
        $dialect = new ProxyDialect(new PostgreSql(), ['upsert' => false]);

        $builder = new Upsert();
        $builder->bindDialect($dialect);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('does not support UPSERT');

        $builder
            ->into('employees')
            ->values(['employee_no' => 'E001', 'first_name' => 'A'])
            ->key(['employee_no'])
            ->update(['first_name'])
            ->toSql();
    }

    public function testCapabilityMatrixExposure(): void
    {
        $expectations = [
            Oracle::class => [
                'returning' => true,
                'merge' => true,
                'upsert' => true,
            ],
            MariaDb::class => [
                'returning' => false,
                'merge' => false,
                'upsert' => true,
            ],
            Db2::class => [
                'returning' => false,
                'merge' => true,
                'upsert' => true,
            ],
        ];

        foreach ($expectations as $class => $matrix) {
            $dialect = new $class();
            $this->assertSame($matrix['returning'], $dialect->supportsReturning());
            $this->assertSame($matrix['merge'], $dialect->supportsMerge());
            $this->assertSame($matrix['upsert'], $dialect->supportsUpsert());
        }
    }

    public function testExplainCapabilityMatrix(): void
    {
        $pg = new PostgreSql();
        $this->assertTrue($pg->supportsExplain());
        $this->assertTrue($pg->supportsExplainAnalyze());

        $maria = new MariaDb();
        $this->assertTrue($maria->supportsExplain());
        $this->assertTrue($maria->supportsExplainAnalyze());

        $sqlite = new SQLite();
        $this->assertTrue($sqlite->supportsExplain());
        $this->assertFalse($sqlite->supportsExplainAnalyze());

        $sqlServer = new SqlServer();
        $this->assertTrue($sqlServer->supportsExplain());
        $this->assertFalse($sqlServer->supportsExplainAnalyze());

        $oracle = new Oracle();
        $this->assertTrue($oracle->supportsExplain());
        $this->assertFalse($oracle->supportsExplainAnalyze());

        $db2 = new Db2();
        $this->assertFalse($db2->supportsExplain());
        $this->assertFalse($db2->supportsExplainAnalyze());
    }

    public function testCteMaterializationHintCapability(): void
    {
        $supported = [new PostgreSql(), new SQLite()];

        foreach ($supported as $dialect) {
            $this->assertTrue($dialect->supportsCteMaterializationHints(), $dialect->name() . ' should advertise hint support');
        }

        $unsupported = [
            new MariaDb(),
            new SqlServer(),
            new Oracle(),
            new Db2(),
        ];

        foreach ($unsupported as $dialect) {
            $this->assertFalse($dialect->supportsCteMaterializationHints(), $dialect->name() . ' should ignore hints');
        }
    }
}

final class ProxyDialect extends \UDA\Query\Dialect\Dialect
{
    public function __construct(private \UDA\Query\Dialect\Dialect $inner, private array $capabilities = [])
    {
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function compileSelect(SelectState $state): Sql
    {
        return $this->inner->compileSelect($state);
    }

    public function compileInsert(InsertState $state): Sql
    {
        return $this->inner->compileInsert($state);
    }

    public function compileUpdate(UpdateState $state): Sql
    {
        return $this->inner->compileUpdate($state);
    }

    public function compileDelete(DeleteState $state): Sql
    {
        return $this->inner->compileDelete($state);
    }

    public function compileUpsert(UpsertState $state): Sql
    {
        return $this->inner->compileUpsert($state);
    }

    public function supportsRecursiveCte(): bool
    {
        return $this->capabilities['recursiveCte'] ?? $this->inner->supportsRecursiveCte();
    }

    public function supportsWindowFunctions(): bool
    {
        return $this->capabilities['window'] ?? $this->inner->supportsWindowFunctions();
    }

    public function supportsUpsert(): bool
    {
        return $this->capabilities['upsert'] ?? $this->inner->supportsUpsert();
    }

    public function supportsCteMaterializationHints(): bool
    {
        return $this->capabilities['cteMaterializationHints'] ?? $this->inner->supportsCteMaterializationHints();
    }

    public function supportsExplain(): bool
    {
        return $this->capabilities['explain'] ?? $this->inner->supportsExplain();
    }

    public function supportsExplainAnalyze(): bool
    {
        return $this->capabilities['explainAnalyze'] ?? $this->inner->supportsExplainAnalyze();
    }
}
