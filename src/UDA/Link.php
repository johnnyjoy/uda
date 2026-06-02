<?php

declare(strict_types=1);

namespace UDA;

use UDA\Query\Expr;
use UDA\Query\Sql as BuilderSql;
use UDA\SQL\SqlMessage;

/**
 * @package UDA
 * @author James Dornan <james@catch22.com>
 * @license MIT
 * @link https://docs.uda.example.com/core/link
 * @since 1.0.0
 */

/*
 * Purpose: Optional link for external abstraction classes built around one connection.
 *
 * Link lets application classes hold their own SQL methods without extending
 * Database. The connection name and Database handle are class-level (static)
 * because they are facts about the class, not about any individual instance.
 * The handle is memoized once per class, not once per object.
 */

/**
 * Provides protected Database-like methods for one configured connection.
 *
 * Declare the connection name as a static property in the consuming class:
 *
 *   protected static string $connection = 'hr';
 *
 * All instances of that class share one Database handle, which is correct
 * because the connection never varies between instances of the same class.
 */
trait Link
{
    /**
     * Per-class memoized Database handle.
     *
     * Each consuming class gets its own copy of this static property because
     * PHP traits with static properties are per-class, not per-trait.
     * After the first call to handle(), this holds the Database instance and
     * subsequent calls cost exactly one null check — no syscalls, no Config reads.
     *
     * @var ?Database
     */
    private static ?Database $handle = null;

    /**
     * Return the shared Database handle for this class's configured connection.
     *
     * The consuming class must declare:
     *
     *   protected static string $connection = 'name';
     *
     * This property is not declared in the trait because PHP forbids a trait and
     * its consuming class from declaring the same property with different defaults.
     * Ownership belongs to the class — the connection is a fact about the class.
     *
     * @return Database Public UDA database handle.
     */
    private static function handle(): Database
    {
        return static::$handle ??= Database::connect(static::$connection);
    }

    /**
     * Execute a query and return all matching rows.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints.
     *
     * @return array Result rows.
     */
    protected function rows(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): array
    {
        return static::handle()->rows($sql, $params, $tableHints);
    }

    /**
     * Execute and return one row.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints.
     *
     * @return ?array The single row result or null.
     */
    protected function row(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): ?array
    {
        return static::handle()->row($sql, $params, $tableHints);
    }

    /**
     * Execute and return a single value.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints.
     *
     * @return mixed The single value result.
     */
    protected function value(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): mixed
    {
        return static::handle()->value($sql, $params, $tableHints);
    }

    /**
     * Execute and return the first column from every row.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints.
     *
     * @return array The values from the first column.
     */
    protected function values(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): array
    {
        return static::handle()->values($sql, $params, $tableHints);
    }

    /**
     * Execute and return the first row as a numeric list.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints.
     *
     * @return ?array<int,mixed> Row values or null.
     */
    protected function list(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): ?array
    {
        return static::handle()->list($sql, $params, $tableHints);
    }

    /**
     * Execute a query and pass each row to a callback.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array|callable               $params      Named parameter values or callback.
     * @param ?callable                    $fn          Callback to execute.
     * @param ?array                       $tableHints  Optional table hints.
     *
     * @return int The number of rows processed.
     */
    protected function each(
        string|SqlMessage|BuilderSql $sql,
        array|callable $params,
        ?callable $fn = null,
        ?array $tableHints = null
    ): int {
        return static::handle()->each($sql, $params, $fn, $tableHints);
    }

    /**
     * Execute a write statement.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints.
     *
     * @return int The number of affected rows.
     */
    protected function exec(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): int
    {
        return static::handle()->exec($sql, $params, $tableHints);
    }

    /**
     * Execute a DML statement with RETURNING/OUTPUT semantics.
     *
     * @param string|SqlMessage|BuilderSql $sql         SQL string, SQL message, or builder SQL object.
     * @param array                        $params      Named parameter values.
     * @param ?array                       $tableHints  Optional table hints.
     *
     * @return array Result rows.
     */
    protected function returning(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tableHints = null): array
    {
        return static::handle()->returning($sql, $params, $tableHints);
    }

    /**
     * Execute a callback within a transaction.
     *
     * @param callable $fn  Callback to execute.
     *
     * @return mixed Execution result.
     */
    protected function transaction(callable $fn): mixed
    {
        return static::handle()->transaction(fn (): mixed => $fn());
    }

    /**
     * Create a SELECT builder for this connection.
     *
     * @param string|Expr ...$columns  Optional columns or expressions to select.
     *
     * @return \UDA\Query\Select Ready-to-configure SELECT query builder.
     */
    protected function select(string|Expr ...$columns): \UDA\Query\Select
    {
        return static::handle()->select(...$columns);
    }

    /**
     * Create an INSERT builder for this connection.
     *
     * @return \UDA\Query\Insert Ready-to-configure INSERT query builder.
     */
    protected function insert(): \UDA\Query\Insert
    {
        return static::handle()->insert();
    }

    /**
     * Create an UPDATE builder for this connection.
     *
     * @return \UDA\Query\Update Ready-to-configure UPDATE query builder.
     */
    protected function update(): \UDA\Query\Update
    {
        return static::handle()->update();
    }

    /**
     * Create a DELETE builder for this connection.
     *
     * @return \UDA\Query\Delete Ready-to-configure DELETE query builder.
     */
    protected function delete(): \UDA\Query\Delete
    {
        return static::handle()->delete();
    }

    /**
     * Create an UPSERT builder for this connection.
     *
     * @return \UDA\Query\Upsert Ready-to-configure UPSERT query builder.
     */
    protected function upsert(): \UDA\Query\Upsert
    {
        return static::handle()->upsert();
    }

    /**
     * Validate and quote an identifier for this connection.
     *
     * @param string $identifier  Identifier value.
     *
     * @return string Quoted identifier.
     */
    protected function q(string $identifier): string
    {
        return static::handle()->q($identifier);
    }

    /**
     * Build an allowlisted ORDER BY fragment.
     *
     * @param string $column     Column name or expression.
     * @param array  $allowlist  Allowed column names.
     * @param string $direction  Sort direction.
     *
     * @return string ORDER BY fragment.
     */
    protected function orderByAllowed(string $column, array $allowlist, string $direction = 'ASC'): string
    {
        return static::handle()->orderByAllowed($column, $allowlist, $direction);
    }

    /**
     * Build an engine-specific limit/offset fragment.
     *
     * @param int $limit   Maximum number of rows.
     * @param int $offset  Number of rows to skip.
     *
     * @return string LIMIT/OFFSET fragment.
     */
    protected function limitOffset(int $limit, int $offset): string
    {
        return static::handle()->limitOffset($limit, $offset);
    }

    /**
     * Build a named-parameter IN list fragment.
     *
     * @param array  $values  Values to process.
     * @param string $hint    Parameter name hint.
     *
     * @return array{0:string,1:array<string,mixed>} IN list fragment and parameters.
     */
    protected function inList(array $values, string $hint = 'p'): array
    {
        return static::handle()->inList($values, $hint);
    }

    /**
     * Return the last SQL executed through this link.
     *
     * @return ?string Last SQL string or null.
     */
    protected function lastSql(): ?string
    {
        return static::handle()->lastSql();
    }

    /**
     * Return the last parameters executed through this link.
     *
     * @return array Last bound parameters.
     */
    protected function lastParams(): array
    {
        return static::handle()->lastParams();
    }
}
