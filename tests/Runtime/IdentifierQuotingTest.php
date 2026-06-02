<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use UDA\Config\Snapshot;
use UDA\Query\Expr;
use UDA\SQL\Identifier;
use UDA\SQL\ParamBag;

final class IdentifierQuotingTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function engineAliasProvider(): array
    {
        return [
            'mysql' => ['mysql', '`users`'],
            'mariadb' => ['mariadb', '`users`'],
            'pgsql' => ['pgsql', '"users"'],
            'postgres' => ['postgres', '"users"'],
            'postgresql' => ['postgresql', '"users"'],
            'sqlite' => ['sqlite', '"users"'],
            'sqlsrv' => ['sqlsrv', '[users]'],
            'sqlserver' => ['sqlserver', '[users]'],
            'dblib' => ['dblib', '[users]'],
            'sybase' => ['sybase', '[users]'],
            'oci' => ['oci', '"USERS"'],
            'oracle' => ['oracle', '"USERS"'],
        ];
    }

    /**
     * @dataProvider engineAliasProvider
     */
    public function test_identifier_uses_engine_rule_aliases(string $engine, string $expected): void
    {
        self::assertSame($expected, (new Identifier('users'))->quoted($engine));
    }

    public function test_identifier_quotes_each_segment_with_engine_rules(): void
    {
        self::assertSame('[dbo].[users]', (new Identifier('dbo.users'))->quoted('sqlserver'));
        self::assertSame('`app`.`users`', (new Identifier('app.users'))->quoted('mariadb'));
    }

    public function test_expr_alias_uses_same_engine_rules_as_identifier(): void
    {
        $expr = Expr::count('id')->as('employee_count');
        $params = new ParamBag('test');

        self::assertSame(
            'COUNT(id) AS `employee_count`',
            $expr->getSql($params, engine: 'mariadb')
        );
        self::assertSame(
            'COUNT(id) AS [employee_count]',
            $expr->getSql($params, engine: 'sqlserver')
        );
        self::assertSame(
            'COUNT(id) AS "employee_count"',
            $expr->getSql($params, engine: 'pgsql')
        );
    }
}
