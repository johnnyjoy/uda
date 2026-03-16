# Work Order 026 — Advanced CTE Materialization Hints

## Authority

Documentation precedence:

1. `constitution.md` + `style-guide.md`
2. `contract.md`
3. `spec.md`
4. `design.md`

The **Query Cookbook** remains the canonical developer interface.

This work order **must not change existing query grammar**.  
All additions must be **additive and optional**.

---

# Goal

Introduce support for **CTE materialization hints** so developers can control whether a Common Table Expression is:

- materialized (executed once and stored)
- inlined (expanded into the outer query)

These hints allow developers to influence query planner behavior in databases that support them.

The implementation must:

- support databases that expose materialization hints
- gracefully ignore hints on engines that do not
- preserve deterministic SQL generation
- integrate with existing `WITH` / `WITH RECURSIVE` builder APIs

---

# Why This Matters

Modern SQL engines optimize CTEs differently.

Two common strategies exist:

```

materialized

```

The CTE is executed once and stored in temporary storage.

```

inlined

````

The CTE is expanded into the parent query.

Each strategy has performance implications.

Example:

```sql
WITH expensive_data AS (
    SELECT ...
)
SELECT ...
FROM expensive_data
JOIN expensive_data ...
``` id="3fxk4y"

Materialization avoids recomputing the expensive subquery.

Inlining may allow better join optimization.

Developers sometimes need explicit control.

---

# Dialect Support

Materialization hints are supported differently across engines.

| Engine | Support |
|------|------|
| PostgreSQL | `MATERIALIZED` / `NOT MATERIALIZED` |
| SQLite ≥3.35 | `MATERIALIZED` / `NOT MATERIALIZED` |
| Oracle | optimizer hints (no direct syntax) |
| SQL Server | none |
| MariaDB | none |
| DB2 | none |
| Sybase | none |

UDA must support:

````

PostgreSQL
SQLite

````

Other engines should **ignore the hint silently**.

---

# Example SQL

PostgreSQL:

```sql
WITH expensive_data AS MATERIALIZED (
    SELECT ...
)
SELECT ...
``` id="8v17w5"

Or:

```sql
WITH expensive_data AS NOT MATERIALIZED (
    SELECT ...
)
SELECT ...
``` id="q4mfqj"

---

# API Design

Extend the CTE builder with optional hints.

Example:

```php
$db->select()
    ->with('expensive_data', function ($q) {
        $q->select('*')
          ->from('large_table');
    })
    ->materialized()
    ->from('expensive_data');
``` id="0j7tn0"

Produces:

```sql
WITH expensive_data AS MATERIALIZED (
    SELECT * FROM large_table
)
SELECT ...
``` id="t7m60a"

---

# NOT MATERIALIZED

Example:

```php
$db->select()
    ->with('temp_data', function ($q) {
        $q->select('*')
          ->from('transactions');
    })
    ->notMaterialized()
    ->from('temp_data');
``` id="ttqhzr"

Produces:

```sql
WITH temp_data AS NOT MATERIALIZED (
    SELECT * FROM transactions
)
SELECT ...
``` id="lt0hly"

---

# Multiple CTEs

Hints must apply to the specific CTE.

Example:

```php
$db->select()
    ->with('a', $queryA)->materialized()
    ->with('b', $queryB)->notMaterialized()
    ->from('a')
    ->join('b', '...');
``` id="a19qru"

Produces:

```sql
WITH
a AS MATERIALIZED (...),
b AS NOT MATERIALIZED (...)
SELECT ...
``` id="n8x8nx"

---

# Recursive CTE Support

Materialization hints must work with recursive CTEs.

Example:

```php
$db->select()
    ->withRecursive('tree', $recursiveQuery)
    ->materialized();
``` id="z6ng1a"

Dialect must decide whether syntax is supported.

---

# Builder Changes

Add hint metadata to the internal CTE representation.

Example structure:

````

CTE {
name
query
recursive
materializationHint
}

```id="shv7y2"

Possible values:

```

null
materialized
not_materialized

```id="gqnyuh"

---

# Dialect Compilation

During SQL compilation:

```

WITH name AS MATERIALIZED (...)
WITH name AS NOT MATERIALIZED (...)

```id="ze3y9q"

Only emitted if the dialect supports hints.

Example dialect behavior:

```

PostgreSQL → emit hint
SQLite → emit hint
Others → ignore

```id="x1fgyh"

---

# Capability Detection

Extend dialect capability flags:

```

supportsCteMaterializationHints()

```id="4u2xrw"

Implement for:

```

PostgreSQL → true
SQLite → true
Others → false

```id="9q8qf7"

---

# Error Handling

Hints must **never break compilation**.

Behavior:

```

supported dialect → emit hint
unsupported dialect → ignore hint

````id="st1fsg"

No exceptions required.

The hint is considered **advisory**.

---

# SQL Generation Examples

## Materialized

```sql
WITH a AS MATERIALIZED (
    SELECT * FROM employees
)
SELECT * FROM a
``` id="1zhxuq"

---

## Not Materialized

```sql
WITH a AS NOT MATERIALIZED (
    SELECT * FROM employees
)
SELECT * FROM a
``` id="bnk2bm"

---

# Tests Required

Create:

````

tests/Query/CteMaterializationTest.php

```id="eqfrkk"

---

## Test 1 — Materialized CTE

Verify compilation:

```

WITH a AS MATERIALIZED

```id="qrcp7s"

---

## Test 2 — Not Materialized

Verify:

```

WITH a AS NOT MATERIALIZED

```id="ubys6r"

---

## Test 3 — Multiple CTEs

Ensure hints apply per CTE.

---

## Test 4 — Unsupported Dialect

Run with MariaDB dialect.

Ensure SQL compiles **without hints**.

---

## Test 5 — Recursive CTE

Verify hint does not break recursive syntax.

---

# Documentation Updates

Update:

```

docs/query-cookbook.md
docs/spec.md

```id="hm5ygf"

Add section:

```

CTE Materialization

```

Include examples and dialect behavior.

---

# Acceptance Criteria

All conditions must be met:

```

materialization hints implemented
hints attach to specific CTEs
supported dialects emit hints
unsupported dialects ignore hints
recursive CTEs unaffected
tests pass
documentation updated

```id="2tyrq1"

---

# Evidence Required

Provide:

```

modified files
phpunit output
compiled SQL examples
documentation updates

```id="2smygk"

---

# Non-Goals

This work order does not implement:

```

optimizer hints
cost-based query tuning
dialect-specific hint systems
query planner manipulation

```id="q1z0ru"

Those belong to future work orders.

---

# Philosophy

CTEs are powerful but can introduce performance variability depending on how the database planner treats them.

Materialization hints allow developers to guide the optimizer in situations where:

```

CTE reuse is beneficial
CTE inlining causes redundant computation

```id="0kuzgv"

By exposing these hints in a structured and portable way, UDA allows developers to fine-tune query execution while maintaining the clarity of the query builder.
