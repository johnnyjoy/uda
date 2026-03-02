<?php

declare(strict_types=1);

/**
 * SQL value wrapper with type-safe parameter binding. Encapsulates arbitrary values for safe inclusion in SQL queries, handling null values, boolean conversion, and providing a clean interface for value binding that prevents type confusion and injection vulnerabilities.
 *
 * PURPOSE: SQL value wrapper with type-safe parameter binding. Encapsulates arbitrary values for safe inclusion in SQL queries, handling null values, boolean conversion, and providing a clean interface for value binding that prevents type confusion and injection vulnerabilities
 */

namespace UDA\SQL;

/**
 * Value parameterization utility
 */
class Value
{
    /**
     * Parameterize a value and add it to the parameter bag
     *
     * @param ParamBag $bag The parameter bag to add the value to
     * @param mixed $value The value to parameterize
     * @return string The parameter placeholder (e.g., ':p1')
     */
    public static function param(ParamBag $bag, mixed $value): string
    {
        $paramName = $bag->alloc();
        $bag->assign($paramName, $value);
        return ':' . $paramName;
    }
}