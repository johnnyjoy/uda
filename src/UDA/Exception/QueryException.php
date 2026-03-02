<?php

declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  Exception
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/exception/query
 * @since       1.0.0
 *
 * This file defines a specialized exception for query-related errors within UDA.
 * It extends RuntimeException to provide meaningful error messages for SQL syntax
 * issues, parameter validation failures, execution errors, and other query-specific
 * problems. This exception type allows for targeted error handling and provides
 * clearer diagnostics for developers debugging database interaction issues.
 */

namespace UDA\Exception;

use RuntimeException;

/**
 * Specialized exception for query-related error scenarios
 */
class QueryException extends RuntimeException
{
}
