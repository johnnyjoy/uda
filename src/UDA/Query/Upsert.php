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
 * Purpose: Builds UPSERT SQL queries without executing them.
 */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\Query\Dialect\UpsertState;

/**
 * UPSERT query builder that produces Sql objects for execution
 */
final class Upsert extends Abs
{
    /** @var ?string Table to upsert into */
    private ?string $table = null;

    /** @var array Values to insert (single row) */
    private array $values = [];

    /** @var array Rows for bulk insert */
    private array $rows = [];

    /** @var array Conflict keys */
    private array $conflictKeys = [];

    /** @var array Columns to update */
    private array $updates = [];

    /** @var bool Whether to do nothing on conflict */
    private bool $doNothing = false;

    /** @var ?Sql Cached Sql instance for repeated toSql() calls */
    private ?Sql $cachedSql = null;

    /**
     * Create the runtime object.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setStatementType('upsert');
    }

    /**
     *   clone.
     *
     * @return mixed Execution result.
     */
    public function __clone()
    {
        parent::__clone();
        $this->cachedSql = null;
    }

    /**
     * @param string $table  The table name
     *
     * @return self
     */
    public function into(string $table): self
    {
        $clone = clone $this;
        $clone->table = $table;

        return $clone;
    }

    /**
     * @param array $row  The values to insert
     *
     * @return self
     */
    public function values(array $row): self
    {
        $clone = clone $this;
        $clone->values = $row;
        $clone->rows = [];

        return $clone;
    }

    /**
     * Set multiple rows for bulk UPSERT
     *
     * @param array $rows  Array of associative arrays
     *
     * @return self
     */
    public function rows(array $rows): self
    {
        $clone = clone $this;
        $clone->rows = $rows;
        $clone->values = [];

        return $clone;
    }

    /**
     * @param array $columns  The conflict keys
     *
     * @return self
     */
    public function key(array $columns): self
    {
        $clone = clone $this;
        $clone->conflictKeys = $columns;

        return $clone;
    }

    /**
     * @param array $columns  The columns to update
     *
     * @return self
     */
    public function update(array $columns): self
    {
        $clone = clone $this;
        $clone->updates = $columns;

        return $clone;
    }

    /**
     * @return self
     */
    public function doNothing(): self
    {
        $clone = clone $this;
        $clone->doNothing = true;

        return $clone;
    }

    /**
     * @return int The number of affected rows
     *
     * @throws QueryException If not bound to a driver
     */
    public function exec(): int
    {
        return $this->delegateThroughDatabase('exec');
    }

    // Query builders produce Sql objects; execution is coordinated by Database.

    /**
     * Build immutable `Sql` for this UPSERT (PostgreSQL-style `ON CONFLICT`).
     *
     * @return Sql
     *
     * @throws QueryException If `into()`, `values()`, or `key()` is incomplete
     */
    public function toSql(): Sql
    {
        if ($this->cachedSql !== null) {
            return $this->cachedSql;
        }

        if ($this->table === null) {
            throw new QueryException('No table defined for upsert query');
        }

        if ($this->values === [] && $this->rows === []) {
            throw new QueryException('No values provided for upsert query');
        }

        if ($this->conflictKeys === []) {
            throw new QueryException('Conflict keys are required for upsert query');
        }

        $state = new UpsertState(
            table: $this->table,
            values: $this->values,
            rows: $this->rows,
            conflictKeys: $this->conflictKeys,
            updates: $this->updates,
            doNothing: $this->doNothing,
            tables: [$this->table],
            params: $this->params,
            parameterize: fn (mixed $value): string => $this->param($value),
            quote: fn (string $identifier): string => $this->quote($identifier)
        );

        $dialect = $this->requireDialect();

        if (!$dialect->supportsUpsert()) {
            throw new QueryException(sprintf('%s dialect does not support UPSERT builders.', $dialect->name()));
        }

        $compiled = $dialect->compileUpsert($state);
        $this->cachedSql = $this->applyGuardrailMetadata($compiled);

        return $this->cachedSql;
    }

}

