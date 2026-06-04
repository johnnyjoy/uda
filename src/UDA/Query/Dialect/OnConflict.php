<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 * @license MIT
 */

/*
 * Purpose: Provides shared ON CONFLICT upsert compilation for compatible dialects.
 *
 * PostgreSQL and SQLite use this base dialect to compile builder state into
 * INSERT ... ON CONFLICT SQL without duplicating the conflict logic.
 */

namespace UDA\Query\Dialect;

use UDA\Exception\QueryException;
use UDA\Query\Sql;
use UDA\Query\State\Upsert as UpsertState;

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

        $quotedColumns = $this->quoteColumns($state, $columns);
        $valueGroups = [];

        foreach ($rows as $row) {
            $valueGroups[] = '(' . implode(', ', $this->rowPlaceholders($state, $row, $columns)) . ')';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $state->quote($state->table),
            implode(', ', $quotedColumns),
            implode(', ', $valueGroups)
        );

        $sql .= ' ON CONFLICT (' . implode(', ', $this->quoteColumns($state, $state->conflictKeys)) . ')';

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
