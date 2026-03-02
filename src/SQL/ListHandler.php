<?php

declare(strict_types=1);

/**
 * SQL list handler with parameter expansion for safe IN clause generation. Provides secure handling of array values in SQL queries by expanding them into properly parameterized lists with unique parameter names to prevent SQL injection while supporting variable-length IN clauses.
 *
 * PURPOSE: SQL list handler with parameter expansion for safe IN clause generation. Provides secure handling of array values in SQL queries by expanding them into properly parameterized lists with unique parameter names to prevent SQL injection while supporting variable-length IN clauses
 */

namespace UDA\SQL;

/**
 * List handling utility for IN clauses
 */
class ListHandler
{
    /**
     * Handle a list of values, returning appropriate SQL fragment
     *
     * @param ParamBag $bag The parameter bag to add values to
     * @param array $values The list of values
     * @return string The SQL fragment (e.g., '(:p1, :p2, :p3)' or '(1=0)')
     */
    public static function handle(ParamBag $bag, array $values): string
    {
        if (empty($values)) {
            return '(1=0)';
        }

        $placeholders = [];
        foreach ($values as $value) {
            $placeholders[] = Value::param($bag, $value);
        }

        return '(' . implode(', ', $placeholders) . ')';
    }
}