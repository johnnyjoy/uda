<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use UDA\Database;

final class ConnectNamedTest extends TestCase
{
    public function test_connect_default_matches_varargs(): void
    {
        self::assertSame(Database::connect(), Database::connectDefault());
    }

    public function test_connect_named_matches_varargs(): void
    {
        self::assertSame(Database::connect('alpha'), Database::connectNamed('alpha'));
        self::assertSame(Database::connect('beta'), Database::connectNamed('beta'));
    }

    public function test_connect_with_config_matches_varargs(): void
    {
        self::assertSame(
            Database::connect(UDA_TEST_CONFIG),
            Database::connectWithConfig(UDA_TEST_CONFIG),
        );
        self::assertSame(
            Database::connect('beta', UDA_TEST_CONFIG),
            Database::connectWithConfig(UDA_TEST_CONFIG, 'beta'),
        );
    }

    public function test_connect_named_pools_like_varargs(): void
    {
        $a = Database::connectNamed('alpha');
        $b = Database::connectNamed('alpha');

        self::assertSame($a, $b);
    }
}
