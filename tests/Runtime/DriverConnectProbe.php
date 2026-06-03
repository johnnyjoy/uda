<?php

declare(strict_types=1);

namespace Tests\Runtime;

use ReflectionMethod;

/**
 * @internal test helper — invokes Driver DSN routing without opening PDO.
 */
final class DriverConnectProbe
{
    /**
     * @param array<string,mixed> $params
     */
    public static function dsnForEngine(string $engine, string $transport, array $params): string
    {
        $method = new ReflectionMethod(\UDA\Driver::class, 'dsn');
        $method->setAccessible(true);

        /** @var string $dsn */
        $dsn = $method->invoke(null, $engine, $transport, $params);

        return $dsn;
    }
}
