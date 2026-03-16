<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/query/insert
 * @since 1.0.0
 */

/*
 * Purpose: Builds INSERT SQL queries without executing them.
 */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\Query\Concerns\ConsumesCtes;
use UDA\Query\Dialect\InsertState;

/**
 * INSERT query builder that produces Sql objects for execution
 */
final class Insert extends Abs
{
    use ConsumesCtes;
    /** @var ?string Table to insert into */
    private ?string $table = null;

    /** @var array Columns and values to insert (single row) */
    private array $columns = [];

    /** @var array Rows for bulk insert */
    private array $rows = [];

    /** @var ?array Columns to return */
    private ?array $returning = null;

    /** @var array<string> Tables referenced for cache attribution */
    private array $hintTables = [];

    /** @var array<string> Column list for INSERT ... SELECT */
    private array $insertColumns = [];

    private Select|Sql|null $selectQuery = null;

    /** @var ?Sql Cached Sql instance for repeated toSql() calls */
    private ?Sql $cachedSql = null;

    public function __construct()
    {
        parent::__construct();
        $this->setStatementType('insert');
    }

    public function __clone()
    {
        parent::__clone();
        $this->cachedSql = null;
        $this->cloneCtesOnClone();
        if ($this->selectQuery instanceof Select) {
            $this->selectQuery = clone $this->selectQuery;
        }
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
        $clone->hintTables = [$table];

        return $clone;
    }

    /**
     *
     * @param  string $column The column name
     * @param  mixed  $value  The value to insert
     * @return self
     */
    public function set(string $column, mixed $value): self
    {
        if ($this->selectQuery !== null) {
            throw new QueryException('Cannot mix select() inserts with values().');
        }
        $clone = clone $this;
        $clone->columns[$column] = $value;
        $clone->rows = [];

        return $clone;
    }

    public function columns(string ...$columns): self
    {
        if ($columns === []) {
            throw new QueryException('columns() requires at least one column name.');
        }

        if ($this->columns !== [] || $this->rows !== []) {
            throw new QueryException('columns() cannot be combined with values() inserts.');
        }

        $normalized = array_map('trim', $columns);

        foreach ($normalized as $column) {
            if ($column === '') {
                throw new QueryException('Column names cannot be empty.');
            }
        }

        $clone = clone $this;
        $clone->insertColumns = $normalized;

        return $clone;
    }

    public function select(Select|Sql $query): self
    {
        if ($this->insertColumns === []) {
            throw new QueryException('Define target columns via columns() before select().');
        }

        if ($this->columns !== [] || $this->rows !== []) {
            throw new QueryException('Cannot mix select() inserts with values().');
        }

        $clone = clone $this;
        $clone->selectQuery = $query instanceof Select ? clone $query : $query;

        return $clone;
    }

    public function values(?array $row = null): self|array
    {
        if ($row !== null) {
            $clone = clone $this;
            $clone->columns = $row;
            $clone->rows = [];

            return $clone;
        }

        if ($this->returning === null) {
            throw new QueryException('Call returning() before requesting returning rows.');
        }

        return array_map('current', $this->rows());
    }

    public function rows(?array $rows = null): self|array
    {
        if ($rows !== null) {
            if ($this->selectQuery !== null) {
                throw new QueryException('Cannot mix select() inserts with rows().');
            }
            $clone = clone $this;
            $clone->rows = $rows;
            $clone->columns = [];

            return $clone;
        }

        if ($this->returning === null) {
            throw new QueryException('Call returning() before requesting returning rows.');
        }

        return $this->delegateReturning();
    }

    /**
     *
     * @param  string ...$columns The columns to return
     * @return self
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
     *
     * @return int            The number of affected rows
     * @throws QueryException If not bound to a driver
     */
    public function exec(): int
    {
        return $this->delegateThroughDatabase('exec');
    }

    public function row(): ?array
    {
        if ($this->returning === null) {
            throw new QueryException('Call returning() before requesting returning rows.');
        }

        $rows = $this->delegateReturning();
        return $rows[0] ?? null;
    }

