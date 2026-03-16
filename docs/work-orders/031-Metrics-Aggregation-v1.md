# Work Order 031 — Metrics Aggregation

**Status:** Planned
**Priority:** Medium
**Category:** Observability / Analytics (Non-Core)
**Depends On:**

* WO027 — Query Tracing / Observability
* WO030 — Query Replay / Debug Snapshots

---

# Objective

Introduce a **lightweight metrics aggregation system** that summarizes database activity across the application.

The system collects and aggregates metrics such as:

* query counts
* total execution time
* average latency
* error counts
* slow query detection
* table activity

These metrics allow developers and observability systems to answer questions like:

* Which queries run the most?
* Which tables are hot?
* Which operations are slow?
* Which queries fail often?

Metrics must be derived **entirely from trace events** and must **never modify the execution path**.

---

# Design Principles

## 1. Trace-Derived Only

Metrics are derived from the **existing trace events produced by Database::traceOperation()**.

Metrics code must **never run in the query execution path directly**.

Execution path remains:

```
Builder → SqlMessage → Database → Driver → PDO
```

Metrics observe via:

```
traceOperation() → TraceListeners → MetricsAggregator
```

---

## 2. Zero Overhead When Disabled

If metrics aggregation is disabled:

* no counters are incremented
* no timers are stored
* no memory structures are allocated

Trace events are already emitted, so the incremental cost must remain negligible.

---

## 3. Aggregation, Not Logging

Metrics do **not store individual queries**.

Instead they aggregate statistics.

Example aggregated metric:

```
SELECT employees_by_id
count: 12,004
avgLatency: 1.3ms
maxLatency: 21ms
errors: 3
```

---

## 4. Deterministic Metric Keys

Metrics must group queries using a **deterministic key**.

The key is derived from:

```
connection
operation
normalized SQL
```

Normalization removes parameter values.

Example:

```
SELECT id,name FROM employees WHERE id = :p1
```

---

# Architecture

Metrics aggregation is implemented as a **trace listener**.

Flow:

```
Query Execution
       │
       ▼
traceOperation()
       │
       ▼
TraceListeners
       │
       ├── LoggingListener
       ├── ReplayCaptureListener
       └── MetricsAggregator
```

MetricsAggregator consumes trace events and updates counters.

---

# New Components

## MetricsAggregator

Namespace:

```
UDA\Metrics\MetricsAggregator
```

Responsibilities:

* consume trace events
* update aggregated statistics
* expose metrics to exporters

---

## QueryMetric

Represents aggregated metrics for a single query shape.

```
class QueryMetric
{
    public string $key;
    public string $sql;
    public string $operation;

    public int $count;
    public int $errorCount;

    public float $totalLatency;
    public float $maxLatency;
}
```

Derived metrics:

```
avgLatency = totalLatency / count
```

---

# Metric Dimensions

Metrics are grouped by:

```
connection
operation
normalized SQL
```

Optional dimension:

```
tables
```

---

# Metrics Collected

## Query Count

Total executions.

```
metric.query.count
```

---

## Total Latency

Sum of execution durations.

```
metric.query.latency.total
```

---

## Average Latency

Derived metric.

```
avg = totalLatency / count
```

---

## Maximum Latency

Largest observed latency.

```
metric.query.latency.max
```

---

## Error Count

Number of failures.

```
metric.query.errors
```

---

## Table Activity

Tracks which tables are most active.

Example:

```
employees: 10,000 operations
orders: 4,200 operations
```

---

# Slow Query Detection

Optional slow query threshold.

Config example:

```
metrics.slowQueryThresholdMs = 50
```

Queries exceeding threshold increment:

```
metric.query.slow
```

---

# In-Memory Storage

MetricsAggregator stores metrics in memory using a hash map.

Example:

```
metrics[key] => QueryMetric
```

Key example:

```
default|select|SELECT id,name FROM employees WHERE id = :p1
```

---

# Exporting Metrics

Metrics must be accessible to external systems.

Expose via:

```
MetricsAggregator::snapshot()
```

Example output:

```
[
  {
    "key": "...",
    "count": 1023,
    "avgLatency": 1.2,
    "maxLatency": 10,
    "errors": 2
  }
]
```

---

# Optional Export Formats

### JSON

```
metrics->exportJson()
```

---

### Prometheus

Example:

```
uda_query_count{operation="select"} 1023
uda_query_latency_avg{operation="select"} 1.2
```

---

# Configuration

Metrics configuration lives in `Config`.

Example:

```
metrics.enabled = true
metrics.slowQueryThresholdMs = 50
metrics.maxTrackedQueries = 10000
```

Default:

```
metrics.enabled = false
```

---

# Memory Limits

To avoid runaway memory growth:

```
metrics.maxTrackedQueries
```

If limit reached:

* oldest entries evicted
* LRU strategy used

---

# Integration with Replay

Replay snapshots (WO030) may include metrics metadata.

Example:

```
"avgLatency": 1.3
```

This helps compare replay vs production performance.

---

# Tests

New test suite:

```
tests/Metrics/
```

Required tests:

### Aggregation Test

Verify query counts increase.

---

### Latency Calculation

Verify avg and max latency computed correctly.

---

### Error Counting

Verify errors increment metrics.

---

### Slow Query Detection

Verify slow threshold triggers.

---

### Memory Limit

Verify LRU eviction works.

---

# Documentation

Add:

```
docs/metrics.md
```

Sections:

* metrics overview
* enabling metrics
* interpreting metrics
* exporting metrics

Cookbook addition:

```
# Observing Query Performance
```

---

# Non-Goals

Metrics aggregation intentionally does **not include**:

* distributed metrics collection
* automatic alerting
* full APM features
* SQL parsing
* index recommendations

The goal is **simple query activity summaries**, not a full monitoring platform.

---

# Success Criteria

WO031 is complete when:

* MetricsAggregator implemented
* trace listener integration working
* metrics snapshot export available
* slow query detection working
* memory limits enforced
* tests pass
* documentation written

---

# Example Developer Usage

Enable metrics:

```
metrics.enabled = true
```

Retrieve metrics:

```
$metrics = $db->metrics()->snapshot();
```

Example output:

```
[
  {
    "operation": "select",
    "sql": "SELECT id,name FROM employees WHERE id = :p1",
    "count": 1502,
    "avgLatency": 1.4,
    "maxLatency": 22,
    "errors": 0
  }
]
```

---

# Expected Benefits

* insight into database usage
* easy performance debugging
* supports AI-driven schema analysis
* complements replay and tracing

Cost when disabled: **zero**.
