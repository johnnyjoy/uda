<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 * @license MIT
 */

/*
 * Purpose: Compiles query builders into CUBRID-specific SQL.
 *
 * CUBRID uses MySQL-compatible syntax: LIMIT/OFFSET pagination and
 * ON DUPLICATE KEY UPDATE upsert. Extending MariaDb inherits both.
 * CUBRID does not support RETURNING clauses.
 */

namespace UDA\Query\Dialect;

/**
 * CUBRID-compatible dialect.
 *
 * Inherits MariaDB/MySQL-style compilation: backtick quoting, LIMIT/OFFSET
 * pagination, and INSERT ... ON DUPLICATE KEY UPDATE upsert.
 */
final class Cubrid extends MariaDb
{
    /**
     * Name.
     *
     * @return string Dialect name.
     */
    public function name(): string
    {
        return 'CUBRID';
    }
}
