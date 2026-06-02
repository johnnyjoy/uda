<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage SQL
 * @author James Dornan <james.dornan@uda.example.com>
 * @license MIT
 * @link https://docs.uda.example.com/sql/exceptions
 * @since 1.0.0
 */

/*
 * Purpose: Signals invalid SQL identifiers during query construction or validation.
 */

namespace UDA\SQL;

use Exception;

/**
 * Exception thrown when an invalid identifier is encountered
 */
class InvalidIdentifierException extends Exception
{
}
