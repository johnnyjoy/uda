<?php

declare(strict_types=1);

namespace Tests\SQLite;

use Closure;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use UDA\Query\Abs;
use UDA\Query\Delete;
use UDA\Query\Insert;
use UDA\Query\Select;
use UDA\Query\Sql;
use UDA\Query\Update;
use UDA\Query\Upsert;
use UDA\Query\Dialect\SQLite as SQLiteDialect;

final class SQLiteDialectTest extends SQLiteTestCase
{
    private SQLiteDialect $dialect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dialect = new SQLiteDialect();
    }

    /**
     * @dataProvider dialectFixturesProvider
     */
    #[DataProvider('dialectFixturesProvider')]
    public function testDialectSnapshots(string $case, Closure $builder, string $expectedSql): void
    {
        $sql = $builder($this);

        self::assertInstanceOf(Sql::class, $sql, sprintf('Fixture "%s" did not return Sql instance', $case));
        self::assertSame($expectedSql, $sql->getQuery(), sprintf('Fixture "%s" SQL mismatch', $case));
    }

    /**
     * @return Generator<string,array{string,Closure(self):Sql,string}>
     */
    public static function dialectFixturesProvider(): Generator
    {
        $directory = __DIR__ . '/fixtures/dialect';
        $files = glob($directory . '/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $fixtureKey = basename($file, '.php');
            $expectedPath = $directory . '/' . $fixtureKey . '.json';

            if (!is_file($expectedPath)) {
                self::fail(sprintf('Missing expected SQL snapshot for %s', $fixtureKey));
            }

            /** @var array<string,array{builder:Closure}> $fixtures */
            $fixtures = require $file;
            ksort($fixtures);

            /** @var array<string,string> $snapshots */
            $snapshots = json_decode((string) file_get_contents($expectedPath), true, flags: JSON_THROW_ON_ERROR);

            foreach ($fixtures as $name => $fixture) {
                if (!array_key_exists($name, $snapshots)) {
                    self::fail(sprintf('Missing SQL snapshot for fixture %s::%s', $fixtureKey, $name));
                }

                $datasetKey = $fixtureKey . '::' . $name;

                yield $datasetKey => [
                    $datasetKey,
                    $fixture['builder'],
                    $snapshots[$name],
                ];
            }
        }
    }

    public function select(): Select
    {
        return $this->configureBuilder(new Select());
    }

    public function insert(): Insert
    {
        return $this->configureBuilder(new Insert());
    }

    public function update(): Update
    {
        return $this->configureBuilder(new Update());
    }

    public function delete(): Delete
    {
        return $this->configureBuilder(new Delete());
    }

    public function upsert(): Upsert
    {
        return $this->configureBuilder(new Upsert());
    }

    /**
     * @template T of Abs
     * @param T $builder
     * @return T
     */
    private function configureBuilder(Abs $builder)
    {
        $builder->driverName = 'sqlite';
        $builder->bindDialect($this->dialect);

        return $builder;
    }
}
