<?php

declare(strict_types=1);

namespace Tests\SQLite;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ExternalUserRepository;
use UDA\Database;
use UDA\Exception\QueryException;

final class ExternalDatabaseUsageTest extends TestCase
{
    public function test_external_class_imports_only_database(): void
    {
        $source = file_get_contents(__DIR__ . '/../Fixtures/ExternalUserRepository.php') ?: '';

        self::assertStringContainsString('use UDA\Database;', $source);
        self::assertStringNotContainsString('use UDA\Driver', $source);
        self::assertStringNotContainsString('use PDO', $source);
        self::assertStringNotContainsString('use UDA\Cache', $source);
        self::assertStringNotContainsString('use UDA\Query\Dialect', $source);
    }

    public function test_external_class_can_read_write_and_transact_through_database(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);
        $repo = new ExternalUserRepository($db);

        $id = random_int(1000, 999999);
        $repo->create($id, 'Ada');
        $repo->rename($id, 'Grace');

        self::assertSame('Grace', $repo->findName($id));
    }

    public function test_same_backend_named_connections_are_isolated(): void
    {
        $alpha = Database::connect('alpha', UDA_TEST_CONFIG);
        $beta = Database::connect('beta', UDA_TEST_CONFIG);
        $id = random_int(1000, 999999);

        $alpha->exec(
            'INSERT INTO users (id, name) VALUES (:id, :name)',
            ['id' => $id, 'name' => 'Alpha'],
            ['users']
        );

        self::assertSame('Alpha', $alpha->value('SELECT name FROM users WHERE id = :id', ['id' => $id], ['users']));
        self::assertNull($beta->value('SELECT name FROM users WHERE id = :id', ['id' => $id], ['users']));
    }

    public function test_positional_parameters_are_rejected_before_pdo(): void
    {
        $db = Database::connect('alpha', UDA_TEST_CONFIG);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Positional parameters are forbidden');

        $db->rows('SELECT ? AS id', [1]);
    }
}
