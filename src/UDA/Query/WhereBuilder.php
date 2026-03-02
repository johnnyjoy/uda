<?php

declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  Query
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/query/where-builder
 * @since       1.0.0
 *
 * Fluent WHERE clause builder for constructing complex boolean expressions with proper
 * operator support (AND, OR, NOT, IN, BETWEEN, LIKE, EXISTS). This builder enables
 * composable WHERE conditions that can be nested using closures, supporting the
 * "where-chain mode" documented in the query cookbook. It produces SQL fragments
 * with proper parameter binding and maintains the separation between query construction
 * and execution as required by UDA's architectural principles.
 *
 * The purpose of this class is to provide a fluent, chainable interface for building
 * complex WHERE clauses with proper operator precedence grouping and parameter binding,
 * preventing scope creep into raw SQL string manipulation while maintaining the
 * architectural separation between query building and execution.
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
 *     ->and('department_id')->in([10, 20, 30])
 *     ->or(fn($w) => $w->and('title')->like('%Engineer%')
 *                       ->and('hire_date')->between('2020-01-01', '2024-12-31'))
 *     ->end();
 * ```
 *
 * Example - HAVING chain with aggregation:
 * ```php
 * $query->groupBy('department_id')
 *     ->having('COUNT(id)')->gt(5)
 *     ->and('AVG(salary)')->lt(120000)
 *     ->end();
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
    
    /** @var callable Function to quote identifiers (string $identifier): string */
    private $quoter;
    
    /** @var string Current column being operated on (for operator attachment) */
    private string $currentColumn = '';
    
    /** @var bool Whether we're awaiting an operator (column()->operator() pattern) */
    private bool $awaitingOperator = false;
    
    /** @var bool Whether the chain has been terminated (end() called or method delegated) */
    private bool $terminated = false;
    
    /** @var bool Whether this builder is for HAVING clause (vs WHERE) */
    private bool $isHaving = false;
    
    

    /**
     * Creates a new WHERE or HAVING clause builder.
     *
     * This constructor is typically called internally by query builders when
     * starting a WHERE or HAVING chain. Application code usually interacts with
     * WhereBuilder through the fluent interface rather than instantiating it directly.
     *
     * @param Select|Update|Delete $parent The query builder that
     *        owns this WHERE/HAVING clause. Used to return control via `end()`.
     * @param ParamBag $params Shared parameter bag for storing all bound values
     *        across the query. Ensures parameter names are unique and values are
     *        properly escaped through prepared statements.
     * @param callable $quoter Function that safely quotes SQL identifiers
     *        (table/column names). Signature: `fn(string $identifier): string`.
     *        This abstraction allows database-specific quoting rules.
     */
    public function __construct(Select|Update|Delete $parent, ParamBag $params, callable $quoter)
    {
        $this->parent = $parent;
        $this->params = $params;
        $this->quoter = $quoter;
    }

    /**
     * Add a simple column=value condition
     *
     * @param string $column Column name or expression
     * @param mixed $value Comparison value
     * @param string $operator Comparison operator (=, !=, >, <, >=, <=)
     * @return self
     */
    public function where(string $column, mixed $value, string $operator = '='): self
    {
        $this->addCondition(
            sprintf('%s %s %s', $this->quoteColumn($column), $operator, $this->param($value))
        );
        return $this;
    }

    /**
     * Add a column comparison condition
     *
     * @param string $left Left column name
     * @param string $right Right column name
     * @param string $operator Comparison operator (=, !=, >, <, >=, <=)
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
     * @param ?string|callable $column Optional column name for operator attachment pattern, or closure for nested group
     * @return self
     */
    public function and($column = null): self
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
     * @param ?string|callable $column Optional column name for operator attachment pattern, or closure for nested group
     * @return self
     */
    public function or($column = null): self
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
     * Creates an IN condition for the current column.
     *
     * This method attaches an IN operator to the column specified by a preceding
     * `and($column)` or `or($column)` call. The values are properly parameterized
     * to prevent SQL injection.
     *
     * Example:
     * ```php
     * ->where('department_id', [10, 20, 30])  // Simple IN
     * // OR using fluent chain:
     * ->and('department_id')->in([10, 20, 30])
     * ```
     *
     * Empty list behavior: `->in([])` produces `1 = 0` (always false) for safety.
     * This prevents SQL syntax errors while maintaining logical correctness.
     *
     * @param array $values Array of values to match against. Can be mixed types
     *                      (strings, integers, etc.) as long as they're compatible
     *                      with the column type.
     * @return self
     * @throws QueryException If called without a preceding column context
     *                        (e.g., `in([...])` without `and($column)` first)
     */
    public function in(array $values): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }
        
        if (empty($values)) {
            $this->addCondition('1 = 0'); // Empty IN list always false
        } else {
            $placeholders = array_map(fn($v) => $this->param($v), $values);
            $this->addCondition(sprintf('%s IN (%s)', $this->quoteColumn($this->currentColumn), implode(', ', $placeholders)));
        }
        
        $this->awaitingOperator = false;
        $this->currentColumn = '';
        return $this;
    }

    /**
     * Attach BETWEEN operator to current column
     *
     * @param mixed $lower Lower bound value
     * @param mixed $upper Upper bound value
     * @return self
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
        $this->currentColumn = '';
        return $this;
    }

    /**
     * Attach LIKE operator to current column
     *
     * @param string $pattern LIKE pattern
     * @return self
     * @throws QueryException If no column is awaiting an operator
     */
    public function like(string $pattern): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }
        
        $this->addCondition(sprintf('%s LIKE %s', $this->quoteColumn($this->currentColumn), $this->param($pattern)));
        
        $this->awaitingOperator = false;
        $this->currentColumn = '';
        return $this;
    }
    
    /**
     * Attach > operator to current column
     *
     * @param mixed $value Comparison value
     * @return self
     * @throws QueryException If no column is awaiting an operator
     */
    public function gt(mixed $value): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }
        
        $this->addCondition(sprintf('%s > %s', $this->quoteColumn($this->currentColumn), $this->param($value)));
        
        $this->awaitingOperator = false;
        $this->currentColumn = '';
        return $this;
    }
    
    /**
     * Attach < operator to current column
     *
     * @param mixed $value Comparison value
     * @return self
     * @throws QueryException If no column is awaiting an operator
     */
    public function lt(mixed $value): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }
        
        $this->addCondition(sprintf('%s < %s', $this->quoteColumn($this->currentColumn), $this->param($value)));
        
        $this->awaitingOperator = false;
        $this->currentColumn = '';
        return $this;
    }
    
    /**
     * Attach >= operator to current column
     *
     * @param mixed $value Comparison value
     * @return self
     * @throws QueryException If no column is awaiting an operator
     */
    public function gte(mixed $value): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }
        
        $this->addCondition(sprintf('%s >= %s', $this->quoteColumn($this->currentColumn), $this->param($value)));
        
        $this->awaitingOperator = false;
        $this->currentColumn = '';
        return $this;
    }
    
    /**
     * Attach <= operator to current column
     *
     * @param mixed $value Comparison value
     * @return self
     * @throws QueryException If no column is awaiting an operator
     */
    public function lte(mixed $value): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }
        
        $this->addCondition(sprintf('%s <= %s', $this->quoteColumn($this->currentColumn), $this->param($value)));
        
        $this->awaitingOperator = false;
        $this->currentColumn = '';
        return $this;
    }
    
    /**
     * Attach = operator to current column
     *
     * @param mixed $value Comparison value
     * @return self
     * @throws QueryException If no column is awaiting an operator
     */
    public function eq(mixed $value): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }
        
        $this->addCondition(sprintf('%s = %s', $this->quoteColumn($this->currentColumn), $this->param($value)));
        
        $this->awaitingOperator = false;
        $this->currentColumn = '';
        return $this;
    }
    
    /**
     * Attach != operator to current column
     *
     * @param mixed $value Comparison value
     * @return self
     * @throws QueryException If no column is awaiting an operator
     */
    public function neq(mixed $value): self
    {
        if (!$this->awaitingOperator) {
            throw new QueryException('No column awaiting operator. Use and($column) or or($column) first.');
        }
        
        $this->addCondition(sprintf('%s != %s', $this->quoteColumn($this->currentColumn), $this->param($value)));
        
        $this->awaitingOperator = false;
        $this->currentColumn = '';
        return $this;
    }

    /**
     * Create EXISTS condition with subquery
     *
     * @param Sql $subquery Subquery to check for existence
     * @return self
     */
    public function exists(\UDA\Query\Sql $subquery): self
    {
        $this->addCondition('EXISTS (' . $subquery->sql . ')');
        return $this;
    }

    /**
     * Create NOT EXISTS condition with subquery
     *
     * @param Sql $subquery Subquery to check for non-existence
     * @return self
     */
    public function notExists(\UDA\Query\Sql $subquery): self
    {
        $this->addCondition('NOT EXISTS (' . $subquery->sql . ')');
        return $this;
    }

    /**
     * Start a nested expression group using closure
     *
     * @param callable $callback Receives a WhereBuilder for nested conditions
     * @return self
     */
    public function group(callable $callback): self
    {
        $nested = new self($this->parent, $this->params, $this->quoter);
        $nested->nextOperator = 'AND'; // Groups default to AND inside
        $callback($nested);
        
        $nestedSql = $nested->build();
        if ($nestedSql !== '') {
            $this->addCondition('(' . $nestedSql . ')');
        }
        
        return $this;
    }

    /**
     * End the current WHERE/HAVING chain and return to parent query builder
     *
     * @return Select|Update|Delete Parent query builder
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
    
    /**
     * Set whether this builder is for HAVING clause
     *
     * @param bool $isHaving True for HAVING, false for WHERE
     */
    public function setHavingMode(bool $isHaving): void
    {
        $this->isHaving = $isHaving;
    }
    
    /**
     * Set current column for fluent operator attachment
     *
     * @param string $column Column/expression
     */
    public function setCurrentColumn(string $column): void
    {
        $this->currentColumn = $column;
        $this->awaitingOperator = true;
    }
    
    

    /**
     * Compiles the constructed conditions into a SQL fragment.
     *
     * This internal method converts the chain of conditions built through
     * the fluent interface into a proper SQL WHERE or HAVING clause fragment.
     * It handles operator precedence, negation, and proper spacing.
     *
     * The compilation process:
     * 1. Combines all conditions with their appropriate logical operators (AND/OR)
     * 2. Applies negation (`NOT`) where specified
     * 3. Returns empty string if no conditions were added
     * 4. Properly formats the SQL for inclusion in a larger query
     *
     * Example output:
     * ```sql
     * active = :param1 AND department_id IN (:param2, :param3, :param4) 
     * OR (title LIKE :param5 AND hire_date BETWEEN :param6 AND :param7)
     * ```
     *
     * Note: This method is typically called internally by `end()` when
     * returning to the parent query builder. Application code rarely needs
     * to call it directly.
     *
     * @return string SQL fragment ready for inclusion in WHERE/HAVING clause,
     *                or empty string if no conditions were specified
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
     * @param string $condition SQL condition fragment
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
     * Quote an identifier
     *
     * @param string $identifier Identifier to quote
     * @return string Quoted identifier
     */
    private function quote(string $identifier): string
    {
        return ($this->quoter)($identifier);
    }

    /**
     * Convert value to parameter placeholder
     *
     * @param mixed $value Value to parameterize
     * @return string Parameter placeholder
     */
    private function param(mixed $value): string
    {
        return \UDA\SQL\Value::param($this->params, $value);
    }
    
    /**
     * Quote column or expression
     *
     * @param string $column Column name or expression
     * @return string Quoted column or raw expression
     */
    private function quoteColumn(string $column): string
    {
        // Check if column looks like an expression (contains parentheses, spaces, or SQL functions)
        $isExpression = str_contains($column, '(') || str_contains($column, ')') || 
                       str_contains($column, ' ') || str_contains(strtoupper($column), ' AS ');
        
        return $isExpression ? $column : $this->quote($column);
    }
}