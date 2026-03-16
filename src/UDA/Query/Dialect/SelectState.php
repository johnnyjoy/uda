<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 */

namespace UDA\Query\Dialect;

use Closure;
use UDA\SQL\ParamBag;

/**
 * Immutable snapshot of SELECT builder configuration for dialect compilation.
 */
final class SelectState
{
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

    public function hasRecursiveCte(): bool
    {
        foreach ($this->ctes as $cte) {
            if (!empty($cte['recursive'])) {
                return true;
            }
        }

        return false;
    }

    public function param(mixed $value): string
    {
        $fn = $this->parameterize;

        return $fn($value);
    }

    public function getParams(): array
    {
        return $this->params->getParams();
    }
}
