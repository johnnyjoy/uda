<?php

declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  SQL
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/sql/sqlmessage
 * @since       1.0.0
 *
 * SQL value object representing a query string and its parameters
 */

namespace UDA\SQL;

/**
 * SQL value object representing a query string and its parameters
 */
final class SqlMessage
{
    private string $query;
    private array $params;

    /**
     * Creates a new SQL message object encapsulating a query with its parameters.
     *
     * This value object pairs a SQL string with its corresponding named parameters,
     * ensuring they remain together as a single, atomic unit throughout the system.
     *
     * @param string $query SQL query string with named placeholders (e.g., ":id", ":name")
     * @param array<string, mixed> $params Named parameters matching placeholders in the query
     *
     * @see Driver::exec() Execution method accepting SqlMessage objects
     * @example
     * $sql = new SqlMessage(
     *     "SELECT * FROM users WHERE id = :id AND status = :status",
     *     ['id' => 123, 'status' => 'active']
     * );
     */
    public function __construct(string $query, array $params = [])
    {
        $this->query = $query;
        $this->params = $params;
    }

    /**
     * Retrieves the SQL query string from this message.
     *
     * Returns the raw SQL string with named parameter placeholders intact.
     * Use getParams() to obtain the corresponding parameter values.
     *
     * @return string SQL query string with named placeholders
     *
     * @see SqlMessage::getParams() Companion method for parameter retrieval
     * @example
     * $query = $sql->getQuery();
     * // Returns: "SELECT * FROM users WHERE id = :id"
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Retrieves the named parameters associated with this SQL message.
     *
     * Returns an associative array where keys match named placeholders
     * in the query string (without the leading colon).
     *
     * @return array<string, mixed> Named parameter values indexed by placeholder name
     *
     * @see SqlMessage::getQuery() Companion method for SQL retrieval
     * @example
     * $params = $sql->getParams();
     * // Returns: ['id' => 123, 'status' => 'active']
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Create a new Sql object with a different query
     *
     * @param string $query The new query string
     * @return self
     */
    public function withQuery(string $query): self
    {
        return new self($query, $this->params);
    }

    /**
     * Convert to string representation
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->query;
    }
}
