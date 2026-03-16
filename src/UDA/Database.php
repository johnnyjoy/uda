<?php

declare(strict_types=1);

namespace UDA;

use UDA\Exception\ConfigException;
use UDA\Exception\ConnectionException;
use UDA\Exception\QueryException;
use UDA\Exception\QuerySafetyException;
use UDA\Query\Abs as QueryBuilder;
use UDA\Query\Dialect\Db2;
use UDA\Query\Dialect\Dialect;
use UDA\Query\Dialect\MariaDb;
use UDA\Query\Dialect\Oracle;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Dialect\SQLite;
use UDA\Query\Dialect\SqlServer;
use UDA\Query\Dialect\Sybase;
use UDA\Query\QueryPlanCache;
use UDA\Query\Sql as BuilderSql;
use UDA\Replay\ReplayBootstrapper;
use UDA\Retry\RetryDecision;
use UDA\Retry\RetryPolicy;
use UDA\SQL\SqlMessage;
use UDA\Safety\GuardrailConfig;
use UDA\Safety\QueryGuardrails;
use UDA\Tracing\QueryTrace;
use UDA\Tracing\QueryTraceListener;

/**
 * @package UDA
 * @author James Dornan <james@catch22.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/core/database
 * @since 1.0.0
 */

/*
 * Purpose: Sole public entry point for all database operations in UDA.
 */

final class Database
{
    /** @var Driver Eagerly-initialized Driver instance */
    private Driver $driver;

    /** @var Dialect Active SQL dialect */
    private Dialect $dialect;

    /** @var string Connection name */
    private string $connectionName;

    private GuardrailConfig $guardrailConfig;

    /** @var list<QueryTraceListener> */
    private static array $traceListeners = [];

    private bool $traceEnabled = false;
    private bool $traceRedactParameters = false;
    private bool $traceLogSlowQueries = false;
    private float $traceSlowThresholdMs = 0.0;
    private bool $pendingPlanCacheHit = false;
    private ?RetryPolicy $retryPolicy = null;
    /** @var array{retryCount:int|null,retried:bool,finalFailure:bool,retryReasons:array<int,string>}|null */
    private ?array $lastRetryMetadata = null;
    private int $transactionDepth = 0;

    /**
     * @param ?string $connectionName The connection name
     */
    private function __construct(?string $connectionName)
    {
        $resolvedName = Config::resolvedConnectionName($connectionName);
        $config = Config::connection($resolvedName);

        $this->connectionName = $resolvedName;
        $guardrailConfig = $config['guardrailConfig'] ?? GuardrailConfig::defaults();

        if (!$guardrailConfig instanceof GuardrailConfig) {
            $guardrailConfig = GuardrailConfig::defaults();
        }

        $this->guardrailConfig = $guardrailConfig;
        $this->applyTraceConfig($config['trace'] ?? []);
        $this->driver = Driver::connect($resolvedName);
        $this->dialect = $this->createDialect($this->driver->getBackendName());
        ReplayBootstrapper::boot();
    }

    /**
     * Connect to a database using configuration.
     *
     * Three configuration loading strategies:
     * 1. PHP array config (recommended for testing/development)
     * 2. JSON config file path (explicit configuration)
     * 3. Environment variable UDA_CONFIG (production default)
     *
     * @return self
     * @throws ConfigException     If connection not found or config invalid
     * @throws ConnectionException If connection fails
     */
    public static function connect(string ...$args): self
    {
        $connection = null;
        $configFile = null;

        foreach ($args as $arg) {
            if (str_ends_with($arg, '.json') || is_file($arg)) {
                $configFile = $arg;
            } else {
                $connection = $arg;
            }
        }

        Config::init($configFile);

        return new self($connection);
    }

    public static function addTraceListener(QueryTraceListener $listener): void
    {
        self::$traceListeners[] = $listener;
    }

    public static function clearTraceListeners(): void
    {
        self::$traceListeners = [];
    }

