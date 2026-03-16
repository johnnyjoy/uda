<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/query/upsert
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

    public function __construct()
    {
        parent::__construct();
        $this->setStatementType('upsert');
    }

    public function __clone()
    {
        parent::__clone();
        $this->cachedSql = null;
    }

    /**
     *
     * @param  string $table The table name
     * @return self
     */
    public function into(string $table): self
    {
        $clone = clone $this;
        $clone->table = $table;

        return $clone;
    }

    /**
     *
     * @param  array $row The values to insert
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
     * @param  array $rows Array of associative arrays
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
     *
     * @param  array $columns The conflict keys
     * @return self
     */
    public function key(array $columns): self
    {
        $clone = clone $this;
        $clone->conflictKeys = $columns;

        return $clone;
    }

    /**
     *
     * @param  array $columns The columns to update
     * @return self
     */
    public function update(array $columns): self
    {
        $clone = clone $this;
        $clone->updates = $columns;

        return $clone;
    }

    /**
     *
     * @return self
     */
    public function doNothing(): self
    {
        $clone = clone $this;
        $clone->doNothing = true;

        return $clone;
    }

    /**
     *
     * @return int            The number of affected rows
     * @throws QueryException If not bound to a driver
     */
    public function exec(): int
    {
        return $this->delegateThroughDatabase('exec');
    }

    public function explain(): array
    {
        return $this->delegateThroughDatabase('explain');
    }

    public function explainAnalyze(): array
    {
        return $this->delegateThroughDatabase('explainAnalyze');
    }

    // Query builders are immutable and do not execute - they only produce Sql objects
    // Execution is handled exclusively by the Driver class

    /**
     * Generates a SQL UPSERT (INSERT ON CONFLICT) statement.
     *
     * This method constructs a PostgreSQL-style UPSERT statement with proper
     * conflict detection and resolution logic. It supports both "DO NOTHING"
     * and "DO UPDATE SET" conflict handling strategies.
     *
     * @return Sql            The executable SQL UPSERT statement with named parameters
     * @throws QueryException If no table is defined (via into() method)
     * @throws QueryException If no values are provided (via values() method)
     * @throws QueryException If conflict keys are not specified (via key() method)
     *
     * @see Upsert::values() Method to specify data to insert
     * @see Upsert::key() Method to define conflict detection columns
     * @see Upsert::update() Method to specify columns to update on conflict
     * @see Upsert::doNothing() Method to specify "DO NOTHING" behavior
     * @example
     * $query = new Upsert();
     * $sql = $query->into('users')
     * ->values(['email' => 'john@example.com', 'name' => 'John'])
     * ->key(['email'])
     * ->update(['name'])
     * ->toSql();
     * // Returns: Sql object with "INSERT INTO users (email, name) VALUES (:p0, :p1)
     * // ON CONFLICT (email) DO UPDATE SET name = EXCLUDED.name"
     * @note This generates PostgreSQL syntax; other databases may require different syntax.
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

    protected function fingerprintPayload(): array
    {
        return [
            'table' => $this->table,
            'valuesColumns' => array_keys($this->values),
            'rowsStructure' => array_map(static fn (array $row): array => array_keys($row), $this->rows),
            'rowsCount' => count($this->rows),
            'conflictKeys' => $this->conflictKeys,
            'updates' => $this->updates,
            'doNothing' => $this->doNothing,
        ];
    }
}
