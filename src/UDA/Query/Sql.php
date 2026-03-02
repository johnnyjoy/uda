<?php

declare(strict_types=1);

/**
 * SQL value object compatible with Query domain
 */

namespace UDA\Query;

/**
 * Immutable value object representing a parameterized SQL query.
 *
 * This class serves as the fundamental building block for all database operations
 * in UDA. It encapsulates both the SQL string and its associated parameters in
 * a type-safe, immutable container. By tracking which tables a query operates on
 * (via `$cacheTables`), it enables intelligent cache invalidation without requiring
 * SQL parsing.
 *
 * Design Principles:
 * - **Immutable**: Once created, cannot be modified (enforces predictable behavior)
 * - **Type-safe**: Constructor enforces string SQL and array parameters
 * - **Self-documenting**: Properties explain their purpose through naming
 * - **Cache-aware**: Knows which tables it touches for automatic invalidation
 *
 * Usage Example:
 * ```php
 * $query = Sql::of(
 *     "SELECT * FROM users WHERE active = :active AND created_at > :date",
 *     ['active' => true, 'date' => '2024-01-01'],
 *     ['users'] // Cache hint: query reads from 'users' table
 * );
 * ```
 *
 * Cache Mechanism:
 * When a table is modified (INSERT/UPDATE/DELETE), its modification timestamp is
 * updated. Cache entries store the timestamp when they were created. On subsequent
 * reads, if the cache entry's timestamp predates any table's modification timestamp,
 * the cache is considered stale and fresh data is fetched.
 */
class Sql
{
    /**
     * Creates a new Sql instance representing a parameterized SQL query.
     *
     * @param string $sql The SQL query string with named parameter placeholders
     *                    (e.g., ":name", ":email"). Never concatenate values directly
     *                    into SQL strings to prevent injection vulnerabilities.
     * @param array $params Associative array mapping parameter names to values.
     *                      Example: ['name' => 'Alice', 'email' => 'alice@example.com']
     * @param string[] $cacheTables Tables this query reads from or writes to.
     *                              Used by the cache system to invalidate stale entries
     *                              when these tables are modified. For SELECT queries,
     *                              list all tables referenced (including joins). For
     *                              INSERT/UPDATE/DELETE, list the target table(s).
     *                              Defaults to empty array (no cache involvement).
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $params,
        public readonly array $cacheTables = []
    ) {}

    /**
     * Returns the SQL query string as provided during construction.
     *
     * This method provides access to the raw SQL for debugging, logging, or
     * when the SQL needs to be passed to systems that don't understand Sql objects.
     * The returned string contains parameter placeholders, not actual values.
     *
     * @return string The SQL query string with parameter placeholders
     */
    public function getQuery(): string
    {
        return $this->sql;
    }

    /**
     * Returns the parameters bound to this SQL query.
     *
     * The parameters are returned exactly as provided during construction,
     * preserving their types and the associative array structure. These parameters
     * should be passed to PDO's prepared statement execution methods.
     *
     * @return array The query parameters as associative array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Factory method for creating Sql instances with explicit cache attribution.
     *
     * This is the recommended way to create Sql objects as it makes cache
     * involvement explicit and self-documenting. The method name `of()` follows
     * the convention of value object factories (e.g., `Money::of(100, 'USD')`).
     *
     * Example with cache attribution:
     * ```php
     * // Query reads from 'users' table - cache will be invalidated when users changes
     * $query = Sql::of(
     *     "SELECT * FROM users WHERE id = :id",
     *     ['id' => 123],
     *     ['users']
     * );
     * ```
     *
     * Example without cache involvement:
     * ```php
     * // Raw SQL without cache tracking (use for administrative queries, etc.)
     * $query = Sql::of("SET time_zone = :tz", ['tz' => 'UTC']);
     * ```
     *
     * @param string $sql The SQL query string with named parameter placeholders
     * @param array $params Associative array of parameter values
     * @param string[] $cacheTables Tables this query operates on for cache invalidation
     * @return self New immutable Sql instance
     */
    public static function of(string $sql, array $params = [], array $cacheTables = []): self
    {
        return new self($sql, $params, $cacheTables);
    }

    /**
     * Returns tables this query operates on for cache invalidation purposes.
     *
     * The cache system uses this information to track when tables are modified.
     * When a table's modification timestamp changes, all cache entries that
     * involve that table (according to their `getCacheTables()` return value)
     * are considered stale and will be refetched on next access.
     *
     * For SELECT queries involving joins, return all tables:
     * ```php
     * // Query: SELECT * FROM users u JOIN orders o ON u.id = o.user_id
     * return ['users', 'orders'];
     * ```
     *
     * @return string[] Array of table names this query reads from or writes to
     */
    public function getCacheTables(): array
    {
        return $this->cacheTables;
    }
}
