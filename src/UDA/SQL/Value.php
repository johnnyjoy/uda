<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage SQL
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/sql/value
 * @since 1.0.0
 */

/*
 * Purpose: SQL value wrapper with type-safe parameter binding.
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
     * @param  ParamBag $bag   The parameter bag to add the value to
     * @param  mixed    $value The value to parameterize
     * @return string   The parameter placeholder (e.g., ':p1')
     */
    public static function param(ParamBag $bag, mixed $value): string
    {
        $paramName = $bag->alloc();
        $bag->assign($paramName, $value);

        return ':' . $paramName;
    }
}
