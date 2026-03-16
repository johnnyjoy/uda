<?php

declare(strict_types=1);

use Tests\SQLite\SQLiteDialectTest;
use UDA\Query\Sql;
use UDA\Query\WhereBuilder;

return [
    'cte-recursive-select' => [
        'builder' => static function (SQLiteDialectTest $test): Sql {
            $recent = $test->select()
                ->select('employees.id', 'employees.name', 'employees.hired_at')
                ->from('employees');

            /** @var WhereBuilder $recentWhere */
            $recentWhere = $recent->where('employees.hired_at', '2023-01-01', '>=');
            $recent = $recentWhere->end();

            $salaryTotals = $test->select()
                ->select('salaries.employee_id')
                ->selectRaw('SUM("salaries"."amount") AS total_paid')
                ->from('salaries')
                ->groupBy('salaries.employee_id');

            /** @var WhereBuilder $having */
            $having = $salaryTotals->having('SUM(salaries.amount)', 200000, '>=' );
            $salaryTotals = $having->end();

            $select = $test->select()
                ->with('recent_hires', $recent)
                ->materialized()
                ->with('salary_totals', $salaryTotals)
                ->select('recent_hires.id', 'recent_hires.name')
                ->selectRaw('COALESCE("salary_totals"."total_paid", 0) AS total_paid')
                ->from('recent_hires')
                ->join('salary_totals', 'recent_hires.id', 'salary_totals.employee_id', 'LEFT')
                ->orderBy('total_paid', 'DESC');

            return $select->toSql();
        },
    ],
];
