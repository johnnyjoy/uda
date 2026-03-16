<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Config;
use UDA\Database;
use UDA\Query\QueryPlanCache;
use UDA\Query\WhereBuilder;
use UDA\SQL\SqlMessage;

require_once __DIR__ . '/CountingDialect.php';

final class QueryPlanCacheTest extends TestCase
{
    private Database $db;
    private string $configPath;

    protected function setUp(): void
    {
        QueryPlanCache::enable();
        QueryPlanCache::setLimit(1000);
        QueryPlanCache::clear();
        Config::clearForTests();
        $this->configPath = $this->writeConfig();
        $this->db = Database::connect($this->configPath);
        $this->db->exec('CREATE TABLE plan_cache_records (id INTEGER PRIMARY KEY, label TEXT)');

        foreach (range(1, 5) as $id) {
            $this->db->exec(
                'INSERT INTO plan_cache_records (id, label) VALUES (:id, :label)',
                ['id' => $id, 'label' => 'row-' . $id]
            );
        }
    }

    protected function tearDown(): void
    {
        QueryPlanCache::setLimit(1000);
        QueryPlanCache::clear();
        @unlink($this->configPath);
    }

    public function testCacheHitSkipsSecondCompilation(): void
    {
        $builder1 = $this->selectById(1);
        $dialect1 = CountingDialect::sqlite();
        $builder1->bindDialect($dialect1);
        $builder1->value();
        $this->assertSame(1, $dialect1->selectCompileCount());

        $builder2 = $this->selectById(1);
        $dialect2 = CountingDialect::sqlite();
        $builder2->bindDialect($dialect2);
        $builder2->value();
        $this->assertSame(0, $dialect2->selectCompileCount());
    }

    public function testParameterVarianceReusesPlan(): void
    {
        foreach ([1, 2, 3] as $index => $id) {
            $builder = $this->selectById($id);
            $dialect = CountingDialect::sqlite();
            $builder->bindDialect($dialect);
            $builder->value();

            $this->assertSame($index === 0 ? 1 : 0, $dialect->selectCompileCount());
        }
    }

    public function testDialectSeparationMaintainsIsolation(): void
    {
        QueryPlanCache::clear();

        $sqliteDialect = CountingDialect::sqlite();
        $this->db->overrideDialectForPlanCache($sqliteDialect);
        $builder = $this->selectById(1);
        $builder->bindDialect($sqliteDialect);
        $builder->value();
        $this->assertSame(1, $sqliteDialect->selectCompileCount());

        $postgresDialect = CountingDialect::postgres();
        $this->db->overrideDialectForPlanCache($postgresDialect);
        $builderPg = $this->selectById(1);
        $builderPg->bindDialect($postgresDialect);
        $builderPg->value();
        $this->assertSame(1, $postgresDialect->selectCompileCount());

        $sqliteDialect2 = CountingDialect::sqlite();
        $this->db->overrideDialectForPlanCache($sqliteDialect2);
        $builderAgain = $this->selectById(1);
        $builderAgain->bindDialect($sqliteDialect2);
        $builderAgain->value();
        $this->assertSame(0, $sqliteDialect2->selectCompileCount());
    }

    public function testCacheEvictionFifo(): void
    {
        QueryPlanCache::setLimit(2);
        QueryPlanCache::clear();

        $this->executeStructuredSelect('id', false, CountingDialect::sqlite());
        $this->executeStructuredSelect('label', false, CountingDialect::sqlite());
        $this->executeStructuredSelect('id', true, CountingDialect::sqlite());

        $compileShouldHit = CountingDialect::sqlite();
        $this->executeStructuredSelect('label', false, $compileShouldHit);
        $this->assertSame(0, $compileShouldHit->selectCompileCount());

        $compileAfterEvict = CountingDialect::sqlite();
        $this->executeStructuredSelect('id', false, $compileAfterEvict);
        $this->assertSame(1, $compileAfterEvict->selectCompileCount());
    }

    public function testPlanCloningReturnsDistinctInstances(): void
    {
        QueryPlanCache::clear();
        $message = new SqlMessage('SELECT 1', ['p1' => 1], ['plan_cache_records']);
        QueryPlanCache::put('unit-test-key', $message);

        $first = QueryPlanCache::get('unit-test-key');
        $second = QueryPlanCache::get('unit-test-key');

        $this->assertNotSame($first, $second);
        $this->assertNotSame(spl_object_id($first), spl_object_id($second));
    }

    private function selectById(int $id)
    {
        $builder = $this->db->select()
            ->select('id')
            ->from('plan_cache_records');

        /** @var WhereBuilder $where */
        $where = $builder->where('id', $id);

        return $where->end();
    }

    private function executeStructuredSelect(string $column, bool $orderByLabel, CountingDialect $dialect): void
    {
        $builder = $this->db->select()
            ->select($column)
            ->from('plan_cache_records');

        /** @var WhereBuilder $where */
        $where = $builder->where('id', 1);
        $builder = $where->end();

        if ($orderByLabel) {
            $builder = $builder->orderBy('label');
        }

        $builder->bindDialect($dialect);
        $builder->value();
    }

    private function writeConfig(): string
    {
        $config = [
            'defaults' => ['connection' => 'plan_cache'],
            'connections' => [
                'plan_cache' => [
                    'driver' => 'sqlite',
                    'params' => ['path' => ':memory:'],
                ],
            ],
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'uda-config-');
        $path = $tmp . '.json';
        rename($tmp, $path);
        file_put_contents($path, (string) json_encode($config));

        return $path;
    }
}
