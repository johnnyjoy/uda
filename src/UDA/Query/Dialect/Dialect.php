<?php

declare(strict_types=1);

namespace UDA\Query\Dialect;

use UDA\Exception\QueryException;
use UDA\Query\Sql;

/*
 * Purpose: Defines the base SQL compilation behavior for query dialects.
 *
 * Dialects translate immutable builder state objects into SQL strings and
 * parameter bags. They do not own database connections or execute SQL.
 */

/**
 * Base query dialect compiler.
 */
abstract class Dialect
{
    abstract public function name(): string;

    /**
     * Compile select.
     *
     * @param SelectState $state  Dialect compilation state.
     *
     * @return Sql Compiled SQL message.
     */
    public function compileSelect(SelectState $state): Sql
    {
        $columns = $state->columns !== [] ? implode(', ', $state->columns) : '*';

        if ($state->distinct) {
            $columns = 'DISTINCT ' . $columns;
        }

        $cteBlock = $this->buildCteBlock($state->ctes, $state->hasRecursiveCte(), 'select');

        $query = ($cteBlock !== '' ? $cteBlock . ' ' : '') . 'SELECT ' . $columns . ' FROM ' . $state->fromClause;

        if ($state->joins) {
            $query .= ' ' . implode(' ', $state->joins);
        }

        if ($state->whereClause !== null) {
            $query .= ' WHERE ' . $state->whereClause;
        }

        if ($state->groupBy) {
            $query .= ' GROUP BY ' . implode(', ', $state->groupBy);
        }

        if ($state->havingClause !== null) {
            $query .= ' HAVING ' . $state->havingClause;
        }

        if ($state->unions !== []) {
            foreach ($state->unions as $union) {
                $query .= sprintf(' %s %s', $union['type'], $union['query']);
            }
        }

        if ($state->orderBy) {
            $query .= ' ORDER BY ' . implode(', ', $state->orderBy);
        }

        $query .= $this->compileSelectPagination($state);

        return new Sql($query, $state->getParams(), $state->tables);
    }

    /**
     * Build cte block.
     *
     * @param array  $ctes          CTE definitions attached to the statement.
     * @param bool   $hasRecursive  Whether any attached CTE is recursive.
     * @param string $context       Compilation context label.
     *
     * @return string Generated SQL fragment.
     *
     * @throws QueryException If the operation fails.
     */
    protected function buildCteBlock(array $ctes, bool $hasRecursive, string $context): string
    {
        if ($ctes === []) {
            return '';
        }

        if (!$this->supportsCte()) {
            throw new QueryException($this->name() . ' dialect does not support CTE clauses.');
        }

        if ($context !== 'select' && !$this->supportsWritableCte()) {
            throw new QueryException($this->name() . sprintf(' dialect does not support CTE clauses for %s statements.', strtoupper($context)));
        }

        if ($hasRecursive) {
            $recursiveSupported = $context === 'select'
                ? $this->supportsRecursiveCte()
                : $this->supportsRecursiveWritableCte();

            if (!$recursiveSupported) {
                throw new QueryException($this->name() . sprintf(' dialect does not support recursive CTE clauses for %s statements.', strtoupper($context)));
            }
        }

        $entries = [];

        foreach ($ctes as $cte) {
            $hintSegment = $this->formatCteMaterializationHint($cte);
            $entries[] = sprintf('%s AS%s(%s)', $cte['name'], $hintSegment, $cte['sql']);
        }

        $prefix = $this->cteKeyword($hasRecursive);

        return $prefix . implode(', ', $entries);
    }

    /**
     * Format cte materialization hint.
     *
     * @param array{name:string,sql:string,recursive:bool,materialization:?string} $cte
     *
     * @return string String result.
     */
    private function formatCteMaterializationHint(array $cte): string
    {
        $hint = $cte['materialization'] ?? null;

        if ($hint === null || !$this->supportsCteMaterializationHints()) {
            return ' ';
        }

        return match ($hint) {
            'materialized' => ' MATERIALIZED ',
            'not_materialized' => ' NOT MATERIALIZED ',
            default => ' ',
        };
    }

