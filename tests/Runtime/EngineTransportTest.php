<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use UDA\Config\Snapshot;
use UDA\Driver;
use UDA\Query\Dialect\SqlServer as SqlServerDialect;
use UDA\Query\Dialect\Sybase as SybaseDialect;

final class EngineTransportTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:?string,2:string,3:string}>
     */
    public static function resolveProvider(): array
    {
        return [
            'legacy dblib' => ['dblib', null, 'sybase', 'dblib'],
            'legacy sqlsrv' => ['sqlsrv', null, 'sqlserver', 'sqlsrv'],
            'sqlserver explicit dblib' => ['sqlserver', 'dblib', 'sqlserver', 'dblib'],
            'sybase explicit dblib' => ['sybase', 'dblib', 'sybase', 'dblib'],
            'pgsql default transport' => ['pgsql', null, 'pgsql', 'pgsql'],
            'mariadb default transport' => ['mariadb', null, 'mariadb', 'mysql'],
        ];
    }

    /**
     * @dataProvider resolveProvider
     */
    public function test_resolve_engine_transport(
        string $driver,
        ?string $transport,
        string $expectedEngine,
        string $expectedTransport
    ): void {
        [$engine, $resolvedTransport] = Driver::resolveEngineTransport($driver, $transport);

        self::assertSame($expectedEngine, $engine);
        self::assertSame($expectedTransport, $resolvedTransport);
    }

    public function test_snapshot_normalizes_engine_and_transport(): void
    {
        $snap = new Snapshot([
            'mssql_dblib' => [
                'driver' => 'sqlserver',
                'transport' => 'dblib',
                'params' => ['host' => 'mssql.internal', 'dbname' => 'app'],
            ],
            'ase' => [
                'driver' => 'dblib',
                'params' => ['host' => 'ase.internal', 'dbname' => 'app'],
            ],
        ]);

        $mssql = $snap->getConnection('mssql_dblib');
        self::assertNotNull($mssql);
        self::assertSame('sqlserver', $mssql['engine']);
        self::assertSame('dblib', $mssql['transport']);
        self::assertSame('sqlserver', $mssql['driver']);

        $ase = $snap->getConnection('ase');
        self::assertNotNull($ase);
        self::assertSame('sybase', $ase['engine']);
        self::assertSame('dblib', $ase['transport']);
    }

    public function test_sqlserver_over_dblib_uses_dblib_dsn_and_sqlserver_dialect_engine(): void
    {
        $params = ['host' => 'mssql.internal', 'dbname' => 'app'];
        $dsn = \UDA\Driver\Dblib::dsn($params);

        self::assertStringStartsWith('dblib:', $dsn);

        [$engine] = Driver::resolveEngineTransport('sqlserver', 'dblib');
        self::assertSame('sqlserver', $engine);

        $dialect = match ($engine) {
            'sqlserver' => new SqlServerDialect(),
            'sybase' => new SybaseDialect(),
            default => null,
        };

        self::assertInstanceOf(SqlServerDialect::class, $dialect);
    }

    public function test_sybase_over_dblib_uses_dblib_dsn_and_sybase_dialect_engine(): void
    {
        $params = ['host' => 'ase.internal', 'dbname' => 'app'];
        $dsn = \UDA\Driver\Dblib::dsn($params);

        self::assertStringStartsWith('dblib:', $dsn);

        [$engine] = Driver::resolveEngineTransport('sybase', 'dblib');
        self::assertSame('sybase', $engine);

        $dialect = match ($engine) {
            'sqlserver' => new SqlServerDialect(),
            'sybase' => new SybaseDialect(),
            default => null,
        };

        self::assertInstanceOf(SybaseDialect::class, $dialect);
    }

    public function test_sqlserver_default_transport_is_sqlsrv_dsn(): void
    {
        $params = ['host' => 'mssql.internal', 'dbname' => 'app'];
        $dsn = \UDA\Driver\SQLServer::dsn($params);

        self::assertStringStartsWith('sqlsrv:', $dsn);
        self::assertSame(['sqlserver', 'sqlsrv'], Driver::resolveEngineTransport('sqlsrv', null));
    }
}