    public function setRetryPolicy(?RetryPolicy $policy): void
    {
        $this->retryPolicy = $policy;

        if ($policy !== null) {
            $policy->bindDriver($this->driver);
        }
    }

    // ----- Public Execution Methods -----

    /**
     * Executes a SELECT query and returns all matching rows.
     *
     * @param ?array $tableHints Optional table hints for cache/tracing
     */
    public function rows(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): array
    {
        if ($this->hasReturningMetadata($sql)) {
            $message = $this->maybeValidateGuardrails($sql, $params, $tableHints, 'returning');

            return $this->traceOperation(
                'returning',
                $message,
                $message->getCacheTables(),
                fn () => $this->executeWithRetry(
                    $message,
                    'returning',
                    fn () => $this->driver->returning($message, [], $message->getCacheTables())
                ),
                fn (array $rows): int => count($rows),
                false
            );
        }

        $message = $this->maybeValidateGuardrails($sql, $params, $tableHints, 'rows');

        return $this->traceOperation(
            'rows',
            $message,
            $message->getCacheTables(),
            fn () => $this->executeWithRetry(
                $message,
                'rows',
                fn () => $this->driver->rows($message, [], $message->getCacheTables())
            ),
            fn (array $rows): int => count($rows),
            false
        );
    }

    /**
     * @return ?array The single row result or null
     * @param ?array $tableHints Optional table hints for cache/tracing
     */
    public function row(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): ?array
    {
        if ($this->hasReturningMetadata($sql)) {
            $message = $this->maybeValidateGuardrails($sql, $params, $tableHints, 'returning');
            return $this->traceOperation(
                'returning',
                $message,
                $message->getCacheTables(),
                function () use ($message): ?array {
                    $rows = $this->executeWithRetry(
                        $message,
                        'returning',
                        fn () => $this->driver->returning($message, [], $message->getCacheTables())
                    );

                    return $rows[0] ?? null;
                },
                fn (?array $row): int => $row === null ? 0 : 1,
                false
            );
        }

        $message = $this->maybeValidateGuardrails($sql, $params, $tableHints, 'row');

        return $this->traceOperation(
            'row',
            $message,
            $message->getCacheTables(),
            fn () => $this->executeWithRetry(
                $message,
                'row',
                fn () => $this->driver->row($message, [], $message->getCacheTables())
            ),
            fn (?array $row): int => $row === null ? 0 : 1,
            false
        );
    }

    /**
     * @return mixed The single value result
     * @param ?array $tableHints Optional table hints for cache/tracing
     */
    public function value(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null)
    {
        $row = $this->row($sql, $params, $tableHints);

        if ($row === null) {
            return null;
        }

        if (count($row) !== 1) {
            throw new QueryException('value() requires a single column result');
        }

        return array_values($row)[0];
    }

    /**
     * @return array The values from the first column
     * @param ?array $tableHints Optional table hints for cache/tracing
     */
    public function values(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): array
    {
        $rows = $this->rows($sql, $params, $tableHints);
        $result = [];

        foreach ($rows as $row) {
            $result[] = array_values($row)[0] ?? null;
        }

        return $result;
    }

    /**
     * @return array The values from the first column
     * @param ?array $tableHints Optional table hints for cache/tracing
     */
    public function list(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): array
    {
        return $this->values($sql, $params, $tableHints);
    }

