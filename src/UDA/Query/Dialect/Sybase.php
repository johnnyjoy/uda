<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 * @license MIT
 */

/*
 * Purpose: Compiles query builders for Sybase ASE.
 *
 * Shares T-SQL pagination and OUTPUT returning with SQL Server where verified
 * compatible. MERGE-based UPSERT is disabled until ASE MERGE is certified.
 */

namespace UDA\Query\Dialect;

/**
 * Sybase ASE dialect — intentionally narrower than SQL Server for capabilities.
 */
final class Sybase extends SqlServer
{
    /**
     * Name.
     *
     * @return string Dialect name.
     */
    public function name(): string
    {
        return 'Sybase';
    }

    /**
     * OUTPUT-style returning is inherited from SqlServer; ASE T-SQL is compatible
     * for the builder paths UDA emits today.
     *
     * @return bool Boolean result.
     */
    public function supportsReturning(): bool
    {
        return true;
    }

    /**
     * MERGE UPSERT is not advertised for Sybase until certified against ASE.
     *
     * @return bool Boolean result.
     */
    public function supportsMerge(): bool
    {
        return false;
    }

    /**
     * Upsert builders require MERGE on the T-SQL family path today.
     *
     * @return bool Boolean result.
     */
    public function supportsUpsert(): bool
    {
        return false;
    }
}
