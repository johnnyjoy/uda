<?php

declare(strict_types=1);

use Tests\Postgres\PostgresDialectTest;
use UDA\Query\Sql;
use UDA\Query\WhereBuilder;

return [
    'update-returning' => [
        'builder' => static function (PostgresDialectTest $test): Sql {
            $update = $test->update()
                ->table('employees')
                ->set('title', 'Director of Engineering')
                ->set('hired_at', '2019-09-01')
                ->returning('id', 'title');

            /** @var WhereBuilder $where */
            $where = $update->where('name', 'Bex Stone');
            $update = $where->{'and'}('title')->eq('Engineering Manager')->end();

            return $update->toSql();
        },
    ],
];
