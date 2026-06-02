<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 */

/*
 * Purpose: Carries UPSERT builder state into dialect compilation.
 *
 * UpsertState preserves conflict keys, write values, update actions, generated
 * params, and quoting callbacks for dialect compilers.
 */

namespace UDA\Query\Dialect;

use Closure;
use UDA\SQL\ParamBag;

/**
 * Immutable UPSERT builder description consumed by dialects.
 */
final class UpsertState
{
    /**
     * Create the runtime object.
     *
     * @param ?string  $table         Target table name.
     * @param array    $values        Values to process.
     * @param array    $rows          Column/value row data set.
     * @param array    $conflictKeys  Columns that identify an upsert conflict.
     * @param array    $updates       Column updates applied when an upsert conflict occurs.
     * @param bool     $doNothing     Whether the upsert should ignore conflicts.
     * @param array    $tables        Table names used for cache metadata.
     * @param ParamBag $params        Named parameter values.
     * @param Closure  $parameterize  Closure that stores a bound value and returns its placeholder.
     * @param Closure  $quote         Closure that quotes an identifier for the active dialect.
     */
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
