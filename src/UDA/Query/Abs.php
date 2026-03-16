<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/query/abs
 * @since 1.0.0
 */

/*
 * Purpose: Base query builder providing shared infrastructure for all concrete query builders.
 */

namespace UDA\Query;

use JsonException;
use UDA\Exception\QueryException;
use UDA\Query\Dialect\Dialect;
use UDA\SQL\Identifier;
use UDA\SQL\ParamBag;
use UDA\SQL\GuardrailMetadata;
use UDA\SQL\SqlMessage;
use UDA\SQL\Value;

/**
 * Abstract base class for all query builders implementing the builder pattern.
 */
abstract class Abs
{
    /**
     *
     * @return Sql The SQL representation of this query
     */
    abstract public function toSql(): Sql;

    /** @var ?\UDA\Driver Driver instance for compatibility */
    public ?\UDA\Driver $driverInstance = null;
    /** @var string Driver name for quoting */
    public string $driverName = '';

    /** @var ?\UDA\Database Originating Database instance for execution delegation */
    private ?\UDA\Database $databaseInstance = null;

    /** @var ?Dialect Dialect assigned to this builder */
    private ?Dialect $dialect = null;

    /** @var ParamBag Parameter bag for storing query parameters */
    protected ParamBag $params;

    /** @var ?string Built WHERE clause from WhereBuilder */
    protected ?string $builtWhere = null;

    /** @var ?string Built HAVING clause from WhereBuilder */
    protected ?string $builtHaving = null;

    /** @var array Cache for quoted identifiers */
    private array $quotedIdentifiers = [];

    /** Guardrail metadata */
    private string $statementType = 'raw';
    private bool $hasWhereClause = false;
    private bool $hasLimitClause = false;
    private bool $unsafe = false;
    private ?bool $retryAllowed = null;

    /**
     * Initializes a new query builder with empty parameter storage.
     *
     * The parameter bag uses 'q' as the default prefix for parameter names
     * (e.g., `:q1`, `:q2`). This prevents collisions when multiple queries
     * are combined or when subqueries are nested.
     */
    public function __construct()
    {
        $this->params = new ParamBag('q');
        $this->statementType = 'raw';
    }

    /**
     * Converts a value to a named parameter placeholder and stores the value.
     *
     * This method is the core of UDA's SQL injection protection. Rather than
     * concatenating values directly into SQL strings, it:
     * 1. Generates a unique parameter name (e.g., `:q123`)
     * 2. Stores the value in the parameter bag
     * 3. Returns the placeholder for use in the SQL string
     *
     * Example:
     * ```php
     * // Instead of: "WHERE id = " . $userId (DANGEROUS!)
     * // Use: "WHERE id = " . $this->param($userId) // Returns ":q1"
     * ```
     *
     * The stored values are later bound to a PDO prepared statement, ensuring
     * proper escaping and type handling by the database driver.
     *
     * @param  mixed  $value The value to parameterize (any PHP type)
     * @return string Named parameter placeholder (e.g., `:q1`, `:q2`)
     */
    protected function param(mixed $value): string
    {
        return Value::param($this->params, $value);
    }

    /**
     *
     * @param  string         $identifier The identifier to quote
     * @return string         The quoted identifier
     * @throws QueryException If the identifier is invalid
     */
    protected function quote(string $identifier): string
    {
        if (!isset($this->quotedIdentifiers[$identifier])) {
            try {
                // Use stored driver name instead of accessing driver directly (spec compliance)
                $this->quotedIdentifiers[$identifier] = (new Identifier($identifier))->quoted($this->driverName);
            } catch (\Throwable $ex) {
                throw new QueryException('Invalid identifier: ' . $identifier, 0, $ex);
            }
        }

        return $this->quotedIdentifiers[$identifier];
    }

    /**
     *
     * @param  string     $query The SQL query string
     * @return SqlMessage The constructed SqlMessage
     */
    protected function buildSql(string $query): SqlMessage
    {
        $message = new SqlMessage($query, $this->params->getParams());
        $message = $message->withGuardrailMetadata(
            $this->statementType,
            $this->hasWhereClause,
            $this->hasLimitClause,
            $this->unsafe
        );

        if ($this->retryAllowed === null) {
            return $message;
        }

        return $message->withRetryAllowed($this->retryAllowed);
    }

