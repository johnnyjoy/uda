<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 */

/*
 * Purpose: Carries DELETE builder state into dialect compilation.
 *
 * DeleteState preserves target table, predicate, returning metadata, and CTE
 * inputs for dialect compilers.
 */

namespace UDA\Query\Dialect;

use Closure;
use UDA\SQL\ParamBag;

/**
 * Immutable DELETE builder snapshot for dialect compilation.
 */
final class DeleteState
{
    /**
     * Create the runtime object.
     *
     * @param array    $ctes         CTE definitions attached to the statement.
     * @param ?string  $table        Target table name.
     * @param ?string  $whereClause  Compiled WHERE clause, or null when absent.
     * @param array    $tables       Table names used for cache metadata.
     * @param ParamBag $params       Named parameter values.
     * @param Closure  $quote        Closure that quotes an identifier for the active dialect.
     * @param ?array   $returning    Returning column list, or null when not requested.
     */
    public function __construct(
        public readonly array $ctes,
        public readonly ?string $table,
        public readonly ?string $whereClause,
        public readonly array $tables,
        private readonly ParamBag $params,
        private readonly Closure $quote,
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
     * Quote.
     *
     * @param string $identifier  Identifier value.
     *
     * @return string String result.
     */
    public function quote(string $identifier): string
    {
        $fn = $this->quote;

        return $fn($identifier);
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
