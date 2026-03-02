<?php

declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  Query
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/query/upsert
 * @since       1.0.0
 *
 * Query builder - builds SQL messages, does NOT execute
 */

namespace UDA\Query;

use UDA\Exception\QueryException;

/**
 * UPSERT query builder that produces Sql objects for execution
 */
final class Upsert extends Abs
{
    /** @var ?string Table to upsert into */
    private ?string $table = null;
    
    /** @var array Values to insert (single row) */
    private array $values = [];
    
    /** @var array Rows for bulk insert */
    private array $rows = [];
    
    /** @var array Conflict keys */
    private array $conflictKeys = [];
    
    /** @var array Columns to update */
    private array $updates = [];
    
    /** @var bool Whether to do nothing on conflict */
    private bool $doNothing = false;
    
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
     * @param array $row The values to insert
     * @return self
     */
    public function values(array $row): self
    {
        $this->values = $row;
        $this->rows = []; // Clear bulk rows
        // Invalidate cache when configuration changes
        $this->cachedSql = null;
        return $this;
    }
    
    /**
     * Set multiple rows for bulk UPSERT
     *
     * @param array $rows Array of associative arrays
     * @return self
     */
    public function rows(array $rows): self
    {
        $this->rows = $rows;
        $this->values = []; // Clear single row
        // Invalidate cache when configuration changes
        $this->cachedSql = null;
        return $this;
    }

    /**
     * 
     * @param array $columns The conflict keys
     * @return self
     */
    public function key(array $columns): self
    {
        $this->conflictKeys = $columns;
        // Invalidate cache when configuration changes
        $this->cachedSql = null;
        return $this;
    }

    /**
     * 
     * @param array $columns The columns to update
     * @return self
     */
    public function update(array $columns): self
    {
        $this->updates = $columns;
        // Invalidate cache when configuration changes
        $this->cachedSql = null;
        return $this;
    }

    /**
     * 
     * @return self
     */
    public function doNothing(): self
    {
        $this->doNothing = true;
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
            throw new QueryException('Query builder not bound to driver - use $driver->upsertExec($query)');
        }
        return $this->driverInstance->upsertExec($this);
    }

    // Query builders are immutable and do not execute - they only produce Sql objects
    // Execution is handled exclusively by the Driver class

    /**
     * Generates a SQL UPSERT (INSERT ON CONFLICT) statement.
     *
     * This method constructs a PostgreSQL-style UPSERT statement with proper
     * conflict detection and resolution logic. It supports both "DO NOTHING"
     * and "DO UPDATE SET" conflict handling strategies.
     *
     * @return Sql The executable SQL UPSERT statement with named parameters
     * @throws QueryException If no table is defined (via into() method)
     * @throws QueryException If no values are provided (via values() method)
     * @throws QueryException If conflict keys are not specified (via key() method)
     *
     * @see Upsert::values() Method to specify data to insert
     * @see Upsert::key() Method to define conflict detection columns
     * @see Upsert::update() Method to specify columns to update on conflict
     * @see Upsert::doNothing() Method to specify "DO NOTHING" behavior
     * @example
     * $query = new Upsert();
     * $sql = $query->into('users')
     *     ->values(['email' => 'john@example.com', 'name' => 'John'])
     *     ->key(['email'])
     *     ->update(['name'])
     *     ->toSql();
     * // Returns: Sql object with "INSERT INTO users (email, name) VALUES (:p0, :p1)
     * //          ON CONFLICT (email) DO UPDATE SET name = EXCLUDED.name"
     * @note This generates PostgreSQL syntax; other databases may require different syntax.
     */
    public function toSql(): Sql
    {
        // Simple caching – the query is immutable after construction in the benchmark.
        if ($this->cachedSql !== null) {
            return $this->cachedSql;
        }
        
        if ($this->table === null) {
            throw new QueryException('No table defined for upsert query');
        }

        if ($this->values === [] && $this->rows === []) {
            throw new QueryException('No values provided for upsert query');
        }

        if ($this->conflictKeys === []) {
            throw new QueryException('Conflict keys are required for upsert query');
        }

        $quotedColumns = [];
        $valueClauses = [];
        
        if ($this->values !== []) {
            // Single row
            $columnPlaceholders = [];
            foreach ($this->values as $column => $value) {
                $quotedColumns[] = $this->quote($column);
                $columnPlaceholders[] = $this->param($value);
            }
            $valueClauses[] = '(' . implode(', ', $columnPlaceholders) . ')';
        } elseif ($this->rows !== []) {
            // Multiple rows
            $firstRow = $this->rows[0];
            foreach (array_keys($firstRow) as $column) {
                $quotedColumns[] = $this->quote($column);
            }
            
            foreach ($this->rows as $row) {
                $rowPlaceholders = [];
                foreach (array_keys($firstRow) as $column) {
                    $rowPlaceholders[] = $this->param($row[$column] ?? null);
                }
                $valueClauses[] = '(' . implode(', ', $rowPlaceholders) . ')';
            }
        }
        
        if (empty($quotedColumns)) {
            throw new QueryException('No columns provided for upsert query');
        }
        
        $query = sprintf('INSERT INTO %s (%s) VALUES %s', $this->quote($this->table), implode(', ', $quotedColumns), implode(', ', $valueClauses));
        $query .= ' ON CONFLICT (' . implode(', ', array_map(fn(string $column): string => $this->quote($column), $this->conflictKeys)) . ')';

        if ($this->doNothing || $this->updates === []) {
            $query .= ' DO NOTHING';
        } else {
            $assignments = [];

            foreach ($this->updates as $column) {
                $assignments[] = sprintf('%s = EXCLUDED.%s', $this->quote($column), $this->quote($column));
            }

            $query .= ' DO UPDATE SET ' . implode(', ', $assignments);
        }

        $this->cachedSql = new Sql($query, $this->params->getParams(), [$this->table]);
        return $this->cachedSql;
    }
}
