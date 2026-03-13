<?php

declare(strict_types=1);

namespace Tests\Safety;

use PHPUnit\Framework\TestCase;
use UDA\Config;
use UDA\Database;
use UDA\Exception\QuerySafetyException;
use UDA\Query\Sql as QuerySql;
use UDA\Safety\GuardrailConfig;
use UDA\Query\QueryPlanCache;

final class GuardrailTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::clearForTests();
        Database::clearTraceListeners();
        QueryPlanCache::clear();
    }

    public function testGuardrailsDisabledAllowsDangerousUpdate(): void
    {
        $db = $this->bootDatabase(['enabled' => false]);

        $db->update()
            ->table('guardrail_items')
            ->set('name', 'beta')
            ->exec();

        $row = $db->row('SELECT name FROM guardrail_items WHERE id = :id', ['id' => 1]);
        $this->assertSame('beta', $row['name'] ?? null);
    }

    public function testUpdateWithoutWhereRejected(): void
    {
        $db = $this->bootDatabase(['enabled' => true]);

        $this->assertGuardrailViolation(
            fn (): int => $db->update()->table('guardrail_items')->set('name', 'blocked')->exec(),
            'update_missing_where'
        );
    }

    public function testDeleteWithoutWhereRejected(): void
    {
        $db = $this->bootDatabase(['enabled' => true]);

        $this->assertGuardrailViolation(
            fn (): int => $db->delete()->table('guardrail_items')->exec(),
            'delete_missing_where'
        );
    }

    public function testUnsafeBypassesGuardrailsWhenNotProduction(): void
    {
        $db = $this->bootDatabase(['enabled' => true]);

        $db->update()
            ->table('guardrail_items')
            ->set('name', 'gamma')
            ->unsafe()
            ->exec();

        $row = $db->row('SELECT name FROM guardrail_items WHERE id = :id', ['id' => 1]);
        $this->assertSame('gamma', $row['name'] ?? null);
    }

    public function testUnsafeBlockedInProductionMode(): void
    {
        $db = $this->bootDatabase(['enabled' => true, 'productionMode' => true]);

        $this->assertGuardrailViolation(
            fn (): int => $db->update()->table('guardrail_items')->set('name', 'gamma')->unsafe()->exec(),
            'update_missing_where'
        );
    }

    public function testRequireLimitOnWritesEnforced(): void
    {
        $db = $this->bootDatabase([
            'enabled' => true,
            'requireLimitOnWrites' => true,
            'updateRequiresWhere' => false,
        ]);

        $sql = QuerySql::of(
            'UPDATE guardrail_items SET name = :name WHERE id = :id',
            ['name' => 'delta', 'id' => 1],
            ['guardrail_items']
        )->withGuardrailMetadata('update', true, false, false);

        $this->assertGuardrailViolation(
            fn (): int => $db->exec($sql),
            'update_missing_limit'
        );
    }

    public function testLimitRequirementSatisfiedWhenMetadataSignalsLimit(): void
    {
        $db = $this->bootDatabase([
            'enabled' => true,
            'requireLimitOnWrites' => true,
        ]);

        $sql = QuerySql::of(
            'UPDATE guardrail_items SET name = :name WHERE id = :id LIMIT 1',
            ['name' => 'epsilon', 'id' => 1],
            ['guardrail_items']
        )->withGuardrailMetadata('update', true, true, false);

        $db->exec($sql);

        $row = $db->row('SELECT name FROM guardrail_items WHERE id = :id', ['id' => 1]);
        $this->assertSame('epsilon', $row['name'] ?? null);
    }

    public function testLimitRequirementRespectsWhitelist(): void
    {
        $db = $this->bootDatabase([
            'enabled' => true,
            'requireLimitOnWrites' => true,
            'requireLimitOnWritesExcept' => ['guardrail_items'],
            'updateRequiresWhere' => false,
        ]);

        $db->update()
            ->table('guardrail_items')
            ->set('name', 'theta')
            ->exec();

        $row = $db->row('SELECT name FROM guardrail_items WHERE id = :id', ['id' => 1]);
        $this->assertSame('theta', $row['name'] ?? null);
    }

    public function testTruncateBlockedWhenConfigured(): void
    {
        $db = $this->bootDatabase([
            'enabled' => true,
            'truncateBlocked' => true,
        ]);

        $this->assertGuardrailViolation(
            fn (): int => $db->exec('TRUNCATE TABLE guardrail_items'),
            'truncate_blocked'
        );
    }

    private function bootDatabase(array $guardrailOverrides): Database
    {
        Config::clearForTests();
        $path = $this->writeConfig($guardrailOverrides);
        $db = Database::connect($path);
        @unlink($path);

        $db->exec('CREATE TABLE guardrail_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $db->exec('INSERT INTO guardrail_items (name) VALUES (:name)', ['name' => 'alpha']);

        return $db;
    }

    private function writeConfig(array $guardrailOverrides): string
    {
        $config = [
            'defaults' => ['connection' => 'guardrail'],
            'connections' => [
                'guardrail' => [
                    'driver' => 'sqlite',
                    'params' => ['path' => ':memory:'],
                    'guardrails' => array_merge(GuardrailConfig::defaults()->toArray(), $guardrailOverrides),
                ],
            ],
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'uda-guardrail-');
        $path = $tmp . '.json';
        rename($tmp, $path);
        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT));

        return $path;
    }

    private function assertGuardrailViolation(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Guardrail violation expected');
        } catch (QuerySafetyException $exception) {
            $this->assertSame($reason, $exception->getReason());
        }
    }
}
