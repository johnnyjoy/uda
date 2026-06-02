<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage SQL
 * @author James Dornan <james.dornan@uda.example.com>
 * @license MIT
 * @link https://docs.uda.example.com/sql/parambag
 * @since 1.0.0
 */

/*
 * Purpose: Parameter bag for collecting and managing named SQL parameters.
 */

namespace UDA\SQL;

/**
 * Parameter bag for allocating unique parameter names with a monotonic counter
 */
class ParamBag
{
    private string $prefix;
    private int $counter = 0;
    private array $params = [];

    /**
     * Create a new parameter bag
     *
     * @param string $prefix  The prefix for parameter names (default: 'p')
     */
    public function __construct(string $prefix = 'p')
    {
        $this->prefix = $prefix;
    }

    /**
     * Allocate a new unique parameter name
     *
     * @return string The allocated parameter name
     */
    public function alloc(): string
    {
        $this->counter++;

        return $this->prefix . $this->counter;
    }

    /**
     * Assign a value to a parameter
     *
     * @param string $param  The parameter name
     * @param mixed  $value  The value to assign
     *
     * @return void
     */
    public function assign(string $param, mixed $value): void
    {
        $this->params[$param] = $value;
    }

    /**
     * Get all assigned parameters
     *
     * @return array The parameter values indexed by parameter names
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Get the current counter value
     *
     * @return int The current counter value
     */
    public function getCounter(): int
    {
        return $this->counter;
    }
}
