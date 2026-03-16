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
 * Immutable DELETE builder snapshot for dialect compilation.
 */
final class DeleteState
{
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

    public function hasRecursiveCte(): bool
    {
        foreach ($this->ctes as $cte) {
            if (!empty($cte['recursive'])) {
                return true;
            }
        }

        return false;
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
