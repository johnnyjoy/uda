<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage SQL
 * @license MIT
 * @link https://github.com/johnnyjoy/uda/blob/master/docs/architecture.md
 * @since 1.0.0
 */

/*
 * Purpose: Table reference validator with schema support.
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
     * @param string ...$segments  The table segments (schema, table name)
     *
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
