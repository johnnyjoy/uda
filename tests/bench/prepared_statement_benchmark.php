<?php

declare(strict_types=1);

use UDA\Config;
use UDA\Database;
use UDA\Query\WhereBuilder;

require __DIR__ . '/../../vendor/autoload.php';

error_reporting(E_ERROR | E_PARSE);

$iterations = 100000;

$withoutReuse = runBenchmark($iterations, 0);
$withReuse = runBenchmark($iterations, 500);

$improvement = $withoutReuse > 0
    ? (($withoutReuse - $withReuse) / $withoutReuse) * 100
    : 0.0;

printf("Prepared statement cache benchmark (%d identical queries)\n", $iterations);
printf("Without reuse: %.4fs\n", $withoutReuse);
printf("With reuse:    %.4fs\n", $withReuse);
printf("Improvement:   %.2f%%\n", $improvement);

function runBenchmark(int $iterations, int $statementCacheLimit): float
{
    Config::clearForTests();
    $config = [
        'defaults' => ['connection' => 'bench'],
        'connections' => [
            'bench' => [
                'driver' => 'sqlite',
                'params' => ['path' => ':memory:'],
                'statement_cache_limit' => $statementCacheLimit,
            ],
        ],
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'uda-ps-bench-');
    $path = $tmp . '.json';
    rename($tmp, $path);
    file_put_contents($path, (string) json_encode($config));

    $db = Database::connect($path);
    @unlink($path);

    $db->exec('CREATE TABLE bench (id INTEGER PRIMARY KEY, label TEXT)');
    foreach (range(1, 5) as $id) {
        $db->exec('INSERT INTO bench (id, label) VALUES (:id, :label)', ['id' => $id, 'label' => 'row-' . $id]);
    }

    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $builder = $db->select()
            ->select('label')
            ->from('bench');

        /** @var WhereBuilder $where */
        $where = $builder->where('id', ($i % 5) + 1);
        $where->end()->value();
    }

    return microtime(true) - $start;
}
