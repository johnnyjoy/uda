<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 */

namespace UDA\Query\Dialect;

/**
 * Sybase dialect piggy-backing on SQL Server semantics.
 */
final class Sybase extends SqlServer
{
    public function name(): string
    {
        return 'Sybase';
    }
}
