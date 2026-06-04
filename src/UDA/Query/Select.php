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
 * Purpose: Fluent, type-safe SELECT query builder that constructs parameterized SQL statements.
 */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\Query\Concerns\ConsumesCtes;
use UDA\Query\State\Select as SelectState;

/**
 * SELECT query builder that produces Sql objects for execution
 */
final class Select extends \UDA\Query
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

    /**
     * Create the runtime object.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setStatementType('select');
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
        $clonedUnions = [];

        foreach ($this->unions as $union) {
            $clonedUnions[] = ['type' => $union['type'], 'query' => clone $union['query']];
        }

        $this->unions = $clonedUnions;
        $this->cloneCtesOnClone();
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
     * @param string|Expr ...$columns  The columns or expressions to select
     *
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
     * @param string ...$expressions  Raw SQL expressions
     *
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
     * @param string  $table  The table name
     * @param ?string $alias  Optional table alias
     *
     * @return self
     */
    public function from(string $table, ?string $alias = null): self
    {
        // Support inline alias shorthand: ->from('employees e') == ->from('employees', 'e')
        if ($alias === null) {
            [$table, $alias] = $this->splitTableAlias($table);
        }

        $clone = clone $this;
        $clone->table = $table;
        $clone->alias = $alias;
        $clone->fromSubquery = null;
        $clone->hintTables = [$table];

        return $clone;
    }

    /**
     * Use a derived table / subquery in the FROM clause.
     *
     * @param Select|Sql $subquery  Subquery to embed.
     * @param string     $alias     SQL alias.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
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
     * Split an inline "table alias" shorthand into a [table, alias] pair.
     *
     * @param string $table  Table reference, optionally with a trailing alias.
     *
     * @return array{0: string, 1: ?string} Table name and alias (null when absent).
     */
    private function splitTableAlias(string $table): array
    {
        if (str_contains($table, ' ')) {
            $parts = preg_split('/\s+/', trim($table), 2);

            if ($parts !== false && count($parts) === 2) {
                return [$parts[0], $parts[1]];
            }
        }

        return [$table, null];
    }

    /**
     * Add a JOIN clause.
     *
     * Two forms are supported:
     *  - Column form:  ->join('departments', 'd.id', 'e.department_id', 'INNER', 'd')
     *  - Inline form:  ->join('departments d', 'd.id = e.department_id')
     *
     * In the inline form the third argument is omitted; the second argument is
     * treated as a raw ON predicate and any trailing alias on the table is parsed.
     *
     * @param string  $table           The table to join (may carry an inline alias).
     * @param string  $leftOrCondition Left column (column form) or raw ON predicate (inline form).
     * @param ?string $right           Right column (column form); null selects the inline form.
     * @param string  $type            The join type (INNER, LEFT, RIGHT, etc.).
     * @param ?string $alias           Optional table alias (column form).
     *
     * @return self
     */
    public function join(string $table, string $leftOrCondition, ?string $right = null, string $type = 'INNER', ?string $alias = null): self
    {
        $clone = clone $this;

        if ($right === null) {
            if ($alias === null) {
                [$table, $alias] = $this->splitTableAlias($table);
            }

            $condition = $leftOrCondition;
        } else {
            $condition = sprintf('%s = %s', $clone->quote($leftOrCondition), $clone->quote($right));
        }

        $clone->joins[] = [
            'type' => strtoupper($type),
            'table' => $table,
            'alias' => $alias,
            'subquery' => null,
            'condition' => $condition,
        ];
        $clone->addHintTable($table);

        return $clone;
    }

    /**
     * Add a LEFT JOIN clause. Accepts the same two forms as join().
     *
     * @param string  $table           The table to join (may carry an inline alias).
     * @param string  $leftOrCondition Left column (column form) or raw ON predicate (inline form).
     * @param ?string $right           Right column (column form); null selects the inline form.
     * @param ?string $alias           Optional table alias (column form).
     *
     * @return self
     */
    public function leftJoin(string $table, string $leftOrCondition, ?string $right = null, ?string $alias = null): self
    {
        return $this->join($table, $leftOrCondition, $right, 'LEFT', $alias);
    }

    /**
     * Join sub.
     *
     * @param Select|Sql $subquery  Subquery to embed.
     * @param string     $alias     SQL alias.
     * @param string     $on        Join predicate SQL.
     * @param string     $type      Join or set-operation type.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
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

    /**
     * Left join sub.
     *
     * @param Select|Sql $subquery  Subquery to embed.
     * @param string     $alias     SQL alias.
     * @param string     $on        Join predicate SQL.
     *
     * @return self Configured instance.
     */
    public function leftJoinSub(Select|Sql $subquery, string $alias, string $on): self
    {
        return $this->joinSub($subquery, $alias, $on, 'LEFT');
    }

    /**
     * Right join sub.
     *
     * @param Select|Sql $subquery  Subquery to embed.
     * @param string     $alias     SQL alias.
     * @param string     $on        Join predicate SQL.
     *
     * @return self Configured instance.
     */
    public function rightJoinSub(Select|Sql $subquery, string $alias, string $on): self
    {
        return $this->joinSub($subquery, $alias, $on, 'RIGHT');
    }

    /**
     * Start a WHERE chain.
     *
     * When called with a value (`->where('id', $id)`), the condition is added
     * immediately. When called without a value (`->where('hire_date')`), the
     * returned WhereBuilder waits for a comparison operator such as
     * `->between()`, `->gt()`, `->in()`, etc.
     *
     * @param string|Expr $column    The column or expression to compare.
     * @param mixed       $value     Optional value; omit to use fluent operator chaining.
     * @param string      $operator  The comparison operator (default '=').
     *
     * @return WhereBuilder
     */
    public function where(string|Expr $column, mixed $value = null, string $operator = '='): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params);

        if ($value !== null) {
            $whereBuilder->where($column, $value, $operator);
        } else {
            $whereBuilder->setCurrentColumn($column);
        }

        return $whereBuilder;
    }

    /**
     * @param string $left      The left column
     * @param string $right     The right column
     * @param string $operator  The comparison operator
     *
     * @return WhereBuilder
     */
    public function whereColumn(string $left, string $right, string $operator = '='): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params);
        $whereBuilder->whereColumn($left, $right, $operator);

        return $whereBuilder;
    }

    /**
     * Start a WHERE chain from a raw SQL condition fragment.
     *
     * Named parameters in the expression are allocated into the builder's
     * parameter bag, so deterministic ordering is preserved.
     *
     * @param string $expression  Raw SQL condition (e.g., 't.employee_id = e.id').
     * @param array  $params      Optional named parameter values.
     *
     * @return WhereBuilder
     */
    public function whereRaw(string $expression, array $params = []): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params);
        $whereBuilder->whereRaw($expression, $params);

        return $whereBuilder;
    }

    /**
     * @param string ...$columns  The columns to group by
     *
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
     * @param Sql $subquery  Subquery to check for existence
     *
     * @return WhereBuilder
     */
    public function whereExists(Select|Sql $subquery): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params);
        $whereBuilder->exists($subquery);

        return $whereBuilder;
    }

    /**
     * Start a WHERE NOT EXISTS chain
     *
     * @param Sql $subquery  Subquery to check for non-existence
     *
     * @return WhereBuilder
     */
    public function whereNotExists(Select|Sql $subquery): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params);
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

    /**
     * Union.
     *
     * @param Select $query  Query builder instance.
     *
     * @return self Configured instance.
     */
    public function union(Select $query): self
    {
        return $this->addUnion('UNION', $query);
    }

    /**
     * Union all.
     *
     * @param Select $query  Query builder instance.
     *
     * @return self Configured instance.
     */
    public function unionAll(Select $query): self
    {
        return $this->addUnion('UNION ALL', $query);
    }

    /**
     * Add union.
     *
     * @param string $type   Join or set-operation type.
     * @param Select $query  Query builder instance.
     *
     * @return self Configured instance.
     */
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
     * @param string|Expr $column    Aggregate expression
     * @param mixed       $value     Comparison value (optional for fluent operator attachment)
     * @param string      $operator  Comparison operator (=, !=, >, <, >=, <=)
     *
     * @return WhereBuilder
     */
    public function having(string|Expr $column, mixed $value = null, string $operator = '='): WhereBuilder
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params);
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

    /**
     * Having raw.
     *
     * @param string $expression  SQL expression.
     * @param array  $params      Named parameter values.
     *
     * @return self Configured instance.
     */
    public function havingRaw(string $expression, array $params = []): self
    {
        $clone = clone $this;
        $whereBuilder = new WhereBuilder($clone, $clone->params);
        $whereBuilder->setHavingMode(true);
        $whereBuilder->whereRaw($expression, $params);

        return $whereBuilder->end();
    }

    /**
     * Order by.
     *
     * @param string|Expr $column     The column or expression to order by
     * @param string      $direction  The sort direction (ASC or DESC)
     * @param array       $allowlist  Allowlist of valid columns
     *
     * @return self
     *
     * @throws QueryException If the operation fails.
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

    /**
     * Report whether is allowed order column.
     *
     * @param string $column     Column name or expression.
     * @param array  $allowlist  Allowed column names.
     *
     * @return bool Boolean result.
     */
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
     * @param int $limit  The limit value
     *
     * @return self
     *
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
     * @param int $offset  The offset value
     *
     * @return self
     *
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

    // Query builders produce Sql objects; execution is coordinated by Database.

    /**
     * @return ?array The tables for cache hinting
     */
    private function cacheTables(): ?array
    {
        if ($this->hintTables === []) {
            return null;
        }

        return array_values(array_unique($this->hintTables));
    }

    /**
     * Merge subquery tables.
     *
     * @param Sql $sql  SQL string, SQL message, or builder SQL object.
     *
     * @return void No return value.
     */
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

        $cteNames = [];

        foreach ($this->ctes as $cte) {
            $cteNames[] = strtolower($cte['name']);
        }

        $result = [];

        foreach ($tables as $table) {
            if (!in_array(strtolower($table), $cteNames, true)) {
                $result[] = $table;
            }
        }

        return $result;
    }

    /**
     * Build immutable `Sql` for this SELECT (memoised on the builder).
     *
     * @return Sql SQL string and named parameters
     *
     * @throws QueryException If FROM/subquery is missing or SQL cannot be built
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
                unions: $unionClauses
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
                $rendered[] = $column->getSql($this->params, engine: $this->engine);
                continue;
            }

            if (is_array($column) && isset($column['expr']) && $column['expr'] instanceof Expr) {
                $rendered[] = $column['expr']->getSql($this->params, engine: $this->engine);
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

    /**
     * Cte context.
     *
     * @return string String result.
     */
    protected function cteContext(): string
    {
        return 'select';
    }

    /**
     * Assert window functions supported.
     *
     * @return void No return value.
     */
    private function assertWindowFunctionsSupported(): void
    {
        $this->assertDialectCapability(
            'windowFunctions',
            '%s dialect does not support window functions.'
        );
    }

    /**
     * Build from clause.
     *
     * @return string The FROM clause
     *
     * @throws QueryException If the operation fails.
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

    /**
     * Build join clauses.
     *
     * @return array Result array.
     */
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

    /**
     * Render subquery.
     *
     * @param Select|Sql $subquery  Subquery to embed.
     *
     * @return string String result.
     */
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
     * Build having clause.
     *
     * @return ?string String result, or null when absent.
     */
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
     * @return ?array The single row result or null
     *
     * @throws QueryException If not bound to a driver
     */
    public function row(): ?array
    {
        return $this->delegateThroughDatabase('row');
    }

    /**
     * Execute and return all rows.
     *
     * @return array Result array.
     */
    public function rows(): array
    {
        return $this->delegateThroughDatabase('rows');
    }

    /**
     * Execute and return a single value.
     *
     * @return mixed Execution result.
     */
    public function value(): mixed
    {
        return $this->delegateThroughDatabase('value');
    }

    /**
     * Execute and return the first column from every row.
     *
     * @return array Result array.
     */
    public function values(): array
    {
        return $this->delegateThroughDatabase('values');
    }

    /**
     * Execute and return the first row as a numeric list.
     *
     * @return ?array<int,mixed> Row values or null.
     */
    public function list(): ?array
    {
        return $this->delegateThroughDatabase('list');
    }

    /**
     * Each.
     *
     * @param callable $fn  Callback to execute.
     *
     * @return int Integer result.
     */
    public function each(callable $fn): int
    {
        return $this->delegateThroughDatabase('each', $fn);
    }

    /**
     * Execute COUNT(...) wrapping this query and return the scalar integer result.
     *
     * @param string $expression  SQL expression.
     *
     * @return int Integer result.
     */
    public function count(string $expression = '*'): int
    {
        $countSql = $this->buildCountSql($expression);

        return (int) $this->executeSql('value', $countSql);
    }

    /**
     * Build count sql.
     *
     * @param string $expression  SQL expression.
     *
     * @return Sql Compiled SQL message.
     */
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
