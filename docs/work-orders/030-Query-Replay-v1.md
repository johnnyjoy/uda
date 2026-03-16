# Work Order 030 — Query Replay / Debug Snapshots

**Status:** Planned
**Priority:** Medium
**Category:** Developer Tooling (Non-Core)
**Depends On:**

* WO027 — Query Tracing / Observability
* WO029 — Safety Guardrails

---

# Objective

Provide a **deterministic query replay mechanism** that allows developers to capture database operations and replay them later for debugging, analysis, and reproducibility.

Replay snapshots capture:

* SQL text
* parameters
* connection name
* dialect
* operation type
* execution timing
* result metadata

This enables:

* reproducing production bugs
* replaying queries locally
* performance analysis
* debugging query builders
* validating dialect compilation

Replay must **not impact normal execution performance** when disabled.

---

# Design Principles

### 1. Observability Layer Only

Replay is built on top of the **existing tracing infrastructure (WO027)**.

Replay does **not modify the query execution path**.

Execution remains:

```text
Builder → SqlMessage → Database → Driver → PDO
```

Replay hooks into **trace events only**.

---

### 2. Zero Overhead When Disabled

Replay must not:

* allocate snapshot structures
* copy parameters
* serialize SQL
* allocate buffers

unless replay capture is enabled.

---

### 3. Deterministic Snapshots

Each replay snapshot must contain sufficient information to reproduce execution:

Required fields:

```text
timestamp
connection
dialect
operation
sql
params
tables
duration
rowCount
```

---

### 4. Replay Must Be Explicit

Replay functionality must be **opt-in**.

Applications must enable it through configuration.

---

# Architecture

Replay integrates with the tracing system introduced in **WO027**.

Execution flow:

```
Query Execution
        │
        ▼
Database::traceOperation()
        │
        ▼
TraceListeners
        │
        ├── LoggingListener
        ├── MetricsListener
        └── ReplayCaptureListener
```

Replay is implemented as a **trace listener**.

---

# New Components

## ReplayCaptureListener

Namespace:

```php
UDA\Tracing\ReplayCaptureListener
```

Responsibilities:

* capture SQL execution events
* construct replay snapshots
* send them to a replay storage backend

---

## ReplaySnapshot

Value object representing a replayable query.

```php
class ReplaySnapshot
{
    public string $connection;
    public string $dialect;
    public string $operation;

    public string $sql;
    public array $params;

    public array $tables;

    public float $duration;
    public int $rowCount;

    public int $timestamp;
}
```

Snapshots must be **immutable**.

---

# Replay Storage

Replay snapshots may be written to multiple backends.

Initial implementation supports:

### File Backend

Directory of newline-delimited JSON snapshots.

Example file:

```text
storage/replay/queries-2026-03-10.ndjson
```

Entry example:

```json
{
  "timestamp": 1710094101,
  "connection": "default",
  "dialect": "postgres",
  "operation": "select",
  "sql": "SELECT id, name FROM employees WHERE id = :p1",
  "params": {"p1": 42},
  "tables": ["employees"],
  "duration": 2.3,
  "rowCount": 1
}
```

---

# Replay Runner

Provide a simple replay executor:

```php
UDA\Replay\QueryReplayer
```

Example usage:

```php
$replayer = new QueryReplayer($db);

$replayer->runSnapshot($snapshot);
```

or:

```php
$replayer->runFile('queries-2026-03-10.ndjson');
```

Replay runner:

* replays SQL
* captures results
* compares metadata if requested

---

# Optional Replay Modes

### 1. Execute Mode

Runs the query normally.

Used for debugging.

---

### 2. Explain Mode

Runs replay using:

```
EXPLAIN
```

This integrates with **WO028**.

Example:

```php
$replayer->explain($snapshot);
```

---

### 3. Timing Mode

Runs queries repeatedly to benchmark performance.

Example:

```php
$replayer->benchmark($snapshot, 100);
```

---

# Configuration

Replay configuration is placed in `Config`.

Example:

```php
replay.enabled = true
replay.backend = file
replay.directory = storage/replay
```

Default:

```php
replay.enabled = false
```

---

# Snapshot Size Limits

To prevent runaway storage usage:

Config options:

```php
replay.maxParamSize = 8192
replay.maxSqlLength = 65536
```

If exceeded:

* snapshot truncated
* warning emitted via trace

---

# Security Considerations

Replay snapshots may contain:

* sensitive parameters
* authentication tokens
* PII

Replay capture must support parameter masking.

Example configuration:

```php
replay.maskParameters = [
    "password",
    "token",
    "secret"
]
```

Masked example:

```json
"params": {
  "password": "***"
}
```

---

# Tests

New test suite:

```
tests/Replay/
```

Tests required:

### Snapshot Creation

Verify snapshot fields match trace event.

---

### Replay Execution

Verify queries replay successfully.

---

### Parameter Preservation

Ensure parameters replay correctly.

---

### Masking

Verify masked parameters are removed.

---

# Documentation

Add documentation:

```
docs/replay.md
```

Sections:

* replay overview
* enabling replay
* snapshot format
* replay runner usage
* debugging workflows

Cookbook addition:

```
# Debugging Queries with Replay
```

---

# Non-Goals

Replay does **not** implement:

* full database recording
* query result recording
* transaction capture
* replication systems

Replay captures **queries only**, not data state.

---

# Success Criteria

WO030 is complete when:

* ReplayCaptureListener implemented
* snapshot format defined
* replay runner functional
* replay capture configurable
* snapshot storage implemented
* replay tests pass
* documentation written

---

# Example Developer Workflow

Capture production queries:

```php
replay.enabled = true
```

Run application.

Snapshots stored automatically.

Replay locally:

```php
$replayer->runFile('queries-2026-03-10.ndjson');
```

Debug problematic queries.

---

# Expected Impact

Benefits:

* reproducible bug reports
* easier debugging
* better performance analysis
* query builder validation

Cost:

* none when disabled
* minimal when enabled
