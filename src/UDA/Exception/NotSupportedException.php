<?php

declare(strict_types=1);

namespace UDA\Exception;

use RuntimeException;

/**
 * @package UDA
 * @subpackage Exception
 *
 * Purpose: Raised when a backend does not support a requested feature.
 */
final class NotSupportedException extends RuntimeException
{
}
