<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/query/controller
 * @since 1.0.0
 */

/*
 * Purpose: Primary public interface for UDA's query building system.
 */

namespace UDA;

use UDA\Query\Delete;
use UDA\Query\Insert;
use UDA\Query\Select;
use UDA\Query\Update;
use UDA\Query\Upsert;

final class Query
{
    /**
     */
    public static function select(?string $connection = null): Select
    {
        $database = Database::connect($connection);

        return $database->select();
    }

    /**
     */
    public static function insert(?string $connection = null): Insert
    {
        $database = Database::connect($connection);

        return $database->insert();
    }

    /**
     */
    public static function update(?string $connection = null): Update
    {
        $database = Database::connect($connection);

        return $database->update();
    }

    /**
     */
    public static function delete(?string $connection = null): Delete
    {
        $database = Database::connect($connection);

        return $database->delete();
    }

    /**
     */
    public static function upsert(?string $connection = null): Upsert
    {
        $database = Database::connect($connection);

        return $database->upsert();
    }

    /**
     */
    public static function rows(string $sql, array $params = [], ?array $tables = null, ?string $connection = null): array
    {
        $database = Database::connect($connection);

        return $database->rows($sql, $params, $tables);
    }

    /**
     */
    public static function row(string $sql, array $params = [], ?array $tables = null, ?string $connection = null): ?array
    {
        $database = Database::connect($connection);

        return $database->row($sql, $params, $tables);
    }

    /**
     */
    public static function value(string $sql, array $params = [], ?array $tables = null, ?string $connection = null)
    {
        $database = Database::connect($connection);

        return $database->value($sql, $params, $tables);
    }

    /**
     */
    public static function values(string $sql, array $params = [], ?array $tables = null, ?string $connection = null): array
    {
        $database = Database::connect($connection);

        return $database->values($sql, $params, $tables);
    }

    /**
     */
    public static function list(string $sql, array $params = [], ?array $tables = null, ?string $connection = null): array
    {
        $database = Database::connect($connection);

        return $database->list($sql, $params, $tables);
    }

    /**
     */
    public static function exec(string $sql, array $params = [], ?array $tables = null, ?string $connection = null): int
    {
        $database = Database::connect($connection);

        return $database->exec($sql, $params, $tables);
    }
}
