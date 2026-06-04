<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 * @license MIT
 */

/*
 * Purpose: Compiles query builders into SQL Server-specific SQL.
 *
 * Handles SQL Server pagination, OUTPUT/RETURNING behavior, and MERGE-style
 * upserts for the Query domain only.
 */

namespace UDA\Query\Dialect;

use UDA\Exception\QueryException;
use UDA\Query\Sql;
use UDA\Query\State\Insert as InsertState;
use UDA\Query\State\Select as SelectState;
use UDA\Query\State\Upsert as UpsertState;

/**
 * SQL Server dialect implementation.
 */
class SqlServer extends Dialect
{
    /**
     * Name.
     *
     * @return string Dialect name.
     */
    public function name(): string
    {
        return 'SQL Server';
    }

    /**
     * Report whether supports returning.
     *
     * @return bool Boolean result.
     */
    public function supportsReturning(): bool
    {
        return true;
    }

    /**
     * Report whether supports upsert.
     *
     * @return bool Boolean result.
     */
    public function supportsUpsert(): bool
    {
        return true;
    }

    /**
     * Report whether supports merge.
     *
     * @return bool Boolean result.
     */
    public function supportsMerge(): bool
    {
        return true;
    }

    /**
     * Compile select pagination.
     *
     * @param SelectState $state  Dialect compilation state.
     *
     * @return string Pagination SQL fragment.
     *
     * @throws QueryException If the operation fails.
     */
    protected function compileSelectPagination(SelectState $state): string
    {
        if ($state->limit === null && $state->offset === null) {
            return '';
        }

        if ($state->orderBy === []) {
            throw new QueryException('SQL Server requires ORDER BY when using pagination');
        }

        // LIMIT/OFFSET are validated ints on the builder; T-SQL rejects bound row counts.
        $offset = $state->offset ?? 0;
        $fragment = sprintf(' OFFSET %d ROWS', $offset);

        if ($state->limit !== null) {
            $fragment .= sprintf(' FETCH NEXT %d ROWS ONLY', $state->limit);
        }

        return $fragment;
    }

    /**
     * Compile insert.
     *
     * @param InsertState $state  Dialect compilation state.
     *
     * @return Sql Compiled SQL message.
     */
    public function compileInsert(InsertState $state): Sql
    {
        [$columnNames, $quotedColumns, $values] = $this->prepareInsertData($state);

        $sql = sprintf('INSERT INTO %s (%s)', $state->quote($state->table), implode(', ', $quotedColumns));

        if ($state->returning !== null) {
            if ($state->returning === []) {
                $outputs = ['INSERTED.*'];
            } else {
                $outputs = $this->quoteColumns($state, $state->returning, 'INSERTED.');
            }

            $sql .= ' OUTPUT ' . implode(', ', $outputs);
        }

        $sql .= ' VALUES ' . implode(', ', $values);

        return new Sql($sql, $state->getParams(), $state->tables, $state->returning ?? []);
    }

    /**
     * Compile upsert.
     *
     * @param UpsertState $state  Dialect compilation state.
     *
     * @return Sql Compiled SQL message.
     *
     * @throws QueryException If the operation fails.
     */
    public function compileUpsert(UpsertState $state): Sql
    {
        if ($state->table === null) {
            throw new QueryException('No table defined for upsert query');
        }

        if ($state->conflictKeys === []) {
            throw new QueryException('Conflict keys are required for upsert query');
        }

        $rows = $state->values !== [] ? [$state->values] : $state->rows;

        if ($rows === []) {
            throw new QueryException('No values provided for upsert query');
        }

        $firstRow = $rows[0];
        $columns = array_keys($firstRow);

        if ($columns === []) {
            throw new QueryException('No columns provided for upsert query');
        }

        $quotedColumns = $this->quoteColumns($state, $columns);
        $valueSets = [];

        foreach ($rows as $row) {
            $valueSets[] = '(' . implode(', ', $this->rowPlaceholders($state, $row, $columns)) . ')';
        }

        $sourceColumns = implode(', ', $quotedColumns);
        $sql = 'MERGE ' . $state->quote($state->table) . ' AS target USING (VALUES ' . implode(', ', $valueSets) . ') AS src (' . $sourceColumns . ') ON ';

        $conditions = [];

        foreach ($state->conflictKeys as $key) {
            $quoted = $state->quote($key);
            $conditions[] = sprintf('target.%s = src.%s', $quoted, $quoted);
        }
        $sql .= implode(' AND ', $conditions);

        if (!$state->doNothing && $state->updates !== []) {
            $assignments = [];

            foreach ($state->updates as $column) {
                $quoted = $state->quote($column);
                $assignments[] = sprintf('%s = src.%s', $quoted, $quoted);
            }
            $sql .= ' WHEN MATCHED THEN UPDATE SET ' . implode(', ', $assignments);
        }

        $insertColumns = implode(', ', $quotedColumns);
        $insertValues = implode(', ', $this->quoteColumns($state, $columns, 'src.'));
        $sql .= ' WHEN NOT MATCHED THEN INSERT (' . $insertColumns . ') VALUES (' . $insertValues . ');';

        return new Sql($sql, $state->getParams(), $state->tables);
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
        return 'WITH ';
    }

}
