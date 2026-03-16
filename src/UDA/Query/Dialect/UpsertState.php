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
 * Immutable UPSERT builder description consumed by dialects.
 */
final class UpsertState
{
    public function __construct(
        public readonly ?string $table,
        public readonly array $values,
        public readonly array $rows,
        public readonly array $conflictKeys,
        public readonly array $updates,
        public readonly bool $doNothing,
        public readonly array $tables,
        private readonly ParamBag $params,
        private readonly Closure $parameterize,
        private readonly Closure $quote
    ) {
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
