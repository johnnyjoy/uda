<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage SQL
 * @license MIT
 * @link https://github.com/johnnyjoy/uda/blob/master/docs/architecture.md
 * @since 1.0.0
 */

/*
 * Purpose: ORDER BY clause builder with allowlist enforcement for safe, injection-proof sorting in SQL queries.
 */

namespace UDA\SQL;

/**
 * ORDER BY clause with allowlist enforcement
 */
class OrderBy
{
    private string $column;
    private string $direction = 'ASC';

    /**
     * Create a new ORDER BY clause with allowlist enforcement
     *
     * @param string $column     The column to order by
     * @param array  $allowlist  The allowed columns
     *
     * @return self
     *
     * @throws InvalidOrderByException If the column is not in the allowlist
     */
    public static function allow(string $column, array $allowlist): self
    {
        if (empty($allowlist)) {
            throw new InvalidOrderByException("Allowlist cannot be empty");
        }

        if (!in_array($column, $allowlist, true)) {
            throw new InvalidOrderByException("Column '{$column}' is not in the allowlist");
        }

        return new self($column);
    }

    /**
     * Private constructor to enforce factory method usage
     *
     * @param string $column  The column to order by
     */
    private function __construct(string $column)
    {
        $this->column = $column;
    }

    /**
     * Set the direction to descending
     *
     * @return self
     */
    public function desc(): self
    {
        $this->direction = 'DESC';

        return $this;
    }

    /**
     * Set the direction to ascending
     *
     * @return self
     */
    public function asc(): self
    {
        $this->direction = 'ASC';

        return $this;
    }

    /**
     * Get the ORDER BY expression
     *
     * @return string
     */
    public function getExpression(): string
    {
        return "{$this->column} {$this->direction}";
    }

    /**
     * Convert to string representation
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getExpression();
    }
}
