<?php

declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  Query
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/query/select
 * @since       1.0.0
 *
 * This file provides a fluent, type-safe SELECT query builder that constructs
 * parameterized SQL statements without executing them. It offers a chainable API
 * for building complex SELECT queries with joins, filtering, ordering, and pagination
 * while preventing SQL injection through parameter binding. The builder produces
 * Sql objects that are executed by the Driver class, maintaining clear separation
 * between query construction and execution as required by UDA's architectural principles.
 */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\SQL\SqlMessage;


/**
 * SELECT query builder that produces Sql objects for execution
 */
final class Select extends Abs
{
    /** @var array Columns to select */
    private array $columns = [];
    
    /** @var bool Whether to use DISTINCT */
    private bool $distinct = false;
    
    /** @var ?string Table to select from */
    private ?string $table = null;
    
    /** @var ?string Table alias */
    private ?string $alias = null;
    
    /** @var array Join clauses */
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
    
    /** @var ?Sql Cached Sql instance for repeated toSql() calls */
    private ?Sql $cachedSql = null;

    /**
     * 
     * @param string ...$columns The columns to select
     * @return self
     */
    public function select(string ...$columns): self
    {
        if (empty($columns)) {
            // No columns specified means SELECT *
            $this->columns = [];
            return $this;
        }
        
        foreach ($columns as $column) {
            // Check if column looks like a literal (numeric or quoted string)
            if (is_numeric($column) || $column === '?') {
                // Numeric literal or parameter placeholder
                $this->columns[] = $column;
            } else {
                $this->columns[] = $this->quote($column);
            }
        }
        return $this;
    }
    
    /**
     * Add raw SQL expressions to SELECT clause
     *
     * @param string ...$expressions Raw SQL expressions
     * @return self
     */
    public function selectRaw(string ...$expressions): self
    {
        foreach ($expressions as $expression) {
            $this->columns[] = $expression;
        }
        return $this;
    }

    /**
     * 
     * @param string $table The table name
     * @param ?string $alias Optional table alias
     * @return self
     */
    public function from(string $table, ?string $alias = null): self
    {
        $this->table = $table;
        $this->alias = $alias;
        $this->hintTables = [$table];
        return $this;
    }

    /**
     * 
     * @param string $table The table to join
     * @param string $left The left column
     * @param string $right The right column
     * @param string $type The join type (INNER, LEFT, RIGHT, etc.)
     * @param ?string $alias Optional table alias
     * @return self
     */
    public function join(string $table, string $left, string $right, string $type = 'INNER', ?string $alias = null): self
    {
        $tableClause = $this->quote($table);
        if ($alias !== null) {
            $tableClause .= ' AS ' . $this->quote($alias);
        }
        $this->joins[] = sprintf('%s JOIN %s ON %s = %s', strtoupper($type), $tableClause, $this->quote($left), $this->quote($right));
        if (!in_array($table, $this->hintTables, true)) {
            $this->hintTables[] = $table;
        }
        return $this;
    }

    /**
     * 
     * @param string $column The column name
     * @param mixed $value The value to compare against
     * @param string $operator The comparison operator
     * @return self
     */
    public function where(string $column, mixed $value, string $operator = '='): WhereBuilder
    {
        $whereBuilder = new WhereBuilder($this, $this->params, fn($id) => $this->quote($id));
        $whereBuilder->where($column, $value, $operator);
        return $whereBuilder;
    }

    /**
     * 
     * @param string $left The left column
     * @param string $right The right column
     * @param string $operator The comparison operator
     * @return self
     */
    public function whereColumn(string $left, string $right, string $operator = '='): WhereBuilder
    {
        $whereBuilder = new WhereBuilder($this, $this->params, fn($id) => $this->quote($id));
        $whereBuilder->whereColumn($left, $right, $operator);
        return $whereBuilder;
    }
    
    

