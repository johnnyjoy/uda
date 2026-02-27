<?php

declare(strict_types=1);

/** @purpose SQL value object compatible with Query domain */

namespace UDA\SQL;

/**
 * Immutable container for an SQL string and its parameters.
 * Mirrors the implementation of UDA\Query\Sql but resides in the
 * UDA\SQL namespace to satisfy the abstract return type declared in
 * AbstractQuery. The class is final and provides public readonly
 * properties for direct access.
 */
class Sql
{
    public function __construct(
        public readonly string $sql,
        public readonly array $params
    ) {}

    /** @purpose Retrieve the SQL query string */
    public function getQuery(): string
    {
        return $this->sql;
    }

    /** @purpose Retrieve the parameters */
    public function getParams(): array
    {
        return $this->params;
    }
}
