<?php

declare(strict_types=1);

use Tests\SQLite\SQLiteDialectTest;
use UDA\Query\Sql;

return [
    'insert-returning' => [
        'builder' => static function (SQLiteDialectTest $test): Sql {
            $insert = $test->insert()
                ->into('employees')
                ->values([
                    'id' => 3,
                    'name' => 'Casey Bloom',
                    'title' => 'Staff Engineer',
                    'hired_at' => '2024-10-15',
                ])
                ->returning('id', 'name');

            return $insert->toSql();
        },
    ],
];