    /**
     * 
     * @param string ...$columns The columns to group by
     * @return self
     */
    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groupBy[] = $this->quote($column);
        }
        return $this;
    }


    
    /**
     * Start a WHERE EXISTS chain
     *
     * @param Sql $subquery Subquery to check for existence
     * @return WhereBuilder
     */
    public function whereExists(\UDA\Query\Sql $subquery): WhereBuilder
    {
        $whereBuilder = new WhereBuilder($this, $this->params, fn($id) => $this->quote($id));
        $whereBuilder->exists($subquery);
        return $whereBuilder;
    }
    
    /**
     * Start a WHERE NOT EXISTS chain
     *
     * @param Sql $subquery Subquery to check for non-existence
     * @return WhereBuilder
     */
    public function whereNotExists(\UDA\Query\Sql $subquery): WhereBuilder
    {
        $whereBuilder = new WhereBuilder($this, $this->params, fn($id) => $this->quote($id));
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
        $this->distinct = true;
        return $this;
    }
    
    /**
     * Start a HAVING chain for aggregate filtering
     *
     * @param string $column Aggregate expression
     * @param mixed $value Comparison value (optional for fluent operator attachment)
     * @param string $operator Comparison operator (=, !=, >, <, >=, <=)
     * @return WhereBuilder
     */
    public function having(string $column, mixed $value = null, string $operator = '='): WhereBuilder
    {
        $whereBuilder = new WhereBuilder($this, $this->params, fn($id) => $this->quote($id));
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
     * 
     * @param string $column The column to order by
     * @param string $direction The sort direction (ASC or DESC)
     * @param array $allowlist Allowlist of valid columns
     * @return self
     */
    public function orderBy(string $column, string $direction = 'ASC', array $allowlist = []): self
    {
        $fragment = $this->driverInstance->orderByAllowed($column, $allowlist ?: [$column => true], $direction);
        $this->orderBy[] = preg_replace('/^ORDER BY\s+/i', '', trim($fragment));
        return $this;
    }

    /**
     * 
     * @param int $limit The limit value
     * @return self
     * @throws QueryException If limit is negative
     */
    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new QueryException('Limit must be zero or positive');
        }
        $this->limit = $limit;
        return $this;
    }

    /**
     * 
     * @param int $offset The offset value
     * @return self
     * @throws QueryException If offset is negative
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new QueryException('Offset must be zero or positive');
        }
        $this->offset = $offset;
        return $this;
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

    /**
     * Get tables this query operates on for cache invalidation
     *
     * @return string[]
     */
    public function getTables(): array
    {
        return $this->cacheTables() ?? [];
    }

    /**
     * Generates a SQL representation of this SELECT query as an executable Sql object.
     *
     * This method constructs the complete SQL string with proper parameter placeholders
     * based on the query configuration (columns, joins, filters, ordering, etc.).
     * The result is cached internally to avoid repeated string building for immutable queries.
     *
     * @return Sql The executable SQL object containing both SQL string and named parameters
     * @throws QueryException If no table is defined (essential for FROM clause)
     * @throws QueryException If the query builder is not properly configured
     *
     * @see Sql::class Executable SQL value object
     * @see SqlMessage::class Alternative SQL representation for fragments
     * @example
     * $query = new Select();
     * $sql = $query->from('users')
     *     ->select('id', 'name')
     *     ->where('status', 'active')
     *     ->orderBy('name')
     *     ->toSql();
     * // Returns: Sql object with "SELECT id, name FROM users WHERE status = :p0 ORDER BY name"
     */
    public function toSql(): \UDA\Query\Sql
    {
        // Simple caching – the query is immutable after construction in the benchmark.
        if ($this->cachedSql !== null) {
            return $this->cachedSql;
        }
        
        if ($this->table === null) {
            throw new QueryException('No table defined for select query');
        }
        
        $columns = $this->columns !== [] ? implode(', ', $this->columns) : '*';
        if ($this->distinct) {
            $columns = 'DISTINCT ' . $columns;
        }
        $query = sprintf('SELECT %s FROM %s', $columns, $this->buildFromClause());
        
        if ($this->joins) {
            $query .= ' ' . implode(' ', $this->joins);
        }
        
        if ($this->builtWhere !== null) {
            $query .= ' WHERE ' . $this->builtWhere;
        } elseif ($this->where) {
            $query .= ' WHERE ' . implode(' AND ', $this->where);
        }
        
        if ($this->groupBy) {
            $query .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        
        if ($this->builtHaving !== null) {
            $query .= ' HAVING ' . $this->builtHaving;
        } elseif ($this->having) {
            $query .= ' HAVING ' . implode(' AND ', $this->having);
        }
        
        if ($this->orderBy) {
            $query .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }
        
        if ($this->limit !== null) {
            $query .= ' LIMIT ' . $this->param($this->limit);
        }
        
        if ($this->offset !== null) {
            $query .= ' OFFSET ' . $this->param($this->offset);
        }
        
        $this->cachedSql = new Sql($query, $this->params->getParams(), $this->getTables());
        return $this->cachedSql;
    }

    /**
     * 
     * @return string The FROM clause
     */
    private function buildFromClause(): string
    {
        $clause = $this->quote($this->table);
        if ($this->alias !== null) {
            $clause .= ' AS ' . $this->quote($this->alias);
        }
        return $clause;
    }
    
    // ----- Constitutional Execution Helpers -----
    
    /**
     * 
     * @return ?array The single row result or null
     * @throws QueryException If not bound to a driver
     */
    public function row(): ?array
    {
        if ($this->driverInstance === null) {
            throw new QueryException('Query builder not bound to driver - use $driver->selectRow($query)');
        }
        return $this->driverInstance->selectRow($this);
    }
    
    /**
     * 
     * @return array The rows result
     * @throws QueryException If not bound to a driver
     */
    public function rows(): array
    {
        if ($this->driverInstance === null) {
            throw new QueryException('Query builder not bound to driver - use $driver->selectRows($query)');
        }
        return $this->driverInstance->selectRows($this);
    }
    
    /**
     * 
     * @return mixed The single value result
     * @throws QueryException If not bound to a driver
     */
    public function value(): mixed
    {
        if ($this->driverInstance === null) {
            throw new QueryException('Query builder not bound to driver - use $driver->selectValue($query)');
        }
        return $this->driverInstance->selectValue($this);
    }
    
    /**
     * 
     * @return array The values from the first column
     * @throws QueryException If not bound to a driver
     */
    public function values(): array
    {
        if ($this->driverInstance === null) {
            throw new QueryException('Query builder not bound to driver - use $driver->selectValues($query)');
        }
        return $this->driverInstance->selectValues($this);
    }
    
    /**
     * 
     * @return array The single row as numerically indexed array
     * @throws QueryException If not bound to a driver
     */
    public function list(): array
    {
        if ($this->driverInstance === null) {
            throw new QueryException('Query builder not bound to driver - use $driver->selectList($query)');
        }
        return $this->driverInstance->selectList($this);
    }
    
    /**
     * 
     * @param callable $fn The function to call for each row
     * @return int The number of rows processed
     * @throws QueryException If not bound to a driver
     */
    public function each(callable $fn): int
    {
        if ($this->driverInstance === null) {
            throw new QueryException('Query builder not bound to driver - use $driver->each($query->toSql(), $fn)');
        }
        return $this->driverInstance->each($this->toSql(), $fn);
    }
    
    /**
     * 
     * @param string|null $expr The expression to count (default: *)
     * @return int The count of rows
     * @throws QueryException If not bound to a driver
     */
    public function count(string $expr = null): int
    {
        if ($this->driverInstance === null) {
            throw new QueryException('Query builder not bound to driver - use $driver->selectValue() with COUNT query');
        }
        
        // Build a count query from this query
        $sql = $this->toSql();
        $expression = $expr ?? '*';
        $countSql = new SqlMessage(
            sprintf('SELECT COUNT(%s) AS total FROM (%s) t', $expression, $sql->getQuery()),
            $sql->getParams()
        );
        
        return (int) $this->driverInstance->selectValueSql($countSql);
    }
}
