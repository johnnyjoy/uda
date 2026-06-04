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
 * Purpose: Builds composable SQL WHERE clauses with fluent, chainable methods and proper boolean operator nesting.
 */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\SQL\ParamBag;

/**
 * Fluent builder for constructing WHERE and HAVING clauses with complex boolean logic.
 *
 * This class implements the "where-chain mode" pattern documented in the query cookbook,
 * providing a fluent, composable interface for building SQL conditions with proper
 * operator precedence and parameter binding. It supports nesting conditions via closures,
 * enabling construction of arbitrarily complex boolean expressions without resorting
 * to raw SQL string manipulation.
 *
 * Design Patterns:
 * - **Fluent Interface**: Method chaining for readable expression building
 * - **Builder Pattern**: Constructs complex objects (SQL conditions) step by step
 * - **Parameter Binding**: All values are properly parameterized to prevent SQL injection
 *
 * Core Concepts:
 * 1. **Chain Initialization**: `where()` or `having()` starts a chain
 * 2. **Logical Operators**: `and()`, `or()`, `not()` combine conditions
 * 3. **Comparison Operators**: `in()`, `between()`, `like()`, `exists()` attach to columns
 * 4. **Nesting**: `group(callable $callback)` creates parenthesized expression groups
 * 5. **Termination**: `end()` returns to parent query builder
 *
 * Example - Simple WHERE chain:
 * ```php
 * $query->where('active', 1)
 * ->and('department_id')->in([10, 20, 30])
 * ->or(fn($w) => $w->and('title')->like('%Engineer%')
 * ->and('hire_date')->between('2020-01-01', '2024-12-31'))
 * ->end();
 * ```
 *
 * Example - HAVING chain with aggregation:
 * ```php
 * $query->groupBy('department_id')
 * ->having('COUNT(id)')->gt(5)
 * ->and('AVG(salary)')->lt(120000)
 * ->end();
 * ```
 *
 * The builder ensures proper SQL generation with named parameters, maintaining
 * UDA's architectural principle of separating query construction from execution.
 */
final class WhereBuilder
{
    /** @var array Conditions in the current chain as [operator, condition] pairs */
    private array $conditions = [];

    /** @var string Operator for next condition (AND/OR) */
    private string $nextOperator = 'AND';

    /** @var bool Whether we're in a negation context (NOT) */
    private bool $negated = false;

    /** @var Select|Update|Delete Parent query builder for nested expressions */
    private Select|Update|Delete $parent;

    /** @var ParamBag Parameter bag for storing query parameters */
    private ParamBag $params;

    /** @var string|Expr|null Current column being operated on (for operator attachment) */
    private string|Expr|null $currentColumn = null;

    /** @var bool Whether we're awaiting an operator (column()->operator() pattern) */
    private bool $awaitingOperator = false;

    /** @var bool Whether the chain has been terminated (end() called or method delegated) */
    private bool $terminated = false;

    /** @var bool Whether this builder is for HAVING clause (vs WHERE) */
    private bool $isHaving = false;

    /**
     * Creates a new WHERE or HAVING clause builder.
     * This constructor is typically called internally by query builders when
     * starting a WHERE or HAVING chain. Application code usually interacts with
     * WhereBuilder through the fluent interface rather than instantiating it directly.
     * Identifier quoting is delegated to the parent builder via `$parent->quote()`.
     *
     * @param Select|Update|Delete $parent  The query builder that owns this WHERE/HAVING clause.
     * @param ParamBag             $params  Shared parameter bag for storing all bound values.
     */
    public function __construct(Select|Update|Delete $parent, ParamBag $params)
    {
        $this->parent = $parent;
        $this->params = $params;
    }

    /**
     * Add a simple column=value condition
     *
     * @param string|Expr $column    Column name or structured expression
     * @param mixed       $value     Comparison value
     * @param string      $operator  Comparison operator (=, !=, >, <, >=, <=)
     *
     * @return self
     */
    public function where(string|Expr $column, mixed $value, string $operator = '='): self
    {
        $this->addCondition(
            sprintf('%s %s %s', $this->quoteColumn($column), $operator, $this->param($value))
        );

        return $this;
    }

    /**
     * Add a column comparison condition
     *
     * @param string $left      Left column name
     * @param string $right     Right column name
     * @param string $operator  Comparison operator (=, !=, >, <, >=, <=)
     *
     * @return self
     */
    public function whereColumn(string $left, string $right, string $operator = '='): self
    {
        $this->addCondition(
            sprintf('%s %s %s', $this->quote($left), $operator, $this->quote($right))
        );

        return $this;
    }

