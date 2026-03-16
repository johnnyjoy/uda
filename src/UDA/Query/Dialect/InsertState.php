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
 * Immutable state object representing an INSERT builder.
 */
final class InsertState
{
    public function __construct(
        public readonly array $ctes,
        public readonly ?string $table,
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?array $returning,
        public readonly array $tables,
        private readonly ParamBag $params,
        private readonly Closure $parameterize,
        private readonly Closure $quote,
        public readonly ?string $selectQuery,
        public readonly array $selectColumns
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

    public function quote(string $identifier): string
    {
        $fn = $this->quote;

        return $fn($identifier);
    }

    public function getParams(): array
    {
        return $this->params->getParams();
    }
}
