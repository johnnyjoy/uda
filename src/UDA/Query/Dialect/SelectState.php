<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 * @license MIT
 */

/*
 * Purpose: Carries SELECT builder state into dialect compilation.
 *
 * SelectState is immutable input for Query dialects and does not own SQL
 * execution or connection behavior.
 */

namespace UDA\Query\Dialect;

use Closure;
use UDA\SQL\ParamBag;

/**
 * Immutable snapshot of SELECT builder configuration for dialect compilation.
 */
final class SelectState
{
    /**
     * Create the runtime object.
     *
     * @param array    $ctes          CTE definitions attached to the statement.
     * @param bool     $distinct      Whether SELECT DISTINCT is enabled.
     * @param array    $columns       Column names.
     * @param string   $fromClause    Compiled FROM clause.
     * @param array    $joins         Compiled JOIN clause fragments.
     * @param ?string  $whereClause   Compiled WHERE clause, or null when absent.
     * @param array    $groupBy       GROUP BY expressions.
     * @param ?string  $havingClause  Compiled HAVING clause, or null when absent.
     * @param array    $orderBy       ORDER BY clause fragments.
     * @param ?int     $limit         Maximum number of rows.
     * @param ?int     $offset        Number of rows to skip.
     * @param array    $tables        Table names used for cache metadata.
     * @param array    $unions        UNION clause descriptors.
     * @param ParamBag $params        Named parameter values.
     * @param Closure  $parameterize  Closure that stores a bound value and returns its placeholder.
     */
    public function __construct(
        public readonly array $ctes,
        public readonly bool $distinct,
        public readonly array $columns,
        public readonly string $fromClause,
        public readonly array $joins,
        public readonly ?string $whereClause,
        public readonly array $groupBy,
        public readonly ?string $havingClause,
        public readonly array $orderBy,
        public readonly ?int $limit,
        public readonly ?int $offset,
        public readonly array $tables,
        public readonly array $unions,
        private readonly ParamBag $params,
        private readonly Closure $parameterize
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
        $fn = $this->parameterize;

        return $fn($value);
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
