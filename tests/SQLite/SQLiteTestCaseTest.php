<?php

declare(strict_types=1);

namespace Tests\SQLite;

use PHPUnit\Framework\Attributes\CoversClass;
use Throwable;
use UDA\Database;

#[CoversClass(SQLiteTestCase::class)]
final class SQLiteTestCaseTest extends SQLiteTestCase
{
    public function testWithMemoryDbSeedsSharedSchema(): void
    {
        $counts = $this->withMemoryDb(function (Database $db): array {
            return [
                'employees' => (int) $db->value('SELECT COUNT(*) FROM employees'),
                'contractors' => (int) $db->value('SELECT COUNT(*) FROM contractors'),
                'salaries' => (int) $db->value('SELECT COUNT(*) FROM salaries'),
            ];
        });

        self::assertSame(2, $counts['employees']);
        self::assertSame(1, $counts['contractors']);
        self::assertSame(3, $counts['salaries']);
    }

    public function testTempDatabaseUsesDistinctFileAndCleansItUp(): void
    {
        $memoryPath = $this->withMemoryDb(fn (Database $db): string => $this->mainDatabasePath($db));

        [$tempPath, $existsDuringTest] = $this->withTempDb(function (Database $db): array {
            $path = $this->mainDatabasePath($db);

            return [$path, file_exists($path)];
        });

        self::assertNotSame($tempPath, $memoryPath);
        self::assertTrue(
            in_array($memoryPath, ['', ':memory:'], true),
            'In-memory connections should not expose a backing file'
        );
        self::assertTrue($existsDuringTest, 'Temporary database file should exist while callback runs');
        self::assertFalse(file_exists($tempPath), 'Temporary database file must be deleted after callback');
    }

    public function testRetryStubDriverWrapsOperations(): void
    {
        $events = [];

        $this->withMemoryDb(function (Database $db) use (&$events): void {
            $stub = $this->retryStubDriver(
                $db,
                function (string $operation, int $attempt) use (&$events): void {
                    $events[] = ['before', $operation, $attempt];
                },
                function (string $operation, int $attempts, mixed $result) use (&$events): void {
                    $events[] = ['after', $operation, $attempts, $result instanceof Throwable];
                }
            );

            $db->row('SELECT name FROM employees WHERE id = :id', ['id' => 1]);

            try {
                $db->exec('INSERT INTO missing_table (id) VALUES (1)');
            } catch (Throwable $exception) {
                // expected: verify after hook records throwable
            }

            self::assertSame(2, $stub->attempts, 'Stub should count both operations');
        });

        self::assertSame(
            [
                ['before', 'row', 1],
                ['after', 'row', 1, false],
                ['before', 'exec', 2],
                ['after', 'exec', 2, true],
            ],
            $events
        );
    }

    private function mainDatabasePath(Database $db): string
    {
        $rows = $db->rows('PRAGMA database_list');

        foreach ($rows as $row) {
            if (($row['name'] ?? $row['NAME'] ?? null) === 'main') {
                return (string) ($row['file'] ?? $row['FILE'] ?? '');
            }
        }

        return '';
    }
}
