<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/query/select
 * @since 1.0.0
 */

/*
 * Purpose: Fluent, type-safe SELECT query builder that constructs parameterized SQL statements.
 */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\Query\Concerns\ConsumesCtes;
use UDA\Query\Dialect\SelectState;

/**
 * SELECT query builder that produces Sql objects for execution
 */
final class Select extends Abs
{
    use ConsumesCtes;
    /** @var array Columns to select */
    private array $columns = [];

    /** @var bool Whether to use DISTINCT */
    private bool $distinct = false;

    /** @var ?string Base table to select from */
    private ?string $table = null;

    /** @var ?string Table alias (or derived-table alias) */
    private ?string $alias = null;

    /** @var Select|Sql|null Derived table for FROM clause */
    private Select|Sql|null $fromSubquery = null;

    /** @var array<int,array<string,mixed>> Join clauses */
    private array $joins = [];

    /** @var array WHERE conditions */
    private array $where = [];

    /** @var array GROUP BY columns */
    private array $groupBy = [];

    /** @var array HAVING conditions */
    private array $having = [];

    /** @var array ORDER BY columns */
    private array $orderBy = [];

    /** @var ?int LIMIT value */
    private ?int $limit = null;

    /** @var ?int OFFSET value */
    private ?int $offset = null;

    /** @var array Tables for cache hinting */
    private array $hintTables = [];

    /** @var array<int,array{type:string,query:Select}> */
    private array $unions = [];

    /** @var ?Sql Cached Sql instance for repeated toSql() calls */
    private ?Sql $cachedSql = null;

    public function __construct()
    {
        parent::__construct();
        $this->setStatementType('select');
    }

    public function __clone()
    {
        parent::__clone();
        $this->cachedSql = null;
        $this->unions = array_map(static fn (array $union): array => ['type' => $union['type'], 'query' => clone $union['query']], $this->unions);
        $this->cloneCtesOnClone();
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

    /**
     *
     * @param  string|Expr ...$columns The columns or expressions to select
     * @return self
     */
    public function select(string|Expr ...$columns): self
    {
        $clone = clone $this;

        if (empty($columns)) {
            $clone->columns = [];

            return $clone;
        }

        foreach ($columns as $column) {
            if ($column instanceof Expr) {
                $clone->columns[] = $column;
                continue;
            }
            if (is_numeric($column) || $column === '?') {
                $clone->columns[] = $column;
            } else {
                $clone->columns[] = $clone->quote($column);
            }
        }

        return $clone;
    }

    /**
     * Add raw SQL expressions to SELECT clause
     *
     * @param  string ...$expressions Raw SQL expressions
     * @return self
     */
    public function selectRaw(string ...$expressions): self
    {
        $clone = clone $this;

        foreach ($expressions as $expression) {
            $clone->columns[] = $expression;
        }

        return $clone;
    }

    /**
     *
     * @param  string  $table The table name
     * @param  ?string $alias Optional table alias
     * @return self
     */
    public function from(string $table, ?string $alias = null): self
    {
        $clone = clone $this;
        $clone->table = $table;
        $clone->alias = $alias;
        $clone->fromSubquery = null;
        $clone->hintTables = [$table];

        return $clone;
    }

    /**
     * Use a derived table / subquery in the FROM clause.
     */
    public function fromSub(Select|Sql $subquery, string $alias): self
    {
        if (trim($alias) === '') {
            throw new QueryException('Derived tables require an alias.');
        }

        $clone = clone $this;
        $clone->table = null;
        $clone->alias = $alias;
        $clone->fromSubquery = $subquery;

        return $clone;
    }

    /**
     *
     * @param  string  $table The table to join
     * @param  string  $left  The left column
     * @param  string  $right The right column
     * @param  string  $type  The join type (INNER, LEFT, RIGHT, etc.)
     * @param  ?string $alias Optional table alias
     * @return self
     */
    public function join(string $table, string $left, string $right, string $type = 'INNER', ?string $alias = null): self
    {
        $clone = clone $this;
        $clone->joins[] = [
            'type' => strtoupper($type),
            'table' => $table,
            'alias' => $alias,
            'subquery' => null,
            'condition' => sprintf('%s = %s', $clone->quote($left), $clone->quote($right)),
        ];
        $clone->addHintTable($table);

        return $clone;
    }

    public function joinSub(Select|Sql $subquery, string $alias, string $on, string $type = 'INNER'): self
    {
        if (trim($alias) === '') {
            throw new QueryException('Subquery joins require an alias.');
        }

        if (trim($on) === '') {
            throw new QueryException('JOIN conditions cannot be empty.');
        }

        $clone = clone $this;
        $clone->joins[] = [
            'type' => strtoupper($type),
            'table' => null,
            'alias' => $alias,
            'subquery' => $subquery,
            'condition' => $on,
        ];

        return $clone;
    }

    public function leftJoinSub(Select|Sql $subquery, string $alias, string $on): self
    {
        return $this->joinSub($subquery, $alias, $on, 'LEFT');
    }

    public function rightJoinSub(Select|Sql $subquery, string $alias, string $on): self
    {
        return $this->joinSub($subquery, $alias, $on, 'RIGHT');
    }


    /**
     *
     * @param  string|Expr $column   The column or expression to compare
     * @param  mixed       $value    The value to compare against
     * @param  string      $operator The comparison operator
     * @return self
     */
    public function where(string|Expr $column, mixed $value, string $operator = '='): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params, fn ($id) => $clone->quote($id));
        $whereBuilder->where($column, $value, $operator);

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
     * @param  string ...$columns The columns to group by
     * @return self
     */
    public function groupBy(string ...$columns): self
    {
        $clone = clone $this;

        foreach ($columns as $column) {
            $clone->groupBy[] = $clone->quote($column);
        }

        return $clone;
    }

