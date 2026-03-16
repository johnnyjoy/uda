<?php

declare(strict_types=1);

namespace UDA\Driver;

use PDOStatement;

/**
 * Connection-scoped cache for prepared PDO statements.
 */
final class PreparedStatementCache
{
    /** @var array<string, PDOStatement> */
    private array $statements = [];

    /** @var list<string> */
    private array $order = [];

    private bool $enabled;

    public function __construct(private int $limit = 500)
    {
        $this->enabled = $limit > 0;
    }

    public function get(string $key): ?PDOStatement
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->statements[$key] ?? null;
    }

    public function put(string $key, PDOStatement $statement): void
    {
        if (!$this->enabled) {
            return;
        }

        if (isset($this->statements[$key])) {
            $this->statements[$key] = $statement;

            return;
        }

        $this->statements[$key] = $statement;
        $this->order[] = $key;
        $this->evictIfNeeded();
    }

    public function clear(): void
    {
        foreach ($this->statements as $statement) {
            $statement->closeCursor();
        }

        $this->statements = [];
        $this->order = [];
    }

    public function size(): int
    {
        return count($this->statements);
    }

    /**
     * @return array<string, PDOStatement>
     */
    public function all(): array
    {
        return $this->statements;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values($this->order);
    }

    private function evictIfNeeded(): void
    {
        while ($this->limit > 0 && count($this->statements) > $this->limit) {
            $key = array_shift($this->order);

            if ($key === null) {
                break;
            }

            if (isset($this->statements[$key])) {
                $this->statements[$key]->closeCursor();
                unset($this->statements[$key]);
            }
        }
    }
}
