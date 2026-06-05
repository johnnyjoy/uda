<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 * @license MIT
 */

/*
 * Purpose: Compiles query builders into MariaDB/MySQL-specific SQL.
 *
 * Handles ON DUPLICATE KEY update forms and MariaDB-compatible query syntax.
 */

namespace UDA\Query\Dialect;

use UDA\Exception\QueryException;
use UDA\Query\Sql;
use UDA\Query\State\Upsert as UpsertState;

/**
 * MariaDB/MySQL-compatible dialect handling INSERT IGNORE and ON DUPLICATE KEY.
 */
class MariaDb extends Dialect
{
    /**
     * Name.
     *
     * @return string Dialect name.
     */
    public function name(): string
    {
        return 'MariaDB';
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
        $valueGroups = [];

        foreach ($rows as $row) {
            $valueGroups[] = '(' . implode(', ', $this->rowPlaceholders($state, $row, $columns)) . ')';
        }

        $verb = ($state->doNothing || $state->updates === []) ? 'INSERT IGNORE INTO' : 'INSERT INTO';

        $sql = sprintf(
            '%s %s (%s) VALUES %s',
            $verb,
            $state->quote($state->table),
            implode(', ', $quotedColumns),
            implode(', ', $valueGroups)
        );

        if (!$state->doNothing && $state->updates !== []) {
            $assignments = [];

            foreach ($state->updates as $column) {
                $assignments[] = sprintf('%s = VALUES(%s)', $state->quote($column), $state->quote($column));
            }
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
        }

        return new Sql($sql, $state->getParams(), $state->tables);
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
