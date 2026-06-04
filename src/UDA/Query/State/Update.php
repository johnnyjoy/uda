<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\State
 * @license MIT
 */

/*
 * Purpose: Carries UPDATE builder state into dialect compilation.
 *
 * Preserves assignments, predicates, returning metadata, and CTE inputs for
 * dialect compilers.
 */

namespace UDA\Query\State;

use UDA\SQL\Identifier;
use UDA\SQL\ParamBag;
use UDA\SQL\Value;

/**
 * Immutable state passed to dialects when compiling UPDATE builders.
 */
final class Update
{
    /**
     * Create the runtime object.
     *
     * @param array    $ctes          CTE definitions attached to the statement.
     * @param ?string  $table         Target table name.
     * @param array    $sets          Column assignments for UPDATE.
     * @param ?string  $whereClause   Compiled WHERE clause, or null when absent.
     * @param array    $tables        Table names used for cache metadata.
     * @param ParamBag $params        Named parameter values.
     * @param string   $engine        Engine key used to quote identifiers.
     * @param ?array   $returning     Returning column list, or null when not requested.
     */
    public function __construct(
        public readonly array $ctes,
        public readonly ?string $table,
        public readonly array $sets,
        public readonly ?string $whereClause,
        public readonly array $tables,
        private readonly ParamBag $params,
        private readonly string $engine,
        public readonly ?array $returning
    ) {
    }

    /**
     * Report whether any attached CTE is recursive.
     *
     * @return bool Boolean result.
     */
    public function hasRecursiveCte(): bool
    {
        foreach ($this->ctes as $cte) {
            if (!empty($cte['recursive'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bind a value and return its generated placeholder.
     *
     * @param mixed $value  Value to render or bind.
     *
     * @return string Named placeholder.
     */
    public function param(mixed $value): string
    {
        return Value::param($this->params, $value);
    }

    /**
     * Quote an identifier for the active dialect.
     *
     * @param string $identifier  Identifier value.
     *
     * @return string String result.
     */
    public function quote(string $identifier): string
    {
        return Identifier::quoteFor($identifier, $this->engine);
    }

    /**
     * Return accumulated named parameters.
     *
     * @return array Result array.
     */
    public function getParams(): array
    {
        return $this->params->getParams();
    }
}