    /**
     * @return int The number of rows processed
     * @param ?array $tableHints Optional table hints for cache/tracing
     */
    public function each(string|SqlMessage|BuilderSql $sql, array|callable $params, callable $fn = null, ?array $tableHints = null): int
    {
        if ($fn === null) {
            if (!is_callable($params)) {
                throw new QueryException('each() requires a callback');
            }
            $callback = $params;
            $parameterPayload = [];
            $driverParams = null;
        } else {
            if (!is_array($params)) {
                throw new QueryException('each() parameters must be provided as an array when callback is supplied');
            }
            $callback = $fn;
            $parameterPayload = $params;
            $driverParams = $params;
        }

        $message = $this->maybeValidateGuardrails($sql, $parameterPayload, $tableHints, 'each');
        $progress = 0;
        $wrapped = function (array $row) use (&$progress, $callback): void {
            $progress++;
            $callback($row);
        };

        $runner = function (?array $tableHintsArg) use ($message, $driverParams, $wrapped): int {
            if ($driverParams === null) {
                return $this->driver->each($message, $wrapped, null, $tableHintsArg);
            }

            return $this->driver->each($message, $driverParams, $wrapped, $tableHintsArg);
        };

        return $this->traceOperation(
            'each',
            $message,
            $message->getCacheTables(),
            fn () => $this->executeWithRetry(
                $message,
                'each',
                function () use ($runner, $message) {
                    return $runner($message->getCacheTables());
                },
                function () use (&$progress): bool {
                    return $progress > 0;
                }
            ),
            fn (int $rows): int => $rows,
            false
        );
    }

    /**
     * @return int The number of affected rows
     * @param ?array $tableHints Optional table hints for cache/tracing
     */
    public function exec(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): int
    {
        $message = $this->maybeValidateGuardrails($sql, $params, $tableHints, 'exec');

        return $this->traceOperation(
            'exec',
            $message,
            $message->getCacheTables(),
            fn () => $this->executeWithRetry(
                $message,
                'exec',
                fn () => $this->driver->exec($message, [], $message->getCacheTables())
            ),
            fn (int $count): int => $count,
            false
        );
    }

    /**
     * Execute a DML statement with RETURNING/OUTPUT semantics and return the emitted rows.
     *
     * @param ?array $tableHints Optional table hints for cache/tracing
     */
    public function returning(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): array
    {
        $message = $this->maybeValidateGuardrails($sql, $params, $tableHints, 'returning');

        return $this->traceOperation(
            'returning',
            $message,
            $message->getCacheTables(),
            fn () => $this->executeWithRetry(
                $message,
                'returning',
                fn () => $this->driver->returning($message, [], $message->getCacheTables())
            ),
            fn (array $rows): int => count($rows),
            false
        );
    }

    /**
     * Generate an EXPLAIN plan for the provided SQL or builder.
     *
     * @param ?array $tableHints Optional table hints for cache/tracing
     */
    public function explain(string|SqlMessage|BuilderSql|QueryBuilder $sql, array $params = [], ?array $tableHints = null): array
    {
        [$message, $planHit] = $this->resolveExplainMessage($sql, $params, $tableHints);

        return $this->executeExplain($message, false, $planHit);
    }

    /**
     * Generate an EXPLAIN ANALYZE plan for the provided SQL or builder.
     *
     * @param ?array $tableHints Optional table hints for cache/tracing
     */
    public function explainAnalyze(string|SqlMessage|BuilderSql|QueryBuilder $sql, array $params = [], ?array $tableHints = null): array
    {
        [$message, $planHit] = $this->resolveExplainMessage($sql, $params, $tableHints);

        return $this->executeExplain($message, true, $planHit);
    }

    /**
     * @return string The ORDER BY fragment
     */
    public function orderByAllowed(string $column, array $allowlist, string $direction = 'ASC'): string
    {
        return $this->driver->orderByAllowed($column, $allowlist, $direction);
    }

    /**
     * @return string The LIMIT/OFFSET fragment
     */
    public function limitOffset(int $limit, int $offset): string
    {
        return $this->driver->limitOffset($limit, $offset);
    }

    /**
     * @return array{0:string,1:array<string,mixed>} IN list fragment and parameters
     */
    public function inList(array $values, string $hint = 'p'): array
    {
        return $this->driver->inList($values, $hint);
    }

    /**
     * @return string The quoted identifier
     */
    public function q(string $identifier): string
    {
        return $this->driver->q($identifier);
    }

