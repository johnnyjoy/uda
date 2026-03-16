<?php

declare(strict_types=1);

namespace UDA\Query\Dialect;

use UDA\Exception\QueryException;
use UDA\Query\Sql;
use UDA\SQL\SqlMessage;

abstract class Dialect
{
    abstract public function name(): string;

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
     * @param array{name:string,sql:string,recursive:bool,materialization:?string} $cte
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

    public function supportsCte(): bool
    {
        return true;
    }

    public function supportsRecursiveCte(): bool
    {
        return true;
    }

    protected function cteKeyword(bool $hasRecursive): string
    {
        return $hasRecursive ? 'WITH RECURSIVE ' : 'WITH ';
    }

    public function supportsWritableCte(): bool
    {
        return false;
    }

    public function supportsRecursiveWritableCte(): bool
    {
        return $this->supportsRecursiveCte() && $this->supportsWritableCte();
    }

    public function supportsCteMaterializationHints(): bool
    {
        return false;
    }

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
     * @return array{0:array<int,string>,1:array<int,string>,2:array<int,string>,3:array<int,array<int,string>>}
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

    public function supportsReturning(): bool
    {
        return false;
    }

    public function supportsExplain(): bool
    {
        return false;
    }

    public function supportsExplainAnalyze(): bool
    {
        return false;
    }

    /**
     * @return iterable<int,SqlMessage>
     */
    public function buildExplainSql(SqlMessage $sql, bool $analyze): iterable
    {
        $descriptor = $analyze ? 'EXPLAIN ANALYZE' : 'EXPLAIN';

        throw new QueryException($this->name() . sprintf(' dialect does not support %s statements.', $descriptor));
    }

    public function supportsMerge(): bool
    {
        return false;
    }

    public function supportsUpsert(): bool
    {
        return false;
    }

    public function supportsIntersect(): bool
    {
        return true;
    }

    public function supportsExcept(): bool
    {
        return true;
    }

    public function supportsWindowFunctions(): bool
    {
        return true;
    }
}
