<?php

declare(strict_types=1);

use Tests\SQLite\SQLiteDialectTest;
use UDA\Query\Sql;
use UDA\Query\WhereBuilder;

return [
    'union-all-people' => [
        'builder' => static function (SQLiteDialectTest $test): Sql {
            $employees = $test->select()
                ->select('employees.name')
                ->selectRaw("'employee' AS source")
                ->from('employees');

            /** @var WhereBuilder $empWhere */
            $empWhere = $employees->where('employees.title', 'Principal Engineer');
            $employees = $empWhere->end();

            $contractors = $test->select()
                ->select('contractors.name')
                ->selectRaw("'contractor' AS source")
                ->from('contractors');

            $union = $employees
                ->unionAll($contractors)
                ->orderBy('name')
                ->limit(5);

            return $union->toSql();
        },
    ],
];
