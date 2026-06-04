<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Driver;
use UDA\Query\Delete;
use UDA\Query\Insert;
use UDA\Query\Select;
use UDA\Query\Update;
use UDA\Query\Upsert;

final class ArchitectureInvariantTest extends TestCase
{
    public function test_pdo_usage_is_restricted_to_driver_domain(): void
    {
        $srcDir = realpath(__DIR__ . '/../../src');
        self::assertIsString($srcDir);

        $violations = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $relativePath = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));

            if ($relativePath === 'UDA/Driver.php' || str_starts_with($relativePath, 'UDA/Driver/')) {
                continue;
            }

            $source = file_get_contents($path) ?: '';
            if (preg_match('/\\?PDO(Statement)?\\?|new PDO\(|->prepare\(|->execute\(/', $source) === 1) {
                $violations[] = $relativePath;
            }
        }

        self::assertSame([], $violations);
    }

    public function test_query_plan_cache_scaffolding_is_not_in_v1_runtime(): void
    {
        self::assertFileDoesNotExist(__DIR__ . '/../../src/UDA/Query/QueryPlanCache.php');
    }

    public function test_plan_and_explain_are_not_public_runtime_api(): void
    {
        foreach ([Database::class, Driver::class, Select::class, Insert::class, Update::class, Delete::class, Upsert::class] as $class) {
            self::assertFalse(method_exists($class, 'plan'), $class . ' must not expose plan()');
            self::assertFalse(method_exists($class, 'explain'), $class . ' must not expose explain()');
            self::assertFalse(method_exists($class, 'explainAnalyze'), $class . ' must not expose explainAnalyze()');
        }
    }

    public function test_each_does_not_materialize_through_rows(): void
    {
        $sources = [
            __DIR__ . '/../../src/UDA/Database.php',
            __DIR__ . '/../../src/UDA/Driver.php',
        ];

        foreach ($sources as $path) {
            $source = file_get_contents($path) ?: '';
            self::assertDoesNotMatchRegularExpression('/function\s+each\b[\s\S]*?\$this->rows\(/', $source, $path);
        }
    }

    public function test_singular_terminators_do_not_route_through_set_terminators(): void
    {
        $sources = [
            __DIR__ . '/../../src/UDA/Database.php',
            __DIR__ . '/../../src/UDA/Driver.php',
        ];

        foreach ($sources as $path) {
            $source = file_get_contents($path) ?: '';
            $row = self::methodBody($source, 'row');
            $list = self::methodBody($source, 'list');

            self::assertStringNotContainsString('$this->rows(', $row, $path);
            self::assertStringNotContainsString('$this->values(', $list, $path);
            self::assertStringNotContainsString('$this->rows(', $list, $path);
        }
    }

    public function test_driver_does_not_import_query_domain(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/UDA/Driver.php') ?: '';

        self::assertDoesNotMatchRegularExpression(
            '/use UDA\\\\Query\\\\(?!Observer\\b)/',
            $source,
            'Driver may import Query\\Observer (telemetry DTO) only — not builders or executors.',
        );
        self::assertStringNotContainsString('BuilderSql', $source);
        self::assertDoesNotMatchRegularExpression('/public function (select|insert|update|delete|upsert)\s*\(/', $source);
        self::assertDoesNotMatchRegularExpression('/bindQueryBuilder|getDialectInstance|toSqlMessage\s*\(/', $source);
    }

    public function test_query_builders_do_not_fallback_to_driver_execution(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/UDA/Query.php') ?: '';

        self::assertStringNotContainsString('driverInstance', $source);
        self::assertDoesNotMatchRegularExpression('/->driverInstance|Driver::/', $source);
    }

    public function test_database_connect_returns_same_instance_for_same_connection(): void
    {
        $a = Database::connect('alpha');
        $b = Database::connect('alpha');

        self::assertSame($a, $b, 'Database::connect() must return the same instance for the same connection name.');
    }

    public function test_database_connect_returns_distinct_instances_for_different_connections(): void
    {
        $alpha = Database::connect('alpha');
        $beta  = Database::connect('beta');

        self::assertNotSame($alpha, $beta, 'Database::connect() must return distinct instances for different connection names.');
    }

    public function test_driver_has_no_static_pool(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/UDA/Driver.php') ?: '';

        self::assertStringNotContainsString('static array $pool', $source, 'Driver must not maintain its own static pool; pooling belongs to Database.');
    }

    public function test_driver_stores_config_for_reconnect(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/UDA/Driver.php') ?: '';

        self::assertStringContainsString('private array $config', $source, 'Driver must store $config to support reconnect().');
        self::assertStringContainsString('function reconnect', $source, 'Driver must implement reconnect().');
        self::assertStringContainsString('function isConnectionLost', $source, 'Driver must classify reconnectable PDO failures.');
        self::assertStringNotContainsString('ensureAlive()', $source, 'Driver must not ping before every query; use reconnect-on-failure instead.');
        self::assertStringContainsString('$this->reconnect()', $source, 'executeInternal must call reconnect() on connection loss.');
        self::assertStringContainsString('$this->isConnectionLost', $source, 'executeInternal must classify connection-loss before retry.');
    }

    public function test_errmode_exception_is_non_negotiable(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/UDA/Driver.php') ?: '';
        $body   = self::methodBody($source, 'resolvePdoOptions');

        self::assertStringContainsString('ERRMODE_EXCEPTION', $body, 'ERRMODE_EXCEPTION must be set in resolvePdoOptions.');

        // The enforcement line must come AFTER array_replace so consumer config cannot override it.
        $replacePos  = strpos($body, 'array_replace');
        $enforcePos  = strrpos($body, 'ERRMODE_EXCEPTION');
        self::assertNotFalse($replacePos, 'array_replace must be present.');
        self::assertNotFalse($enforcePos, 'Final ERRMODE_EXCEPTION assignment must be present.');
        self::assertGreaterThan($replacePos, $enforcePos, 'ERRMODE_EXCEPTION must be forced after array_replace, not before.');
    }

    public function test_link_declares_static_handle_for_memoization(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/UDA/Link.php') ?: '';

        self::assertStringContainsString('static ?Database $handle = null', $source, 'Link must declare static $handle for per-class memoization.');

        $body = self::methodBody($source, 'handle');
        self::assertStringContainsString('??=', $body, 'Link::handle() must use ??= for lazy memoization.');
    }

    public function test_value_uses_fetch_column_not_row(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/UDA/Driver.php') ?: '';
        $body   = self::methodBody($source, 'value');

        self::assertStringContainsString('fetchColumn', $body, 'Driver::value() must use fetchColumn() for direct scalar fetch.');
        self::assertStringNotContainsString('$this->row(', $body, 'Driver::value() must not delegate to row(), which allocates an intermediate array.');
    }

    public function test_row_and_list_use_single_fetch_modes_not_fetch_all(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/UDA/Driver.php') ?: '';
        $rowBody = self::methodBody($source, 'row');
        $listBody = self::methodBody($source, 'list');

        self::assertStringContainsString(
            'fetch(PDO::FETCH_ASSOC)',
            $rowBody,
            'Driver::row() must use fetch(PDO::FETCH_ASSOC) for one-row behavior.'
        );
        self::assertStringContainsString(
            'fetch(PDO::FETCH_NUM)',
            $listBody,
            'Driver::list() must use fetch(PDO::FETCH_NUM) for list-shaped one-row behavior.'
        );
        self::assertStringNotContainsString(
            'fetchAll',
            $rowBody,
            'Driver::row() must not use fetchAll(), which reads the full result set.'
        );
        self::assertStringNotContainsString(
            'fetchAll',
            $listBody,
            'Driver::list() must not use fetchAll(), which reads the full result set.'
        );
    }

    public function test_driver_read_and_execute_paths_are_closure_free(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/UDA/Driver.php') ?: '';

        $methods = ['rows', 'row', 'value', 'values', 'list', 'executeInternal', 'cacheHit', 'cacheStore'];

        foreach ($methods as $method) {
            $body = self::methodBody($source, $method);

            self::assertDoesNotMatchRegularExpression(
                '/\bfn\s*\(/',
                $body,
                'Driver::' . $method . '() must not allocate arrow-function closures on the hot path.'
            );
            self::assertDoesNotMatchRegularExpression(
                '/\bfunction\s*\(/',
                $body,
                'Driver::' . $method . '() must not allocate anonymous-function closures on the hot path.'
            );
        }
    }

    public function test_read_terminators_do_not_route_through_executor_closure(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/UDA/Driver.php') ?: '';

        self::assertStringNotContainsString(
            'function executeRead',
            $source,
            'executeRead() closure dispatcher must be removed; reads use cacheHit()/cacheStore() helpers.'
        );
        self::assertStringContainsString('function cacheHit', $source);
        self::assertStringContainsString('function cacheStore', $source);
    }

    public function test_dialect_state_objects_are_closure_free(): void
    {
        $stateFiles = [
            'Select.php',
            'Insert.php',
            'Update.php',
            'Delete.php',
            'Upsert.php',
        ];

        foreach ($stateFiles as $file) {
            $source = file_get_contents(__DIR__ . '/../../src/UDA/Query/State/' . $file) ?: '';

            self::assertStringNotContainsString(
                'Closure',
                $source,
                $file . ' must not store Closure properties; param()/quote() bind directly.'
            );
            self::assertDoesNotMatchRegularExpression(
                '/\bfn\s*\(|\bfunction\s*\(/',
                $source,
                $file . ' must not allocate closures during compilation.'
            );
        }
    }

    public function test_where_builder_quotes_via_parent_not_stored_closure(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/UDA/Query/WhereBuilder.php') ?: '';

        self::assertStringNotContainsString(
            '$this->quoter',
            $source,
            'WhereBuilder must quote via its injected parent builder, not a stored quoter closure.'
        );
        self::assertStringContainsString(
            '$this->parent->quote(',
            $source,
            'WhereBuilder::quote() must delegate to the parent builder.'
        );
    }

    /**
     * Extract a method body from source for lightweight architecture guards.
     *
     * @param string $source  PHP source.
     * @param string $method  Method name.
     *
     * @return string Method body.
     */
    private static function methodBody(string $source, string $method): string
    {
        $pattern = sprintf('/function\s+%s\b[^{]*\{(?P<body>[\s\S]*?)\n    \}/', preg_quote($method, '/'));
        self::assertMatchesRegularExpression($pattern, $source);
        preg_match($pattern, $source, $matches);

        return $matches['body'] ?? '';
    }
}
