<?php

declare(strict_types=1);

namespace UDA;

use UDA\Exception\ConfigException;
use UDA\Exception\ConnectionException;
use UDA\Exception\QueryException;
use UDA\Query;
use UDA\Query\Delete;
use UDA\Query\Dialect\Db2 as Db2Dialect;
use UDA\Query\Dialect\Dialect;
use UDA\Query\Dialect\Firebird as FirebirdDialect;
use UDA\Query\Dialect\MariaDb;
use UDA\Query\Dialect\Oracle as OracleDialect;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Dialect\SQLite as SqliteDialect;
use UDA\Query\Dialect\SqlServer as SqlServerDialect;
use UDA\Query\Dialect\Sybase;
use UDA\Query\Expr;
use UDA\Query\Insert;
use UDA\Query\Select;
use UDA\Query\Sql as BuilderSql;
use UDA\Query\Update;
use UDA\Query\Upsert;
use UDA\SQL\SqlMessage;

/**
 * @package UDA
 * @author James Dornan <james@catch22.com>
 * @license MIT
 * @link https://github.com/johnnyjoy/uda/blob/master/docs/public-api.md
 * @since 1.0.0
 */

/*
 * Purpose: Public Database handle for application classes using UDA.
 *
 * Database is the only domain application code should import. It coordinates
 * builders, SQL messages, dialect selection, and Driver execution without
 * exposing PDO, cache internals, or per-engine rule classes.
 */

/**
 * Public entry point for configured database work.
 *
 * Each Database instance wraps one configured connection name and delegates
 * execution to a one-connection Driver runtime.
 */
final class Database
{
    /**
     * Process-lifetime pool of Database instances keyed by resolved connection name.
     *
     * Each unique connection name gets one Database instance per process. Because
     * Driver is also pooled, this means one PDO per connection name per process.
     *
     * @var array<string,self>
     */
    private static array $databases = [];

    /** @var Driver Eagerly-initialized Driver instance */
    private Driver $driver;

    /** @var string Connection name */
    private string $connectionName;

    /** @var ?Dialect Lazily-created Query dialect for builders */
    private ?Dialect $dialect = null;

    /**
     * @param ?string $connectionName  The connection name
     */
    private function __construct(?string $connectionName)
    {
        $resolvedName = $connectionName ?? Config::default();

        $this->connectionName = $resolvedName;
        $this->driver = Driver::connect($resolvedName);
    }

    /**
     * Return the Database handle for a named connection, creating it on first call.
     *
     * Subsequent calls with the same connection name return the same instance.
     * The underlying Driver (and PDO) are also pooled, so exactly one physical
     * connection per named connection per process lifetime is opened.
     *
     * @param string ...$args  Connection name and optional path to a JSON config file.
     *
     * @return self
     *
     * @throws ConfigException
     * @throws ConnectionException
     */
    public static function connect(string ...$args): self
    {
        // Fast path: single known connection name already in the pool.
        // Skips argument parsing, is_file() syscall, and Config::init().
        if (count($args) === 1
            && !str_ends_with($args[0], '.json')
            && isset(self::$databases[$args[0]])) {
            return self::$databases[$args[0]];
        }

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

        $resolved = $connection ?? Config::default();

        return self::$databases[$resolved] ??= new self($resolved);
    }

    /**
     * Default connection from `UDA_CONFIG` (or env-loaded config).
     *
     * @return self
     *
     * @throws ConfigException
     * @throws ConnectionException
     */
    public static function connectDefault(): self
    {
        return self::connect();
    }

    /**
     * Named connection from `UDA_CONFIG` (or env-loaded config).
     *
     * @param string $name  Connection name in config.
     *
     * @return self
     *
     * @throws ConfigException
     * @throws ConnectionException
     */
    public static function connectNamed(string $name): self
    {
        return self::connect($name);
    }

    /**
     * Connection from an explicit JSON config file.
     *
     * @param string      $file  Path to UDA config JSON.
     * @param null|string $name  Connection name; null uses the file default.
     *
     * @return self
     *
     * @throws ConfigException
     * @throws ConnectionException
     */
    public static function connectWithConfig(string $file, ?string $name = null): self
    {
        return $name === null ? self::connect($file) : self::connect($name, $file);
    }

