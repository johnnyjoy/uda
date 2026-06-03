<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Query;
use UDA\Query\Delete;
use UDA\Query\Select;

/**
 * Ensures FQCN UDA\Query (abstract base) coexists with namespace UDA\Query\ (concrete builders).
 */
final class QueryBaseAutoloadTest extends TestCase
{
    public function test_abstract_query_class_and_query_namespace_coexist(): void
    {
        self::assertTrue(is_subclass_of(Select::class, Query::class));
        self::assertTrue(is_subclass_of(Delete::class, Query::class));
        self::assertSame('UDA\Query', Query::class);
        self::assertSame('UDA\Query\Select', Select::class);
    }

    public function test_database_bind_builder_accepts_concrete_subclass(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $builder = $db->select('id')->from('users');

        self::assertInstanceOf(Select::class, $builder);
        self::assertInstanceOf(Query::class, $builder);
    }
}
