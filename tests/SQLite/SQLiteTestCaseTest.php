<?php

declare(strict_types=1);

namespace Tests\SQLite;

use PHPUnit\Framework\Attributes\CoversClass;
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
