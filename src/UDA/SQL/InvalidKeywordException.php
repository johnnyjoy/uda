<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage SQL
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/sql/exceptions
 * @since 1.0.0
 */

/*
 * Purpose: Signals invalid SQL keywords during query construction or validation.
 */

namespace UDA\SQL;

use Exception;

/**
 * Exception thrown when an invalid keyword is encountered
 */
class InvalidKeywordException extends Exception
{
}
