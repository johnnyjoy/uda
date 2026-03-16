<?php

declare(strict_types=1);

use UDA\Config;
use UDA\Database;
use UDA\Query\QueryPlanCache;
use UDA\Query\WhereBuilder;

require __DIR__ . '/../../vendor/autoload.php';

error_reporting(E_ERROR | E_PARSE);

$tmp = tempnam(sys_get_temp_dir(), 'uda-plan-cache-bench-');
$configPath = $tmp . '.json';
rename($tmp, $configPath);
$config = [
    'defaults' => ['connection' => 'bench'],
    'connections' => [
        'bench' => [
            'driver' => 'sqlite',
            'params' => ['path' => ':memory:'],
        ],
    ],
];
file_put_contents($configPath, (string) json_encode($config));

Config::clearForTests();
$db = Database::connect($configPath);
@unlink($configPath);

$db->exec('CREATE TABLE bench (id INTEGER PRIMARY KEY, label TEXT)');
foreach (range(1, 5) as $id) {
    $db->exec('INSERT INTO bench (id, label) VALUES (:id, :label)', ['id' => $id, 'label' => 'row-' . $id]);
}

$iterations = 100000;

$withoutCache = runBenchmark($db, $iterations, false);
$withCache = runBenchmark($db, $iterations, true);

$improvement = $withoutCache > 0
    ? (($withoutCache - $withCache) / $withoutCache) * 100
    : 0.0;

printf("Query plan cache benchmark (%d identical selects)\n", $iterations);
printf("Without cache: %.4fs\n", $withoutCache);
printf("With cache:    %.4fs\n", $withCache);
printf("Improvement:   %.2f%%\n", $improvement);

function runBenchmark(Database $db, int $iterations, bool $useCache): float
{
    if ($useCache) {
        QueryPlanCache::enable();
        QueryPlanCache::clear();
    } else {
        QueryPlanCache::disable();
    }

    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $builder = $db->select()
            ->select('id')
            ->from('bench');

        /** @var WhereBuilder $where */
        $where = $builder->where('id', ($i % 5) + 1);
        $builder = $where->end();
        $builder->value();
    }

    $elapsed = microtime(true) - $start;

    QueryPlanCache::enable();

    return $elapsed;
}
