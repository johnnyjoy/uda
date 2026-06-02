<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Driver;
use UDA\Exception\QueryException;
use UDA\SQL\SqlMessage;

final class NormalizeSqlTest extends TestCase
{
    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function normalize(string|SqlMessage $sql, array $params): array
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $dprop = new \ReflectionProperty(Database::class, 'driver');
        $driver = $dprop->getValue($db);
        self::assertInstanceOf(Driver::class, $driver);

        $method = new \ReflectionMethod(Driver::class, 'normalizeSql');

        /** @var array{0:string,1:array<string,mixed>} */
        return $method->invoke($driver, $sql, $params);
    }

    public function test_merges_sql_message_params(): void
    {
        $message = new SqlMessage('SELECT :id', ['id' => 1]);

        [$query, $params] = self::normalize($message, ['extra' => 'x']);

        self::assertSame('SELECT :id', $query);
        self::assertSame(['id' => 1, 'extra' => 'x'], $params);
    }

    public function test_rejects_positional_parameters(): void
    {
        $this->expectException(QueryException::class);

        self::normalize('SELECT ?', []);
    }
}
