<?php

declare(strict_types=1);

namespace Tests\Replay;

use PHPUnit\Framework\TestCase;
use UDA\Config;
use UDA\Database;
use UDA\Replay\QueryReplayer;
use UDA\Tracing\ReplaySnapshot;

final class QueryReplayerTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::clearForTests();
    }

    public function testRunSnapshotRows(): void
    {
        $db = $this->bootDatabase();
        $replayer = new QueryReplayer($db);
        $snapshot = $this->makeSnapshot('rows', 'SELECT name FROM replay_items ORDER BY id');

        $result = $replayer->runSnapshot($snapshot);

        $this->assertSame('alpha', $result[0]['name']);
    }

    public function testRunSnapshotExec(): void
    {
        $db = $this->bootDatabase();
        $replayer = new QueryReplayer($db);
        $snapshot = $this->makeSnapshot('exec', 'UPDATE replay_items SET name = :name WHERE id = :id', ['name' => 'beta', 'id' => 1]);

        $affected = $replayer->runSnapshot($snapshot);
        $this->assertSame(1, $affected);

        $row = $db->row('SELECT name FROM replay_items WHERE id = :id', ['id' => 1]);
        $this->assertSame('beta', $row['name']);
    }

    public function testRunFileReplaysSnapshots(): void
    {
        $db = $this->bootDatabase();
        $replayer = new QueryReplayer($db);
        $file = $this->writeSnapshotFile([
            $this->makeSnapshot('exec', 'INSERT INTO replay_items (name) VALUES (:name)', ['name' => 'gamma'])->toArray(),
            $this->makeSnapshot('rows', 'SELECT COUNT(*) AS total FROM replay_items')->toArray(),
        ]);

        $results = $replayer->runFile($file);
        $this->assertCount(2, $results);
        $this->assertSame('gamma', $db->row('SELECT name FROM replay_items WHERE id = :id', ['id' => 3])['name']);
        $this->assertSame(3, (int) ($results[1][0]['total'] ?? 0));
    }

    public function testExplainReturnsPlan(): void
    {
        $db = $this->bootDatabase();
        $replayer = new QueryReplayer($db);
        $snapshot = $this->makeSnapshot('rows', 'SELECT * FROM replay_items WHERE id = :id', ['id' => 1]);

        $plan = $replayer->explain($snapshot);
        $this->assertNotEmpty($plan);
    }

    public function testBenchmarkCollectsDurations(): void
    {
        $db = $this->bootDatabase();
        $replayer = new QueryReplayer($db);
        $snapshot = $this->makeSnapshot('rows', 'SELECT name FROM replay_items');

        $result = $replayer->benchmark($snapshot, 2);
        $this->assertCount(2, $result['durations']);
        $this->assertSame('alpha', $result['result'][0]['name']);
    }

    private function bootDatabase(): Database
    {
        $config = [
            'defaults' => ['connection' => 'replay'],
            'connections' => [
                'replay' => [
                    'driver' => 'sqlite',
                    'params' => ['path' => ':memory:'],
                    'guardrails' => ['enabled' => false],
                ],
            ],
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'uda-replay-config-');
        $file = $tmp . '.json';
        rename($tmp, $file);
        file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT));

        $db = Database::connect($file);
        @unlink($file);

        $db->exec('CREATE TABLE replay_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $db->exec('INSERT INTO replay_items (name) VALUES (:name)', ['name' => 'alpha']);
        $db->exec('INSERT INTO replay_items (name) VALUES (:name)', ['name' => 'delta']);

        return $db;
    }

    private function makeSnapshot(string $operation, string $sql, array $params = []): ReplaySnapshot
    {
        return new ReplaySnapshot(
            connection: 'replay',
            dialect: 'sqlite',
            operation: $operation,
            sql: $sql,
            params: $params,
            tables: ['replay_items'],
            durationMs: 0.0,
            rowCount: 0,
            timestamp: time()
        );
    }

    private function writeSnapshotFile(array $entries): string
    {
        $file = tempnam(sys_get_temp_dir(), 'uda-replay-file-');
        $lines = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $entries);
        file_put_contents($file, implode("\n", $lines));

        return $file;
    }
}
