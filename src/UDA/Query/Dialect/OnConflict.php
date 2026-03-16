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
 * Base dialect that implements INSERT ... ON CONFLICT semantics.
 */
abstract class OnConflict extends Dialect
{
    final public function compileUpsert(UpsertState $state): Sql
    {
        if ($state->table === null) {
            throw new QueryException('No table defined for upsert query');
        }

        $rows = $state->values !== [] ? [$state->values] : $state->rows;

        if ($rows === []) {
            throw new QueryException('No values provided for upsert query');
        }

        if ($state->conflictKeys === []) {
            throw new QueryException('Conflict keys are required for upsert query');
        }

        $firstRow = $rows[0];
        $columns = array_keys($firstRow);

        if ($columns === []) {
            throw new QueryException('No columns provided for upsert query');
        }

        $quotedColumns = array_map(fn (string $col): string => $state->quote($col), $columns);
        $valueGroups = [];

        foreach ($rows as $row) {
            $valueGroups[] = '(' . implode(', ', array_map(fn (string $column) => $state->param($row[$column] ?? null), $columns)) . ')';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $state->quote($state->table),
            implode(', ', $quotedColumns),
            implode(', ', $valueGroups)
        );

        $sql .= ' ON CONFLICT (' . implode(', ', array_map(fn (string $key): string => $state->quote($key), $state->conflictKeys)) . ')';

        if ($state->doNothing || $state->updates === []) {
            $sql .= ' DO NOTHING';
        } else {
            $assignments = [];

            foreach ($state->updates as $column) {
                $quoted = $state->quote($column);
                $assignments[] = sprintf('%s = EXCLUDED.%s', $quoted, $quoted);
            }
            $sql .= ' DO UPDATE SET ' . implode(', ', $assignments);
        }

        return new Sql($sql, $state->getParams(), $state->tables);
    }
}