    private function hasReturningMetadata(string|SqlMessage|BuilderSql $sql): bool
    {
        if ($sql instanceof SqlMessage) {
            return $sql->getReturningColumns() !== [];
        }

        if ($sql instanceof BuilderSql) {
            return $sql->getReturningColumns() !== [];
        }

        return false;
    }

    /**
     * Executes a callback within a database transaction.
     */
    public function transaction(callable $fn): mixed
    {
        $this->transactionDepth++;

        try {
            return $this->driver->transaction($fn);
        } finally {
            $this->transactionDepth--;
        }
    }

    /**
     * @return ?string The last executed SQL or null
     */
    public function lastSql(): ?string
    {
        return $this->driver->lastSql();
    }

    /**
     * @return array The last executed parameters
     */
    public function lastParams(): array
    {
        return $this->driver->lastParams();
    }

    public function executeBuilder(QueryBuilder $builder, string $method, mixed ...$args): mixed
    {
        if ($method === 'explain' || $method === 'explainAnalyze') {
            $message = $this->resolvePlan($builder);
            $planHit = $this->consumePlanCacheHit();

            return $this->executeExplain($message, $method === 'explainAnalyze', $planHit);
        }

        $message = $this->resolvePlan($builder);
        $planHit = $this->consumePlanCacheHit();

        $message = $this->maybeValidateGuardrails($message, [], $message->getCacheTables(), $method);
        $progress = 0;

        $tables = $message->getCacheTables();
        $runner = function () use ($message, $method, $args, &$progress, $tables) {
            if ($method === 'each') {
                $callback = $args[0] ?? null;

                if (!is_callable($callback)) {
                    throw new QueryException('each() requires a callback');
                }

                $wrapped = function (array $row) use (&$progress, $callback): void {
                    $progress++;
                    $callback($row);
                };

                return $this->driver->each($message, $wrapped, null, $tables);
            }

            return match ($method) {
                'rows' => $this->driver->rows($message, [], $tables),
                'row' => $this->driver->row($message, [], $tables),
                'value' => $this->driver->value($message, [], $tables),
                'values' => $this->driver->values($message, [], $tables),
                'list' => $this->driver->list($message, [], $tables),
                'exec' => $this->driver->exec($message, [], $tables),
                'returning' => $this->driver->returning($message, [], $tables),
                default => $this->driver->$method($message, ...$args),
            };
        };

        $eachProgress = $method === 'each'
            ? function () use (&$progress): bool {
                return $progress > 0;
            }
            : null;

        return $this->traceOperation(
            $method,
            $message,
            $message->getCacheTables(),
            fn () => $this->executeWithRetry($message, $method, $runner, $eachProgress),
            $this->rowCountResolver($method),
            $planHit
        );
    }

    public function executeBuilderReturning(QueryBuilder $builder): array
    {
        $message = $this->resolvePlan($builder);
        $planHit = $this->consumePlanCacheHit();

        $message = $this->maybeValidateGuardrails($message, [], $message->getCacheTables(), 'returning');

        return $this->traceOperation(
            'returning',
            $message,
            $message->getCacheTables(),
            fn () => $this->executeWithRetry(
                $message,
                'returning',
                fn () => $this->driver->returning($message, [], $message->getCacheTables())
            ),
            fn (array $rows): int => count($rows),
            $planHit
        );
    }

    // ----- Query Builder Methods -----

    /**
     * @return \UDA\Query\Select Ready-to-configure SELECT query builder
     */
    public function select(): \UDA\Query\Select
    {
        $builder = $this->driver->select();
        $builder->bindDatabase($this);
        $builder->bindDialect($this->dialect);

        return $builder;
    }

    /**
     * @return \UDA\Query\Insert The INSERT query builder
     */
    public function insert(): \UDA\Query\Insert
    {
        $builder = $this->driver->insert();
        $builder->bindDatabase($this);
        $builder->bindDialect($this->dialect);

        return $builder;
    }

    /**
     * @return \UDA\Query\Update The UPDATE query builder
     */
    public function update(): \UDA\Query\Update
    {
        $builder = $this->driver->update();
        $builder->bindDatabase($this);
        $builder->bindDialect($this->dialect);

        return $builder;
    }