    /**
     * Compile select pagination.
     *
     * @param SelectState $state  Dialect compilation state.
     *
     * @return string Pagination SQL fragment.
     */
    protected function compileSelectPagination(SelectState $state): string
    {
        $fragment = '';

        if ($state->limit !== null) {
            $fragment .= ' LIMIT ' . $state->param($state->limit);
        }

        if ($state->offset !== null) {
            $fragment .= ' OFFSET ' . $state->param($state->offset);
        }

        return $fragment;
    }

    /**
     * Report whether supports cte.
     *
     * @return bool Boolean result.
     */
    public function supportsCte(): bool
    {
        return true;
    }

    /**
     * Report whether supports recursive cte.
     *
     * @return bool Boolean result.
     */
    public function supportsRecursiveCte(): bool
    {
        return true;
    }

    /**
     * Cte keyword.
     *
     * @param bool $hasRecursive  Whether any attached CTE is recursive.
     *
     * @return string String result.
     */
    protected function cteKeyword(bool $hasRecursive): string
    {
        return $hasRecursive ? 'WITH RECURSIVE ' : 'WITH ';
    }

    /**
     * Report whether supports writable cte.
     *
     * @return bool Boolean result.
     */
    public function supportsWritableCte(): bool
    {
        return false;
    }

    /**
     * Report whether supports recursive writable cte.
     *
     * @return bool Boolean result.
     */
    public function supportsRecursiveWritableCte(): bool
    {
        return $this->supportsRecursiveCte() && $this->supportsWritableCte();
    }

    /**
     * Report whether supports cte materialization hints.
     *
     * @return bool Boolean result.
     */
    public function supportsCteMaterializationHints(): bool
    {
        return false;
    }

    /**
     * Compile insert.
     *
     * @param InsertState $state  Dialect compilation state.
     *
     * @return Sql Compiled SQL message.
     *
     * @throws QueryException If the operation fails.
     */
    public function compileInsert(InsertState $state): Sql
    {
        if ($state->table === null) {
            throw new QueryException('No table defined for insert query');
        }

        $cteBlock = $this->buildCteBlock($state->ctes, $state->hasRecursiveCte(), 'insert');
        $prefix = $cteBlock !== '' ? $cteBlock . ' ' : '';

        $returningColumns = [];
        $columnNames = [];
        $valuePlaceholders = [];

        if ($state->selectQuery !== null) {
            if ($state->selectColumns === []) {
                throw new QueryException('INSERT ... SELECT requires explicit columns.');
            }

            $columnNames = $state->selectColumns;
            $quotedColumns = array_map(fn (string $col): string => $state->quote($col), $columnNames);

            $sql = sprintf(
                '%sINSERT INTO %s (%s) %s',
                $prefix,
                $state->quote($state->table),
                implode(', ', $quotedColumns),
                $state->selectQuery
            );
        } else {
            [$columnNames, $quotedColumns, $values, $valuePlaceholders] = $this->prepareInsertData($state);

            $sql = sprintf(
                '%sINSERT INTO %s (%s) VALUES %s',
                $prefix,
                $state->quote($state->table),
                implode(', ', $quotedColumns),
                implode(', ', $values)
            );
        }

        if ($state->returning !== null) {
            if (!$this->supportsReturning()) {
                throw new QueryException($this->name() . ' dialect does not support RETURNING clauses');
            }
            $returningColumns = $state->returning === []
                ? ['*']
                : array_map(fn (string $col): string => $state->quote($col), $state->returning);
            $sql .= ' RETURNING ' . implode(', ', $returningColumns);
        }

        return new Sql(
            $sql,
            $state->getParams(),
            $state->tables,
            $state->returning ?? [],
            $state->table,
            $columnNames,
            $valuePlaceholders
        );
    }

    /**
     * Normalize insert rows/columns and produce placeholders.
     *
     * @param InsertState $state  Dialect compilation state.
     *
     * @return array{0:array<int,string>,1:array<int,string>,2:array<int,string>,3:array<int,array<int,string>>}
     *
     * @throws QueryException If the operation fails.
     */
    protected function prepareInsertData(InsertState $state): array
    {
        if ($state->table === null) {
            throw new QueryException('No table defined for insert query');
        }

        if ($state->columns === [] && $state->rows === []) {
            throw new QueryException('No data provided for insert query');
        }

        if ($state->columns !== []) {
            $rows = [$state->columns];
        } else {
            $rows = $state->rows;
        }

        $firstRow = $rows[0] ?? [];

        if ($firstRow === []) {
            throw new QueryException('Insert statements require at least one column');
        }

        $columnNames = array_keys($firstRow);
        $quotedColumns = array_map(fn (string $col): string => $state->quote($col), $columnNames);

        $values = [];
        $valuePlaceholders = [];

        foreach ($rows as $row) {
            $rowPlaceholders = [];

            foreach ($columnNames as $column) {
                $rowPlaceholders[] = $state->param($row[$column] ?? null);
            }
            $values[] = '(' . implode(', ', $rowPlaceholders) . ')';
            $valuePlaceholders[] = $rowPlaceholders;
        }

        return [$columnNames, $quotedColumns, $values, $valuePlaceholders];
    }

