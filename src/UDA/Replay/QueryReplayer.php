<?php

declare(strict_types=1);

namespace UDA\Replay;

use InvalidArgumentException;
use UDA\Database;
use UDA\Tracing\ReplaySnapshot;

final class QueryReplayer
{
    public function __construct(private readonly Database $database)
    {
    }

    public function runSnapshot(ReplaySnapshot $snapshot): mixed
    {
        $sql = $snapshot->sql;
        $params = $snapshot->params;
        $tables = $snapshot->tables;

        return match ($snapshot->operation) {
            'rows' => $this->database->rows($sql, $params, $tables),
            'row' => $this->database->row($sql, $params, $tables),
            'value' => $this->database->value($sql, $params, $tables),
            'values' => $this->database->values($sql, $params, $tables),
            'list' => $this->database->list($sql, $params, $tables),
            'exec' => $this->database->exec($sql, $params, $tables),
            'returning' => $this->database->returning($sql, $params, $tables),
            'explain' => $this->database->explain($sql, $params, $tables),
            'explain_analyze' => $this->database->explainAnalyze($sql, $params, $tables),
            'each' => $this->database->rows($sql, $params, $tables), // fallback to rows
            default => throw new InvalidArgumentException('Unsupported replay operation: ' . $snapshot->operation),
        };
    }

    /**
     * @return array<int,mixed>
     */
    public function runFile(string $path): array
    {
        $results = [];

        foreach (ReplaySnapshotLoader::fromFile($path) as $snapshot) {
            $results[] = $this->runSnapshot($snapshot);
        }

        return $results;
    }

    public function explain(ReplaySnapshot $snapshot, bool $analyze = false): array
    {
        if ($analyze || $snapshot->operation === 'explain_analyze') {
            return $this->database->explainAnalyze($snapshot->sql, $snapshot->params, $snapshot->tables);
        }

        return $this->database->explain($snapshot->sql, $snapshot->params, $snapshot->tables);
    }

    /**
     * @return array{result:mixed,durations:array<int,float>}
     */
    public function benchmark(ReplaySnapshot $snapshot, int $iterations = 1): array
    {
        if ($iterations < 1) {
            throw new InvalidArgumentException('Benchmark iterations must be >= 1');
        }

        $durations = [];
        $result = null;

        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $result = $this->runSnapshot($snapshot);
            $durations[] = microtime(true) - $start;
        }

        return ['result' => $result, 'durations' => $durations];
    }
}