    /**
     * Start a WHERE EXISTS chain
     *
     * @param  Sql          $subquery Subquery to check for existence
     * @return WhereBuilder
     */
    public function whereExists(Select|Sql $subquery): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params, fn ($id) => $clone->quote($id));
        $whereBuilder->exists($subquery);

        return $whereBuilder;
    }

    /**
     * Start a WHERE NOT EXISTS chain
     *
     * @param  Sql          $subquery Subquery to check for non-existence
     * @return WhereBuilder
     */
    public function whereNotExists(Select|Sql $subquery): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params, fn ($id) => $clone->quote($id));
        $whereBuilder->notExists($subquery);

        return $whereBuilder;
    }

    /**
     * Apply DISTINCT modifier to SELECT
     *
     * @return self
     */
    public function distinct(): self
    {
        $clone = clone $this;
        $clone->distinct = true;

        return $clone;
    }

    public function union(Select $query): self
    {
        return $this->addUnion('UNION', $query);
    }

    public function unionAll(Select $query): self
    {
        return $this->addUnion('UNION ALL', $query);
    }

    private function addUnion(string $type, Select $query): self
    {
        $clone = clone $this;
        $clone->unions[] = [
            'type' => strtoupper($type),
            'query' => clone $query,
        ];

        return $clone;
    }

    /**
     * Start a HAVING chain for aggregate filtering
     *
     * @param  string|Expr  $column   Aggregate expression
     * @param  mixed        $value    Comparison value (optional for fluent operator attachment)
     * @param  string       $operator Comparison operator (=, !=, >, <, >=, <=)
     * @return WhereBuilder
     */
    public function having(string|Expr $column, mixed $value = null, string $operator = '='): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params, fn ($id) => $clone->quote($id));
        $whereBuilder->setHavingMode(true);

        if ($value === null) {
            // Fluent mode: having('COUNT(id)')->gt(5)
            $whereBuilder->setCurrentColumn($column);
        } else {
            // Direct mode: having('COUNT(id)', 5, '>')
            $whereBuilder->where($column, $value, $operator);
        }

        return $whereBuilder;
    }

    public function havingRaw(string $expression, array $params = []): self
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params, fn ($id) => $clone->quote($id));
        $whereBuilder->setHavingMode(true);
        $whereBuilder->whereRaw($expression, $params);

        return $whereBuilder->end();
    }

    /**
     *
     * @param  string|Expr $column    The column or expression to order by
     * @param  string      $direction The sort direction (ASC or DESC)
     * @param  array       $allowlist Allowlist of valid columns
     * @return self
     */
    public function orderBy(string|Expr $column, string $direction = 'ASC', array $allowlist = []): self
    {
        $direction = strtoupper($direction);

        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new QueryException('Order direction must be ASC or DESC');
        }

        $clone = clone $this;

        if ($column instanceof Expr) {
            if ($allowlist !== []) {
                throw new QueryException('ORDER BY expressions cannot be validated against an allowlist.');
            }
            $clone->orderBy[] = [
                'expr' => $column,
                'direction' => $direction,
            ];
        } else {
            if ($allowlist !== [] && !$this->isAllowedOrderColumn($column, $allowlist)) {
                throw new QueryException(sprintf('Column not allowed in ORDER BY: %s', $column));
            }
            $clone->orderBy[] = sprintf('%s %s', $clone->quote($column), $direction);
        }

        return $clone;
    }

    private function isAllowedOrderColumn(string $column, array $allowlist): bool
    {
        $needle = strtolower($column);

        foreach ($allowlist as $key => $value) {
            $candidate = is_int($key) ? (string) $value : (string) $key;

            if (strtolower($candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     *
     * @param  int            $limit The limit value
     * @return self
     * @throws QueryException If limit is negative
     */
    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new QueryException('Limit must be zero or positive');
        }
        $clone = clone $this;
        $clone->limit = $limit;
        $clone->markLimitUsed();

        return $clone;
    }

    /**
     *
     * @param  int            $offset The offset value
     * @return self
     * @throws QueryException If offset is negative
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new QueryException('Offset must be zero or positive');
        }
        $clone = clone $this;
        $clone->offset = $offset;
        $clone->markLimitUsed();

        return $clone;
    }

    // Query builders are immutable and do not execute - they only produce Sql objects
    // Execution is handled exclusively by the Driver class

    /**
     *
     * @return ?array The tables for cache hinting
     */
    private function cacheTables(): ?array
    {
        if ($this->hintTables === []) {
            return null;
        }

        return array_values(array_unique($this->hintTables));
    }

    public function mergeSubqueryTables(Sql $sql): void
    {
        foreach ($sql->getCacheTables() as $table) {
            $this->addHintTable($table);
        }
    }

    /**
     * Get tables this query operates on for cache invalidation
     *
     * @return string[]
     */
    public function getTables(): array
    {
        $tables = $this->cacheTables() ?? [];

        if ($tables === []) {
            return [];
        }

        if ($this->ctes === []) {
            return $tables;
        }

        $cteNames = array_map(static fn (string $name): string => strtolower($name), array_column($this->ctes, 'name'));

        return array_values(array_filter($tables, static function (string $table) use ($cteNames): bool {
            return !in_array(strtolower($table), $cteNames, true);
        }));
    }

    protected function fingerprintPayload(): array
    {
        return [
            'ctes' => $this->fingerprintCtes(),
            'distinct' => $this->distinct,
            'columns' => array_map(fn ($column) => $this->fingerprintColumn($column), $this->columns),
            'table' => $this->table,
            'alias' => $this->alias,
            'fromSubquery' => $this->fingerprintSubqueryDescriptor($this->fromSubquery),
            'joins' => array_map(fn (array $join): array => $this->fingerprintJoin($join), $this->joins),
            'where' => $this->builtWhere,
            'whereFragments' => $this->where,
            'groupBy' => $this->groupBy,
            'having' => $this->builtHaving,
            'havingFragments' => $this->having,
            'orderBy' => array_map(fn ($clause) => $this->fingerprintOrderByClause($clause), $this->orderBy),
            'limit' => $this->limit,
            'offset' => $this->offset,
            'unions' => array_map(function (array $union): array {
                return [
                    'type' => $union['type'],
                    'query' => $this->fingerprintSubqueryDescriptor($union['query']),
                ];
            }, $this->unions),
        ];
    }

    private function fingerprintColumn(mixed $column): mixed
    {
        if ($column instanceof Expr) {
            return ['expr' => $column->fingerprintSql()];
        }

        if (is_array($column) && isset($column['expr'])) {
            $expr = $column['expr'] instanceof Expr
                ? $column['expr']->fingerprintSql()
                : $column['expr'];

            $payload = ['expr' => $expr];

            if (isset($column['alias'])) {
                $payload['alias'] = $column['alias'];
            }

            return $payload;
        }

        return $column;
    }

    private function fingerprintOrderByClause(mixed $clause): mixed
    {
        if (is_array($clause) && isset($clause['expr'], $clause['direction'])) {
            $expr = $clause['expr'] instanceof Expr
                ? $clause['expr']->fingerprintSql()
                : $clause['expr'];

            return [
                'expr' => $expr,
                'direction' => $clause['direction'],
            ];
        }

        return $clause;
    }

    private function fingerprintJoin(array $join): array
    {
        return [
            'type' => $join['type'],
            'table' => $join['table'],
            'alias' => $join['alias'],
            'condition' => $join['condition'],
            'subquery' => $this->fingerprintSubqueryDescriptor($join['subquery']),
        ];
    }

    private function fingerprintSubqueryDescriptor(Select|Sql|null $subquery): ?array
    {
        if ($subquery === null) {
            return null;
        }

        if ($subquery instanceof Select) {
            return ['select' => $subquery->fingerprint()];
        }

        return [
            'sql' => $subquery->getQuery(),
            'params' => array_keys($subquery->getParams()),
        ];
    }

    /**
     * Generates a SQL representation of this SELECT query as an executable Sql object.
     *
     * This method constructs the complete SQL string with proper parameter placeholders
     * based on the query configuration (columns, joins, filters, ordering, etc.).
     * The result is cached internally to avoid repeated string building for immutable queries.
     *
     * @return Sql            The executable SQL object containing both SQL string and named parameters
     * @throws QueryException If no table is defined (essential for FROM clause)
     * @throws QueryException If the query builder is not properly configured
     *
     * @see Sql::class Executable SQL value object
     * @see SqlMessage::class Alternative SQL representation for fragments
     * @example
     * $query = new Select();
     * $sql = $query->from('users')
     * ->select('id', 'name')
     * ->where('status', 'active')
     * ->orderBy('name')
     * ->toSql();
     * // Returns: Sql object with "SELECT id, name FROM users WHERE status = :p0 ORDER BY name"
     */
    public function toSql(): \UDA\Query\Sql
    {
        if ($this->cachedSql !== null) {
            return $this->cachedSql;
        }

        if ($this->table === null && $this->fromSubquery === null) {
            throw new QueryException('No table defined for select query');
        }

        $originalParams = $this->params;
        $this->params = clone $this->params;

        $unionClauses = [];
        foreach ($this->unions as $union) {
            $unionClauses[] = [
                'type' => $union['type'],
                'query' => $this->renderSubquery($union['query']),
            ];
        }

        try {
            $ctes = $this->renderCtes();
            $columns = $this->renderColumns();
            $orderBy = $this->renderOrderBy();
            $state = new SelectState(
                ctes: $ctes,
                distinct: $this->distinct,
                columns: $columns,
                fromClause: $this->buildFromClause(),
                joins: $this->buildJoinClauses(),
                whereClause: $this->buildWhereClause(),
                groupBy: $this->groupBy,
                havingClause: $this->buildHavingClause(),
                orderBy: $orderBy,
                limit: $this->limit,
                offset: $this->offset,
                tables: $this->getTables(),
                params: $this->params,
                unions: $unionClauses,
                parameterize: fn (mixed $value): string => $this->param($value)
            );

            $compiled = $this->requireDialect()->compileSelect($state);
            $this->cachedSql = $this->applyGuardrailMetadata($compiled);
        } finally {
            $this->params = $originalParams;
        }

        return $this->cachedSql;
    }

    /**
     * @return array<int,string>
     */
    private function renderColumns(): array
    {
        if ($this->columns === []) {
            return [];
        }

        $rendered = [];

        foreach ($this->columns as $column) {
            if ($column instanceof Expr) {
                if ($column->usesWindow()) {
                    $this->assertWindowFunctionsSupported();
                }
                $rendered[] = $column->getSql($this->params);
                continue;
            }

            if (is_array($column) && isset($column['expr']) && $column['expr'] instanceof Expr) {
                $rendered[] = $column['expr']->getSql($this->params);
                continue;
            }

            $rendered[] = $column;
        }

        return $rendered;
    }

    /**
     * @return array<int,string>
     */
    private function renderOrderBy(): array
    {
        if ($this->orderBy === []) {
            return [];
        }

        $clauses = [];

        foreach ($this->orderBy as $clause) {
            if (is_array($clause) && isset($clause['expr'], $clause['direction']) && $clause['expr'] instanceof Expr) {
                if ($clause['expr']->usesWindow()) {
                    $this->assertWindowFunctionsSupported();
                }
                $clauses[] = sprintf(
                    '%s %s',
                    $clause['expr']->getSql($this->params, includeAlias: false),
                    $clause['direction']
                );
                continue;
            }

            $clauses[] = $clause;
        }

        return $clauses;
    }

    protected function cteContext(): string
    {
        return 'select';
    }

    private function assertWindowFunctionsSupported(): void
    {
        $this->assertDialectCapability(
            fn ($dialect) => $dialect->supportsWindowFunctions(),
            '%s dialect does not support window functions.'
        );
    }

    /**
     *
     * @return string The FROM clause
     */
    private function buildFromClause(): string
    {
        if ($this->fromSubquery !== null) {
            if ($this->alias === null) {
                throw new QueryException('Derived tables require an alias.');
            }

            $sub = $this->renderSubquery($this->fromSubquery);

            return sprintf('%s AS %s', $sub, $this->quote($this->alias));
        }

        if ($this->table === null) {
            throw new QueryException('No table defined for select query');
        }

        $clause = $this->quote($this->table);

        if ($this->alias !== null) {
            $clause .= ' AS ' . $this->quote($this->alias);
        }

        return $clause;
    }

    private function buildJoinClauses(): array
    {
        $clauses = [];

        foreach ($this->joins as $join) {
            $type = strtoupper($join['type']);
            $condition = $join['condition'];

            if ($join['subquery'] !== null) {
                $sub = $this->renderSubquery($join['subquery']);
                $alias = $this->quote($join['alias']);
                $clauses[] = sprintf('%s JOIN %s AS %s ON %s', $type, $sub, $alias, $condition);
            } else {
                $tableClause = $this->quote($join['table']);
                if ($join['alias'] !== null) {
                    $tableClause .= ' AS ' . $this->quote($join['alias']);
                }
                $clauses[] = sprintf('%s JOIN %s ON %s', $type, $tableClause, $condition);
            }
        }

        return $clauses;
    }

    private function renderSubquery(Select|Sql $subquery): string
    {
        $sql = $subquery instanceof Select ? $subquery->toSql() : $subquery;

        $query = $sql->getQuery();
        $replacements = [];
        foreach ($sql->getParams() as $name => $value) {
            $replacements[':' . $name] = $this->param($value);
        }
        if ($replacements !== []) {
            $query = strtr($query, $replacements);
        }

        $this->mergeSubqueryTables($sql);

        return '(' . $query . ')';
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

    private function buildHavingClause(): ?string
    {
        if ($this->builtHaving !== null) {
            return $this->builtHaving;
        }

        if ($this->having !== []) {
            return implode(' AND ', $this->having);
        }

        return null;
    }

    // ----- Constitutional Execution Helpers -----

    /**
     *
     * @return ?array         The single row result or null
     * @throws QueryException If not bound to a driver
     */
    public function row(): ?array
    {
        return $this->delegateThroughDatabase('row');
    }

    public function rows(): array
    {
        return $this->delegateThroughDatabase('rows');
    }

    public function value(): mixed
    {
        return $this->delegateThroughDatabase('value');
    }

    public function values(): array
    {
        return $this->delegateThroughDatabase('values');
    }

    public function list(): array
    {
        return $this->delegateThroughDatabase('list');
    }

    public function explain(): array
    {
        return $this->delegateThroughDatabase('explain');
    }

    public function explainAnalyze(): array
    {
        return $this->delegateThroughDatabase('explainAnalyze');
    }

    public function each(callable $fn): int
    {
        return $this->delegateThroughDatabase('each', $fn);
    }

    /**
     * Execute COUNT(...) wrapping this query and return the scalar integer result.
     */
    public function count(string $expression = '*'): int
    {
        $countSql = $this->buildCountSql($expression);

        return (int) $this->executeSql('value', $countSql);
    }

    private function buildCountSql(string $expression): Sql
    {
        $inner = $this->toSql();
        $expr = trim($expression) === '' ? '*' : $expression;

        $query = sprintf(
            'SELECT COUNT(%s) AS total FROM (%s) uda_count',
            $expr,
            $inner->getQuery()
        );

        $countSql = new Sql($query, $inner->getParams(), $inner->getCacheTables());

        return $countSql->withGuardrailMetadata(
            'select',
            $inner->hasWhereClause(),
            $inner->hasLimitClause(),
            $inner->isUnsafe()
        );
    }
}
