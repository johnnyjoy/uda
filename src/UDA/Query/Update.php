<?php

declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  Query
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/query/update
 * @since       1.0.0
 *
 * Query builder - builds SQL messages, does NOT execute
 */

namespace UDA\Query;

use UDA\Exception\QueryException;

/**
 * UPDATE query builder that produces Sql objects for execution
 */
final class Update extends Abs
{
    /** @var ?string Table to update */
    private ?string $table = null;
    
    /** @var array Column assignments */
    private array $sets = [];
    
    /** @var array WHERE conditions */
    private array $where = [];

    /**
     * 
     * @param string $table The table name
     * @return self
     */
    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * 
     * @param string $column The column name
     * @param mixed $value The value to set
     * @return self
     */
    public function set(string $column, mixed $value): self
    {
        $this->sets[$column] = $value;
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
     * @return int The number of affected rows
     * @throws QueryException If not bound to a driver
     */
    public function exec(): int
    {
        if ($this->driverInstance === null) {
            throw new QueryException('Query builder not bound to driver - use $driver->updateExec($query)');
        }
        return $this->driverInstance->updateExec($this);
    }

    // Query builders are immutable and do not execute - they only produce Sql objects
    // Execution is handled exclusively by the Driver class

    /**
     * Generates a SQL UPDATE statement with SET and WHERE clauses.
     *
     * This method constructs a parameterized UPDATE statement with proper
     * column quoting and named parameter placeholders. The WHERE clause is
     * included if any conditions have been specified.
     *
     * @return Sql The executable SQL UPDATE statement with named parameters
     * @throws QueryException If no table is defined (via table() method)
     * @throws QueryException If no column values have been set (via set() method)
     *
     * @see Update::set() Method to define column updates
     * @see Update::where() Method to add WHERE conditions
     * @see Update::whereColumn() Method for column-to-column comparisons
     * @example
     * $query = new Update();
     * $sql = $query->table('users')
     *     ->set('status', 'inactive')
     *     ->where('last_login', '2022-01-01', '<')
     *     ->toSql();
     * // Returns: Sql object with "UPDATE users SET status = :p0 WHERE last_login < :p1"
     */
    public function toSql(): Sql
    {
        // Simple caching – the query is immutable after construction
        if ($this->cachedSql !== null) {
            return $this->cachedSql;
        }
        
        if ($this->table === null) {
            throw new QueryException('No table defined for update query');
        }

        if ($this->sets === []) {
            throw new QueryException('No values set for update query');
        }

        $assignments = [];

        foreach ($this->sets as $column => $value) {
            $assignments[] = sprintf('%s = %s', $this->quote($column), $this->param($value));
        }

        $query = sprintf('UPDATE %s SET %s', $this->quote($this->table), implode(', ', $assignments));

        if ($this->builtWhere !== null) {
            $query .= ' WHERE ' . $this->builtWhere;
        } elseif ($this->where) {
            $query .= ' WHERE ' . implode(' AND ', $this->where);
        }

        $this->cachedSql = new Sql($query, $this->params->getParams(), [$this->table]);
        return $this->cachedSql;
    }
}

