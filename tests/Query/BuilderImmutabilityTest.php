<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Exception\QueryException;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Insert;
use UDA\Query\Select;
use UDA\Query\WhereBuilder;

final class BuilderImmutabilityTest extends TestCase
{
    public function testSelectBuilderCanBeReusedWithoutMutation(): void
    {
        $base = $this->makeSelect()->from('employees');

        /** @var WhereBuilder $dept10Builder */
        $dept10Builder = $base->where('department_id', 10);
        $dept10 = $dept10Builder->end();

        /** @var WhereBuilder $dept20Builder */
        $dept20Builder = $base->where('department_id', 20);
        $dept20 = $dept20Builder->end();

        $this->assertNotSame($base, $dept10);
        $this->assertNotSame($base, $dept20);
        $this->assertNotSame($dept10, $dept20);

        $baseSql = $base->toSql();
        $this->assertStringNotContainsString('department_id', $baseSql->getQuery());

        $dept10Sql = $dept10->toSql();
        $dept20Sql = $dept20->toSql();

        $this->assertStringContainsString('"department_id" = :q1', $dept10Sql->getQuery());
        $this->assertStringContainsString('"department_id" = :q1', $dept20Sql->getQuery());
        $this->assertSame(['q1' => 10], $dept10Sql->getParams());
        $this->assertSame(['q1' => 20], $dept20Sql->getParams());
    }

    public function testInsertBuilderReuseKeepsBaseClean(): void
    {
        $base = $this->makeInsert()->into('audit_log');

        $alpha = $base->set('event', 'alpha');
        $beta = $base->set('event', 'beta');

        $this->assertNotSame($alpha, $beta);

        $this->assertSame(
            ['q1' => 'alpha'],
            $alpha->toSql()->getParams()
        );

        $this->assertSame(
            ['q1' => 'beta'],
            $beta->toSql()->getParams()
        );

        try {
            $base->toSql();
            $this->fail('Base builder should not have enough data to compile SQL');
        } catch (QueryException $e) {
            $this->assertStringContainsString('No data provided', $e->getMessage());
        }
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
}