    /**
     * Compile update.
     *
     * @param UpdateState $state  Dialect compilation state.
     *
     * @return Sql Compiled SQL message.
     *
     * @throws QueryException If the operation fails.
     */
    public function compileUpdate(UpdateState $state): Sql
    {
        if ($state->table === null) {
            throw new QueryException('No table defined for update query');
        }

        if ($state->sets === []) {
            throw new QueryException('No values set for update query');
        }

        $assignments = [];

        foreach ($state->sets as $column => $value) {
            $assignments[] = sprintf('%s = %s', $state->quote($column), $state->param($value));
        }

        $cteBlock = $this->buildCteBlock($state->ctes, $state->hasRecursiveCte(), 'update');
        $prefix = $cteBlock !== '' ? $cteBlock . ' ' : '';

        $sql = sprintf('%sUPDATE %s SET %s', $prefix, $state->quote($state->table), implode(', ', $assignments));

        if ($state->whereClause !== null) {
            $sql .= ' WHERE ' . $state->whereClause;
        }

        if ($state->returning !== null) {
            if (!$this->supportsReturning()) {
                throw new QueryException($this->name() . ' dialect does not support RETURNING clauses.');
            }

            $columns = $state->returning === []
                ? ['*']
                : array_map(fn (string $col): string => $state->quote($col), $state->returning);

            $sql .= ' RETURNING ' . implode(', ', $columns);
        }

        return new Sql($sql, $state->getParams(), $state->tables, $state->returning ?? []);
    }

    /**
     * Compile delete.
     *
     * @param DeleteState $state  Dialect compilation state.
     *
     * @return Sql Compiled SQL message.
     *
     * @throws QueryException If the operation fails.
     */
    public function compileDelete(DeleteState $state): Sql
    {
        if ($state->table === null) {
            throw new QueryException('No table defined for delete query');
        }

        $cteBlock = $this->buildCteBlock($state->ctes, $state->hasRecursiveCte(), 'delete');
        $prefix = $cteBlock !== '' ? $cteBlock . ' ' : '';

        $sql = $prefix . 'DELETE FROM ' . $state->quote($state->table);

        if ($state->whereClause !== null) {
            $sql .= ' WHERE ' . $state->whereClause;
        }

        if ($state->returning !== null) {
            if (!$this->supportsReturning()) {
                throw new QueryException($this->name() . ' dialect does not support RETURNING clauses.');
            }

            $columns = $state->returning === []
                ? ['*']
                : array_map(fn (string $col): string => $state->quote($col), $state->returning);

            $sql .= ' RETURNING ' . implode(', ', $columns);
        }

        return new Sql($sql, $state->getParams(), $state->tables, $state->returning ?? []);
    }

    abstract public function compileUpsert(UpsertState $state): Sql;

    /**
     * Report whether supports returning.
     *
     * @return bool Boolean result.
     */
    public function supportsReturning(): bool
    {
        return false;
    }

    /**
     * Report whether supports merge.
     *
     * @return bool Boolean result.
     */
    public function supportsMerge(): bool
    {
        return false;
    }

    /**
     * Report whether supports upsert.
     *
     * @return bool Boolean result.
     */
    public function supportsUpsert(): bool
    {
        return false;
    }

    /**
     * Report whether supports intersect.
     *
     * @return bool Boolean result.
     */
    public function supportsIntersect(): bool
    {
        return true;
    }

    /**
     * Report whether supports except.
     *
     * @return bool Boolean result.
     */
    public function supportsExcept(): bool
    {
        return true;
    }

    /**
     * Report whether supports window functions.
     *
     * @return bool Boolean result.
     */
    public function supportsWindowFunctions(): bool
    {
        return true;
    }
}
