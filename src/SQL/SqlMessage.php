<?php

declare(strict_types=1);

/** @purpose UDA\SQL\SqlMessage: Add detailed purpose here */

namespace UDA\SQL;

/**
 * SQL value object representing a query string and its parameters
 */
final class SqlMessage
{
    private string $query;
    private array $params;

    /**
     * Create a new SQL object
     *
     * @param string $query The SQL query string
     * @param array $params The parameters for the query
     */
    public function __construct(string $query, array $params = [])
    {
        $this->query = $query;
        $this->params = $params;
    }

    /**
     * Get the SQL query string
     *
     * @return string
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Get the parameters
     *
     * @return array
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
