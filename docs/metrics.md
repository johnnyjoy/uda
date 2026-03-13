# Metrics Aggregation

Metrics aggregation is an opt-in trace listener that summarizes query activity (counts, latency, slow hits, errors, table usage) without touching the execution hot path.

## Attaching the Aggregator

```php
use UDA\Metrics\MetricsAggregator;
use UDA\Metrics\MetricsConfig;

$metrics = new MetricsAggregator(new MetricsConfig(
    enabled: true,
    slowQueryThresholdMs: 50,
    maxTrackedQueries: 10000,
    reportTables: true,
));

Database::addTraceListener($metrics);
```

Because it plugs into `Database::addTraceListener`, metrics stay non-core—you decide when to add it (per connection, per environment, per test).

## Snapshotting Metrics

```php
$snapshot = $metrics->snapshot();

foreach ($snapshot->metrics as $metric) {
    printf(
        "%s %s count=%d avg=%.2f max=%.2f slow=%d errors=%d\n",
        $metric->connection,
        $metric->sql,
        $metric->count,
        $metric->averageLatency(),
        $metric->maxLatencyMs,
        $metric->slowCount,
        $metric->errorCount,
    );
}

// Emit JSON blob for logs/exporters
file_put_contents('metrics.json', $snapshot->toJson());
```

Each `QueryMetric` includes `connection`, `operation`, `fingerprint`, `sql`, counts, total/max latency, slow count, error count, and the list of tables recently touched. The snapshot also exposes a `tableActivity` map showing which tables are “hot.”

## Configuration Options

The config object is constructed manually:

- `enabled` (bool): Enables aggregation; default false.
- `slowQueryThresholdMs` (float): Slow-query threshold. Default 0 (disabled).
- `maxTrackedQueries` (int): LRU cap. Default 0 (no limit).
- `reportTables` (bool): Track table activity. Default true.

Example: track only the top 1,000 queries and mark anything over 100 ms as slow:

```php
$metrics = new MetricsAggregator(new MetricsConfig(
    enabled: true,
    slowQueryThresholdMs: 100,
    maxTrackedQueries: 1000,
));
```

## Error Counting

`Database::traceOperation()` now emits an explicit `error` flag in `QueryTrace`. The aggregator increments `errorCount` whenever that flag is true, so failures are counted even if the error happens before row counts are computed.

## Reporter Integration

You can export metrics to Prometheus or other systems by walking the snapshot:

```php
foreach ($snapshot->metrics as $metric) {
    printf("uda_query_count{connection=\"%s\",operation=\"%s\"} %d\n",
        $metric->connection,
        $metric->operation,
        $metric->count,
    );
}
```

## Table Activity

The snapshot includes a `tables` map sorted by frequency. Use it to spot “hot” tables or sudden spikes:

```
{
  "tables": {
    "employees": 4200,
    "orders": 1800
  }
}
```

## Retaining Metrics

- Call `$metrics->reset()` between tests or on demand.
- Set `maxTrackedQueries` to prevent unbounded memory use; the aggregator evicts least recently updated entries when the cap is reached.

## Slow Query Monitoring

With `slowQueryThresholdMs > 0`, the aggregator increments `slowCount` for any trace whose execution time exceeds the threshold. Use this to alert when SELECTs exceed SLA targets without scanning raw trace logs.
