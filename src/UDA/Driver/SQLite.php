<?php
declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  Driver
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/driver/sqlite
 * @since       1.0.0
 *
 * Compatibility shim – alias for SQLite driver implementation.
 */

namespace UDA\Driver;

/**
 * Compatibility shim that aliases the SQLite driver implementation
 */
class SQLite extends SQLiteDriver
{
    // Inherits all functionality from SQLiteDriver.
}
