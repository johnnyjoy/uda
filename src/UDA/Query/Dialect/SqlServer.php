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
 * SQL Server dialect implementation.
 */
class SqlServer extends Dialect
{
    public function name(): string
    {
        return 'SQL Server';
    }

    public function supportsReturning(): bool
    {
        return true;
    }

    public function supportsUpsert(): bool
    {
        return true;
    }

    public function supportsMerge(): bool
    {
        return true;
    }

    protected function compileSelectPagination(SelectState $state): string
    {
        if ($state->limit === null && $state->offset === null) {
            return '';
        }

        if ($state->orderBy === []) {
            throw new QueryException('SQL Server requires ORDER BY when using pagination');
        }

        $offset = $state->offset ?? 0;
        $fragment = ' OFFSET ' . $state->param($offset) . ' ROWS';

        if ($state->limit !== null) {
            $fragment .= ' FETCH NEXT ' . $state->param($state->limit) . ' ROWS ONLY';
        }

        return $fragment;
    }

    public function compileInsert(InsertState $state): Sql
    {
        [$columnNames, $quotedColumns, $values] = $this->prepareInsertData($state);

        $sql = sprintf('INSERT INTO %s (%s)', $state->quote($state->table), implode(', ', $quotedColumns));

        if ($state->returning !== null) {
            if ($state->returning === []) {
                $outputs = ['INSERTED.*'];
            } else {
                $outputs = array_map(
                    fn (string $col): string => 'INSERTED.' . $state->quote($col),
                    $state->returning
                );
            }

            $sql .= ' OUTPUT ' . implode(', ', $outputs);
        }

        $sql .= ' VALUES ' . implode(', ', $values);

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
        $valueSets = [];

        foreach ($rows as $row) {
            $valueSets[] = '(' . implode(', ', array_map(fn (string $column) => $state->param($row[$column] ?? null), $columns)) . ')';
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
        $insertValues = implode(', ', array_map(fn (string $col): string => 'src.' . $state->quote($col), $columns));
        $sql .= ' WHEN NOT MATCHED THEN INSERT (' . $insertColumns . ') VALUES (' . $insertValues . ');';

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
            throw new QueryException('SQL Server dialect does not support EXPLAIN ANALYZE statements.');
        }

        $batch = <<<SQL
BEGIN TRY
    SET SHOWPLAN_ALL ON;
    %s
    SET SHOWPLAN_ALL OFF;
END TRY
BEGIN CATCH
    SET SHOWPLAN_ALL OFF;
    THROW;
END CATCH
SQL;

        $statement = sprintf($batch, $sql->getQuery());

        yield new SqlMessage($statement, $sql->getParams(), $sql->getCacheTables());
    }
}