    /**
     * Executes a query and returns all matching rows.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints
     *
     * @return array Result array.
     */
    public function rows(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): array
    {
        $this->assertReadTableHints($sql, $tableHints);
        $message = $this->normalizeSqlMessage($sql, $params, $tableHints);

        if ($this->hasReturningMetadata($message)) {
            return $this->driver->returning($message, [], $message->getCacheTables());
        }

        return $this->driver->rows($message, [], $message->getCacheTables());
    }

    /**
     * Execute and return the first row (or null).
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints
     *
     * @return ?array The first row or null
     */
    public function row(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): ?array
    {
        $this->assertReadTableHints($sql, $tableHints);
        $message = $this->normalizeSqlMessage($sql, $params, $tableHints);

        if ($this->hasReturningMetadata($message)) {
            $rows = $this->driver->returning($message, [], $message->getCacheTables());

            return $rows[0] ?? null;
        }

        return $this->driver->row($message, [], $message->getCacheTables());
    }

    /**
     * Execute and return a single value.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints
     *
     * @return mixed The single value result
     *
     * @throws QueryException If the operation fails.
     */
    public function value(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null)
    {
        $this->assertReadTableHints($sql, $tableHints);
        $message = $this->normalizeSqlMessage($sql, $params, $tableHints);

        if ($this->hasReturningMetadata($message)) {
            $rows = $this->driver->returning($message, [], $message->getCacheTables());
            $row = $rows[0] ?? null;

            if ($row === null) {
                return null;
            }

            if (count($row) !== 1) {
                throw QueryException::guardrail('value() requires a single column result');
            }

            return array_values($row)[0];
        }

        return $this->driver->value($message, [], $message->getCacheTables());
    }

    /**
     * Execute and return the first column from every row.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints
     *
     * @return array The values from the first column
     */
    public function values(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): array
    {
        $this->assertReadTableHints($sql, $tableHints);
        $message = $this->normalizeSqlMessage($sql, $params, $tableHints);

        if ($this->hasReturningMetadata($message)) {
            $rows = $this->driver->returning($message, [], $message->getCacheTables());
            $result = [];

            foreach ($rows as $row) {
                $result[] = array_values($row)[0] ?? null;
            }

            return $result;
        }

        return $this->driver->values($message, [], $message->getCacheTables());
    }

    /**
     * Execute and return the first row as a numeric list.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints
     *
     * @return ?array<int,mixed> Row values or null.
     */
    public function list(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): ?array
    {
        $this->assertReadTableHints($sql, $tableHints);
        $message = $this->normalizeSqlMessage($sql, $params, $tableHints);

        if ($this->hasReturningMetadata($message)) {
            $rows = $this->driver->returning($message, [], $message->getCacheTables());
            $row = $rows[0] ?? null;

            return $row === null ? null : array_values($row);
        }

        return $this->driver->list($message, [], $message->getCacheTables());
    }

    /**
     * Each.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array|callable               $params      Named parameter values.
     * @param callable                     $fn          Callback to execute.
     * @param ?array                       $tableHints  Optional table hints
     *
     * @return int The number of rows processed
     *
     * @throws QueryException If the operation fails.
     */
    public function each(string|SqlMessage|BuilderSql $sql, array|callable $params, ?callable $fn = null, ?array $tableHints = null): int
    {
        if ($fn === null) {
            if (!is_callable($params)) {
                throw QueryException::guardrail('each() requires a callback');
            }

            $callback = $params;
            $paramList = [];
        } else {
            if (!is_array($params)) {
                throw QueryException::guardrail('each() parameters must be provided as an array when callback is supplied');
            }

            $callback = $fn;
            $paramList = $params;
        }

        $this->assertReadTableHints($sql, $tableHints);
        $message = $this->normalizeSqlMessage($sql, $paramList, $tableHints);

        return $this->driver->each($message, [], $callback, $message->getCacheTables());
    }

    /**
     * Execute a write statement.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints
     *
     * @return int The number of affected rows
     */
    public function exec(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): int
    {
        $message = $this->normalizeSqlMessage($sql, $params, $tableHints);

        return $this->driver->exec($message, [], $message->getCacheTables());
    }

    /**
     * Execute a DML statement with RETURNING/OUTPUT semantics and return the emitted rows.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints
     *
     * @return array Result array.
     */
    public function returning(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): array
    {
        $message = $this->normalizeSqlMessage($sql, $params, $tableHints);

        return $this->driver->returning($message, [], $message->getCacheTables());
    }

