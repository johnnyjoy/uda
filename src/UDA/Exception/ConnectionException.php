<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Exception
 * @author James Dornan <james.dornan@uda.example.com>
 * @license MIT
 * @link https://docs.uda.example.com/exception/connection
 * @since 1.0.0
 */

/*
 * Purpose: Specialized exception for database connection failures in UDA.
 */

namespace UDA\Exception;

/**
 * Specialized exception for connection-related error scenarios
 */
class ConnectionException extends \Exception
{
}
