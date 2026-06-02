<?php

declare(strict_types=1);

namespace Tests\SQLite;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\TraitBetaRepository;
use Tests\Fixtures\TraitUserRepository;
use UDA\Exception\QueryException;

final class ExternalTraitUsageTest extends TestCase
{
    public function test_external_class_imports_only_trait(): void
    {
        $source = file_get_contents(__DIR__ . '/../Fixtures/TraitUserRepository.php') ?: '';

        self::assertStringContainsString('use UDA\Link;', $source);
        self::assertStringContainsString('use Link;', $source);
        self::assertStringNotContainsString('use UDA\Database', $source);
        self::assertStringNotContainsString('use UDA\Driver', $source);
        self::assertStringNotContainsString('use PDO', $source);
        self::assertStringNotContainsString('use UDA\Cache', $source);
        self::assertStringNotContainsString('use UDA\Query\Dialect', $source);
        self::assertStringNotContainsString('->database()', $source);
    }

    public function test_trait_backed_class_can_read_write_and_transact(): void
    {
        $repo = new TraitUserRepository();
        $id = random_int(1000, 999999);

        $repo->create($id, 'Ada');
        $repo->rename($id, 'Grace');

        self::assertSame('Grace', $repo->findName($id));
        self::assertSame([$id, 'Grace'], $repo->findList($id));
        self::assertNull($repo->findList($id + 1));
    }

    public function test_trait_backed_connections_remain_isolated_by_name(): void
    {
        // Isolation is between classes, not instances.
        // TraitUserRepository is pinned to 'alpha'; TraitBetaRepository to 'beta'.
        // A row written through alpha must not be visible through beta.
        $alpha = new TraitUserRepository();
        $beta  = new TraitBetaRepository();
        $id    = random_int(1000, 999999);

        $alpha->create($id, 'Alpha');

        self::assertSame('Alpha', $alpha->findName($id));
        self::assertNull($beta->findName($id));
    }

    public function test_trait_path_still_rejects_positional_parameters_before_pdo(): void
    {
        $repo = new TraitUserRepository();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Positional parameters are forbidden');

        $repo->runUnsafeProbe();
    }
}