    /**
     * Switch to AND mode for subsequent conditions
     *
     * @param string|Expr|callable|null $column  Optional column/expression for operator attachment pattern, or closure for nested group
     *
     * @return self
     */
    public function and(string|Expr|callable|null $column = null): self
    {
        if (is_callable($column)) {
            return $this->group($column);
        }

        if ($column !== null) {
            $this->currentColumn = $column;
            $this->awaitingOperator = true;
            $this->nextOperator = 'AND';

            return $this;
        }

        $this->nextOperator = 'AND';

        return $this;
    }

    /**
     * Switch to OR mode for subsequent conditions
     *
     * @param string|Expr|callable|null $column  Optional column/expression for operator attachment pattern, or closure for nested group
     *
     * @return self
     */
    public function or(string|Expr|callable|null $column = null): self
    {
        if (is_callable($column)) {
            // Create OR group
            $savedNextOperator = $this->nextOperator;
            $this->nextOperator = 'OR';
            $this->group($column);
            $this->nextOperator = $savedNextOperator;

            return $this;
        }

        if ($column !== null) {
            $this->currentColumn = $column;
            $this->awaitingOperator = true;
            $this->nextOperator = 'OR';

            return $this;
        }

        $this->nextOperator = 'OR';

        return $this;
    }

    /**
     * Enter negation context (NOT) for next condition
     *
     * @return self
     */
    public function not(): self
    {
        $this->negated = true;

        return $this;
    }

