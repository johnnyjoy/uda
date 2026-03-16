<?php

declare(strict_types=1);

namespace Tests\SQLite;

use PHPUnit\Framework\Attributes\Depends;
use UDA\Database;
use UDA\Query\WhereBuilder;

final class SQLitePerformanceTest extends SQLiteTestCase
{
    private const RESULT_DIR = 'build/sqlite-cert';
    private static array $benchmarks = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!is_dir(self::RESULT_DIR)) {
            mkdir(self::RESULT_DIR, 0777, true);
        }
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (self::$benchmarks === []) {
            return;
        }

        $path = self::RESULT_DIR . '/benchmarks.json';
        file_put_contents($path, json_encode(self::$benchmarks, JSON_PRETTY_PRINT));
    }

    public function testBuilderCompilationThroughput(): void
    {
        $iterations = 10000;
        $result = $this->withMemoryDb(function (Database $db) use ($iterations): array {
            $start = hrtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $builder = $db->select('employees.id')
                    ->from('employees');
                /** @var WhereBuilder $where */
                $where = $builder->where('id', ($i % 2) + 1);
                $builder = $where->end()
                    ->orderBy('employees.id')
                    ->limit(1);
                $builder->toSql();
            }
            $duration = (hrtime(true) - $start) / 1_000_000_000;
            return ['duration' => $duration, 'iterations' => $iterations];
        });

        $opsPerSecond = $result['iterations'] / max($result['duration'], 0.0001);
        self::$benchmarks['builder_compilation'] = [
            'iterations' => $iterations,
            'seconds' => $result['duration'],
            'ops_per_second' => $opsPerSecond,
        ];

        self::assertGreaterThan(1000, $opsPerSecond, 'Builder compilation throughput too low.');
    }

    #[Depends('testBuilderCompilationThroughput')]
    public function testSelectExecutionThroughput(): void
    {
        $iterations = 2000;
        $result = $this->withMemoryDb(function (Database $db) use ($iterations): array {
            $start = hrtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $db->row('SELECT id FROM employees WHERE id = :id', ['id' => ($i % 2) + 1]);
            }
            $duration = (hrtime(true) - $start) / 1_000_000_000;
            return ['duration' => $duration, 'iterations' => $iterations];
        });

        $opsPerSecond = $result['iterations'] / max($result['duration'], 0.0001);
        self::$benchmarks['select_execution'] = [
            'iterations' => $iterations,
            'seconds' => $result['duration'],
            'ops_per_second' => $opsPerSecond,
        ];

        self::assertGreaterThan(500, $opsPerSecond, 'Select execution throughput too low.');
    }

    #[Depends('testSelectExecutionThroughput')]
    public function testExplainPlanGenerationCost(): void
    {
        $iterations = 100;
        $result = $this->withMemoryDb(function (Database $db) use ($iterations): array {
            $builder = $db->select('employees.id')
                ->from('employees')
                ->orderBy('employees.id');
            $sql = $builder->toSql();
            $start = hrtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $db->explain($sql);
            }
            $duration = (hrtime(true) - $start) / 1_000_000_000;
            return ['duration' => $duration, 'iterations' => $iterations];
        });

        $opsPerSecond = $result['iterations'] / max($result['duration'], 0.0001);
        self::$benchmarks['plan_generation'] = [
            'iterations' => $iterations,
            'seconds' => $result['duration'],
            'ops_per_second' => $opsPerSecond,
        ];

        self::assertGreaterThan(50, $opsPerSecond, 'Plan generation throughput too low.');
    }
}
