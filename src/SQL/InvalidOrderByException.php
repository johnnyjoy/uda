<?php

declare(strict_types=1);

/** @purpose Specialized exception for InvalidOrderByException error scenarios */

namespace UDA\SQL;

use Exception;

/**
 * Exception thrown when an invalid ORDER BY clause is encountered
 */
class InvalidOrderByException extends Exception
{
}