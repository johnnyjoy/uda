<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/query/delete
 * @since 1.0.0
 */

/*
 * Purpose: Builds DELETE SQL queries without executing them.
 */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\Query\Concerns\ConsumesCtes;
use UDA\Query\Dialect\DeleteState;

/**
 * DELETE query builder that produces Sql objects for execution
 */
final class Delete extends Abs
{
    use ConsumesCtes;
    /** @var ?string Table to delete from */
    private ?string $table = null;

    /** @var array WHERE conditions */
    private array $where = [];

    /** @var array<string> Tables referenced for cache hints */
    private array $hintTables = [];

    /** @var ?array Columns requested via returning() */
    private ?array $returning = null;

    /** @var ?Sql Cached Sql instance for repeated toSql() calls */
    private ?Sql $cachedSql = null;

    public function __construct()
    {
        parent::__construct();
        $this->setStatementType('delete');
    }

    public function __clone()
    {
        parent::__clone();
        $this->cachedSql = null;
        $this->cloneCtesOnClone();
    }

    /**
     *
     * @param  string $table The table name
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
     *
     * @param  string $column   The column name
     * @param  mixed  $value    The value to compare against
     * @param  string $operator The comparison operator
     * @return self
     */
    public function where(string $column, mixed $value, string $operator = '='): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params, fn ($id) => $clone->quote($id));
        $whereBuilder->where($column, $value, $operator);

        return $whereBuilder;
    }

    public function whereRaw(string $expression, array $params = []): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params, fn ($id) => $clone->quote($id));
        $whereBuilder->whereRaw($expression, $params);

        return $whereBuilder;
    }

    /**
     *
     * @param  string $left     The left column
     * @param  string $right    The right column
     * @param  string $operator The comparison operator
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
     *
     * @return int            The number of affected rows
     * @throws QueryException If not bound to a driver
     */
    public function exec(): int
    {
        return $this->delegateThroughDatabase('exec');
    }

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

    public function row(): ?array
    {
        if ($this->returning === null) {
            throw new QueryException('Call returning() before requesting returning rows.');
        }

        $rows = $this->delegateReturning();

        return $rows[0] ?? null;
    }

    public function rows(): array
    {
        if ($this->returning === null) {
            throw new QueryException('Call returning() before requesting returning rows.');
        }

        return $this->delegateReturning();
    }

    public function value(): mixed
    {
        if ($this->returning === null) {
            throw new QueryException('Call returning() before requesting returning rows.');
        }

        $row = $this->row();

        return $row === null ? null : (array_values($row)[0] ?? null);
    }

    public function list(): array
    {
        if ($this->returning === null) {
            throw new QueryException('Call returning() before requesting returning rows.');
        }

        return $this->rows();
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
     * Generates a SQL DELETE statement with an optional WHERE clause.
     *
     * This method constructs a parameterized DELETE statement. The WHERE clause
     * is included only if filtering conditions have been specified; otherwise,
     * it generates a blanket DELETE FROM statement.
     *
     * @return Sql            The executable SQL DELETE statement with named parameters
     * @throws QueryException If no table is defined (via table() method)
     * @throws QueryException If WHERE conditions are not specified (prevents accidental data loss)
     *
     * @see Delete::where() Method to add WHERE conditions
     * @see Delete::whereColumn() Method for column-to-column comparisons
     * @example
     * $query = new Delete();
     * $sql = $query->table('users')
     * ->where('id', 123)
     * ->toSql();
     * // Returns: Sql object with "DELETE FROM users WHERE id = :p0"
     * @note For safety, always specify WHERE conditions before calling toSql().
     * An exception will be thrown if no table is defined.
     */
    public function toSql(): Sql
    {
        // Simple caching – the query is immutable after construction in the benchmark.
        if ($this->cachedSql !== null) {
            return $this->cachedSql;
        }

        if ($this->table === null) {
            throw new QueryException('No table defined for delete query');
        }

        $originalParams = $this->params;
        $this->params = clone $this->params;

        try {
            if ($this->hintTables === [] && $this->table !== null) {
                $this->addHintTable($this->table);
            }

            $state = new DeleteState(
                ctes: $this->renderCtes(),
                table: $this->table,
                whereClause: $this->buildWhereClause(),
                tables: $this->getTables(),
                params: $this->params,
                quote: fn (string $identifier): string => $this->quote($identifier),
                returning: $this->returning
            );

            $compiled = $this->requireDialect()->compileDelete($state);
            $this->cachedSql = $this->applyGuardrailMetadata($compiled);
        } finally {
            $this->params = $originalParams;
        }

        return $this->cachedSql;
    }

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

    private function addHintTable(string $table): void
    {
        if ($table === '') {
            return;
        }

        if (!in_array($table, $this->hintTables, true)) {
            $this->hintTables[] = $table;
        }
    }

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

    protected function mergeSubqueryTables(Sql $sql): void
    {
        foreach ($sql->getCacheTables() as $table) {
            $this->addHintTable($table);
        }
    }

    protected function cteContext(): string
    {
        return 'delete';
    }

    protected function fingerprintPayload(): array
    {
        return [
            'ctes' => $this->fingerprintCtes(),
            'table' => $this->table,
            'where' => $this->builtWhere,
            'whereFragments' => $this->where,
            'returning' => $this->returning,
        ];
    }
}
