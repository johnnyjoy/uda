<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 */

namespace UDA\Query\Dialect;

use UDA\Exception\QueryException;
use UDA\Query\Sql;
use UDA\SQL\SqlMessage;

/**
 * Oracle dialect implementation (MERGE, FETCH pagination, RETURNING metadata).
 */
final class Oracle extends Dialect
{
    public function name(): string
    {
        return 'Oracle';
    }

    public function supportsReturning(): bool
    {
        return true;
    }

    public function supportsMerge(): bool
    {
        return true;
    }

    public function supportsUpsert(): bool
    {
        return true;
    }

    protected function compileSelectPagination(SelectState $state): string
    {
        $fragment = '';

        if ($state->offset !== null) {
            $fragment .= ' OFFSET ' . $state->param($state->offset) . ' ROWS';
        }

        if ($state->limit !== null) {
            if ($fragment === '') {
                $fragment = ' OFFSET 0 ROWS';
            }
            $fragment .= ' FETCH NEXT ' . $state->param($state->limit) . ' ROWS ONLY';
        }

        return $fragment;
    }

    public function compileInsert(InsertState $state): Sql
    {
        if ($state->table === null) {
            throw new QueryException('No table defined for insert query');
        }

        if ($state->columns === [] && $state->rows === []) {
            throw new QueryException('No data provided for insert query');
        }

        [$columnNames, $quotedColumns, $values, $valuePlaceholders] = $this->prepareInsertData($state);

        if ($state->returning !== null && $state->returning === []) {
            throw new QueryException('Oracle returning() requires explicit column names.');
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $state->quote($state->table),
            implode(', ', $quotedColumns),
            implode(', ', $values)
        );

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

    public function compileUpdate(UpdateState $state): Sql
    {
        if ($state->table === null) {
            throw new QueryException('No table defined for update query');
        }

        if ($state->sets === []) {
            throw new QueryException('No values set for update query');
        }

        if ($state->returning !== null && $state->returning === []) {
            throw new QueryException('Oracle returning() requires explicit column names.');
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

        return new Sql($sql, $state->getParams(), $state->tables, $state->returning ?? []);
    }

    public function compileDelete(DeleteState $state): Sql
    {
        if ($state->table === null) {
            throw new QueryException('No table defined for delete query');
        }

        if ($state->returning !== null && $state->returning === []) {
            throw new QueryException('Oracle returning() requires explicit column names.');
        }

        $cteBlock = $this->buildCteBlock($state->ctes, $state->hasRecursiveCte(), 'delete');
        $prefix = $cteBlock !== '' ? $cteBlock . ' ' : '';

        $sql = $prefix . 'DELETE FROM ' . $state->quote($state->table);

        if ($state->whereClause !== null) {
            $sql .= ' WHERE ' . $state->whereClause;
        }

        return new Sql($sql, $state->getParams(), $state->tables, $state->returning ?? []);
    }

    public function compileUpsert(UpsertState $state): Sql
    {
        if ($state->table === null) {
            throw new QueryException('No table defined for upsert query');
        }

        if ($state->conflictKeys === []) {
            throw new QueryException('Conflict keys are required for upsert query');
        }

        $row = $state->values !== [] ? $state->values : ($state->rows[0] ?? []);

        if ($row === []) {
            throw new QueryException('Oracle MERGE upsert requires a single row of values');
        }

        $columns = array_keys($row);
        $selectParts = [];

        foreach ($columns as $column) {
            $selectParts[] = $state->param($row[$column] ?? null) . ' AS ' . $state->quote($column);
        }
        $source = 'SELECT ' . implode(', ', $selectParts) . ' FROM dual';

        $sql = 'MERGE INTO ' . $state->quote($state->table) . ' target USING (' . $source . ') src ON ';

        $conditions = [];

        foreach ($state->conflictKeys as $key) {
            $quoted = $state->quote($key);
            $conditions[] = sprintf('target.%s = src.%s', $quoted, $quoted);
        }
        $sql .= '(' . implode(' AND ', $conditions) . ')';

        if (!$state->doNothing && $state->updates !== []) {
            $assignments = [];

            foreach ($state->updates as $column) {
                $quoted = $state->quote($column);
                $assignments[] = sprintf('target.%s = src.%s', $quoted, $quoted);
            }
            $sql .= ' WHEN MATCHED THEN UPDATE SET ' . implode(', ', $assignments);
        }

        $quotedColumns = array_map(fn (string $col): string => $state->quote($col), $columns);
        $sql .= ' WHEN NOT MATCHED THEN INSERT (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', array_map(fn (string $col): string => 'src.' . $state->quote($col), $columns)) . ')';

        return new Sql($sql, $state->getParams(), $state->tables);
    }

    protected function cteKeyword(bool $hasRecursive): string
    {
        return 'WITH ';
    }

    public function supportsExplain(): bool
    {
        return true;
    }

    public function buildExplainSql(SqlMessage $sql, bool $analyze): iterable
    {
        if ($analyze) {
            throw new QueryException('Oracle dialect does not support EXPLAIN ANALYZE statements.');
        }

        yield new SqlMessage('EXPLAIN PLAN FOR ' . $sql->getQuery(), $sql->getParams(), $sql->getCacheTables());
        yield new SqlMessage('SELECT * FROM TABLE(DBMS_XPLAN.DISPLAY())', [], $sql->getCacheTables());
    }
}
