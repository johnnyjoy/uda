<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @license MIT
 * @since 1.0.0
 */

namespace UDA\Driver;

use PDOStatement;

/*
 * Purpose: Reuse prepared PDOStatement objects for one connection (cleared on reconnect).
 *
 * Not a read-result cache — that is UDA\Cache. prepare() is supplied by Driver via callback.
 */

/**
 * Prepared-statement reuse for one Driver connection.
 */
final class Prepared
{
    public const MAX = 64;

    /**
     * @var array<string,PDOStatement>
     */
    private array $map = [];

    public function __construct(private readonly int $max = self::MAX)
    {
    }

    public function clear(): void
    {
        $this->map = [];
    }

    /**
     * Return the cached statement for this SQL, or null on miss.
     *
     * On a hit the entry is moved to the most-recently-used position.
     */
    public function get(string $query): ?PDOStatement
    {
        if (!isset($this->map[$query])) {
            return null;
        }

        $stmt = $this->map[$query];
        unset($this->map[$query]);
        $this->map[$query] = $stmt;

        return $stmt;
    }

    /**
     * Store a prepared statement, evicting the oldest entry past capacity.
     */
    public function put(string $query, PDOStatement $stmt): void
    {
        if (count($this->map) >= $this->max) {
            $oldest = array_key_first($this->map);

            if ($oldest !== null) {
                unset($this->map[$oldest]);
            }
        }

        $this->map[$query] = $stmt;
    }
}