    /**
     * Order by allowed.
     *
     * @param string $column     Column name or expression.
     * @param array  $allowlist  Allowed column names.
     * @param string $direction  Sort direction.
     *
     * @return string The ORDER BY fragment
     */
    public function orderByAllowed(string $column, array $allowlist, string $direction = 'ASC'): string
    {
        return $this->driver->orderByAllowed($column, $allowlist, $direction);
    }

    /**
     * Limit offset.
     *
     * @param int $limit   Maximum number of rows.
     * @param int $offset  Number of rows to skip.
     *
     * @return string The LIMIT/OFFSET fragment
     */
    public function limitOffset(int $limit, int $offset): string
    {
        return $this->driver->limitOffset($limit, $offset);
    }

    /**
     * In list.
     *
     * @param array  $values  Values to process.
     * @param string $hint    Parameter name hint.
     *
     * @return array{0:string,1:array<string,mixed>} IN list fragment and parameters
     */
    public function inList(array $values, string $hint = 'p'): array
    {
        return $this->driver->inList($values, $hint);
    }

    /**
     * Q.
     *
     * @param string $identifier  Identifier value.
     *
     * @return string The quoted identifier
     */
    public function q(string $identifier): string
    {
        return $this->driver->q($identifier);
    }

    /**
     * Report whether has returning metadata.
     *
     * @param string|SqlMessage|BuilderSql $sql  SQL string, SQL message, or builder SQL object.
     *
     * @return bool Boolean result.
     */
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
     *
     * @param callable $fn  Callback to execute.
     *
     * @return mixed Execution result.
     */
    public function transaction(callable $fn): mixed
    {
        return $this->driver->transaction(fn (): mixed => $fn($this));
    }

    /**
     * Delete cached read results for this connection's configured cache store.
     *
     * Ops-only: deploy hooks, admin CLI, incident response. Normal read paths stay transparent.
     *
     * @return void
     */
    public function flushCache(): void
    {
        Cache::flush($this->connectionName);
    }

