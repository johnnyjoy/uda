# Work Order 027 — Query Tracing & Observability

## Authority

Documentation precedence:

1. `constitution.md` + `style-guide.md`
2. `contract.md`
3. `spec.md`
4. `design.md`

The **Query Cookbook** defines the developer-facing grammar and must not change as part of this work order.

This work order introduces **runtime visibility**, not new query capabilities.

---

# Goal

Introduce **query tracing and observability** to UDA so that developers can inspect:

- executed SQL
- bound parameters
- execution time
- row counts
- dialect
- connection identity
- cache usage
- prepared statement reuse

The system must allow:

- lightweight tracing in development
- optional tracing in production
- minimal overhead when disabled

The tracing system must **not alter query behavior**.

---

# Problem This Work Order Solves

Currently UDA executes queries but exposes little runtime insight.

When debugging systems developers need to know:

```

what SQL executed
how long it took
what parameters were bound
which tables were touched
whether caching occurred

```

Without observability developers resort to:

```

driver logging
SQL server logging
manual debug prints

```

This work order introduces **first-class query tracing** within UDA.

---

# Design Overview

Introduce a **QueryTrace event** emitted for every query execution.

Conceptual flow:

```

Builder
→ SqlMessage
→ Database
→ Driver
→ Execute SQL
→ Capture execution metrics
→ Emit QueryTrace

```

Trace events may be:

```

logged
collected
streamed
observed

````

---

# QueryTrace Structure

Introduce an immutable trace object.

Example:

```php
class QueryTrace
{
    public string $sql;

    public array $parameters;

    public string $dialect;

    public string $connectionId;

    public float $executionTimeMs;

    public int $rowCount;

    public bool $planCacheHit;

    public bool $statementCacheHit;

    public array $tables;
}
````

---

# Trace Capture Points

Tracing must occur in **Database execution layer**.

Capture immediately before and after driver execution.

Example flow:

```
startTime = now()
execute SQL
endTime = now()

trace.executionTime = endTime - startTime
```

---

# Trace Events

Each query execution emits exactly one event.

Example:

```json
{
  "sql": "SELECT id FROM users WHERE id = :p1",
  "params": {"p1":42},
  "dialect": "postgresql",
  "connection": "default",
  "time_ms": 0.87,
  "rows": 1,
  "plan_cache": true,
  "statement_cache": true,
  "tables": ["users"]
}
```

---

# Trace Listener System

Introduce a simple listener interface.

```php
interface QueryTraceListener
{
    public function handle(QueryTrace $trace): void;
}
```

Developers may register listeners.

Example:

```php
Database::addTraceListener(new QueryLogger());
```

Multiple listeners must be supported.

---

# Built-in Trace Listeners

Provide two default implementations.

## 1. Logging Listener

Logs queries to a file or logger.

Example output:

```
[0.84ms] SELECT id FROM users WHERE id = :p1
params: {p1:42}
rows: 1
```

---

## 2. In-Memory Collector

Used for testing and profiling.

Example:

```php
$collector = new QueryTraceCollector();
Database::addTraceListener($collector);
```

Retrieve traces:

```php
$collector->getTraces();
```

---

# Trace Configuration

Tracing must be configurable.

Example config:

```
trace.enabled = true
trace.log_slow_queries = true
trace.slow_query_ms = 100
```

Tracing must default to:

```
disabled
```

to avoid overhead.

---

# Slow Query Detection

Add optional slow query detection.

Example:

```
slow query threshold: 100ms
```

When exceeded:

```
trace.slow = true
```

Slow queries may trigger special logging.

---

# Integration With Existing Systems

Trace events must include signals for:

```
WO024 Query Plan Cache
WO025 Prepared Statement Cache
```

Trace flags:

```
planCacheHit
statementCacheHit
```

This allows developers to confirm caching behavior.

---

# Streaming Query Support

Trace events must still fire for streaming queries:

```
each()
```

The trace should reflect:

```
rows returned (if known)
execution time
```

Row count may be:

```
unknown (-1)
```

if streaming prevents full counting.

---

# Table Metadata

Trace events should include table metadata when available.

This is already available from `SqlMessage`.

Example:

```
tables: ["employees", "departments"]
```

This assists with cache invalidation debugging.

---

# Security Considerations

Sensitive values must not leak unintentionally.

Optional configuration:

```
trace.redact_parameters = true
```

If enabled:

```
parameters replaced with ***
```

Example:

```
{p1:"***"}
```

---

# Performance Requirements

Tracing overhead when disabled must be negligible.

Requirement:

```
<1% overhead
```

Implementation suggestion:

```
if (!traceEnabled) return;
```

placed early in execution path.

---

# Tests Required

Add:

```
tests/Query/QueryTracingTest.php
```

---

## Test 1 — Trace Emitted

Execute query.

Verify trace emitted.

---

## Test 2 — Execution Time Captured

Verify time measurement exists.

---

## Test 3 — Parameters Captured

Verify parameter map included.

---

## Test 4 — Plan Cache Flag

Execute identical query twice.

Verify:

```
planCacheHit = true
```

second execution.

---

## Test 5 — Statement Cache Flag

Verify prepared statement reuse flagged.

---

## Test 6 — Listener Invocation

Register listener.

Verify handler invoked.

---

## Test 7 — Slow Query Detection

Simulate long query.

Verify:

```
slow = true
```

---

# Documentation Updates

Update:

```
docs/architecture.md
docs/spec.md
docs/security.md
```

Add section:

```
Query Tracing
```

Explain:

```
trace system
listener architecture
slow query detection
parameter redaction
```

---

# Acceptance Criteria

All must be satisfied:

```
trace events implemented
listener system working
execution time captured
parameters recorded
cache flags included
slow query detection optional
tests pass
documentation updated
```

---

# Evidence Required

Provide:

```
modified files
phpunit output
example trace output
slow query example
```

---

# Non-Goals

This work order does not implement:

```
distributed tracing
OpenTelemetry integration
query visualization
external monitoring agents
```

These may be future enhancements.

---

# Philosophy

As systems scale, the difficulty shifts from writing queries to **understanding how they behave in production**.

Observability ensures that developers can answer:

```
what ran
how long it took
why it behaved that way
```

By introducing structured query tracing, UDA becomes not just a query builder but a **transparent database interaction layer**.
