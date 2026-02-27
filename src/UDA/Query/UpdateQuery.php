<?php

declare(strict_types=1);

/** @purpose Query builder - builds SQL messages, does NOT execute */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\Query\Sql;

final class UpdateQuery extends AbstractQuery
{
    private ?string $table = null;
    private array $sets = [];
    private array $where = [];

    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    public function set(string $column, mixed $value): self
    {
        $this->sets[$column] = $value;
        return $this;
    }

    public function where(string $column, mixed $value, string $operator = '='): self
    {
        $this->where[] = sprintf('%s %s %s', $this->quote($column), $operator, $this->param($value));
        return $this;
    }

    public function whereColumn(string $left, string $right, string $operator = '='): self
    {
        $this->where[] = sprintf('%s %s %s', $this->quote($left), $operator, $this->quote($right));
        return $this;
    }

    // Query builders are immutable and do not execute - they only produce Sql objects
    // Execution is handled exclusively by the Driver class

    public function toSql(): Sql
    {
        if ($this->table === null) {
            throw new QueryException('No table defined for update query');
        }

        if ($this->sets === []) {
            throw new QueryException('No values set for update query');
        }

        $assignments = [];

        foreach ($this->sets as $column => $value) {
            $assignments[] = sprintf('%s = %s', $this->quote($column), $this->param($value));
        }

        $query = sprintf('UPDATE %s SET %s', $this->quote($this->table), implode(', ', $assignments));

        if ($this->where) {
            $query .= ' WHERE ' . implode(' AND ', $this->where);
        }

        return new Sql($query, $this->params->getParams());
    }
}

