<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query
 * @license MIT
 * @link https://github.com/johnnyjoy/uda/blob/master/docs/public-api.md
 * @since 1.0.0
 */

/*
 * Purpose: Base query builder providing shared infrastructure for all concrete query builders.
 *
 * `Abs` = abstract base class (historical short name). Application code uses
 * `Database::select()` / `insert()` / … — not this type directly. See
 * `docs/public-api.md` §3.1.
 */

namespace UDA\Query;

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
     * Materialize this builder as an immutable `Sql` value for execution or inspection.
     *
     * @return Sql Immutable SQL representation of this builder.
     */
    abstract public function toSql(): Sql;

    /**
     * Configured engine key used for identifier quoting.
     *
     * @internal Set exclusively by Database::bindBuilder(). Do not write from application code.
     *
     * @var string
     */
    public string $engine = '';

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


    /**
     * Initializes a new query builder with empty parameter storage.
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
     * Store $value in the bag and return a fresh named placeholder (e.g. `:q1`).
     *
     * @param mixed $value  Value to bind
     *
     * @return string Placeholder token
     */
    protected function param(mixed $value): string
    {
        return Value::param($this->params, $value);
    }

    /**
     * @param string $identifier  The identifier to quote
     *
     * @return string The quoted identifier
     *
     * @throws QueryException If the identifier is invalid
     */
    protected function quote(string $identifier): string
    {
        if (!isset($this->quotedIdentifiers[$identifier])) {
            try {
                $this->quotedIdentifiers[$identifier] = (new Identifier($identifier))->quoted($this->engine);
            } catch (\Throwable $ex) {
                throw new QueryException('Invalid identifier: ' . $identifier, 0, $ex);
            }
        }

        return $this->quotedIdentifiers[$identifier];
    }

    /**
     * @param string $query  The SQL query string
     *
     * @return SqlMessage The constructed SqlMessage
     */
    protected function buildSql(string $query): SqlMessage
    {
        $message = new SqlMessage($query, $this->params->getParams());

        return $message->withGuardrailMetadata(
            $this->statementType,
            $this->hasWhereClause,
            $this->hasLimitClause,
            $this->unsafe
        );
    }

    /**
     * Set the WHERE clause built by WhereBuilder
     *
     * @param string $whereClause  The WHERE clause SQL fragment
     *
     * @return void No return value.
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
     * @param string $havingClause  The HAVING clause SQL fragment
     *
     * @return void No return value.
     */
    public function setHavingClause(string $havingClause): void
    {
        $this->builtHaving = $havingClause;
    }

    /**
     * Bypass WHERE/LIMIT guardrails for this statement.
     *
     * Use only when a statement deliberately has no WHERE clause or LIMIT —
     * for example, a bulk UPDATE that sets a flag on every row in a table,
     * or a DELETE that intentionally empties a table. The guardrail exists
     * to prevent accidental unbounded writes; call unsafe() only after
     * confirming the intent is correct.
     *
     * @return static Configured instance.
     */
    public function unsafe(): static
    {
        $this->unsafe = true;

        return $this;
    }

    /**
     * Bind a Database instance for execution delegation.
     *
     * @internal Called exclusively by Database::bindBuilder(). Do not call from application code.
     *
     * @param \UDA\Database $database  Database handle used for builder execution.
     *
     * @return void No return value.
     */
    public function bindDatabase(\UDA\Database $database): void
    {
        $this->databaseInstance = $database;
    }

    /**
     * Bind the SQL dialect for this connection.
     *
     * @internal Called exclusively by Database::bindBuilder(). Do not call from application code.
     *
     * @param Dialect $dialect  Dialect instance.
     *
     * @return void No return value.
     */
    public function bindDialect(Dialect $dialect): void
    {
        $this->dialect = $dialect;
    }

    /**
     * Delegate through database.
     *
     * @param string $method   Expression helper method name.
     * @param mixed  ...$args  Connection name and optional config file path arguments.
     *
     * @return mixed Execution result.
     */
    protected function delegateThroughDatabase(string $method, mixed ...$args): mixed
    {
        if ($this->databaseInstance !== null) {
            return $this->databaseInstance->executeBuilder($this, $method, ...$args);
        }

        throw $this->executionBindingException();
    }

    /**
     * Delegate returning.
     *
     * @return array Result array.
     */
    protected function delegateReturning(): array
    {
        if ($this->databaseInstance !== null) {
            return $this->databaseInstance->executeBuilderReturning($this);
        }

        throw $this->executionBindingException();
    }

    /**
     * Execute sql.
     *
     * @param string $method   Expression helper method name.
     * @param Sql    $sql      SQL string, SQL message, or builder SQL object.
     * @param mixed  ...$args  Connection name and optional config file path arguments.
     *
     * @return mixed Execution result.
     */
    protected function executeSql(string $method, Sql $sql, mixed ...$args): mixed
    {
        if ($this->databaseInstance !== null) {
            return $this->databaseInstance->$method($sql, ...$args);
        }

        throw $this->executionBindingException();
    }

    /**
     * Require dialect.
     *
     * @return Dialect Bound SQL dialect.
     *
     * @throws QueryException If the operation fails.
     */
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

    /**
     *   clone.
     *
     * @return mixed Execution result.
     */
    public function __clone()
    {
        $this->params = clone $this->params;
    }

    /**
     * Bound dialect.
     *
     * @return ?Dialect Bound SQL dialect, or null before binding.
     */
    protected function boundDialect(): ?Dialect
    {
        return $this->dialect;
    }

    /**
     * Assert dialect capability.
     *
     * @param callable(Dialect):bool $capabilityCheck
     * @param string                 $errorMessage     Error message to raise when the capability is missing.
     *
     * @return void No return value.
     *
     * @throws QueryException If the operation fails.
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

    /**
     * Execution binding exception.
     *
     * @return QueryException Exception describing the binding failure.
     */
    private function executionBindingException(): QueryException
    {
        return new QueryException(sprintf(
            '%s is not bound to a Database. Obtain builders via Database::select/insert/etc. before calling terminators.',
            static::class
        ));
    }

    /**
     * Set statement type.
     *
     * @param string $statementType  Statement type.
     *
     * @return void No return value.
     */
    protected function setStatementType(string $statementType): void
    {
        $this->statementType = GuardrailMetadata::normalizeType($statementType);
    }

    /**
     * Mark where used.
     *
     * @return void No return value.
     */
    protected function markWhereUsed(): void
    {
        $this->hasWhereClause = true;
    }

    /**
     * Mark limit used.
     *
     * @return void No return value.
     */
    protected function markLimitUsed(): void
    {
        $this->hasLimitClause = true;
    }

    /**
     * Apply guardrail metadata.
     *
     * @param Sql $sql  SQL string, SQL message, or builder SQL object.
     *
     * @return Sql Compiled SQL message.
     */
    protected function applyGuardrailMetadata(Sql $sql): Sql
    {
        $sql = $sql->withGuardrailMetadata(
            $this->statementType,
            $this->hasWhereClause,
            $this->hasLimitClause,
            $this->unsafe
        );

        return $sql;
    }

}

