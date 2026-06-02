<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PDOException;
use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Driver;

final class ConnectionLostTest extends TestCase
{
    /**
     * @return array<string,array{0:PDOException,1:bool}>
     */
    public static function connectionLostProvider(): array
    {
        return [
            'sqlstate 08' => [self::pdoException(['08006', 0, 'connection failure']), true],
            'mysql 2006' => [self::pdoException(['HY000', 2006, 'server has gone away']), true],
            'mysql 2013' => [self::pdoException(['HY000', 2013, 'lost connection']), true],
            'hy000 gone away message' => [self::pdoException(['HY000', 0, 'MySQL server has gone away']), true],
            'hy000 lost connection message' => [self::pdoException(['HY000', 0, 'Lost connection to MySQL server']), true],
            'other sqlstate' => [self::pdoException(['23000', 1062, 'duplicate']), false],
            'hy000 unrelated' => [self::pdoException(['HY000', 0, 'syntax error']), false],
        ];
    }

    /**
     * @dataProvider connectionLostProvider
     */
    public function test_is_connection_lost(PDOException $exception, bool $expected): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $dprop = new \ReflectionProperty(Database::class, 'driver');
        $driver = $dprop->getValue($db);
        self::assertInstanceOf(Driver::class, $driver);

        $method = new \ReflectionMethod(Driver::class, 'isConnectionLost');

        self::assertSame($expected, $method->invoke($driver, $exception));
    }

    /**
     * @param array<int,mixed> $errorInfo
     */
    private static function pdoException(array $errorInfo): PDOException
    {
        $ex = new PDOException((string) ($errorInfo[2] ?? 'pdo error'));
        $ref = new \ReflectionProperty(PDOException::class, 'errorInfo');
        $ref->setValue($ex, $errorInfo);

        return $ex;
    }
}
