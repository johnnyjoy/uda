<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use UDA\Config;
use UDA\Database;
use UDA\Driver;
use UDA\Driver\PreparedStatementCache;
use UDA\Query\Dialect\SQLite as SQLiteDialect;
use UDA\Query\QueryPlanCache;
use UDA\Query\WhereBuilder;

require_once __DIR__ . '/CountingDialect.php';

final class PreparedStatementReuseTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = $this->bootstrapDatabase();
        $this->clearStatementCache($this->db);
    }

    public function testStatementReusedForIdenticalSelect(): void
    {
        $this->clearStatementCache($this->db);

        $this->selectLabel(1);
        $firstId = $this->firstStatementObjectId($this->db);

        $this->selectLabel(1);
        $secondId = $this->firstStatementObjectId($this->db);

        $this->assertNotNull($firstId);
        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, $this->statementCacheSize($this->db));
    }

    public function testParameterVariationReusesStatement(): void
    {
        $this->clearStatementCache($this->db);

        foreach ([1, 2, 3] as $id) {
            $this->selectLabel($id);
        }

        $this->assertSame(1, $this->statementCacheSize($this->db));
    }

    public function testDialectNamesIsolatePreparedStatements(): void
    {
        $this->clearStatementCache($this->db);

        $this->setDriverDialectName('SQLite-A');
        $this->selectLabel(1);
        $this->assertSame(1, $this->statementCacheSize($this->db));

        $this->setDriverDialectName('SQLite-B');
        $this->selectLabel(1);
        $this->assertSame(2, $this->statementCacheSize($this->db));
    }

    public function testSeparateConnectionsMaintainSeparateCaches(): void
    {
        $this->clearStatementCache($this->db);

        $other = $this->bootstrapDatabase();
        $this->clearStatementCache($other);

        $this->selectLabel(1);
        $this->assertSame(1, $this->statementCacheSize($this->db));

        $this->selectLabel(1, $other);
        $this->assertSame(1, $this->statementCacheSize($other));
        $this->assertSame(1, $this->statementCacheSize($this->db));
    }

    public function testCacheEvictsOldestStatement(): void
    {
        $db = $this->bootstrapDatabase(statementCacheLimit: 2);
        $this->clearStatementCache($db);

        $this->selectColumn($db, 'id');
        $firstStmt = $this->firstStatementObjectId($db);

        $this->selectColumn($db, 'label');
        $this->selectWhereLabel($db, 'row-1');

        $this->assertSame(2, $this->statementCacheSize($db));
        $this->assertNotContains($firstStmt, $this->statementObjectIds($db));
    }

    public function testReturningStatementsReusePreparedStatement(): void
    {
        $this->clearStatementCache($this->db);
        $this->insertReturning('alpha');
        $firstId = $this->firstStatementObjectId($this->db);

        $this->insertReturning('beta');
        $secondId = $this->firstStatementObjectId($this->db);

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, $this->statementCacheSize($this->db));
    }

    public function testEachClosesCursorForReuse(): void
    {
        $this->clearStatementCache($this->db);

        $this->db->select()
            ->select('label')
            ->from('ps_reuse')
            ->each(fn () => null);

        $firstId = $this->firstStatementObjectId($this->db);

        $this->db->select()
            ->select('label')
            ->from('ps_reuse')
            ->each(fn () => null);

        $this->assertSame($firstId, $this->firstStatementObjectId($this->db));
    }

    private function selectLabel(int $id, ?Database $db = null): mixed
    {
        $db ??= $this->db;

        $builder = $db->select()
            ->select('label')
            ->from('ps_reuse');

        /** @var WhereBuilder $where */
        $where = $builder->where('id', $id);

        return $where->end()->value();
    }

    private function selectColumn(Database $db, string $column): mixed
    {
        return $db->select()
            ->select($column)
            ->from('ps_reuse')
            ->limit(1)
            ->value();
    }

    private function selectWhereLabel(Database $db, string $label): mixed
    {
        $builder = $db->select()
            ->select('id')
            ->from('ps_reuse');

        /** @var WhereBuilder $where */
        $where = $builder->where('label', $label);

        return $where->end()->value();
    }

    private function insertReturning(string $label): mixed
    {
        return $this->db->insert()
            ->into('ps_return')
            ->set('label', $label)
            ->returning('id')
            ->value();
    }

    private function bootstrapDatabase(?int $statementCacheLimit = null): Database
    {
        QueryPlanCache::enable();
        QueryPlanCache::clear();
        Config::clearForTests();
        $path = $this->writeConfig($statementCacheLimit);
        $db = Database::connect($path);
        @unlink($path);

        $db->exec('CREATE TABLE ps_reuse (id INTEGER PRIMARY KEY, label TEXT)');
        foreach (range(1, 5) as $id) {
            $db->exec(
                'INSERT INTO ps_reuse (id, label) VALUES (:id, :label)',
                ['id' => $id, 'label' => 'row-' . $id]
            );
        }

        $db->exec('CREATE TABLE ps_return (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');

        return $db;
    }

    private function writeConfig(?int $statementCacheLimit = null): string
    {
        $config = [
            'defaults' => ['connection' => 'ps_cache'],
            'connections' => [
                'ps_cache' => [
                    'driver' => 'sqlite',
                    'params' => ['path' => ':memory:'],
                ],
            ],
        ];

        if ($statementCacheLimit !== null) {
            $config['connections']['ps_cache']['statement_cache_limit'] = $statementCacheLimit;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'uda-ps-cache-');
        $path = $tmp . '.json';
        rename($tmp, $path);
        file_put_contents($path, (string) json_encode($config));

        return $path;
    }

    private function getDriver(Database $db): Driver
    {
        $ref = new ReflectionProperty(Database::class, 'driver');
        $ref->setAccessible(true);

        /** @var Driver $driver */
        $driver = $ref->getValue($db);

        return $driver;
    }

    private function getStatementCache(Database $db): PreparedStatementCache
    {
        $driver = $this->getDriver($db);
        $ref = new ReflectionProperty(Driver::class, 'statementCache');
        $ref->setAccessible(true);

        /** @var PreparedStatementCache $cache */
        $cache = $ref->getValue($driver);

        return $cache;
    }

    private function clearStatementCache(Database $db): void
    {
        $this->getStatementCache($db)->clear();
    }

    private function statementCacheSize(Database $db): int
    {
        return $this->getStatementCache($db)->size();
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedStatements(Database $db): array
    {
        $cache = $this->getStatementCache($db);
        $ref = new ReflectionProperty(PreparedStatementCache::class, 'statements');
        $ref->setAccessible(true);

        /** @var array<string, mixed> $statements */
        $statements = $ref->getValue($cache);

        return $statements;
    }

    private function statementObjectIds(Database $db): array
    {
        return array_map('spl_object_id', $this->cachedStatements($db));
    }

    private function firstStatementObjectId(Database $db): ?int
    {
        $statements = $this->cachedStatements($db);
        if ($statements === []) {
            return null;
        }

        $first = reset($statements);

        return $first instanceof \PDOStatement ? spl_object_id($first) : null;
    }

    private function setDriverDialectName(string $name): void
    {
        $driver = $this->getDriver($this->db);
        $ref = new ReflectionProperty(Driver::class, 'dialectInstance');
        $ref->setAccessible(true);
        $ref->setValue($driver, new CountingDialect(new SQLiteDialect(), $name));
    }
}
