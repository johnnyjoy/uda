<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 * @license MIT
 */

/*
 * Purpose: Firebird query dialect — pagination, MERGE upsert, RETURNING.
 */

namespace UDA\Query\Dialect;

use UDA\Exception\QueryException;
use UDA\Query\Sql;
use UDA\Query\State\Select as SelectState;
use UDA\Query\State\Upsert as UpsertState;

/**
 * Firebird 5+ dialect.
 */
final class Firebird extends Dialect
{
    public function name(): string
    {
        return 'Firebird';
    }

    public function supportsReturning(): bool
    {
        return true;
    }

    public function supportsWritableCte(): bool
    {
        return false;
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
        if ($state->limit === null && $state->offset === null) {
            return '';
        }

        if ($state->orderBy === []) {
            throw new QueryException('Firebird requires ORDER BY when using pagination');
        }

        if ($state->limit !== null) {
            $offset = $state->offset ?? 0;

            if ($offset === 0) {
                return sprintf(' FETCH FIRST %d ROWS ONLY', $state->limit);
            }

            return sprintf(' OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $offset, $state->limit);
        }

        return sprintf(' OFFSET %d ROWS', $state->offset);
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
                $value = $row[$column] ?? null;
                $param = $state->param($value);
                $valueExprs[] = self::mergeCast($value, $param) . ' AS ' . $state->quote($column);
            }
            $selectParts[] = 'SELECT ' . implode(', ', $valueExprs) . ' FROM RDB$DATABASE';
        }

        $source = '(' . implode(' UNION ALL ', $selectParts) . ') src';

        $sql = 'MERGE INTO ' . $state->quote($state->table) . ' target USING ' . $source . ' ON ';

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

        $quotedColumns = $this->quoteColumns($state, $columns);
        $sql .= ' WHEN NOT MATCHED THEN INSERT (' . implode(', ', $quotedColumns) . ') VALUES (';
        $sql .= implode(', ', $this->quoteColumns($state, $columns, 'src.'));
        $sql .= ')';

        return new Sql($sql, $state->getParams(), $state->tables);
    }

    private static function mergeCast(mixed $value, string $paramSql): string
    {
        if (is_int($value)) {
            return 'CAST(' . $paramSql . ' AS INTEGER)';
        }

        if (is_float($value)) {
            return 'CAST(' . $paramSql . ' AS DOUBLE PRECISION)';
        }

        if (is_bool($value)) {
            return 'CAST(' . $paramSql . ' AS SMALLINT)';
        }

        return 'CAST(' . $paramSql . ' AS VARCHAR(100))';
    }
}