    public function value(): mixed
    {
        if ($this->returning === null) {
            throw new QueryException('Call returning() before requesting returning rows.');
        }

        $row = $this->row();
        if ($row === null) {
            return null;
        }

        return array_values($row)[0] ?? null;
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
     * Generates a SQL INSERT statement with appropriate parameter placeholders.
     *
     * This method constructs a parameterized INSERT statement suitable for
     * safe execution against the database. Column names are automatically quoted,
     * and values are replaced with named placeholders to prevent SQL injection.
     *
     * @return Sql            The executable SQL INSERT statement with named parameters
     * @throws QueryException If no table is defined (via into() method)
     * @throws QueryException If no columns have been set (via set() method)
     *
     * @see Insert::set() Method to add column-value pairs
     * @see Insert::into() Method to specify target table
     * @see Insert::returning() Method to add RETURNING clause (PostgreSQL)
     * @example
     * $query = new Insert();
     * $sql = $query->into('users')
     * ->set('name', 'John')
     * ->set('email', 'john@example.com')
     * ->toSql();
     * // Returns: Sql object with "INSERT INTO users (name, email) VALUES (:p0, :p1)"
     */
    public function toSql(): Sql
    {
        if ($this->cachedSql !== null) {
            return $this->cachedSql;
        }

        if ($this->table === null) {
            throw new QueryException('No table defined for insert query');
        }

        if ($this->columns === [] && $this->rows === [] && $this->selectQuery === null) {
            throw new QueryException('No data provided for insert query');
        }

        if ($this->selectQuery !== null && $this->insertColumns === []) {
            throw new QueryException('columns() is required when using select().');
        }

        $originalParams = $this->params;
        $this->params = clone $this->params;

        try {
            $ctes = $this->renderCtes();
            $selectSql = $this->renderInsertSelectQuery();

            if ($this->hintTables === [] && $this->table !== null) {
                $this->addHintTable($this->table);
            }

            $state = new InsertState(
                ctes: $ctes,
                table: $this->table,
                columns: $this->columns,
                rows: $this->rows,
                returning: $this->returning,
                tables: $this->getTables(),
                params: $this->params,
                parameterize: fn (mixed $value): string => $this->param($value),
                quote: fn (string $identifier): string => $this->quote($identifier),
                selectQuery: $selectSql,
                selectColumns: $this->insertColumns
            );

            $compiled = $this->requireDialect()->compileInsert($state);
            $this->cachedSql = $this->applyGuardrailMetadata($compiled);
        } finally {
            $this->params = $originalParams;
        }

        return $this->cachedSql;
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

    private function renderInsertSelectQuery(): ?string
    {
        if ($this->selectQuery === null) {
            return null;
        }

        $sql = $this->selectQuery instanceof Select ? $this->selectQuery->toSql() : $this->selectQuery;
        $query = $sql->getQuery();
        $replacements = [];

        foreach ($sql->getParams() as $name => $value) {
            $replacements[':' . $name] = $this->param($value);
        }

        if ($replacements !== []) {
            $query = strtr($query, $replacements);
        }

        $this->mergeSubqueryTables($sql);

        return $query;
    }

    protected function cteContext(): string
    {
        return 'insert';
    }

    protected function fingerprintPayload(): array
    {
        return [
            'ctes' => $this->fingerprintCtes(),
            'table' => $this->table,
            'singleRowColumns' => array_keys($this->columns),
            'rowsStructure' => array_map(static fn (array $row): array => array_keys($row), $this->rows),
            'rowsCount' => count($this->rows),
            'insertColumns' => $this->insertColumns,
            'selectQuery' => $this->fingerprintSelectQueryDescriptor(),
            'returning' => $this->returning,
        ];
    }

    private function fingerprintSelectQueryDescriptor(): ?array
    {
        if ($this->selectQuery === null) {
            return null;
        }

        if ($this->selectQuery instanceof Select) {
            return ['select' => $this->selectQuery->fingerprint()];
        }

        return [
            'sql' => $this->selectQuery->getQuery(),
            'params' => array_keys($this->selectQuery->getParams()),
        ];
    }
}
