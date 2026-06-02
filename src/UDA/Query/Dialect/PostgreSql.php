<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 * @license MIT
 */

/*
 * Purpose: Compiles query builders into PostgreSQL-specific SQL.
 *
 * Handles PostgreSQL UPSERT, RETURNING, writable CTEs, and materialization
 * hints in the Query domain.
 */

namespace UDA\Query\Dialect;


/**
 * PostgreSQL dialect implementation.
 */
final class PostgreSql extends OnConflict
{
    /**
     * Name.
     *
     * @return string Dialect name.
     */
    public function name(): string
    {
        return 'PostgreSQL';
    }

    /**
     * Report whether supports returning.
     *
     * @return bool Boolean result.
     */
    public function supportsReturning(): bool
    {
        return true;
    }

    /**
     * Report whether supports writable cte.
     *
     * @return bool Boolean result.
     */
    public function supportsWritableCte(): bool
    {
        return true;
    }

    /**
     * Report whether supports recursive writable cte.
     *
     * @return bool Boolean result.
     */
    public function supportsRecursiveWritableCte(): bool
    {
        return true;
    }

    /**
     * Report whether supports upsert.
     *
     * @return bool Boolean result.
     */
    public function supportsUpsert(): bool
    {
        return true;
    }

    /**
     * Report whether supports cte materialization hints.
     *
     * @return bool Boolean result.
     */
    public function supportsCteMaterializationHints(): bool
    {
        return true;
    }

}
