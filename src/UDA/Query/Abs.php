<?php

declare(strict_types=1);

/**
 * Base query builder providing shared infrastructure for all concrete query builders.
 *
 * This abstract class implements the core functionality needed by all SQL query
 * builders in UDA: parameter management, identifier quoting, and the bridge to
 * the execution layer. It enforces the architectural principle that query
 * construction must be separate from query execution.
 *
 * Key Responsibilities:
 * 1. **Parameter Management**: Stores and namespaces query parameters safely
 * 2. **Identifier Quoting**: Safely quotes table/column names per database dialect
 * 3. **Driver Integration**: Provides hooks for database-specific behavior
 * 4. **WHERE/HAVING Storage**: Stores clauses built by WhereBuilder
 *
 * Design Pattern: Template Method Pattern
 * - Abstract: `toSql()` method that concrete classes must implement
 * - Concrete: Shared infrastructure methods (`quote()`, `param()`, etc.)
 *
 * Architectural Principle:
 * Query builders construct Sql objects but NEVER execute them. Execution is
 * delegated to the Driver class, maintaining clear separation of concerns.
 */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\SQL\Identifier;
use UDA\SQL\ParamBag;
use UDA\SQL\SqlMessage;
use UDA\SQL\Value;

/**
 * Abstract base class for all query builders implementing the builder pattern.
 */
abstract class Abs
{
    /**
     * 
     * @return Sql The SQL representation of this query
     */
    abstract public function toSql(): Sql;

    /** @var ?\UDA\Driver Driver instance for compatibility */
    public ?\UDA\Driver $driverInstance = null;
    /** @var string Driver name for quoting */
    public string $driverName = '';

    /** @var ParamBag Parameter bag for storing query parameters */
    protected ParamBag $params;
    
    /** @var ?string Built WHERE clause from WhereBuilder */
    protected ?string $builtWhere = null;
    
    /** @var ?string Built HAVING clause from WhereBuilder */
    protected ?string $builtHaving = null;
    
    /** @var array Cache for quoted identifiers */
    private array $quotedIdentifiers = [];

/**
     * Initializes a new query builder with empty parameter storage.
     *
     * The parameter bag uses 'q' as the default prefix for parameter names
     * (e.g., `:q1`, `:q2`). This prevents collisions when multiple queries
     * are combined or when subqueries are nested.
     */
    public function __construct()
    {
        $this->params = new ParamBag('q');
    }

    /**
     * Converts a value to a named parameter placeholder and stores the value.
     *
     * This method is the core of UDA's SQL injection protection. Rather than
     * concatenating values directly into SQL strings, it:
     * 1. Generates a unique parameter name (e.g., `:q123`)
     * 2. Stores the value in the parameter bag
     * 3. Returns the placeholder for use in the SQL string
     *
     * Example:
     * ```php
     * // Instead of: "WHERE id = " . $userId (DANGEROUS!)
     * // Use: "WHERE id = " . $this->param($userId)  // Returns ":q1"
     * ```
     *
     * The stored values are later bound to a PDO prepared statement, ensuring
     * proper escaping and type handling by the database driver.
     *
     * @param mixed $value The value to parameterize (any PHP type)
     * @return string Named parameter placeholder (e.g., `:q1`, `:q2`)
     */
    protected function param(mixed $value): string
    {
        return Value::param($this->params, $value);
    }

    /**
     * 
     * @param string $identifier The identifier to quote
     * @return string The quoted identifier
     * @throws QueryException If the identifier is invalid
     */
    protected function quote(string $identifier): string
    {
        if (!isset($this->quotedIdentifiers[$identifier])) {
            try {
                // Use stored driver name instead of accessing driver directly (spec compliance)
                $this->quotedIdentifiers[$identifier] = (new Identifier($identifier))->quoted($this->driverName);
            } catch (\Throwable $ex) {
                throw new QueryException('Invalid identifier: ' . $identifier, 0, $ex);
            }
        }
        return $this->quotedIdentifiers[$identifier];
    }

    /**
     * 
     * @param string $query The SQL query string
     * @return SqlMessage The constructed SqlMessage
     */
    protected function buildSql(string $query): SqlMessage
    {
        return new SqlMessage($query, $this->params->getParams());
    }
    
    /**
     * Set the WHERE clause built by WhereBuilder
     *
     * @param string $whereClause The WHERE clause SQL fragment
     */
    public function setWhereClause(string $whereClause): void
    {
        $this->builtWhere = $whereClause;
    }
    
    /**
     * Set the HAVING clause built by WhereBuilder
     *
     * @param string $havingClause The HAVING clause SQL fragment
     */
    public function setHavingClause(string $havingClause): void
    {
        $this->builtHaving = $havingClause;
    }
}