    /**
     * IN on the current column (after `and($column)` / `or($column)`).
     * Scalars are named parameters; subquery via `Select` / `Sql`. Empty array → `1 = 0`.
     *
     * @param array|Select|Sql $values  List of scalars, or subquery
     *
     * @return self
     *
     * @throws QueryException If no column is awaiting an operator
     */
    public function in(array|Select|Sql $values): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }

        if ($values instanceof Select || $values instanceof Sql) {
            $sub = $this->renderSubquery($values);
            $this->addCondition(sprintf('%s IN %s', $this->quoteColumn($this->currentColumn), $sub));
        } else {
            if ($values === []) {
                $this->addCondition('1 = 0');
            } else {
                $placeholders = [];

                foreach ($values as $value) {
                    $placeholders[] = $this->param($value);
                }

                $this->addCondition(sprintf('%s IN (%s)', $this->quoteColumn($this->currentColumn), implode(', ', $placeholders)));
            }
        }

        $this->awaitingOperator = false;
        $this->currentColumn = null;

        return $this;
    }

    /**
     * Not in.
     *
     * @param array|Select|Sql $values  Values to process.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
    public function notIn(array|Select|Sql $values): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }

        if ($values instanceof Select || $values instanceof Sql) {
            $sub = $this->renderSubquery($values);
            $this->addCondition(sprintf('%s NOT IN %s', $this->quoteColumn($this->currentColumn), $sub));
        } else {
            if ($values === []) {
                $this->addCondition('1 = 1');
            } else {
                $placeholders = [];

                foreach ($values as $value) {
                    $placeholders[] = $this->param($value);
                }

                $this->addCondition(sprintf('%s NOT IN (%s)', $this->quoteColumn($this->currentColumn), implode(', ', $placeholders)));
            }
        }

        $this->awaitingOperator = false;
        $this->currentColumn = null;

        return $this;
    }

    /**
     * Attach BETWEEN operator to current column
     *
     * @param mixed $lower  Lower bound value
     * @param mixed $upper  Upper bound value
     *
     * @return self
     *
     * @throws QueryException If no column is awaiting an operator
     */
    public function between(mixed $lower, mixed $upper): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }

        $this->addCondition(sprintf(
            '%s BETWEEN %s AND %s',
            $this->quoteColumn($this->currentColumn),
            $this->param($lower),
            $this->param($upper)
        ));

        $this->awaitingOperator = false;
        $this->currentColumn = null;

        return $this;
    }

    /**
     * Report whether is null.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
    public function isNull(): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }

        $this->addCondition(sprintf('%s IS NULL', $this->quoteColumn($this->currentColumn)));
        $this->awaitingOperator = false;
        $this->currentColumn = null;

        return $this;
    }

    /**
     * Report whether is not null.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
    public function isNotNull(): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }

        $this->addCondition(sprintf('%s IS NOT NULL', $this->quoteColumn($this->currentColumn)));
        $this->awaitingOperator = false;
        $this->currentColumn = null;

        return $this;
    }

    /**
     * Attach LIKE operator to current column
     *
     * @param string $pattern  LIKE pattern
     *
     * @return self
     *
     * @throws QueryException If no column is awaiting an operator
     */
    public function like(string $pattern): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }

        $this->addCondition(sprintf('%s LIKE %s', $this->quoteColumn($this->currentColumn), $this->param($pattern)));

        $this->awaitingOperator = false;
        $this->currentColumn = null;

        return $this;
    }

    /**
     * Not like.
     *
     * @param string $pattern  LIKE pattern.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
    public function notLike(string $pattern): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }

        $this->addCondition(sprintf('%s NOT LIKE %s', $this->quoteColumn($this->currentColumn), $this->param($pattern)));

        $this->awaitingOperator = false;
        $this->currentColumn = null;

        return $this;
    }

    /**
     * Attach > operator to current column
     *
     * @param mixed $value  Comparison value
     *
     * @return self
     *
     * @throws QueryException If no column is awaiting an operator
     */
    public function gt(mixed $value): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }

        $this->addCondition(sprintf('%s > %s', $this->quoteColumn($this->currentColumn), $this->param($value)));

        $this->awaitingOperator = false;
        $this->currentColumn = null;

        return $this;
    }

    /**
     * Attach < operator to current column
     *
     * @param mixed $value  Comparison value
     *
     * @return self
     *
     * @throws QueryException If no column is awaiting an operator
     */
    public function lt(mixed $value): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }

        $this->addCondition(sprintf('%s < %s', $this->quoteColumn($this->currentColumn), $this->param($value)));

        $this->awaitingOperator = false;
        $this->currentColumn = null;

        return $this;
    }

    /**
     * Attach >= operator to current column
     *
     * @param mixed $value  Comparison value
     *
     * @return self
     *
     * @throws QueryException If no column is awaiting an operator
     */
    public function gte(mixed $value): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }

        $this->addCondition(sprintf('%s >= %s', $this->quoteColumn($this->currentColumn), $this->param($value)));

        $this->awaitingOperator = false;
        $this->currentColumn = null;

        return $this;
    }

    /**
     * Attach <= operator to current column
     *
     * @param mixed $value  Comparison value
     *
     * @return self
     *
     * @throws QueryException If no column is awaiting an operator
     */
    public function lte(mixed $value): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }

        $this->addCondition(sprintf('%s <= %s', $this->quoteColumn($this->currentColumn), $this->param($value)));

        $this->awaitingOperator = false;
        $this->currentColumn = null;

        return $this;
    }

    /**
     * Attach = operator to current column
     *
     * @param mixed $value  Comparison value
     *
     * @return self
     *
     * @throws QueryException If no column is awaiting an operator
     */
    public function eq(mixed $value): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }

        $this->addCondition(sprintf('%s = %s', $this->quoteColumn($this->currentColumn), $this->param($value)));

        $this->awaitingOperator = false;
        $this->currentColumn = null;

        return $this;
    }

    /**
     * Attach != operator to current column
     *
     * @param mixed $value  Comparison value
     *
     * @return self
     *
     * @throws QueryException If no column is awaiting an operator
     */
    public function neq(mixed $value): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }

        $this->addCondition(sprintf('%s != %s', $this->quoteColumn($this->currentColumn), $this->param($value)));

        $this->awaitingOperator = false;
        $this->currentColumn = null;

        return $this;
    }

    /**
     * Create EXISTS condition with subquery
     *
     * @param Sql $subquery  Subquery to check for existence
     *
     * @return self
     */
    public function exists(Select|Sql $subquery): self
    {
        $this->addCondition('EXISTS ' . $this->renderSubquery($subquery));

        return $this;
    }

    /**
     * Create NOT EXISTS condition with subquery
     *
     * @param Sql $subquery  Subquery to check for non-existence
     *
     * @return self
     */
    public function notExists(Select|Sql $subquery): self
    {
        $this->addCondition('NOT EXISTS ' . $this->renderSubquery($subquery));

        return $this;
    }

    /**
     * Start a nested expression group using closure
     *
     * @param callable $callback  Receives a WhereBuilder for nested conditions
     *
     * @return self
     */
    public function group(callable $callback): self
    {
        $nested = new self($this->parent, $this->params);
        $nested->nextOperator = 'AND'; // Groups default to AND inside
        $callback($nested);

        $nestedSql = $nested->build();

        if ($nestedSql !== '') {
            $this->addCondition('(' . $nestedSql . ')');
        }

        return $this;
    }

    /**
     * End the current WHERE/HAVING chain and return to parent query builder.
     *
     * @return Select|Update|Delete Parent query builder
     *
     * @throws QueryException If the operation fails.
     */
    public function end()
    {
        $this->terminated = true;

        if ($this->parent === null) {
            throw new QueryException('No parent query builder to return to');
        }

        // Store the clause in the parent query
        $sql = $this->build();

        if ($sql !== '') {
            if ($this->isHaving) {
                $this->parent->setHavingClause($sql);
            } else {
                $this->parent->setWhereClause($sql);
            }
        }

        return $this->parent;
    }

    // -------------------------------------------------------------------------
    // Proxy terminators — call end() then forward, so ->where(...)->rows() and
    // ->where(...)->toSql() work without requiring an explicit ->end() call.
    // -------------------------------------------------------------------------

    /**
     * End the chain and return all matching rows.
     *
     * @return array Result array.
     *
     * @throws QueryException If the parent builder is not a Select.
     */
    public function rows(): array
    {
        $parent = $this->end();

        if (!$parent instanceof Select) {
            throw new QueryException('rows() is only available on SELECT builders.');
        }

        return $parent->rows();
    }

    /**
     * End the chain and execute the write, returning the affected-row count.
     *
     * `exec()` is a terminator, so it closes the WHERE chain automatically; an
     * explicit `->end()` is not required.
     *
     * @return int Affected row count.
     *
     * @throws QueryException If the parent builder is not an UPDATE or DELETE.
     */
    public function exec(): int
    {
        $parent = $this->end();

        if (!$parent instanceof Update && !$parent instanceof Delete) {
            throw new QueryException('exec() is only available on UPDATE/DELETE builders.');
        }

        return $parent->exec();
    }

    /**
     * End the chain and return the first row (or null).
     *
     * @return ?array The first row or null.
     *
     * @throws QueryException If the parent builder is not a Select.
     */
    public function row(): ?array
    {
        $parent = $this->end();

        if (!$parent instanceof Select) {
            throw new QueryException('row() is only available on SELECT builders.');
        }

        return $parent->row();
    }

    /**
     * End the chain and return a single column value.
     *
     * @return mixed Execution result.
     *
     * @throws QueryException If the parent builder is not a Select.
     */
    public function value(): mixed
    {
        $parent = $this->end();

        if (!$parent instanceof Select) {
            throw new QueryException('value() is only available on SELECT builders.');
        }

        return $parent->value();
    }

    /**
     * End the chain and return the first column from every row.
     *
     * @return array Result array.
     *
     * @throws QueryException If the parent builder is not a Select.
     */
    public function values(): array
    {
        $parent = $this->end();

        if (!$parent instanceof Select) {
            throw new QueryException('values() is only available on SELECT builders.');
        }

        return $parent->values();
    }

    /**
     * End the chain and return the first row as a numeric list.
     *
     * @return ?array<int,mixed> Row values or null.
     *
     * @throws QueryException If the parent builder is not a Select.
     */
    public function list(): ?array
    {
        $parent = $this->end();

        if (!$parent instanceof Select) {
            throw new QueryException('list() is only available on SELECT builders.');
        }

        return $parent->list();
    }

    /**
     * End the chain and stream each row to a callable.
     *
     * @param callable $fn  Callback to execute.
     *
     * @return int Number of rows processed.
     *
     * @throws QueryException If the parent builder is not a Select.
     */
    public function each(callable $fn): int
    {
        $parent = $this->end();

        if (!$parent instanceof Select) {
            throw new QueryException('each() is only available on SELECT builders.');
        }

        return $parent->each($fn);
    }

    /**
     * End the chain and execute COUNT(*) or COUNT(expression).
     *
     * @param string $expression  SQL expression (default '*').
     *
     * @return int Row count.
     *
     * @throws QueryException If the parent builder is not a Select.
     */
    public function count(string $expression = '*'): int
    {
        $parent = $this->end();

        if (!$parent instanceof Select) {
            throw new QueryException('count() is only available on SELECT builders.');
        }

        return $parent->count($expression);
    }

    /**
     * End the chain and compile SELECT to Sql (no execution).
     *
     * @return Sql Compiled SQL and parameters.
     *
     * @throws QueryException If the parent builder is not a Select.
     */
    public function toSql(): Sql
    {
        $parent = $this->end();

        if (!$parent instanceof Select) {
            throw new QueryException('toSql() is only available on SELECT builders.');
        }

        return $parent->toSql();
    }

    /**
     * Set whether this builder is for HAVING clause
     *
     * @param bool $isHaving  True for HAVING, false for WHERE
     *
     * @return void No return value.
     */
    public function setHavingMode(bool $isHaving): void
    {
        $this->isHaving = $isHaving;
    }

    /**
     * Set current column for fluent operator attachment
     *
     * @param string|Expr $column  Column/expression
     *
     * @return void No return value.
     */
    public function setCurrentColumn(string|Expr $column): void
    {
        $this->currentColumn = $column;
        $this->awaitingOperator = true;
    }

    /**
     * Compile chained conditions into a WHERE/HAVING fragment (no leading keyword).
     * Called from `end()`; returns '' when there are no conditions.
     *
     * @return string SQL fragment
     */
    public function build(): string
    {
        if (empty($this->conditions)) {
            return '';
        }

        $parts = [];

        foreach ($this->conditions as $index => $conditionData) {
            $operator = $conditionData['operator'];
            $condition = $conditionData['condition'];

            if ($index === 0) {
                // First condition doesn't need operator
                $parts[] = $condition;
            } else {
                $parts[] = $operator . ' ' . $condition;
            }
        }

        $sql = implode(' ', $parts);

        if ($this->negated) {
            $sql = 'NOT (' . $sql . ')';
            $this->negated = false; // Reset after use
        }

        return $sql;
    }

    /**
     * Get the parameter bag for parameter extraction
     *
     * @return ParamBag The parameter bag
     */
    public function getParams(): ParamBag
    {
        return $this->params;
    }

    /**
     * Add a condition to the current chain
     *
     * @param string $condition  SQL condition fragment
     *
     * @return void No return value.
     */
    private function addCondition(string $condition): void
    {
        $this->conditions[] = [
            'operator' => $this->nextOperator,
            'condition' => $condition
        ];
        $this->negated = false; // Reset negation after use
        $this->nextOperator = 'AND'; // Reset to AND for next condition
    }

    /**
     * Where raw.
     *
     * @param string $sql     SQL string, SQL message, or builder SQL object.
     * @param array  $params  Named parameter values.
     *
     * @return self Configured instance.
     */
    public function whereRaw(string $sql, array $params = []): self
    {
        foreach ($params as $key => $value) {
            $allocated = $this->param($value);

            if (is_string($key)) {
                $token = ':' . ltrim((string) $key, ':');
                $sql = $this->replaceNamedPlaceholder($sql, $token, $allocated);
            } else {
                $sql = $this->replaceFirstPlaceholder($sql, $allocated);
            }
        }

        $this->addCondition($sql);

        return $this;
    }

    /**
     * Replace first placeholder.
     *
     * @param string $sql    SQL string, SQL message, or builder SQL object.
     * @param string $value  Value to render or bind.
     *
     * @return string String result.
     *
     * @throws QueryException If the operation fails.
     */
    private function replaceFirstPlaceholder(string $sql, string $value): string
    {
        $pos = strpos($sql, '?');

        if ($pos === false) {
            throw new QueryException('whereRaw parameter count mismatch.');
        }

        return substr($sql, 0, $pos) . $value . substr($sql, $pos + 1);
    }

    /**
     * Replace named placeholder.
     *
     * @param string $sql    SQL string, SQL message, or builder SQL object.
     * @param string $token  SQL token.
     * @param string $value  Value to render or bind.
     *
     * @return string String result.
     *
     * @throws QueryException If the operation fails.
     */
    private function replaceNamedPlaceholder(string $sql, string $token, string $value): string
    {
        if (strpos($sql, $token) === false) {
            throw new QueryException(sprintf('Missing placeholder %s in whereRaw expression.', $token));
        }

        return preg_replace('/' . preg_quote($token, '/') . '/', $value, $sql, 1) ?? $sql;
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

        if (method_exists($this->parent, 'mergeSubqueryTables')) {
            $this->parent->mergeSubqueryTables($sql);
        }

        return '(' . $query . ')';
    }

    /**
     * Quote an identifier
     *
     * @param string $identifier  Identifier to quote
     *
     * @return string Quoted identifier
     */
    private function quote(string $identifier): string
    {
        return $this->parent->quote($identifier);
    }

    /**
     * Convert value to parameter placeholder
     *
     * @param mixed $value  Value to parameterize
     *
     * @return string Parameter placeholder
     */
    private function param(mixed $value): string
    {
        return \UDA\SQL\Value::param($this->params, $value);
    }

    /**
     * Quote column or expression
     *
     * @param string $column  Column name or expression
     *
     * @return string Quoted column or raw expression
     */
    private function quoteColumn(string|Expr $column): string
    {
        if ($column instanceof Expr) {
            return $column->getSql($this->params, includeAlias: false);
        }

        // Check if column looks like an expression (contains parentheses, spaces, or SQL functions)
        $isExpression = str_contains($column, '(') || str_contains($column, ')') ||
            str_contains($column, ' ') || str_contains(strtoupper($column), ' AS ');

        return $isExpression ? $column : $this->quote($column);
    }
}
