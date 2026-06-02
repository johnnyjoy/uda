<?php

declare(strict_types=1);

namespace UDA\Exception;

use RuntimeException;

/**
 * @package UDA
 * @subpackage Exception
 * @license MIT
 */

/*
 * Purpose: Represents unsupported engine capability requests in UDA.
 */

/**
 * Raised when a domain cannot provide the requested engine capability.
 */
final class NotSupportedException extends RuntimeException
{
}
