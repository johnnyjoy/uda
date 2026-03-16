<?php

declare(strict_types=1);

use Tests\Postgres\PostgresDialectTest;
use UDA\Query\Sql;

return [
    'insert-multi-row' => [
        'builder' => static function (PostgresDialectTest $test): Sql {
            $insert = $test->insert()
                ->into('salaries')
                ->rows([
                    [
                        'id' => 6001,
                        'employee_id' => 1,
                        'contractor_id' => null,
                        'amount' => 130000,
                        'paid_at' => '2024-04-30',
                    ],
                    [
                        'id' => 6002,
                        'employee_id' => 2,
                        'contractor_id' => null,
                        'amount' => 135000,
                        'paid_at' => '2024-04-30',
                    ],
                ]);

            return $insert->toSql();
        },
    ],
];
