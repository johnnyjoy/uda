<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage SQL
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/sql/sqlmessage
 * @since 1.0.0
 */

/*
 * Purpose: SQL value object representing a query string and its parameters in UDA.
 */

namespace UDA\SQL;

/**
 * SQL value object representing a query string and its parameters
 */
final class SqlMessage
{
    private string $query;
    private array $params;
    private array $cacheTables;
    private array $returningColumns;
    private ?string $insertTable;
    private array $insertColumns;
    private array $valuePlaceholders;
    private ?string $fingerprint;
    private string $statementType;
    private bool $hasWhereClause;
    private bool $hasLimitClause;
    private bool $unsafe;
    private ?bool $retryAllowed;

    /**
     * Creates a new SQL message object encapsulating a query with its parameters.
     *
     * This value object pairs a SQL string with its corresponding named parameters,
     * ensuring they remain together as a single, atomic unit throughout the system.
     *
     * @param string               $query            SQL query string with named placeholders (e.g., ":id", ":name")
     * @param array<string, mixed> $params           Named parameters matching placeholders in the query
     * @param string[]             $cacheTables      Tables this SQL touches (for cache invalidation)
     * @param string[]             $returningColumns Columns requested via RETURNING/OUTPUT
     * @param string|null          $insertTable      Raw table name for INSERT statements (if applicable)
     * @param string[]             $insertColumns    Column names involved in INSERT statements
     * @param array<int,array<int,string>> $valuePlaceholders Placeholder sets per value group (INSERT)
     *
     * @see Driver::exec() Execution method accepting SqlMessage objects
     * @example
     * $sql = new SqlMessage(
     * "SELECT * FROM users WHERE id = :id AND status = :status",
     * ['id' => 123, 'status' => 'active']
     * );
     */
    public function __construct(string $query, array $params = [], array $cacheTables = [], array $returningColumns = [], ?string $insertTable = null, array $insertColumns = [], array $valuePlaceholders = [], string $statementType = 'raw', bool $hasWhereClause = false, bool $hasLimitClause = false, bool $unsafe = false, ?string $fingerprint = null, ?bool $retryAllowed = null)
    {
        $this->query = $query;
        $this->params = $params;
        $this->cacheTables = $cacheTables;
        $this->returningColumns = $returningColumns;
        $this->insertTable = $insertTable;
        $this->insertColumns = $insertColumns;
        $this->valuePlaceholders = $valuePlaceholders;
        $this->fingerprint = $fingerprint;
        $this->retryAllowed = $retryAllowed;

        [$type, $hasWhere, $hasLimit, $isUnsafe] = GuardrailMetadata::normalize([
            'statementType' => $statementType,
            'hasWhere' => $hasWhereClause,
            'hasLimit' => $hasLimitClause,
            'unsafe' => $unsafe,
        ]);

        $this->statementType = $type;
        $this->hasWhereClause = $hasWhere;
        $this->hasLimitClause = $hasLimit;
        $this->unsafe = $isUnsafe;
    }

    /**
     * Retrieves the SQL query string from this message.
     *
     * Returns the raw SQL string with named parameter placeholders intact.
     * Use getParams() to obtain the corresponding parameter values.
     *
     * @return string SQL query string with named placeholders
     *
     * @see SqlMessage::getParams() Companion method for parameter retrieval
     * @example
     * $query = $sql->getQuery();
     * // Returns: "SELECT * FROM users WHERE id = :id"
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Retrieves the named parameters associated with this SQL message.
     *
     * Returns an associative array where keys match named placeholders
     * in the query string (without the leading colon).
     *
     * @return array<string, mixed> Named parameter values indexed by placeholder name
     *
     * @see SqlMessage::getQuery() Companion method for SQL retrieval
     * @example
     * $params = $sql->getParams();
     * // Returns: ['id' => 123, 'status' => 'active']
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @return string[]
     */
    public function getCacheTables(): array
    {
        return $this->cacheTables;
    }

    /**
     * @return string[]
     */
    public function getReturningColumns(): array
    {
        return $this->returningColumns;
    }

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
     * Create a new Sql object with a different query
     *
     * @param  string $query The new query string
     * @return self
     */
    public function withQuery(string $query): self
    {
        return new self(
            $query,
            $this->params,
            $this->cacheTables,
            $this->returningColumns,
            $this->insertTable,
            $this->insertColumns,
            $this->valuePlaceholders,
            $this->statementType,
            $this->hasWhereClause,
            $this->hasLimitClause,
            $this->unsafe,
            $this->fingerprint,
            $this->retryAllowed
        );
    }

    /**
     * @param string[] $cacheTables
     */
    public function withCacheTables(array $cacheTables): self
    {
        return new self(
            $this->query,
            $this->params,
            $cacheTables,
            $this->returningColumns,
            $this->insertTable,
            $this->insertColumns,
            $this->valuePlaceholders,
            $this->statementType,
            $this->hasWhereClause,
            $this->hasLimitClause,
            $this->unsafe,
            $this->fingerprint,
            $this->retryAllowed
        );
    }

    public function getStatementType(): string
    {
        return $this->statementType;
    }

    public function hasWhereClause(): bool
    {
        return $this->hasWhereClause;
    }

    public function hasLimitClause(): bool
    {
        return $this->hasLimitClause;
    }

    public function isUnsafe(): bool
    {
        return $this->unsafe;
    }

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function withFingerprint(?string $fingerprint): self
    {
        return new self(
            $this->query,
            $this->params,
            $this->cacheTables,
            $this->returningColumns,
            $this->insertTable,
            $this->insertColumns,
            $this->valuePlaceholders,
            $this->statementType,
            $this->hasWhereClause,
            $this->hasLimitClause,
            $this->unsafe,
            $fingerprint,
            $this->retryAllowed
        );
    }

    public function withGuardrailMetadata(string $statementType, bool $hasWhereClause, bool $hasLimitClause, bool $unsafe): self
    {
        return new self(
            $this->query,
            $this->params,
            $this->cacheTables,
            $this->returningColumns,
            $this->insertTable,
            $this->insertColumns,
            $this->valuePlaceholders,
            GuardrailMetadata::normalizeType($statementType),
            $hasWhereClause,
            $hasLimitClause,
            $unsafe,
            $this->fingerprint,
            $this->retryAllowed
        );
    }

    public function getRetryAllowed(): ?bool
    {
        return $this->retryAllowed;
    }

    public function withRetryAllowed(?bool $retryAllowed): self
    {
        return new self(
            $this->query,
            $this->params,
            $this->cacheTables,
            $this->returningColumns,
            $this->insertTable,
            $this->insertColumns,
            $this->valuePlaceholders,
            $this->statementType,
            $this->hasWhereClause,
            $this->hasLimitClause,
            $this->unsafe,
            $this->fingerprint,
            $retryAllowed
        );
    }

    /**
     * Convert to string representation
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->query;
    }

}
