<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage SQL
 * @license MIT
 * @link https://github.com/johnnyjoy/uda/blob/master/docs/architecture.md
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
