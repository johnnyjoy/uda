<?php

declare(strict_types=1);

use Tests\Postgres\PostgresDialectTest;
use UDA\Query\Sql;

return [
    'upsert-on-conflict-update' => [
        'builder' => static function (PostgresDialectTest $test): Sql {
            $upsert = $test->upsert()
                ->into('salaries')
                ->values([
                    'id' => 5001,
                    'employee_id' => 1,
                    'contractor_id' => null,
                    'amount' => 140000,
                    'paid_at' => '2024-12-31',
                ])
                ->key(['id'])
                ->update(['amount', 'paid_at']);

            return $upsert->toSql();
        },
    ],
    'upsert-on-conflict-nothing' => [
        'builder' => static function (PostgresDialectTest $test): Sql {
            $upsert = $test->upsert()
                ->into('employees')
                ->values([
                    'id' => 2,
                    'name' => 'Bex Stone',
                    'title' => 'Engineering Manager',
                    'hired_at' => '2021-06-01',
                ])
                ->key(['id'])
                ->doNothing();

            return $upsert->toSql();
        },
    ],
];
