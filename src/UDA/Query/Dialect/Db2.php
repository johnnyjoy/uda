<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 * @license MIT
 */

/*
 * Purpose: Compiles query builders into DB2-specific SQL.
 *
 * Handles DB2 pagination and MERGE/upsert syntax in the Query domain.
 * Requires UDA\Driver\Db2 and pdo_ibm at connect time.
 */

namespace UDA\Query\Dialect;

use UDA\Exception\QueryException;
use UDA\Query\Sql;

/**
 * Db2 dialect implementation.
 */
final class Db2 extends Dialect
{
    /**
     * Name.
     *
     * @return string Dialect name.
     */
    public function name(): string
    {
        return 'DB2';
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
        if ($state->limit === null && $state->offset === null) {
            return '';
        }

        // LIMIT/OFFSET are validated ints; Db2 LUW rejects bound row-count parameters.
        if ($state->offset === null && $state->limit !== null) {
            return sprintf(' FETCH FIRST %d ROWS ONLY', $state->limit);
        }

        $fragment = '';

        if ($state->offset !== null) {
            $fragment .= sprintf(' OFFSET %d ROWS', $state->offset);
        }

        if ($state->limit !== null) {
            $fragment .= sprintf(' FETCH NEXT %d ROWS ONLY', $state->limit);
        }

        return $fragment;
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

        $selectParts = [];
        foreach ($rows as $row) {
            $valueExprs = [];
            foreach ($columns as $column) {
                $valueExprs[] = $state->param($row[$column] ?? null) . ' AS ' . $state->quote($column);
            }
            $selectParts[] = 'SELECT ' . implode(', ', $valueExprs) . ' FROM SYSIBM.SYSDUMMY1';
        }

        $source = '(' . implode(' UNION ALL ', $selectParts) . ') src';

        $sql = 'MERGE INTO ' . $state->quote($state->table) . ' AS target USING ' . $source . ' ON ';

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

        $quotedColumns = array_map(fn (string $col): string => $state->quote($col), $columns);
        $sql .= ' WHEN NOT MATCHED THEN INSERT (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', array_map(fn (string $col): string => 'src.' . $state->quote($col), $columns)) . ')';

        return new Sql($sql, $state->getParams(), $state->tables);
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
     * Report whether supports upsert.
     *
     * @return bool Boolean result.
     */
    public function supportsUpsert(): bool
    {
        return true;
    }
}
