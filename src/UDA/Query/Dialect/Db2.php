<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 */

namespace UDA\Query\Dialect;

use UDA\Exception\QueryException;
use UDA\Query\Sql;

/**
 * Db2 dialect implementation.
 */
final class Db2 extends Dialect
{
    public function name(): string
    {
        return 'DB2';
    }

    protected function compileSelectPagination(SelectState $state): string
    {
        if ($state->limit === null && $state->offset === null) {
            return '';
        }

        $fragment = '';

        if ($state->offset !== null) {
            $fragment .= ' OFFSET ' . $state->param($state->offset) . ' ROWS';
        }

        if ($state->limit !== null) {
            $fragment .= ' FETCH NEXT ' . $state->param($state->limit) . ' ROWS ONLY';
        }

        return $fragment;
    }

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

    public function supportsMerge(): bool
    {
        return true;
    }

    public function supportsUpsert(): bool
    {
        return true;
    }
}
