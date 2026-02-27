<?php

declare(strict_types=1);

/** @purpose UDA\SQL\Value: Add detailed purpose here */

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