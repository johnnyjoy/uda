<?php

declare(strict_types=1);

use Tests\SQLite\SQLiteDialectTest;
use UDA\Query\Sql;
use UDA\Query\WhereBuilder;

return [
    'select-basic' => [
        'builder' => static function (SQLiteDialectTest $test): Sql {
            $select = $test->select()
                ->distinct()
                ->select('employees.id', 'employees.name', 'salaries.amount')
                ->from('employees')
                ->join('salaries', 'employees.id', 'salaries.employee_id', 'INNER')
                ->groupBy('employees.id', 'employees.name')
                ->orderBy('employees.hired_at', 'DESC')
                ->limit(5)
                ->offset(2);

            /** @var WhereBuilder $where */
            $where = $select->where('employees.title', 'Principal Engineer');
            $where->{'and'}('salaries.amount')->gte(120000);
            $select = $where->end();

            /** @var WhereBuilder $having */
            $having = $select->having('COUNT(salaries.id)', 1, '>');
            $select = $having->end();

            return $select->toSql();
        },
    ],
];
