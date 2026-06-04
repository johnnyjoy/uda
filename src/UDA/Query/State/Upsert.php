<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\State
 * @license MIT
 */

/*
 * Purpose: Carries UPSERT builder state into dialect compilation.
 *
 * Preserves conflict keys, write values, update actions, generated params, and
 * the engine key used for quoting in dialect compilers.
 */

namespace UDA\Query\State;

use UDA\SQL\Identifier;
use UDA\SQL\ParamBag;
use UDA\SQL\Value;

/**
 * Immutable UPSERT builder description consumed by dialects.
 */
final class Upsert
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
     * @param string   $engine        Engine key used to quote identifiers.
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
        private readonly string $engine
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
