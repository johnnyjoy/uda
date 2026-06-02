<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use UDA\Config\Snapshot;
use UDA\Config\Validator;
use UDA\Exception\ConfigException;

final class TraceConfigTest extends TestCase
{
    /** @var list<string> */
    private array $notices = [];

    protected function setUp(): void
    {
        set_error_handler(function (int $s, string $m): bool {
            if ($s === E_USER_NOTICE) {
                $this->notices[] = $m;
            }

            return true;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function aliasProvider(): array
    {
        return ['dblib' => ['dblib', 'sybase'], 'sqlsrv' => ['sqlsrv', 'sqlserver']];
    }

    /** @dataProvider aliasProvider */
    public function test_trace_notices_on_alias(string $driver, string $engine): void
    {
        new Snapshot(['c' => ['driver' => $driver, 'trace' => true, 'params' => ['host' => 'x']]]);
        self::assertStringContainsString($engine, $this->notices[0] ?? '');

        $this->notices = [];
        new Snapshot(['c' => ['driver' => $driver, 'trace' => false, 'params' => ['host' => 'x']]]);
        self::assertSame([], $this->notices);
    }

    public function test_trace_is_per_connection_and_skips_explicit_driver(): void
    {
        $row = ['driver' => 'dblib', 'params' => ['host' => 'x']];
        new Snapshot(['quiet' => $row, 'noisy' => $row + ['trace' => true]]);
        self::assertCount(1, $this->notices);

        $this->notices = [];
        new Snapshot([
            'mssql' => ['driver' => 'sqlserver', 'transport' => 'dblib', 'trace' => true, 'params' => []],
        ]);
        self::assertSame([], $this->notices);

        (new Validator())->validate(['connections' => ['x' => $row]]);
        self::assertSame([], $this->notices);
    }

    public function test_trace_must_be_boolean(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("'trace' must be boolean");
        (new Validator())->validate([
            'connections' => ['x' => ['driver' => 'sqlite', 'trace' => 'yes', 'params' => []]],
        ]);
    }
}
