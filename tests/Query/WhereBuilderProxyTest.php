<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Query\Dialect\SQLite;
use UDA\Query\Select;

final class WhereBuilderProxyTest extends TestCase
{
    public function test_toSql_after_where_without_explicit_end(): void
    {
        $select = new Select();
        $select->bindDialect(new SQLite());
        $select->engine = 'sqlite';

        $withEnd = $select->from('users')->where('active', 1)->end()->toSql();
        $direct = $select->from('users')->where('active', 1)->toSql();

        self::assertSame($withEnd->sql, $direct->sql);
        self::assertSame($withEnd->params, $direct->params);
        self::assertStringContainsString('active', $direct->sql);
    }
}
