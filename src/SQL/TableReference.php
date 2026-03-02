<?php

declare(strict_types=1);

/**
 * Table reference validator with schema support. Validates and represents database table references (potentially with schema prefixes) ensuring they follow proper naming conventions and don't contain dangerous SQL patterns, supporting cross-database schema.table notation.
 *
 * PURPOSE: Table reference validator with schema support. Validates and represents database table references (potentially with schema prefixes) ensuring they follow proper naming conventions and don't contain dangerous SQL patterns, supporting cross-database schema.table notation
 */

namespace UDA\SQL;

/**
 * Table reference validation
 */
class TableReference extends Identifier
{
    /**
     * Create a new table reference
     *
     * @param string ...$segments The table segments (schema, table name)
     * @throws InvalidIdentifierException If the table reference is invalid
     */
    public function __construct(string ...$segments)
    {
        parent::__construct(...$segments);
    }

    /**
     * Convert to string representation
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getName();
    }
}