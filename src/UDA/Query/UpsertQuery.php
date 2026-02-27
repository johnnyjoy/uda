<?php

declare(strict_types=1);

/** @purpose Query builder - builds SQL messages, does NOT execute */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\SQL\Sql;

final class UpsertQuery extends AbstractQuery
{
    private ?string $table = null;
    private array $values = [];
    private array $conflictKeys = [];
    private array $updates = [];
    private bool $doNothing = false;

    public function into(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    public function values(array $row): self
    {
        $this->values = $row;
        return $this;
    }

    public function key(array $columns): self
    {
        $this->conflictKeys = $columns;
        return $this;
    }

    public function update(array $columns): self
    {
        $this->updates = $columns;
        return $this;
    }

    public function doNothing(): self
    {
        $this->doNothing = true;
        return $this;
    }

    // Query builders are immutable and do not execute - they only produce Sql objects
    // Execution is handled exclusively by the Driver class

    public function toSql(): Sql
    {
        if ($this->table === null) {
            throw new QueryException('No table defined for upsert query');
        }

        if ($this->values === []) {
            throw new QueryException('No values provided for upsert query');
        }

        if ($this->conflictKeys === []) {
            throw new QueryException('Conflict keys are required for upsert query');
        }

        $columnPlaceholders = [];
        $quotedColumns = [];

        foreach ($this->values as $column => $value) {
            $quotedColumns[] = $this->quote($column);
            $columnPlaceholders[] = $this->param($value);
        }

        $query = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->quote($this->table), implode(', ', $quotedColumns), implode(', ', $columnPlaceholders));
        $query .= ' ON CONFLICT (' . implode(', ', array_map(fn(string $column): string => $this->quote($column), $this->conflictKeys)) . ')';

        if ($this->doNothing || $this->updates === []) {
            $query .= ' DO NOTHING';
        } else {
            $assignments = [];

            foreach ($this->updates as $column) {
                $assignments[] = sprintf('%s = EXCLUDED.%s', $this->quote($column), $this->quote($column));
            }

            $query .= ' DO UPDATE SET ' . implode(', ', $assignments);
        }

        return new Sql($query, $this->params->getParams());
    }
}