    /**
     * Set the WHERE clause built by WhereBuilder
     *
     * @param string $whereClause The WHERE clause SQL fragment
     */
    public function setWhereClause(string $whereClause): void
    {
        $this->builtWhere = $whereClause;
        if ($whereClause !== '') {
            $this->markWhereUsed();
        }
    }

    /**
     * Set the HAVING clause built by WhereBuilder
     *
     * @param string $havingClause The HAVING clause SQL fragment
     */
    public function setHavingClause(string $havingClause): void
    {
        $this->builtHaving = $havingClause;
    }

    public function unsafe(): static
    {
        $this->unsafe = true;

        return $this;
    }

    public function bindDatabase(\UDA\Database $database): void
    {
        $this->databaseInstance = $database;
    }

    public function bindDialect(Dialect $dialect): void
    {
        $this->dialect = $dialect;
    }

    protected function delegateThroughDatabase(string $method, mixed ...$args): mixed
    {
        if ($this->databaseInstance !== null) {
            return $this->databaseInstance->executeBuilder($this, $method, ...$args);
        }

        $sql = $this->toSql();

        if ($this->driverInstance !== null) {
            return $this->driverInstance->$method($sql, ...$args);
        }

        throw $this->executionBindingException();
    }

    protected function delegateReturning(): array
    {
        if ($this->databaseInstance !== null) {
            return $this->databaseInstance->executeBuilderReturning($this);
        }

        $sql = $this->toSql();

        if ($this->driverInstance !== null) {
            return $this->driverInstance->returning($sql);
        }

        throw $this->executionBindingException();
    }

    protected function executeSql(string $method, Sql $sql, mixed ...$args): mixed
    {
        if ($this->databaseInstance !== null) {
            return $this->databaseInstance->$method($sql, ...$args);
        }

        if ($this->driverInstance !== null) {
            return $this->driverInstance->$method($sql, ...$args);
        }

        throw $this->executionBindingException();
    }

    protected function requireDialect(): Dialect
    {
        if ($this->dialect === null) {
            throw new QueryException(sprintf(
                '%s is not bound to a Query\Dialect. Ensure Database::connect() selected a dialect.',
                static::class
            ));
        }

        return $this->dialect;
    }

    public function __clone()
    {
        $this->params = clone $this->params;
    }

    protected function boundDialect(): ?Dialect
    {
        return $this->dialect;
    }

    /**
     * @param callable(Dialect):bool $capabilityCheck
     */
    protected function assertDialectCapability(callable $capabilityCheck, string $errorMessage): void
    {
        $dialect = $this->dialect;

        if ($dialect === null) {
            return;
        }

        if (!$capabilityCheck($dialect)) {
            throw new QueryException(sprintf($errorMessage, $dialect->name()));
        }
    }

    public function fingerprint(): string
    {
        $payload = [
            'class' => static::class,
            'structure' => $this->fingerprintPayload(),
        ];

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            /** @var string $encoded */
            $encoded = serialize($payload);
        }

        return hash('sha256', $encoded);
    }

    abstract protected function fingerprintPayload(): array;

    private function executionBindingException(): QueryException
    {
        return new QueryException(sprintf(
            '%s is not bound to a Database. Obtain builders via Database::select/insert/etc. before calling terminators.',
            static::class
        ));
    }

    protected function setStatementType(string $statementType): void
    {
        $this->statementType = GuardrailMetadata::normalizeType($statementType);
    }

    protected function markWhereUsed(): void
    {
        $this->hasWhereClause = true;
    }

    protected function markLimitUsed(): void
    {
        $this->hasLimitClause = true;
    }

    protected function applyGuardrailMetadata(Sql $sql): Sql
    {
        $sql = $sql->withGuardrailMetadata(
            $this->statementType,
            $this->hasWhereClause,
            $this->hasLimitClause,
            $this->unsafe
        );

        if ($this->retryAllowed === null) {
            return $sql;
        }

        return $sql->withRetryAllowed($this->retryAllowed);
    }

    public function allowRetry(): static
    {
        $clone = clone $this;
        $clone->retryAllowed = true;

        return $clone;
    }

    public function noRetry(): static
    {
        $clone = clone $this;
        $clone->retryAllowed = false;

        return $clone;
    }
}