    /**
     * @return \UDA\Query\Delete The DELETE query builder
     */
    public function delete(): \UDA\Query\Delete
    {
        $builder = $this->driver->delete();
        $builder->bindDatabase($this);
        $builder->bindDialect($this->dialect);

        return $builder;
    }

    /**
     * @return \UDA\Query\Upsert The UPSERT query builder
     */
    public function upsert(): \UDA\Query\Upsert
    {
        $builder = $this->driver->upsert();
        $builder->bindDatabase($this);
        $builder->bindDialect($this->dialect);

        return $builder;
    }

    private function createDialect(?string $backend): Dialect
    {
        $key = strtolower($backend ?? '');

        return match ($key) {
            'pgsql', 'postgres', 'postgresql' => new PostgreSql(),
            'sqlite' => new SQLite(),
            'mysql', 'mariadb' => new MariaDb(),
            'sqlsrv', 'sqlserver' => new SqlServer(),
            'dblib', 'sybase' => new Sybase(),
            'oci', 'oracle' => new Oracle(),
            'db2' => new Db2(),
            default => throw new ConfigException('No SQL dialect available for backend: ' . ($backend ?? 'unknown')),
        };
    }

    public function overrideDialectForPlanCache(Dialect $dialect): void
    {
        $this->dialect = $dialect;
    }

    private function resolvePlan(QueryBuilder $builder): SqlMessage
    {
        $this->pendingPlanCacheHit = false;

        $fingerprint = $builder->fingerprint();

        if (!QueryPlanCache::isEnabled()) {
            return $this->toSqlMessage($builder->toSql())->withFingerprint($fingerprint);
        }

        $key = $this->planCacheKey($fingerprint);
        $cached = QueryPlanCache::get($key);

        if ($cached !== null) {
            $this->pendingPlanCacheHit = true;
            if ($cached->getFingerprint() === null) {
                $cached = $cached->withFingerprint($fingerprint);
                QueryPlanCache::put($key, $cached);
            }

            return $cached;
        }

        $message = $this->toSqlMessage($builder->toSql())->withFingerprint($fingerprint);
        QueryPlanCache::put($key, $message);

        return $message;
    }

    private function consumePlanCacheHit(): bool
    {
        $hit = $this->pendingPlanCacheHit;
        $this->pendingPlanCacheHit = false;

        return $hit;
    }

    private function planCacheKey(string $fingerprint): string
    {
        $dialectName = $this->dialect->name();

        return strtolower($dialectName) . ':' . $fingerprint;
    }

    private function applyTraceConfig(mixed $traceConfig): void
    {
        if (!is_array($traceConfig)) {
            $traceConfig = [];
        }

        $this->traceEnabled = (bool)($traceConfig['enabled'] ?? false);
        $this->traceRedactParameters = (bool)($traceConfig['redact_parameters'] ?? false);
        $this->traceLogSlowQueries = (bool)($traceConfig['log_slow_queries'] ?? false);
        $this->traceSlowThresholdMs = max(0.0, (float)($traceConfig['slow_query_ms'] ?? 0));
    }

    private function shouldTrace(): bool
    {
        return $this->traceEnabled || $this->traceLogSlowQueries || self::$traceListeners !== [];
    }

