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
 * MariaDB/MySQL-compatible dialect handling INSERT IGNORE and ON DUPLICATE KEY.
 */
final class MariaDb extends Dialect
{
    public function name(): string
    {
        return 'MariaDB';
    }

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

        $quotedColumns = array_map(fn (string $col): string => $state->quote($col), $columns);
        $valueGroups = [];

        foreach ($rows as $row) {
            $valueGroups[] = '(' . implode(', ', array_map(fn (string $column) => $state->param($row[$column] ?? null), $columns)) . ')';
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

    public function supportsUpsert(): bool
    {
        return true;
    }

    public function supportsExplain(): bool
    {
        return true;
    }

    public function supportsExplainAnalyze(): bool
    {
        return true;
    }

    public function buildExplainSql(SqlMessage $sql, bool $analyze): iterable
    {
        $prefix = $analyze ? 'EXPLAIN ANALYZE ' : 'EXPLAIN ';

        yield new SqlMessage($prefix . $sql->getQuery(), $sql->getParams(), $sql->getCacheTables());
    }
}
