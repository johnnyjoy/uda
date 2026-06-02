<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Exception
 * @author James Dornan <james.dornan@uda.example.com>
 * @license MIT
 * @link https://docs.uda.example.com/exception/query
 * @since 1.0.0
 */

/*
 * Purpose: Specialized exception for query-related errors in UDA.
 */

namespace UDA\Exception;

use RuntimeException;

/**
 * Specialized exception for query-related error scenarios
 */
class QueryException extends RuntimeException
{
}