    /**
     * @template T
     * @param callable():T $runner
     * @param callable(T):int $rowCountResolver
     * @return T
     */
    private function traceOperation(
        string $operation,
        string|SqlMessage|BuilderSql $sqlInput,
        ?array $tableHints,
        callable $runner,
        callable $rowCountResolver,
        bool $planCacheHit
    ) {
        if (!$this->shouldTrace()) {
            try {
                return $runner();
            } finally {
                $this->consumeRetryMetadata();
            }
        }

        $start = microtime(true);
        try {
            $result = $runner();
        } catch (\Throwable $exception) {
            $durationMs = (microtime(true) - $start) * 1000;
            $trace = $this->createTrace(
                $operation,
                $sqlInput,
                $tableHints,
                $durationMs,
                0,
                $planCacheHit,
                true,
                $this->consumeRetryMetadata()
            );
            $this->dispatchTrace($trace);

            throw $exception;
        }

        $durationMs = (microtime(true) - $start) * 1000;
        $rowCount = (int) $rowCountResolver($result);

        $trace = $this->createTrace(
            $operation,
            $sqlInput,
            $tableHints,
            $durationMs,
            $rowCount,
            $planCacheHit,
            false,
            $this->consumeRetryMetadata()
        );
        $this->dispatchTrace($trace);

        return $result;
    }

    private function maybeValidateGuardrails(string|SqlMessage|BuilderSql $sqlInput, array|callable $params, ?array $tableHints, string $operation): SqlMessage
    {
        $message = $this->normalizeSqlMessage($sqlInput, $params, $tableHints);

        if (!$this->guardrailConfig->enabled) {
            return $message;
        }

        try {
            QueryGuardrails::validate($message, $this->guardrailConfig, $operation);
        } catch (QuerySafetyException $exception) {
            $this->traceGuardrailViolation($message, $operation, $exception);
            throw $exception;
        }

        return $message;
    }

    private function normalizeSqlMessage(string|SqlMessage|BuilderSql $sqlInput, array|callable $params, ?array $tableHints): SqlMessage
    {
        $overrideTables = $tableHints;

        if ($sqlInput instanceof SqlMessage) {
            $message = $sqlInput;
        } elseif ($sqlInput instanceof BuilderSql) {
            $message = $this->toSqlMessage($sqlInput);
        } else {
            $paramList = is_array($params) ? $params : [];
            $message = new SqlMessage($sqlInput, $paramList, $overrideTables ?? []);
        }

        if ($overrideTables !== null) {
            // External callers explicitly provided table hints; override message metadata entirely.
            $message = $message->withCacheTables($overrideTables);
        }

        return $message;
    }

    private function createTrace(
        string $operation,
        string|SqlMessage|BuilderSql $sqlInput,
        ?array $tableHints,
        float $durationMs,
        int $rowCount,
        bool $planCacheHit,
        bool $error,
        ?array $retryMeta = null
    ): QueryTrace {
        $sql = $this->driver->lastSql() ?? $this->extractSql($sqlInput) ?? '';
        $params = $this->driver->lastParams();

        if ($this->traceRedactParameters) {
            $params = $this->redactParameters($params);
        }

        $statementCacheHit = $this->driver->consumeStatementCacheHit();
        $resultCacheHit = $this->driver->consumeResultCacheHit();
        $tableList = $this->resolveTables($sqlInput, $tableHints);
        $slow = $this->isSlowQuery($durationMs);
        $statementType = $this->resolveStatementType($sqlInput);
        $retryCount = $retryMeta['retryCount'] ?? null;
        $retried = $retryMeta['retried'] ?? false;
        $finalFailure = $retryMeta['finalFailure'] ?? false;
        $retryReasons = $retryMeta['retryReasons'] ?? [];

        return new QueryTrace(
            operation: $operation,
            sql: $sql,
            parameters: $params,
            dialect: strtolower($this->dialect->name()),
            connection: $this->connectionName,
            executionTimeMs: $durationMs,
            rowCount: $rowCount,
            planCacheHit: $planCacheHit,
            statementCacheHit: $statementCacheHit,
            resultCacheHit: $resultCacheHit,
            tables: $tableList,
            slow: $slow,
            traceType: 'query',
            meta: [
                'statementType' => $statementType,
                'fingerprint' => $this->extractFingerprint($sqlInput),
            ],
            error: $error,
            retryCount: $retryCount,
            retried: $retried,
            finalFailure: $finalFailure,
            retryReasons: $retryReasons
        );
    }

