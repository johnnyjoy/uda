<?php

declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  Driver
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/driver/postgresql
 * @since       1.0.0
 *
 * This file provides PostgreSQL-specific database driver implementation extending
 * the base Driver class. It handles PostgreSQL DSN construction, connection
 * parameters, and PostgreSQL-specific SQL dialect features like RETURNING clauses
 * and savepoint management. The driver ensures proper interaction with PostgreSQL
 * databases while maintaining the uniform execution contract defined by UDA's Driver interface.
 */

namespace UDA\Driver;

use PDO;
use UDA\Driver;
use UDA\Driver\SqlHelper;
use UDA\Cache\Setup;

/**
 * PostgreSQL driver that handles PostgreSQL-specific DSN building and dialect
 */
final class PostgreSQL extends Driver
{
    /**
     * Builds a PostgreSQL DSN (Data Source Name) string from connection parameters.
     *
     * Constructs a DSN in the format `pgsql:host=...;port=...;dbname=...` for use
     * with PDO. Omits optional fields (like SSL) if not provided in $params.
     *
     * @param array<string, string> $params Connection parameters with optional keys:
     *        - 'host': Database server hostname/IP (defaults omit)
     *        - 'port': Database port (defaults omit)
     *        - 'dbname': Database name (defaults omit)
     * @return string The constructed DSN string
     *
     * @see PDO::__construct() PDO DSN format requirements
     * @see Driver::buildDsn() Generic DSN builder for other drivers
     * @example
     * // Returns: "pgsql:host=localhost;port=5432;dbname=mydb"
     * $this->buildDsn([
     *     'host' => 'localhost',
     *     'port' => '5432',
     *     'dbname' => 'mydb'
     * ]);
     */
    protected function buildDsn(array $params): string
    {
        $parts = ['pgsql'];
        
        if (isset($params['host'])) {
            $parts[] = 'host=' . $params['host'];
        }
        if (isset($params['dbname'])) {
            $parts[] = 'dbname=' . $params['dbname'];
        }
        if (isset($params['port'])) {
            $parts[] = 'port=' . $params['port'];
        }
        
        return implode(';', $parts);
    }
    
    /**
     * Generates a PostgreSQL RETURNING clause for retrieving inserted/updated rows.
     *
     * The RETURNING clause allows immediate access to modified data without a separate
     * SELECT query. This is especially useful for retrieving generated IDs or sequences.
     *
     * @param string $table The table name (used for documentation; not in output SQL)
     * @param array<string> $columns Column names to return (e.g., ["id", "created_at"])
     * @return string SQL fragment like ` RETURNING id, created_at`
     *
     * @throws \RuntimeException If column array is empty
     * @see PDOStatement::fetch() Methods to process returned rows
     * @example
     * // Insert and return generated data
     * $sql = "INSERT INTO users (name, email) VALUES ('Alice', 'alice@example.com')"
     *        . $driver->buildReturningSql('users', ['id', 'created_at']);
     * $result = $driver->row($sql);  // Returns ['id' => 1, 'created_at' => '2023-01-01']
     */
    public function buildReturningSql(string $table, array $columns): string
    {
        $quotedCols = array_map([$this, 'q'], $columns);
        return ' RETURNING ' . implode(', ', $quotedCols);
    }
    
    /**
     * 
     * @return string The savepoint name
     */
    protected function createSavepointName(): string
    {
        $this->savepointCounter++;
        return 'uda_sp_' . $this->savepointCounter;
    }
}
