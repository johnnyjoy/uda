<?php

declare(strict_types=1);

use Tests\Postgres\PostgresDialectTest;
use UDA\Query\Sql;
use UDA\Query\WhereBuilder;

return [
    'delete-returning' => [
        'builder' => static function (PostgresDialectTest $test): Sql {
            $delete = $test->delete()
                ->table('salaries')
                ->returning('id', 'amount');

            /** @var WhereBuilder $where */
            $where = $delete->where('paid_at', '2023-03-31');
            $delete = $where->{'and'}('amount')->gte(125000)->end();

            return $delete->toSql();
        },
    ],
];
