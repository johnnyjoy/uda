<?php

declare(strict_types=1);

/** @purpose Query builder - builds SQL messages, does NOT execute */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\SQL\SqlMessage;
use UDA\Query\Sql;

final class SelectQuery extends AbstractQuery
{
    private array $columns = [];
    private ?string $table = null;
    private ?string $alias = null;
    private array $joins = [];
    private array $where = [];
    private array $groupBy = [];
    private array $having = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $hintTables = [];
    /** @var ?Sql Cached Sql instance for repeated toSql() calls */
    private ?Sql $cachedSql = null;

    public function select(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->columns[] = $this->quote($column);
        }
        return $this;
    }

    public function from(string $table, ?string $alias = null): self
    {
        $this->table = $table;
        $this->alias = $alias;
        $this->hintTables = [$table];
        return $this;
    }

    public function join(string $table, string $left, string $right, string $type = 'INNER', ?string $alias = null): self
    {
        $tableClause = $this->quote($table);
        if ($alias !== null) {
            $tableClause .= ' AS ' . $this->quote($alias);
        }
        $this->joins[] = sprintf('%s JOIN %s ON %s = %s', strtoupper($type), $tableClause, $this->quote($left), $this->quote($right));
        if (!in_array($table, $this->hintTables, true)) {
            $this->hintTables[] = $table;
        }
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

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groupBy[] = $this->quote($column);
        }
        return $this;
    }

    public function having(string $column, mixed $value, string $operator = '='): self
    {
        $this->having[] = sprintf('%s %s %s', $this->quote($column), $operator, $this->param($value));
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC', array $allowlist = []): self
    {
        $fragment = $this->driverInstance->orderByAllowed($column, $allowlist ?: [$column => true], $direction);
        $this->orderBy[] = preg_replace('/^ORDER BY\s+/i', '', trim($fragment));
        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new QueryException('Limit must be zero or positive');
        }
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new QueryException('Offset must be zero or positive');
        }
        $this->offset = $offset;
        return $this;
    }

    // Query builders are immutable and do not execute - they only produce Sql objects
    // Execution is handled exclusively by the Driver class

    private function cacheTables(): ?array
    {
        if ($this->hintTables === []) {
            return null;
        }
        return array_values(array_unique($this->hintTables));
    }

    public function toSql(): Sql
    {
        // Simple caching – the query is immutable after construction in the benchmark.
        if ($this->cachedSql !== null) {
            return $this->cachedSql;
        }
        if ($this->table === null) {
            throw new QueryException('No table defined for select query');
        }
        $columns = $this->columns !== [] ? implode(', ', $this->columns) : '*';
        $query = sprintf('SELECT %s FROM %s', $columns, $this->buildFromClause());
        if ($this->joins) {
            $query .= ' ' . implode(' ', $this->joins);
        }
        if ($this->where) {
            $query .= ' WHERE ' . implode(' AND ', $this->where);
        }
        if ($this->groupBy) {
            $query .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        if ($this->having) {
            $query .= ' HAVING ' . implode(' AND ', $this->having);
        }
        if ($this->orderBy) {
            $query .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }
        if ($this->limit !== null) {
            $query .= ' LIMIT ' . $this->param($this->limit);
        }
        if ($this->offset !== null) {
            $query .= ' OFFSET ' . $this->param($this->offset);
        }
        $this->cachedSql = new Sql($query, $this->params->getParams());
        return $this->cachedSql;
    }

    private function buildFromClause(): string
    {
        $clause = $this->quote($this->table);
        if ($this->alias !== null) {
            $clause .= ' AS ' . $this->quote($this->alias);
        }
        return $clause;
    }
}
