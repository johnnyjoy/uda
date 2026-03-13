# Query Replay & Debug Snapshots

Query replay gives developers a deterministic way to reproduce production database activity on any environment. When replay capture is enabled, UDA records every traced query (reads, writes, explains) as newline-delimited JSON snapshots that can later be executed, explained, or benchmarked with the `QueryReplayer` utility.

## Enabling Capture

Replay is disabled by default. Enable it via configuration (root `replay` block):

```json
{
  "defaults": {
    "connection": "app",
    "replay": {
      "enabled": true,
      "backend": "file",
      "directory": "storage/replay",
      "maxSqlLength": 65536,
      "maxParamSize": 8192
    }
  },
  "connections": {
    "app": {
      "driver": "pgsql",
      "params": {"host": "db", "dbname": "app"}
    }
  }
}
```

When enabled, UDA registers `ReplayCaptureListener`, which listens to trace events and writes snapshots to `storage/replay/queries-YYYY-MM-DD.ndjson`. No trace listener is registered when replay is disabled, so normal execution has zero additional cost.

## Snapshot Format

Each line in the NDJSON file is a `ReplaySnapshot`:

```json
{
  "timestamp": 1710094101,
  "connection": "primary",
  "dialect": "postgres",
  "operation": "select",
  "sql": "SELECT id, name FROM employees WHERE id = :p1",
  "params": {":p1": 42},
  "tables": ["employees"],
  "duration": 2.3,
  "rowCount": 1,
  "sqlTruncated": false,
  "parametersTruncated": false
}
```

Fields exceeding `maxSqlLength` or `maxParamSize` are truncated and flagged. Snapshot parameters reuse the tracing redaction settings; you can additionally mask specific keys via `replay.maskParameters` (values replaced with `***`).

## Storage Backend

The initial backend writes NDJSON files under `storage/replay`. Files are rotated once per UTC day (e.g., `queries-2026-03-10.ndjson`). You can change the directory by setting `replay.directory` to an absolute or relative path. Storage ensures directories exist and uses exclusive locks while appending to avoid partially written lines.

## Replaying Snapshots

Instantiate the `QueryReplayer` with any `Database` instance:

```php
$db = Database::connect();
$replayer = new QueryReplayer($db);

// Run a single snapshot object
$result = $replayer->runSnapshot($snapshot);

// Replay an entire NDJSON file
$results = $replayer->runFile('storage/replay/queries-2026-03-10.ndjson');

// Explain or benchmark a snapshot
$plan = $replayer->explain($snapshot);
$benchmark = $replayer->benchmark($snapshot, iterations: 100);
```

`runSnapshot` maps the recorded operation to the matching Database API (`rows`, `row`, `exec`, `returning`, `explain`, etc.). `benchmark()` executes the snapshot repeatedly and returns latency measurements so you can compare environments.

## Debug Workflow

1. Enable replay capture in the target environment and deploy.
2. Run the application/workload; snapshots accumulate under `storage/replay`.
3. Copy the NDJSON file to a development machine.
4. Start a database configured like production and run `QueryReplayer->runFile()` to reproduce the workload locally.
5. Use `explain()` or `benchmark()` to compare plans and timings, then adjust queries or indexes accordingly.

Replay captures query text and parameters but not database state. For reproducible debugging, pair snapshots with relevant data exports or seed scripts.
