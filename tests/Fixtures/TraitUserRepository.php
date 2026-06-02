<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use UDA\Link;

/**
 * Purpose: Proves an external class can keep SQL behind a UDA Link.
 *
 * The connection is a fact about this class, not about any individual
 * instance. It is declared static so a single Database handle is shared
 * across all instances — built once, reused forever.
 */
final class TraitUserRepository
{
    use Link;

    protected static string $connection = 'alpha';

    /**
     * Create a user.
     *
     * @param int    $id    User id.
     * @param string $name  User name.
     *
     * @return void
     */
    public function create(int $id, string $name): void
    {
        $this->exec(
            'INSERT INTO users (id, name) VALUES (:id, :name)',
            ['id' => $id, 'name' => $name],
            ['users']
        );
    }

    /**
     * Rename a user inside a transaction.
     *
     * @param int    $id    User id.
     * @param string $name  New user name.
     *
     * @return void
     */
    public function rename(int $id, string $name): void
    {
        $this->transaction(function () use ($id, $name): void {
            $this->exec(
                'UPDATE users SET name = :name WHERE id = :id',
                ['id' => $id, 'name' => $name],
                ['users']
            );
        });
    }

    /**
     * Find a user name by id.
     *
     * @param int $id  User id.
     *
     * @return ?string User name or null.
     */
    public function findName(int $id): ?string
    {
        $value = $this->value(
            'SELECT name FROM users WHERE id = :id',
            ['id' => $id],
            ['users']
        );

        return is_string($value) ? $value : null;
    }

    /**
     * Return all users.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findAll(): array
    {
        return $this->rows('SELECT id, name FROM users', [], ['users']);
    }

    /**
     * Find a user as a numeric list.
     *
     * @param int $id  User id.
     *
     * @return ?array<int,mixed> User row values or null.
     */
    public function findList(int $id): ?array
    {
        return $this->list(
            'SELECT id, name FROM users WHERE id = :id',
            ['id' => $id],
            ['users']
        );
    }

    /**
     * Run intentionally unsafe SQL to prove normal UDA safety still applies.
     *
     * @return void
     */
    public function runUnsafeProbe(): void
    {
        $this->rows('SELECT ? AS id', [1]);
    }
}