    /**
     * Register a process-wide query observer (ops/instrumentation). Null disables.
     *
     * @param null|callable(\UDA\Query\Observer):void $observer  Callback or null.
     *
     * @return void No return value.
     */
    public static function setQueryObserver(?callable $observer): void
    {
        Driver::setQueryObserver($observer);
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

    /**
     * Internal callback entry point used by query builder delegation.
     *
     * Application code must never call this directly. Obtain builders via
     * `$db->select()`, `$db->insert()`, etc. and call terminators on them.
     * Calling this method directly bypasses builder state setup and violates
     * the single execution path contract.
     *
     * @internal
     *
     * @param Query $builder  Query builder instance.
     * @param string       $method   Terminator method name.
     * @param mixed        ...$args  Additional terminator arguments.
     *
     * @return mixed Execution result.
     *
     * @throws QueryException If the operation fails.
     */
    public function executeBuilder(Query $builder, string $method, mixed ...$args): mixed
    {
        $message = $this->toSqlMessage($builder->toSql());

        return match ($method) {
            'rows' => $this->driver->rows($message, [], $message->getCacheTables()),
            'row' => $this->driver->row($message, [], $message->getCacheTables()),
            'value' => $this->driver->value($message, [], $message->getCacheTables()),
            'values' => $this->driver->values($message, [], $message->getCacheTables()),
            'list' => $this->driver->list($message, [], $message->getCacheTables()),
            'exec' => $this->driver->exec($message, [], $message->getCacheTables()),
            'returning' => $this->driver->returning($message, [], $message->getCacheTables()),
            'each' => $this->executeBuilderEach($message, $args),
            default => throw new QueryException(sprintf('Unsupported builder terminator: %s', $method)),
        };
    }

    /**
     * Internal callback entry point for RETURNING delegation from query builders.
     *
     * @internal
     *
     * @param Query $builder  Query builder instance.
     *
     * @return array Result array.
     */
    public function executeBuilderReturning(Query $builder): array
    {
        $message = $this->toSqlMessage($builder->toSql());

        return $this->driver->returning($message, [], $message->getCacheTables());
    }

    /**
     * @param string|Expr ...$columns  Optional columns or expressions to select.
     *
     * @return Select Ready-to-configure SELECT query builder
     */
    public function select(string|Expr ...$columns): Select
    {
        $builder = $this->bindBuilder(new Select());

        if ($columns === []) {
            return $builder;
        }

        return $builder->select(...$columns);
    }

    /**
     * @return Insert The INSERT query builder
     */
    public function insert(): Insert
    {
        return $this->bindBuilder(new Insert());
    }

    /**
     * @return Update The UPDATE query builder
     */
    public function update(): Update
    {
        return $this->bindBuilder(new Update());
    }

    /**
     * @return Delete The DELETE query builder
     */
    public function delete(): Delete
    {
        return $this->bindBuilder(new Delete());
    }

    /**
     * @return Upsert The UPSERT query builder
     */
    public function upsert(): Upsert
    {
        return $this->bindBuilder(new Upsert());
    }

    /**
     * Bind a query builder to this Database coordinator.
     *
     * @template T of Query
     *
     * @param T $builder  Query builder instance.
     *
     * @return T Bound query builder.
     */
    private function bindBuilder(Query $builder): Query
    {
        $builder->bindDatabase($this);
        $builder->engine = $this->driver->engineName();
        $builder->bindDialect($this->queryDialect());

        return $builder;
    }

    /**
     * Resolve the Query dialect for the configured engine.
     *
     * Dialect compilers live in Query; Driver must not import Query. Alias
     * normalization is centralized on Driver::engineKey() — this match uses
     * only canonical engine keys from Driver::engineName().
     *
     * @return Dialect Bound SQL dialect.
     *
     * @throws QueryException If no dialect exists for the engine.
     */
    private function queryDialect(): Dialect
    {
        if ($this->dialect !== null) {
            return $this->dialect;
        }

        $this->dialect = match ($this->driver->engineName()) {
            'pgsql' => new PostgreSql(),
            'sqlite' => new SqliteDialect(),
            'mariadb' => new MariaDb(),
            'sqlserver' => new SqlServerDialect(),
            'sybase' => new Sybase(),
            'oracle' => new OracleDialect(),
            'db2' => new Db2Dialect(),
            'firebird' => new FirebirdDialect(),
            default => throw new QueryException(
                'No SQL dialect available for engine: ' . $this->driver->engineName()
            ),
        };

        return $this->dialect;
    }

    /**
     * Require table hints on raw SQL reads when configured for this connection.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL input.
     * @param ?array                       $tableHints  Optional table hints.
     *
     * @return void No return value.
     *
     * @throws QueryException When hints are required but missing.
     */
    private function assertReadTableHints(string|SqlMessage|BuilderSql $sql, ?array $tableHints): void
    {
        if (!is_string($sql)) {
            return;
        }

        if ($tableHints !== null && $tableHints !== []) {
            return;
        }

        if (Config::cacheStore($this->connectionName) === 'off') {
            return;
        }

        if (!Config::cacheRequireTableHints($this->connectionName)) {
            return;
        }

        throw QueryException::guardrail(
            'Raw SQL read requires table hints when cache.require_table_hints is enabled for connection '
            . $this->connectionName
        );
    }

    /**
     * Normalize sql message.
     *
     * @param string|SqlMessage|BuilderSql $sqlInput    SQL input before normalization.
     * @param array|callable               $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table names used for cache metadata.
     *
     * @return SqlMessage SQL message value object.
     */
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
            $message = $message->withCacheTables($overrideTables);
        }

        return $message;
    }

    /**
     * Execute builder each.
     *
     * @param SqlMessage $message  SQL message to execute.
     * @param array      $args     Connection name and optional config file path arguments.
     *
     * @return int Integer result.
     *
     * @throws QueryException If the operation fails.
     */
    private function executeBuilderEach(SqlMessage $message, array $args): int
    {
        $callback = $args[0] ?? null;

        if (!is_callable($callback)) {
            throw QueryException::guardrail('each() requires a callback');
        }

        return $this->driver->each($message, [], $callback, $message->getCacheTables());
    }

    /**
     * To sql message.
     *
     * @param BuilderSql $sql  SQL string, SQL message, or builder SQL object.
     *
     * @return SqlMessage SQL message value object.
     */
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
            $sql->isUnsafe()
        );
    }
}
