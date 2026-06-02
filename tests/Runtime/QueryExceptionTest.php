<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PDOException;
use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Exception\QueryException;

final class QueryExceptionTest extends TestCase
{
    public function test_guardrail_factory_sets_category(): void
    {
        $ex = QueryException::guardrail('Positional parameters are forbidden in public API');

        self::assertSame(QueryException::CATEGORY_GUARDRAIL, $ex->category());
        self::assertNull($ex->sqlState());
        self::assertNull($ex->driverCode());
    }

    public function test_from_pdo_extracts_sqlstate_and_constraint_category(): void
    {
        $pdo = new PDOException('duplicate key', 23505);
        $pdo->errorInfo = ['23505', 23505, 'duplicate key value violates unique constraint'];

        $ex = QueryException::fromPdo('Query execution failed', $pdo);

        self::assertSame('23505', $ex->sqlState());
        self::assertSame('23505', $ex->driverCode());
        self::assertSame(QueryException::CATEGORY_CONSTRAINT, $ex->category());
        self::assertSame($pdo, $ex->getPrevious());
    }

    public function test_from_pdo_maps_connection_sqlstate_class(): void
    {
        $pdo = new PDOException('connection lost');
        $pdo->errorInfo = ['08006', 7, 'connection failure'];

        $ex = QueryException::fromPdo('Failed to prepare statement', $pdo);

        self::assertSame('08006', $ex->sqlState());
        self::assertSame(QueryException::CATEGORY_CONNECTION, $ex->category());
    }

    public function test_positional_placeholder_rejected_as_guardrail(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);

        try {
            $db->rows('SELECT * FROM users WHERE id = ?', ['id' => 1]);
            self::fail('Expected QueryException');
        } catch (QueryException $ex) {
            self::assertSame(QueryException::CATEGORY_GUARDRAIL, $ex->category());
        }
    }
}
