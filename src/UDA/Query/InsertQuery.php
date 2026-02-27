<?php

declare(strict_types=1);

/** @purpose Query builder - builds INSERT statements */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\Query\Sql;

final class InsertQuery extends AbstractQuery
{
    private ?string $table = null;
    private array $columns = [];
    private ?array $returning = null;

    public function into(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    public function set(string $column, mixed $value): self
    {
        $this->columns[$column] = $value;
        return $this;
    }

    public function returning(string ...$columns): self
    {
        $this->returning = $columns;
        return $this;
    }

    // Query builders are immutable and do not execute - they only produce Sql objects
    // Execution is handled exclusively by the Driver class

    // Query builders are immutable and do not execute - they only produce Sql objects
    // Execution is handled exclusively by the Driver class

    public function toSql(): Sql
    {
        return $this->buildInsertSql(false);
    }

    private function buildInsertSql(bool $withReturning): Sql
    {
        if ($this->table === null) {
            throw new QueryException('No table defined for insert query');
        }
        if ($this->columns === []) {
            throw new QueryException('No columns have been set for insert query');
        }

        $columnList = array_map(fn(string $col): string => $this->quote($col), array_keys($this->columns));
        $placeholders = [];
        foreach ($this->columns as $value) {
            $placeholders[] = $this->param($value);
        }

        $query = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quote($this->table),
            implode(', ', $columnList),
            implode(', ', $placeholders)
        );

        if ($withReturning) {
            $cols = $this->returning ?? ['*'];
            $query .= ' RETURNING ' . implode(', ', array_map(fn(string $c): string => $this->quote($c), $cols));
        }

        return new Sql($query, $this->params->getParams());
    }
}
