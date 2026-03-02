<?php

declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  Query
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/query/delete
 * @since       1.0.0
 *
 * Query builder - builds SQL messages, does NOT execute
 */

namespace UDA\Query;

use UDA\Exception\QueryException;

/**
 * DELETE query builder that produces Sql objects for execution
 */
final class Delete extends Abs
{
    /** @var ?string Table to delete from */
    private ?string $table = null;
    
    /** @var array WHERE conditions */
    private array $where = [];
    
    /** @var ?Sql Cached Sql instance for repeated toSql() calls */
    private ?Sql $cachedSql = null;

    /**
     * 
     * @param string $table The table name
     * @return self
     */
    public function table(string $table): self
    {
        $this->table = $table;
        // Invalidate cache when configuration changes
        $this->cachedSql = null;
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
            throw new QueryException('Query builder not bound to driver - use $driver->deleteExec($query)');
        }
        return $this->driverInstance->deleteExec($this);
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
     * @return Sql The executable SQL DELETE statement with named parameters
     * @throws QueryException If no table is defined (via table() method)
     * @throws QueryException If WHERE conditions are not specified (prevents accidental data loss)
     *
     * @see Delete::where() Method to add WHERE conditions
     * @see Delete::whereColumn() Method for column-to-column comparisons
     * @example
     * $query = new Delete();
     * $sql = $query->table('users')
     *     ->where('id', 123)
     *     ->toSql();
     * // Returns: Sql object with "DELETE FROM users WHERE id = :p0"
     * @note For safety, always specify WHERE conditions before calling toSql().
     *       An exception will be thrown if no table is defined.
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

        $query = sprintf('DELETE FROM %s', $this->quote($this->table));

        if ($this->builtWhere !== null) {
            $query .= ' WHERE ' . $this->builtWhere;
        } elseif ($this->where) {
            $query .= ' WHERE ' . implode(' AND ', $this->where);
        }

        $this->cachedSql = new Sql($query, $this->params->getParams(), [$this->table]);
        return $this->cachedSql;
    }
}

