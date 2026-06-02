<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/query/update
 * @since 1.0.0
 */

/*
 * Purpose: Builds UPDATE SQL queries without executing them.
 */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\Query\Concerns\ConsumesCtes;
use UDA\Query\Dialect\UpdateState;

/**
 * UPDATE query builder that produces Sql objects for execution
 */
final class Update extends Abs
{
    use ConsumesCtes;
    /** @var ?string Table to update */
    private ?string $table = null;

    /** @var array Column assignments */
    private array $sets = [];

    /** @var array WHERE conditions */
    private array $where = [];

    /** @var array<string> Tables referenced for cache hints */
    private array $hintTables = [];

    /** @var ?array Columns requested via returning() */
    private ?array $returning = null;

    /** @var ?Sql Cached Sql instance for repeated toSql() calls */
    private ?Sql $cachedSql = null;

    /**
     * Create the runtime object.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setStatementType('update');
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
        $this->cloneCtesOnClone();
    }

    /**
     * @param string $table  The table name
     *
     * @return self
     */
    public function table(string $table): self
    {
        $clone = clone $this;
        $clone->table = $table;
        $clone->hintTables = [$table];

        return $clone;
    }

    /**
     * @param string $column  The column name
     * @param mixed  $value   The value to set
     *
     * @return self
     */
    public function set(string $column, mixed $value): self
    {
        $clone = clone $this;
        $clone->sets[$column] = $value;

        return $clone;
    }

    /**
     * @param string $column    The column name
     * @param mixed  $value     The value to compare against
     * @param string $operator  The comparison operator
     *
     * @return self
     */
    public function where(string $column, mixed $value, string $operator = '='): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params, fn ($id) => $clone->quote($id));
        $whereBuilder->where($column, $value, $operator);

        return $whereBuilder;
    }

    /**
     * Where raw.
     *
     * @param string $expression  SQL expression.
     * @param array  $params      Named parameter values.
     *
     * @return WhereBuilder Configured WhereBuilder builder.
     */
    public function whereRaw(string $expression, array $params = []): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params, fn ($id) => $clone->quote($id));
        $whereBuilder->whereRaw($expression, $params);

        return $whereBuilder;
    }

    /**
     * @param string $left      The left column
     * @param string $right     The right column
     * @param string $operator  The comparison operator
     *
     * @return self
     */
    public function whereColumn(string $left, string $right, string $operator = '='): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params, fn ($id) => $clone->quote($id));
        $whereBuilder->whereColumn($left, $right, $operator);

        return $whereBuilder;
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

    /**
     * Returning.
     *
     * @param string ...$columns  Column names.
     *
     * @return self Configured instance.
     */
    public function returning(string ...$columns): self
    {
        $this->assertDialectCapability(
            fn ($dialect) => $dialect->supportsReturning(),
            '%s dialect does not support RETURNING clauses.'
        );

        $clone = clone $this;
        $clone->returning = $columns;

        return $clone;
    }

    /**
     * Execute and return one row.
     *
     * @return ?array Result array.
     *
     * @throws QueryException If the operation fails.
     */
    public function row(): ?array
    {
        if ($this->returning === null) {
            throw new QueryException('Call returning() before requesting returning rows.');
        }

        $rows = $this->delegateReturning();

        return $rows[0] ?? null;
    }

    /**
     * Execute and return all rows.
     *
     * @return array Result array.
     *
     * @throws QueryException If the operation fails.
     */
    public function rows(): array
    {
        if ($this->returning === null) {
            throw new QueryException('Call returning() before requesting returning rows.');
        }

        return $this->delegateReturning();
    }

    /**
     * Execute and return a single value.
     *
     * @return mixed Execution result.
     *
     * @throws QueryException If the operation fails.
     */
    public function value(): mixed
    {
        if ($this->returning === null) {
            throw new QueryException('Call returning() before requesting returning rows.');
        }

        $row = $this->row();

        return $row === null ? null : (array_values($row)[0] ?? null);
    }

    /**
     * Execute RETURNING and return the first row as a numeric list.
     *
     * @return ?array<int,mixed> Row values or null.
     *
     * @throws QueryException If the operation fails.
     */
    public function list(): ?array
    {
        if ($this->returning === null) {
            throw new QueryException('Call returning() before requesting returning rows.');
        }

        $row = $this->row();

        return $row === null ? null : array_values($row);
    }

    // Query builders produce Sql objects; execution is coordinated by Database.

    /**
     * Build immutable `Sql` for this UPDATE.
     *
     * @return Sql
     *
     * @throws QueryException If table or SET columns are missing
     */
    public function toSql(): Sql
    {
        if ($this->cachedSql !== null) {
            return $this->cachedSql;
        }

        if ($this->table === null) {
            throw new QueryException('No table defined for update query');
        }

        if ($this->sets === []) {
            throw new QueryException('No values set for update query');
        }

        $originalParams = $this->params;
        $this->params = clone $this->params;

        try {
            if ($this->hintTables === [] && $this->table !== null) {
                $this->addHintTable($this->table);
            }

            $state = new UpdateState(
                ctes: $this->renderCtes(),
                table: $this->table,
                sets: $this->sets,
                whereClause: $this->buildWhereClause(),
                tables: $this->getTables(),
                params: $this->params,
                parameterize: fn (mixed $value): string => $this->param($value),
                quote: fn (string $identifier): string => $this->quote($identifier),
                returning: $this->returning
            );

            $compiled = $this->requireDialect()->compileUpdate($state);
            $this->cachedSql = $this->applyGuardrailMetadata($compiled);
        } finally {
            $this->params = $originalParams;
        }

        return $this->cachedSql;
    }

    /**
     * Build where clause.
     *
     * @return ?string String result, or null when absent.
     */
    private function buildWhereClause(): ?string
    {
        if ($this->builtWhere !== null) {
            return $this->builtWhere;
        }

        if ($this->where !== []) {
            return implode(' AND ', $this->where);
        }

        return null;
    }

    /**
     * Add hint table.
     *
     * @param string $table  Target table name.
     *
     * @return void No return value.
     */
    private function addHintTable(string $table): void
    {
        if ($table === '') {
            return;
        }

        if (!in_array($table, $this->hintTables, true)) {
            $this->hintTables[] = $table;
        }
    }

    /**
     * Return tables.
     *
     * @return array Result array.
     */
    private function getTables(): array
    {
        $tables = $this->hintTables;

        if ($tables === []) {
            return [];
        }

        if ($this->ctes === []) {
            return array_values(array_unique($tables));
        }

        $cteNames = array_map(static fn (array $cte): string => strtolower($cte['name']), $this->ctes);

        return array_values(array_filter(array_unique($tables), static function (string $table) use ($cteNames): bool {
            return !in_array(strtolower($table), $cteNames, true);
        }));
    }

    /**
     * Merge subquery tables.
     *
     * @param Sql $sql  SQL string, SQL message, or builder SQL object.
     *
     * @return void No return value.
     */
    protected function mergeSubqueryTables(Sql $sql): void
    {
        foreach ($sql->getCacheTables() as $table) {
            $this->addHintTable($table);
        }
    }

    /**
     * Cte context.
     *
     * @return string String result.
     */
    protected function cteContext(): string
    {
        return 'update';
    }

}