    private function dispatchTrace(QueryTrace $trace): void
    {
        foreach (self::$traceListeners as $listener) {
            $listener->handle($trace);
        }

        if ($trace->slow && $this->traceLogSlowQueries) {
            error_log(sprintf(
                '[UDA][slow-query][%s] %s (%0.2fms)',
                $trace->connection,
                $trace->sql,
                $trace->executionTimeMs
            ));
        }
    }

    private function traceGuardrailViolation(SqlMessage $sql, string $operation, QuerySafetyException $exception): void
    {
        if (!$this->shouldTrace()) {
            return;
        }

        $params = $sql->getParams();

        if ($this->traceRedactParameters) {
            $params = $this->redactParameters($params);
        }

        $this->dispatchTrace(new QueryTrace(
            operation: 'guardrail_violation',
            sql: $sql->getQuery(),
            parameters: $params,
            dialect: strtolower($this->dialect->name()),
            connection: $this->connectionName,
            executionTimeMs: 0.0,
            rowCount: 0,
            planCacheHit: false,
            statementCacheHit: false,
            resultCacheHit: false,
            tables: $sql->getCacheTables(),
            slow: false,
            traceType: 'guardrail_violation',
            meta: [
                'reason' => $exception->getReason(),
                'statementType' => $sql->getStatementType(),
                'operation' => $operation,
                'fingerprint' => $sql->getFingerprint(),
            ],
            error: true
        ));
    }

    private function emitRetryAttemptTrace(SqlMessage $sql, string $operation, RetryDecision $decision, \Throwable $exception): void
    {
        $params = $sql->getParams();

        if ($this->traceRedactParameters) {
            $params = $this->redactParameters($params);
        }

        $trace = new QueryTrace(
            operation: $operation,
            sql: $sql->getQuery(),
            parameters: $params,
            dialect: strtolower($this->dialect->name()),
            connection: $this->connectionName,
            executionTimeMs: 0.0,
            rowCount: 0,
            planCacheHit: false,
            statementCacheHit: false,
            resultCacheHit: false,
            tables: $sql->getCacheTables(),
            slow: false,
            traceType: 'retry_attempt',
            meta: [
                'attempt' => $decision->attempt,
                'delayMs' => $decision->delayMs,
                'reason' => $decision->reason,
                'statementType' => $sql->getStatementType(),
                'fingerprint' => $sql->getFingerprint(),
                'exception' => get_class($exception),
            ],
            error: true,
            retryCount: $decision->attempt,
            retried: true,
            finalFailure: false,
            retryReasons: [$decision->reason]
        );

        $this->dispatchTrace($trace);
    }

    private function extractFingerprint(string|SqlMessage|BuilderSql $sqlInput): ?string
    {
        if ($sqlInput instanceof SqlMessage) {
            return $sqlInput->getFingerprint();
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function resolveTables(string|SqlMessage|BuilderSql $sqlInput, ?array $tableHints): array
    {
        if ($tableHints !== null) {
            return $tableHints;
        }

        if ($sqlInput instanceof SqlMessage || $sqlInput instanceof BuilderSql) {
            return $sqlInput->getCacheTables();
        }

        return [];
    }

    private function resolveStatementType(string|SqlMessage|BuilderSql $sqlInput): string
    {
        if ($sqlInput instanceof SqlMessage || $sqlInput instanceof BuilderSql) {
            return $sqlInput->getStatementType();
        }

        return 'raw';
    }

    private function extractSql(string|SqlMessage|BuilderSql $sqlInput): ?string
    {
        if (is_string($sqlInput)) {
            return $sqlInput;
        }

        return $sqlInput->getQuery();
    }

    /**
     * @template T
     * @param callable():T $driverCall
     * @return T
     */
    private function executeWithRetry(SqlMessage $sql, string $operation, callable $driverCall, ?callable $eachProgress = null)
    {
        if ($this->retryPolicy === null) {
            $this->lastRetryMetadata = null;

            return $driverCall();
        }

        $listener = null;

        if ($this->shouldTrace()) {
            $listener = function (RetryDecision $decision, \Throwable $exception) use ($sql, $operation): void {
                $this->emitRetryAttemptTrace($sql, $operation, $decision, $exception);
            };
        }

        try {
            $result = $this->retryPolicy->execute(
                $sql,
                $operation,
                $this->transactionDepth > 0,
                $driverCall,
                $listener,
                $eachProgress
            );
        } finally {
            $this->lastRetryMetadata = $this->retryPolicy->getLastMetadata();
        }

        return $result;
    }

    private function consumeRetryMetadata(): ?array
    {
        $meta = $this->lastRetryMetadata;
        $this->lastRetryMetadata = null;

        return $meta;
    }

    private function redactParameters(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = $this->redactParameters($value);
            } else {
                $params[$key] = '***';
            }
        }

        return $params;
    }

