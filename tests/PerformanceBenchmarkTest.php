<?php

declare(strict_types=1);

/** @purpose Performance benchmarks - measure key operations */

namespace UniversalDataAbstraction\Tests;

use PHPUnit\Framework\TestCase;
use UDA\Config;
use UDA\Query\SelectQuery;
use UDA\Query\Sql;

final class PerformanceBenchmarkTest extends TestCase
{
    private array $performanceResults = [];
    
    /**
     * @testgroup benchmark
     * @test
     */
    public function testConfigLoadPerformance(): void
    {
        $config = [
            'connections' => [
                'default' => ['driver' => 'sqlite', 'dsn' => 'sqlite::memory:']
            ]
        ];
        
        $path = sys_get_temp_dir() . '/bench_config.json';
        file_put_contents($path, json_encode($config));
        
        $start = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            Config::clear();
            Config::load($path);
        }
        $duration = microtime(true) - $start;
        
        $opsPerSecond = 1000 / $duration;
        $this->performanceResults['config_load'] = $opsPerSecond;
        
        $this->assertGreaterThan(500, $opsPerSecond, 'Config load > 500 ops/sec');
        
        unlink($path);
    }
    
    /**
     * @testgroup benchmark
     * @test
     */
    public function testQueryToSqlPerformance(): void
    {
        $query = new SelectQuery();
        $query->from('users')->where('active', 1)->limit(100);
        
        $start = microtime(true);
        for ($i = 0; $i < 10000; $i++) {
            $sql = $query->toSql();
        }
        $duration = microtime(true) - $start;
        
        $opsPerSecond = 10000 / $duration;
        $this->performanceResults['query_toSql'] = $opsPerSecond;
        
        $this->assertGreaterThan(10000, $opsPerSecond, 'toSql() > 10k ops/sec');
        $this->assertInstanceOf(Sql::class, $sql);
    }
    
    /**
     * @testgroup benchmark
     * @test
     */
    public function testConfigSnapshotImmutability(): void
    {
        $snapshot = Config::snapshot();
        
        // Reading should be fast (no copies)
        $start = microtime(true);
        for ($i = 0; $i < 10000; $i++) {
            $names = $snapshot->getConnectionNames();
        }
        $duration = microtime(true) - $start;
        
        $opsPerSecond = 10000 / $duration;
        $this->performanceResults['snapshot_read'] = $opsPerSecond;
        
        $this->assertGreaterThan(50000, $opsPerSecond, 'Snapshot read > 50k ops/sec');
    }
    
    protected function tearDown(): void
    {
        if (!empty($this->performanceResults)) {
            echo "\n📊 Performance Results:\n";
            foreach ($this->performanceResults as $name => $ops) {
                printf("  %-20s: %.0f ops/sec\n", $name, $ops);
            }
        }
    }
}
