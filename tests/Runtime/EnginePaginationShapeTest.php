<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use UDA\Driver;
use UDA\Driver\Oracle;
use UDA\Driver\SQLServer as SQLServerRules;
use UDA\Driver\Sybase as SybaseRules;
use UDA\Query\Dialect\Dialect;
use UDA\Query\Dialect\Oracle as OracleDialect;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\State\Select as SelectState;
use UDA\Query\Dialect\SqlServer as SqlServerDialect;
use UDA\Query\Dialect\Sybase as SybaseDialect;
use UDA\SQL\ParamBag;

final class EnginePaginationShapeTest extends TestCase
{
    private const LIMIT = 10;

    private const OFFSET = 20;

    /**
     * @return array<string,array{0:string,1:Dialect,2:callable}>
     */
    public static function engineProvider(): array
    {
        return [
            'pgsql' => [
                'pgsql',
                new PostgreSql(),
                static fn (int $limit, int $offset): string => sprintf('LIMIT %d OFFSET %d', $limit, $offset),
            ],
            'sqlserver' => [
                'sqlserver',
                new SqlServerDialect(),
                [SQLServerRules::class, 'limitOffset'],
            ],
            'sybase' => [
                'sybase',
                new SybaseDialect(),
                [SybaseRules::class, 'limitOffset'],
            ],
            'oracle' => [
                'oracle',
                new OracleDialect(),
                [Oracle::class, 'limitOffset'],
            ],
        ];
    }

    /**
     * @dataProvider engineProvider
     */
    public function test_dialect_select_pagination_matches_driver_helper_shape(
        string $engine,
        Dialect $dialect,
        callable $driverHelper
    ): void {
        $driverFragment = ($driverHelper)(self::LIMIT, self::OFFSET);
        $dialectFragment = $this->compileDialectPagination($dialect, $engine, self::LIMIT, self::OFFSET);

        self::assertSame(
            $this->paginationShape($driverFragment),
            $this->paginationShape($dialectFragment),
            sprintf('%s dialect and Driver helper pagination shapes diverged', $engine)
        );
    }

    public function test_driver_limitOffset_instance_delegates_to_engine_rules(): void
    {
        $driver = Driver::connect('alpha');
        self::assertSame(
            $this->paginationShape(sprintf('LIMIT %d OFFSET %d', self::LIMIT, self::OFFSET)),
            $this->paginationShape($driver->limitOffset(self::LIMIT, self::OFFSET))
        );
    }

    private function compileDialectPagination(Dialect $dialect, string $engine, int $limit, int $offset): string
    {
        $params = new ParamBag();

        $orderBy = in_array($engine, ['sqlserver', 'sybase'], true) ? ['[id] ASC'] : [];

        $state = new SelectState(
            ctes: [],
            distinct: false,
            columns: ['*'],
            fromClause: 'users',
            joins: [],
            whereClause: null,
            groupBy: [],
            havingClause: null,
            orderBy: $orderBy,
            limit: $limit,
            offset: $offset,
            tables: ['users'],
            unions: [],
            params: $params,
        );

        $method = new ReflectionMethod($dialect, 'compileSelectPagination');
        $method->setAccessible(true);

        return (string) $method->invoke($dialect, $state);
    }

    private function paginationShape(string $fragment): string
    {
        $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $fragment) ?? ''));
        $normalized = preg_replace('/:\w+/', '?', $normalized) ?? '';
        $normalized = preg_replace('/\b\d+\b/', '?', $normalized) ?? '';

        return $normalized;
    }
}