    private function isSlowQuery(float $durationMs): bool
    {
        return $this->traceSlowThresholdMs > 0 && $durationMs >= $this->traceSlowThresholdMs;
    }

    /**
     * @return callable(mixed):int
     */
    private function rowCountResolver(string $operation): callable
    {
        return match ($operation) {
            'rows', 'values', 'list', 'returning', 'explain', 'explain_analyze' => fn ($rows): int => is_array($rows) ? count($rows) : 0,
            'row' => fn ($row): int => $row === null ? 0 : 1,
            'value' => fn ($value): int => $value === null ? 0 : 1,
            'each', 'exec' => fn ($count): int => (int) $count,
            default => fn ($result): int => is_array($result) ? count($result) : (int) ($result ?? 0),
        };
    }
    private function executeExplain(SqlMessage $sql, bool $analyze, bool $planCacheHit): array
    {
        if ($analyze && !$this->dialect->supportsExplainAnalyze()) {
            throw new QueryException($this->dialect->name() . ' dialect does not support EXPLAIN ANALYZE statements.');
        }

        if (!$analyze && !$this->dialect->supportsExplain()) {
            throw new QueryException($this->dialect->name() . ' dialect does not support EXPLAIN statements.');
        }

        $operation = $analyze ? 'explain_analyze' : 'explain';
        $tableHints = $sql->getCacheTables();

        return $this->traceOperation(
            $operation,
            $sql,
            $tableHints,
            fn () => $this->executeWithRetry(
                $sql,
                $operation,
                fn () => $analyze ? $this->driver->explainAnalyze($sql) : $this->driver->explain($sql)
            ),
            $this->rowCountResolver($operation),
            $planCacheHit
        );
    }

    /**
     * @param string|SqlMessage|BuilderSql|QueryBuilder $sql
     */
    /**
     * @return array{0:SqlMessage,1:bool}
     */
    private function resolveExplainMessage(string|SqlMessage|BuilderSql|QueryBuilder $sql, array $params, ?array $tableHints): array
    {
        if ($sql instanceof QueryBuilder) {
            $message = $this->resolvePlan($sql);
            $planHit = $this->consumePlanCacheHit();

            return [$message, $planHit];
        }

        if ($sql instanceof SqlMessage) {
            return [$sql, false];
        }

        if ($sql instanceof BuilderSql) {
            return [$this->toSqlMessage($sql), false];
        }

        $tableHints = $tableHints ?? [];

        return [new SqlMessage($sql, $params, $tableHints), false];
    }

    private function toSqlMessage(BuilderSql $sql): SqlMessage
    {
        return new SqlMessage(
            $sql->getQuery(),
            $sql->getParams(),
            $sql->getCacheTables(),
            $sql->getReturningColumns(),
            $sql->getInsertTable(),
            $sql->getInsertColumns(),
            $sql->getValuePlaceholders(),
            $sql->getStatementType(),
            $sql->hasWhereClause(),
            $sql->hasLimitClause(),
            $sql->isUnsafe(),
            fingerprint: null,
            retryAllowed: $sql->getRetryAllowed()
        );
    }
}
