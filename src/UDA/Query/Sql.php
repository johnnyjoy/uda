<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query
 * @author James Dornan <james.dornan@uda.example.com>
 * @license MIT
 * @link https://docs.uda.example.com/query/sql
 * @since 1.0.0
 */

/*
 * Purpose: Encapsulates parameterized SQL queries in an immutable value object for type-safe execution and cache management.
 */

namespace UDA\Query;

use UDA\SQL\GuardrailMetadata;

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
 * "SELECT * FROM users WHERE active = :active AND created_at > :date",
 * ['active' => true, 'date' => '2024-01-01'],
 * ['users'] // Cache hint: query reads from 'users' table
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
    private string $statementType;
    private bool $hasWhereClause;
    private bool $hasLimitClause;
    private bool $unsafe;

    /**
     * Creates a new Sql instance representing a parameterized SQL query.
     *
     * @param string   $sql                The SQL query string with named parameter placeholders.
     * @param array    $params             Associative array mapping parameter names to values.
     * @param string[] $cacheTables        Tables this query reads from or writes to.
     * @param array    $returningColumns   Columns requested from RETURNING/OUTPUT.
     * @param ?string  $insertTable        Insert target table.
     * @param array    $insertColumns      Insert target columns.
     * @param array    $valuePlaceholders  Parameter placeholders for inserted values.
     * @param array    $metadata           Guardrail and cache metadata.
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $params,
        public readonly array $cacheTables = [],
        public readonly array $returningColumns = [],
        public readonly ?string $insertTable = null,
        public readonly array $insertColumns = [],
        public readonly array $valuePlaceholders = [],
        array $metadata = []
    ) {
        [$statementType, $hasWhere, $hasLimit, $unsafe] = GuardrailMetadata::normalize($metadata);
        $this->statementType = $statementType;
        $this->hasWhereClause = $hasWhere;
        $this->hasLimitClause = $hasLimit;
        $this->unsafe = $unsafe;
    }

    /**
     * SQL text as constructed (named placeholders only; values live in `getParams()`).
     *
     * @return string
     */
    public function getQuery(): string
    {
        return $this->sql;
    }

    /**
     * Parameter values as provided at construction (same keys as placeholders).
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Construct immutable `Sql` with optional cache tables and RETURNING metadata.
     *
     * @param string   $sql                SQL with named placeholders
     * @param array    $params             Parameter values
     * @param string[] $cacheTables        Tables for cache attribution
     * @param array    $returningColumns   RETURNING / OUTPUT columns
     * @param ?string  $insertTable        Insert target table
     * @param array    $insertColumns      Insert target columns
     * @param array    $valuePlaceholders  Placeholders for inserted values
     * @param array    $metadata           Guardrail / cache metadata
     *
     * @return self
     */
    public static function of(string $sql, array $params = [], array $cacheTables = [], array $returningColumns = [], ?string $insertTable = null, array $insertColumns = [], array $valuePlaceholders = [], array $metadata = []): self
    {
        return new self($sql, $params, $cacheTables, $returningColumns, $insertTable, $insertColumns, $valuePlaceholders, $metadata);
    }

    /**
     * Tables used for cache attribution / invalidation metadata.
     *
     * @return string[]
     */
    public function getCacheTables(): array
    {
        return $this->cacheTables;
    }

    /**
     * Return returning columns.
     *
     * @return array Result array.
     */
    public function getReturningColumns(): array
    {
        return $this->returningColumns;
    }

    /**
     * Return insert table.
     *
     * @return ?string String result, or null when absent.
     */
    public function getInsertTable(): ?string
    {
        return $this->insertTable;
    }

    /**
     * @return string[]
     */
    public function getInsertColumns(): array
    {
        return $this->insertColumns;
    }

    /**
     * @return array<int,array<int,string>>
     */
    public function getValuePlaceholders(): array
    {
        return $this->valuePlaceholders;
    }

    /**
     * Return statement type.
     *
     * @return string String result.
     */
    public function getStatementType(): string
    {
        return $this->statementType;
    }

    /**
     * Report whether has where clause.
     *
     * @return bool Boolean result.
     */
    public function hasWhereClause(): bool
    {
        return $this->hasWhereClause;
    }

    /**
     * Report whether has limit clause.
     *
     * @return bool Boolean result.
     */
    public function hasLimitClause(): bool
    {
        return $this->hasLimitClause;
    }

    /**
     * Report whether is unsafe.
     *
     * @return bool Boolean result.
     */
    public function isUnsafe(): bool
    {
        return $this->unsafe;
    }

    /**
     * Return a copy of this instance with different guardrail metadata.
     *
     * @param string $statementType   Statement type.
     * @param bool   $hasWhereClause  Whether the SQL contains a WHERE clause.
     * @param bool   $hasLimitClause  Whether the SQL contains a LIMIT/OFFSET clause.
     * @param bool   $unsafe          Whether guardrails were bypassed.
     *
     * @return self Configured instance.
     */
    public function withGuardrailMetadata(string $statementType, bool $hasWhereClause, bool $hasLimitClause, bool $unsafe): self
    {
        return new self(
            $this->sql,
            $this->params,
            $this->cacheTables,
            $this->returningColumns,
            $this->insertTable,
            $this->insertColumns,
            $this->valuePlaceholders,
            GuardrailMetadata::package($statementType, $hasWhereClause, $hasLimitClause, $unsafe)
        );
    }

}

