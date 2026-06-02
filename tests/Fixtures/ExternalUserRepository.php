<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use UDA\Database;

/**
 * Purpose: Proves an external application class can use UDA through Database only.
 */
final class ExternalUserRepository
{
    public function __construct(private Database $db)
    {
    }

    public function create(int $id, string $name): void
    {
        $this->db->exec(
            'INSERT INTO users (id, name) VALUES (:id, :name)',
            ['id' => $id, 'name' => $name],
            ['users']
        );
    }

    public function rename(int $id, string $name): void
    {
        $this->db->transaction(function (Database $db) use ($id, $name): void {
            $db->exec(
                'UPDATE users SET name = :name WHERE id = :id',
                ['id' => $id, 'name' => $name],
                ['users']
            );
        });
    }

    public function findName(int $id): ?string
    {
        $value = $this->db->value(
            'SELECT name FROM users WHERE id = :id',
            ['id' => $id],
            ['users']
        );

        return is_string($value) ? $value : null;
    }
}
