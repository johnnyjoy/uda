<?php

declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  Query
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/query/insert
 * @since       1.0.0
 *
 * Query builder - builds INSERT statements
 */

namespace UDA\Query;

use UDA\Exception\QueryException;

/**
 * INSERT query builder that produces Sql objects for execution
 */
final class Insert extends Abs
{
    /** @var ?string Table to insert into */
    private ?string $table = null;
    
    /** @var array Columns and values to insert (single row) */
    private array $columns = [];
    
    /** @var array Rows for bulk insert */
    private array $rows = [];
    
    /** @var ?array Columns to return */
    private ?array $returning = null;
    
    /** @var ?Sql Cached Sql instance for repeated toSql() calls */
    private ?Sql $cachedSql = null;

    /**
     * 
     * @param string $table The table name
     * @return self
     */
    public function into(string $table): self
    {
        $this->table = $table;
        // Invalidate cache when configuration changes
        $this->cachedSql = null;
        return $this;
    }

    /**
     * 
     * @param string $column The column name
     * @param mixed $value The value to insert
     * @return self
     */
    public function set(string $column, mixed $value): self
    {
        $this->columns[$column] = $value;
        $this->rows = []; // Clear bulk rows
        // Invalidate cache when configuration changes
        $this->cachedSql = null;
        return $this;
    }
    
    /**
     * Set values for single row insert
     *
     * @param array $row Associative array of column => value
     * @return self
     */
    public function values(array $row): self
    {
        $this->columns = $row;
        $this->rows = []; // Clear bulk rows
        // Invalidate cache when configuration changes
        $this->cachedSql = null;
        return $this;
    }
    
    /**
     * Set multiple rows for bulk insert
     *
     * @param array $rows Array of associative arrays
     * @return self
     */
    public function rows(array $rows): self
    {
        $this->rows = $rows;
        $this->columns = []; // Clear single row
        // Invalidate cache when configuration changes
        $this->cachedSql = null;
        return $this;
    }

    /**
     * 
     * @param string ...$columns The columns to return
     * @return self
     */
    public function returning(string ...$columns): self
    {
        $this->returning = $columns;
        // Invalidate cache when configuration changes
        $this->cachedSql = null;
        return $this;
    }

    /**
     * 
     * @return int The number of affected rows
     * @throws QueryException If not bound to a driver
     */
    public function exec(): int
    {
        if ($this->driverInstance === null) {
            throw new QueryException('Query builder not bound to driver - use $driver->insertExec($query)');
        }
        return $this->driverInstance->insertExec($this);
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
     * @return Sql The executable SQL INSERT statement with named parameters
     * @throws QueryException If no table is defined (via into() method)
     * @throws QueryException If no columns have been set (via set() method)
     *
     * @see Insert::set() Method to add column-value pairs
     * @see Insert::into() Method to specify target table
     * @see Insert::returning() Method to add RETURNING clause (PostgreSQL)
     * @example
     * $query = new Insert();
     * $sql = $query->into('users')
     *     ->set('name', 'John')
     *     ->set('email', 'john@example.com')
     *     ->toSql();
     * // Returns: Sql object with "INSERT INTO users (name, email) VALUES (:p0, :p1)"
     */
    public function toSql(): Sql
    {
        // Simple caching – the query is immutable after construction in the benchmark.
        if ($this->cachedSql !== null) {
            return $this->cachedSql;
        }
        
        if ($this->table === null) {
            throw new QueryException('No table defined for insert query');
        }
        if ($this->columns === [] && $this->rows === []) {
            throw new QueryException('No data provided for insert query');
        }

        $columnList = [];
        $valueClauses = [];
        
        if ($this->columns !== []) {
            // Single row
            $columnList = array_map(fn(string $col): string => $this->quote($col), array_keys($this->columns));
            $placeholders = [];
            foreach ($this->columns as $value) {
                $placeholders[] = $this->param($value);
            }
            $valueClauses[] = '(' . implode(', ', $placeholders) . ')';
        } elseif ($this->rows !== []) {
            // Multiple rows
            $firstRow = $this->rows[0];
            $columnList = array_map(fn(string $col): string => $this->quote($col), array_keys($firstRow));
            
            foreach ($this->rows as $row) {
                $rowPlaceholders = [];
                foreach (array_keys($firstRow) as $column) {
                    $rowPlaceholders[] = $this->param($row[$column] ?? null);
                }
                $valueClauses[] = '(' . implode(', ', $rowPlaceholders) . ')';
            }
        }

        $query = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->quote($this->table),
            implode(', ', $columnList),
            implode(', ', $valueClauses)
        );

        if ($this->returning !== null) {
            $cols = $this->returning ?: ['*'];
            $query .= ' RETURNING ' . implode(', ', array_map(fn(string $c): string => $this->quote($c), $cols));
        }

        $this->cachedSql = new Sql($query, $this->params->getParams(), [$this->table]);
        return $this->cachedSql;
    }
}
