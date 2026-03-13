<?php

declare(strict_types=1);

namespace Tests\Tracing;

use PHPUnit\Framework\TestCase;
use UDA\Config;
use UDA\Database;
use UDA\Exception\QuerySafetyException;
use UDA\Query\QueryPlanCache;
use UDA\Tracing\QueryTraceCollector;

final class GuardrailTraceTest extends TestCase
{
    protected function setUp(): void
    {
        Config::clearForTests();
        Database::clearTraceListeners();
        QueryPlanCache::clear();
    }

    protected function tearDown(): void
    {
        Config::clearForTests();
        Database::clearTraceListeners();
        QueryPlanCache::clear();

        parent::tearDown();
    }

    public function testGuardrailViolationEmitsTrace(): void
    {
        $collector = new QueryTraceCollector();
        Database::addTraceListener($collector);

        $db = $this->bootDatabase(['enabled' => true]);

        try {
            $db->update()->table('guardrail_items')->set('name', 'blocked')->exec();
            $this->fail('Guardrail violation expected');
        } catch (QuerySafetyException $exception) {
            $this->assertSame('update_missing_where', $exception->getReason());
        }

        $traces = $collector->getTraces();
        $this->assertNotEmpty($traces, 'Guardrail violation should emit a trace');
        $trace = end($traces);

        $this->assertSame('guardrail_violation', $trace->traceType);
        $this->assertSame('guardrail_violation', $trace->operation);
        $this->assertSame('update_missing_where', $trace->meta['reason'] ?? null);
        $this->assertSame('update', $trace->meta['statementType'] ?? null);
        $this->assertSame('exec', $trace->meta['operation'] ?? null);
        $this->assertSame(['guardrail_items'], $trace->tables);
    }

    public function testUnsafeWriteSkipsGuardrailTraces(): void
    {
        $collector = new QueryTraceCollector();
        Database::addTraceListener($collector);

        $db = $this->bootDatabase(['enabled' => true]);

        $update = $db->update()
            ->table('guardrail_items')
            ->set('name', 'delta')
            ->unsafe();

        $this->assertTrue($update->toSql()->isUnsafe());

        $rows = $update->exec();

        $this->assertSame(1, $rows);

        $row = $db->row('SELECT name FROM guardrail_items WHERE id = :id', ['id' => 1]);
        $this->assertSame('delta', $row['name'] ?? null);

        $guardrailTraces = array_filter(
            $collector->getTraces(),
            static fn ($trace): bool => $trace->traceType === 'guardrail_violation'
        );

        $this->assertSame([], $guardrailTraces, 'Unsafe writes should bypass guardrail violation tracing');
    }

    private function bootDatabase(array $guardrailOverrides): Database
    {
        $config = [
            'defaults' => ['connection' => 'guardrail_trace'],
            'connections' => [
                'guardrail_trace' => [
                    'driver' => 'sqlite',
                    'params' => ['path' => ':memory:'],
                    'guardrails' => $guardrailOverrides,
                ],
            ],
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'uda-guardrail-trace-');
        $path = $tmp . '.json';
        rename($tmp, $path);
        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT));

        $db = Database::connect($path);
        @unlink($path);

        $db->exec('CREATE TABLE guardrail_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $db->exec('INSERT INTO guardrail_items (name) VALUES (:name)', ['name' => 'alpha']);

        return $db;
    }
}
