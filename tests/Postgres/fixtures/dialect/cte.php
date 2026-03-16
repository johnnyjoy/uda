<?php

declare(strict_types=1);

use Tests\Postgres\PostgresDialectTest;
use UDA\Query\Sql;
use UDA\Query\WhereBuilder;

return [
    'cte-recursive-select' => [
        'builder' => static function (PostgresDialectTest $test): Sql {
            $recent = $test->select()
                ->select('employees.id', 'employees.name', 'employees.hired_at')
                ->from('employees');

            /** @var WhereBuilder $recentWhere */
            $recentWhere = $recent->where('employees.hired_at', '2023-01-01', '>=');
            $recent = $recentWhere->end();

            $treePaths = Sql::of(
                'SELECT "tree_nodes"."id", "tree_nodes"."parent_id", "tree_nodes"."name" FROM "tree_nodes" WHERE "tree_nodes"."parent_id" IS NULL '
                . 'UNION ALL '
                . 'SELECT "child"."id", "child"."parent_id", "child"."name" FROM "tree_nodes" AS "child" '
                . 'JOIN "tree_paths" ON "child"."parent_id" = "tree_paths"."id"'
            );

            $select = $test->select()
                ->with('recent_hires', $recent)
                ->materialized()
                ->withRecursive('tree_paths', $treePaths)
                ->select('tree_paths.id', 'tree_paths.name')
                ->selectRaw('COALESCE("recent_hires"."name", \'n/a\') AS recent_name')
                ->from('tree_paths')
                ->join('recent_hires', 'tree_paths.id', 'recent_hires.id', 'LEFT')
                ->orderBy('tree_paths.id');

            return $select->toSql();
        },
    ],
];
