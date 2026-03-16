# Work Order 025 — Prepared Statement Reuse

## Authority

Documentation precedence:

1. `constitution.md` + `style-guide.md`
2. `contract.md`
3. `spec.md`
4. `design.md`

The **Query Cookbook** remains the canonical developer interface.  
This work order **must not change the public query grammar**.

---

# Goal

Implement **prepared statement reuse** within the UDA execution pipeline.

This optimization allows identical compiled SQL statements to reuse a previously prepared database statement instead of preparing a new one for every execution.

The system must:

- reuse prepared statements safely
- preserve parameter binding semantics
- respect dialect and connection boundaries
- avoid cross-connection contamination
- maintain deterministic behavior

Prepared statement reuse builds on **WO024 Query Plan Cache**, reducing overhead at the **database driver layer**.

---

# Problem This Work Order Solves

Currently execution follows this path:

```

Builder
→ SQL compile (possibly cached by WO024)
→ SqlMessage
→ Database
→ Driver
→ PDO::prepare()
→ bind parameters
→ execute

```

Even if SQL compilation is cached, **PDO still prepares the same SQL repeatedly**.

Preparing statements repeatedly costs:

- CPU
- driver allocations
- network round trips (for some drivers)
- database server resources

High-throughput systems avoid this by **reusing prepared statements**.

---

# Design Overview

Introduce a **Prepared Statement Cache** within the driver layer.

Conceptual pipeline:

```

Builder
→ Query Plan Cache (WO024)
→ SqlMessage
→ Prepared Statement Cache
→ bind parameters
→ execute

```

Prepared statements are cached **per connection + SQL string**.

---

# Cache Scope

Prepared statements must be scoped to:

```

Connection instance
Dialect
SQL string

```

Prepared statements must **never cross connections**.

Example safe cache key:

```

oracle:connectionHash:SQL_HASH

```

Example:

```

oracle:92fa31:SELECT * FROM employees WHERE id = :p1

````

---

# Cache Storage

Introduce driver-level cache:

```php
class PreparedStatementCache
{
    private array $statements = [];
}
````

Structure:

```
[
  cacheKey => PDOStatement
]
```

This cache must exist **inside each Driver instance**.

---

# Cache Key

Cache key components:

```
dialect name
connection identifier
SQL string
```

Example key:

```
pgsql:0x91af:SELECT id FROM users WHERE id = :p1
```

The SQL string must be **exactly identical**.

---

# Execution Flow

Execution path changes from:

```
prepare
bind
execute
```

to:

```
lookup prepared statement
  ↓
cache miss → prepare
  ↓
bind parameters
  ↓
execute
```

Pseudo code:

```php
$stmt = $statementCache->get($sql);

if (!$stmt) {
    $stmt = $pdo->prepare($sql);
    $statementCache->store($sql, $stmt);
}

bindParameters($stmt);
$stmt->execute();
```

---

# Parameter Binding

Parameter binding must occur **every execution**, even when the statement is reused.

Example:

```
: p1 = 5
: p1 = 42
```

Reuse only the prepared statement object.

Never reuse bound parameter values.

---

# SqlMessage Integration

`SqlMessage` already contains:

```
sql string
parameter values
table metadata
returning metadata
```

Prepared statement reuse operates purely on:

```
sql string
```

Binding values must be pulled from `SqlMessage`.

---

# Driver Integration

Prepared statement reuse must occur in:

```
Driver::execute()
Driver::rows()
Driver::row()
Driver::value()
Driver::returning()
```

All execution paths must use the same statement cache.

---

# Cache Size Limit

Prepared statement cache must be bounded.

Default:

```
500 statements per connection
```

When exceeded:

```
evict oldest statement
```

Eviction policy:

```
FIFO
```

No LRU required for initial implementation.

---

# Statement Reset

PDO statements must be reset between executions.

After each execution:

```
$stmt->closeCursor();
```

This ensures the statement is safe for reuse.

---

# Driver Safety

Prepared statement reuse must work across all supported drivers:

```
PostgreSQL
SQLite
SQL Server
Sybase
Oracle
MariaDB
DB2
```

Special attention required for:

```
Oracle RETURNING
OUTPUT clauses
```

These must not corrupt reused statements.

---

# Returning Statement Handling

Statements using `RETURNING` must still be reusable.

Example:

```
INSERT ... RETURNING id
```

Reuse is allowed as long as:

```
parameter structure identical
returning columns identical
```

The SQL string already guarantees this.

---

# Transaction Safety

Prepared statement reuse must remain valid inside transactions.

Example:

```
BEGIN
execute cached statement
execute cached statement
COMMIT
```

Prepared statements must remain connection-bound.

---

# Tests Required

Add:

```
tests/Query/PreparedStatementReuseTest.php
```

---

## Test 1 — Basic Reuse

Execute identical query twice.

Verify:

```
prepare executed once
execute executed twice
```

---

## Test 2 — Parameter Variation

Execute:

```
id = 1
id = 2
id = 3
```

Ensure same prepared statement reused.

---

## Test 3 — Dialect Separation

Same SQL across two dialects.

Ensure:

```
separate statement caches
```

---

## Test 4 — Connection Separation

Two connections using identical SQL.

Ensure:

```
separate prepared statements
```

---

## Test 5 — Cache Eviction

Fill cache beyond capacity.

Verify oldest statement removed.

---

## Test 6 — Returning Query Reuse

Test:

```
INSERT ... RETURNING
```

Ensure:

```
statement reuse does not corrupt output buffers
```

Important for Oracle.

---

## Test 7 — Streaming Query Reuse

Test with:

```
each()
```

Ensure cursor closure allows reuse.

---

# Performance Benchmark

Add benchmark:

```
tests/bench/prepared_statement_benchmark.php
```

Scenario:

```
100000 identical queries
```

Compare:

```
without reuse
with reuse
```

Expected improvement:

```
10–30% reduction in driver overhead
```

---

# Documentation Updates

Update:

```
docs/architecture.md
docs/spec.md
```

Add section:

```
Prepared Statement Cache
```

Explain:

```
statement reuse
connection isolation
parameter binding
cache limits
```

---

# Acceptance Criteria

All must be satisfied:

```
prepared statement reuse implemented
driver-level cache working
connection isolation enforced
statement reset handled correctly
cache size limits enforced
tests pass
documentation updated
```

---

# Evidence Required

Provide:

```
modified files
phpunit output
benchmark results
example statement cache keys
```

---

# Non-Goals

This work order does not implement:

```
persistent statement caches
distributed caches
driver-level pooling
cross-process reuse
```

Those belong to future infrastructure work orders.

---

# Philosophy

Prepared statement reuse eliminates redundant database preparation overhead.

Combined with **WO024 Query Plan Cache**, this creates a two-layer performance optimization:

```
Query Plan Cache → avoids SQL compilation
Prepared Statement Cache → avoids driver preparation
```

Together they produce a significantly faster execution path for high-throughput applications while preserving UDA’s deterministic query behavior.
