<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use UDA\Link;

/**
 * Purpose: Second Link fixture bound to the 'beta' connection.
 *
 * Exists solely to prove that two classes using UDA\Link with different
 * static connection names remain isolated from each other.
 */
final class TraitBetaRepository
{
    use Link;

    protected static string $connection = 'beta';

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
}
