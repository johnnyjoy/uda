<?php

declare(strict_types=1);

namespace Tests\Replay;

use PHPUnit\Framework\TestCase;
use UDA\Tracing\ReplaySnapshot;
use UDA\Tracing\Storage\FileReplayStorage;

final class FileReplayStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/uda-replay-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $files = glob($this->dir . '/*');

            if ($files !== false) {
                array_map('unlink', array_filter($files, 'is_file'));
            }

            @rmdir($this->dir);
        }
    }

    public function testPersistsSnapshotToNdjson(): void
    {
        $time = strtotime('2026-03-10T12:00:00Z');
        $storage = new FileReplayStorage($this->dir, enabled: true, clock: static fn () => $time);

        $snapshot = $this->makeSnapshot();
        $storage->persist($snapshot);
        $storage->close();

        $path = $this->dir . '/queries-2026-03-10.ndjson';
        $this->assertFileExists($path);
        $contents = trim((string) file_get_contents($path));
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($snapshot->sql, $decoded['sql']);
        $this->assertSame($snapshot->params, $decoded['params']);
        $this->assertSame($snapshot->tables, $decoded['tables']);
        $this->assertFalse($decoded['sqlTruncated']);
        $this->assertFalse($decoded['parametersTruncated']);
    }

    public function testTruncationFlagsSetWhenLimitsExceeded(): void
    {
        $storage = new FileReplayStorage($this->dir, maxSqlLength: 5, maxParamSize: 10, enabled: true);
        $snapshot = new ReplaySnapshot(
            connection: 'default',
            dialect: 'pg',
            operation: 'select',
            sql: 'SELECT * FROM example',
            params: ['name' => 'abcdefghijklmnopqrstuvwxyz'],
            tables: ['example'],
            durationMs: 1.0,
            rowCount: 0,
            timestamp: time()
        );

        $storage->persist($snapshot);
        $storage->close();

        $path = $this->dir . '/queries-' . gmdate('Y-m-d') . '.ndjson';
        $this->assertFileExists($path);
        $decoded = json_decode(trim((string) file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('SELEC', $decoded['sql']);
        $this->assertSame(['__truncated__' => true], $decoded['params']);
        $this->assertTrue($decoded['sqlTruncated']);
        $this->assertTrue($decoded['parametersTruncated']);
    }

    public function testRotationCreatesNewFilePerDay(): void
    {
        $time = strtotime('2026-03-10T12:00:00Z');
        $storage = new FileReplayStorage(
            $this->dir,
            enabled: true,
            clock: static function () use (&$time): int {
                return $time;
            }
        );

        $storage->persist($this->makeSnapshot());
        $time = strtotime('2026-03-11T01:00:00Z');
        $storage->persist($this->makeSnapshot());
        $storage->close();

        $this->assertFileExists($this->dir . '/queries-2026-03-10.ndjson');
        $this->assertFileExists($this->dir . '/queries-2026-03-11.ndjson');
    }

    public function testDisabledStorageDoesNothing(): void
    {
        $storage = new FileReplayStorage($this->dir, enabled: false);
        $storage->persist($this->makeSnapshot());
        $storage->close();

        $this->assertDirectoryDoesNotExist($this->dir);
    }

    private function makeSnapshot(): ReplaySnapshot
    {
        return new ReplaySnapshot(
            connection: 'default',
            dialect: 'pg',
            operation: 'select',
            sql: 'SELECT 1',
            params: ['id' => 1],
            tables: ['example'],
            durationMs: 2.0,
            rowCount: 1,
            timestamp: strtotime('2026-03-10T12:00:00Z')
        );
    }
}
