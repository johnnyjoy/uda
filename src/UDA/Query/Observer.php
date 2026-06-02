<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query
 * @license MIT
 * @since 1.0.0
 */

namespace UDA\Query;

use Throwable;

/*
 * Purpose: Payload for optional query observer callbacks after each execute or cache read.
 */

/**
 * One completed query attempt (PDO execute or cache read).
 */
final class Observer
{
    /**
     * @param string               $connection  Config connection name.
     * @param string               $sql         SQL text executed or read from cache.
     * @param array<string,mixed>  $params      Bound named parameters.
     * @param float                $durationMs  Wall time in milliseconds.
     * @param bool                 $cacheHit    True when result came from read cache (no PDO).
     * @param bool                 $retried     True when executeInternal reconnected and retried once.
     * @param ?Throwable           $error       Set when the attempt failed.
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $sql,
        public readonly array $params,
        public readonly float $durationMs,
        public readonly bool $cacheHit,
        public readonly bool $retried,
        public readonly ?Throwable $error,
    ) {
    }
}
