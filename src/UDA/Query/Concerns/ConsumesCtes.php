<?php

declare(strict_types=1);

/**
 * @license MIT
 */

namespace UDA\Query\Concerns;

use UDA\Exception\QueryException;
use UDA\Query\Select;
use UDA\Query\Sql;

/*
 * Purpose: Shares CTE handling across query builders.
 *
 * Builders use this trait to collect, clone, validate, and render WITH clauses
 * before dialect compilation. It does not execute SQL or own connection state.
 */

/**
 * Shared implementation for builders that support WITH / WITH RECURSIVE clauses.
 */
trait ConsumesCtes
{
    /**
     * @var array<int,array{name:string,quoted:string,recursive:bool,query:Select|Sql,materialization:?string}>
     */
    private array $ctes = [];

    abstract protected function cteContext(): string;

    /**
     * With.
     *
     * @param string     $name   Name value.
     * @param Select|Sql $query  Query builder instance.
     *
     * @return self Configured instance.
     */
    public function with(string $name, Select|Sql $query): self
    {
        return $this->addCte($name, $query, false);
    }

    /**
     * With recursive.
     *
     * @param string     $name   Name value.
     * @param Select|Sql $query  Query builder instance.
     *
     * @return self Configured instance.
     */
    public function withRecursive(string $name, Select|Sql $query): self
    {
        return $this->addCte($name, $query, true);
    }

    /**
     * Clone ctes on clone.
     *
     * @return void No return value.
     */
    protected function cloneCtesOnClone(): void
    {
        $this->ctes = array_map(static function (array $cte): array {
            return [
                'name' => $cte['name'],
                'quoted' => $cte['quoted'],
                'recursive' => $cte['recursive'],
                'query' => $cte['query'] instanceof Select ? clone $cte['query'] : $cte['query'],
                'materialization' => $cte['materialization'] ?? null,
            ];
        }, $this->ctes);
    }

    /**
     * Report whether any attached CTE is recursive.
     *
     * @return bool Boolean result.
     */
    protected function hasRecursiveCte(): bool
    {
        foreach ($this->ctes as $cte) {
            if (!empty($cte['recursive'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,array{name:string,sql:string,recursive:bool,materialization:?string}>
     */
    protected function renderCtes(): array
    {
        if ($this->ctes === []) {
            return [];
        }

        $rendered = [];

        foreach ($this->ctes as $cte) {
            $rendered[] = [
                'name' => $cte['quoted'],
                'sql' => $this->renderCteQuery($cte['query']),
                'recursive' => $cte['recursive'],
                'materialization' => $cte['materialization'] ?? null,
            ];
        }

        return $rendered;
    }

    /**
     * Add cte.
     *
     * @param string     $name       Name value.
     * @param Select|Sql $query      Query builder instance.
     * @param bool       $recursive  Whether the CTE should be recursive.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
    private function addCte(string $name, Select|Sql $query, bool $recursive): self
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new QueryException('CTE names cannot be empty.');
        }

        $this->assertCteCapability($recursive);

        $clone = clone $this;
        $clone->ctes[] = [
            'name' => $trimmed,
            'quoted' => $this->quote($trimmed),
            'recursive' => $recursive,
            'query' => $query instanceof Select ? clone $query : $query,
            'materialization' => null,
        ];

        return $clone;
    }

    /**
     * Materialized.
     *
     * @return self Configured instance.
     */
    public function materialized(): self
    {
        return $this->applyMaterializationHint('materialized');
    }

    /**
     * Not materialized.
     *
     * @return self Configured instance.
     */
    public function notMaterialized(): self
    {
        return $this->applyMaterializationHint('not_materialized');
    }

    /**
     * Apply materialization hint.
     *
     * @param string $hint  Parameter name hint.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
    private function applyMaterializationHint(string $hint): self
    {
        if ($this->ctes === []) {
            throw new QueryException('Materialization hints require at least one CTE.');
        }

        $clone = clone $this;
        $index = array_key_last($clone->ctes);

        if ($index === null) {
            return $clone;
        }

        $clone->ctes[$index]['materialization'] = $hint;

        return $clone;
    }

    /**
     * Render cte query.
     *
     * @param Select|Sql $query  Query builder instance.
     *
     * @return string String result.
     */
    private function renderCteQuery(Select|Sql $query): string
    {
        $sql = $query instanceof Select ? $query->toSql() : $query;
        $text = $sql->getQuery();
        $replacements = [];

        foreach ($sql->getParams() as $name => $value) {
            $replacements[':' . $name] = $this->param($value);
        }

        if ($replacements !== []) {
            $text = strtr($text, $replacements);
        }

        $this->mergeSubqueryTables($sql);

        return $text;
    }

    abstract protected function mergeSubqueryTables(Sql $sql): void;

    /**
     * Assert cte capability.
     *
     * @param bool $recursive  Whether the CTE should be recursive.
     *
     * @return void No return value.
     *
     * @throws QueryException If the operation fails.
     */
    private function assertCteCapability(bool $recursive): void
    {
        if (!method_exists($this, 'boundDialect')) {
            return;
        }

        $dialect = $this->boundDialect();

        if ($dialect === null) {
            return;
        }

        if (!$dialect->supportsCte()) {
            throw new QueryException(sprintf('%s dialect does not support CTE clauses.', $dialect->name()));
        }

        $context = $this->cteContext();

        if ($context !== 'select' && !$dialect->supportsWritableCte()) {
            throw new QueryException(sprintf(
                '%s dialect does not support CTE clauses for %s statements.',
                $dialect->name(),
                strtoupper($context)
            ));
        }

        if ($recursive) {
            $supportsRecursive = $context === 'select'
                ? $dialect->supportsRecursiveCte()
                : $dialect->supportsRecursiveWritableCte();

            if (!$supportsRecursive) {
                throw new QueryException(sprintf(
                    '%s dialect does not support RECURSIVE CTE clauses for %s statements.',
                    $dialect->name(),
                    strtoupper($context)
                ));
            }
        }
    }
}
