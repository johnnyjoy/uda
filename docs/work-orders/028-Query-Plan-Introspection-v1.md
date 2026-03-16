# Work Order 028 — Query Plan Introspection (EXPLAIN)

## Authority

Documentation precedence:

1. constitution.md + style-guide.md
2. contract.md
3. spec.md
4. design.md

The Query Cookbook remains the canonical guide for developer-facing query grammar.

This work order introduces **query plan inspection**, not query behavior changes.

---

# Goal

Add support for retrieving database **query execution plans** using the builder system.

Developers must be able to request the execution plan for any query produced by UDA.

Example:

```php
$db->select()
   ->from('employees')
   ->where('id',$id)
   ->explain();
````

The result is the **database query plan**, not query rows.

---

# Design Principle

`explain()` is a **terminator**, not a modifier.

Terminators execute the query and return results:

```
rows()
row()
value()
exec()
each()
explain()
```

Explain returns **query plan data** instead of rows.

---

# Execution Model

Explain follows the standard UDA execution path:

```
Builder
 → SqlMessage
 → Database
 → Driver
 → dialect-specific EXPLAIN
 → return plan rows
```

The original query must **not execute normally**.

---

# Dialect Behavior

Different databases expose plans differently.

UDA must normalize this into **rows of plan text or structured plan rows**.

| Engine             | Explain Syntax                            |
| ------------------ | ----------------------------------------- |
| PostgreSQL         | `EXPLAIN`                                 |
| PostgreSQL analyze | `EXPLAIN ANALYZE`                         |
| SQLite             | `EXPLAIN QUERY PLAN`                      |
| SQL Server         | `SET SHOWPLAN_ALL ON`                     |
| Oracle             | `EXPLAIN PLAN FOR` + `DBMS_XPLAN.DISPLAY` |
| MariaDB            | `EXPLAIN`                                 |
| DB2                | `EXPLAIN PLAN`                            |

Dialect layer must implement the correct mechanism.

---

# Builder API

Basic explain:

```php
$plan = $db->select()
    ->from('employees')
    ->where('id',$id)
    ->explain();
```

Result:

```
array<plan rows>
```

---

# Explain Analyze

Optional execution analysis.

Example:

```php
$db->select()
   ->from('employees')
   ->where('id',$id)
   ->explainAnalyze();
```

Supported dialects:

```
PostgreSQL
SQLite
MariaDB
```

Other engines ignore analyze mode.

---

# Explain Output

Explain returns:

```
array<int,array<string,mixed>>
```

Example PostgreSQL output:

```
[
  ['QUERY PLAN' => 'Index Scan using employees_pkey ...']
]
```

SQLite:

```
[
  ['detail' => 'SEARCH TABLE employees USING INTEGER PRIMARY KEY']
]
```

UDA does **not normalize the internal plan format**.

It simply returns the rows provided by the engine.

---

# Database Layer

Add methods:

```
Database::explain(Sql|Builder)
Database::explainAnalyze(Sql|Builder)
```

Builder terminators call these internally.

---

# Driver Responsibilities

Drivers must implement:

```
Driver::explain(SqlMessage)
Driver::explainAnalyze(SqlMessage)
```

Each driver applies dialect-specific syntax.

Example PostgreSQL:

```
EXPLAIN SELECT ...
```

Example SQLite:

```
EXPLAIN QUERY PLAN SELECT ...
```

Example Oracle:

```
EXPLAIN PLAN FOR ...
SELECT * FROM TABLE(DBMS_XPLAN.DISPLAY)
```

---

# Trace Integration

Explain queries must still emit trace events.

Trace event fields:

```
trace.type = "explain"
trace.executionTime
trace.sql
```

Explain events must **not count as normal query execution metrics**.

---

# Tests Required

Create:

```
tests/Query/ExplainTest.php
```

---

## Test 1 — Basic Explain

```
select()->from()->explain()
```

Verify plan rows returned.

---

## Test 2 — Explain Analyze

Verify supported dialects return analysis output.

---

## Test 3 — Explain Does Not Execute Query

Use query with side effect.

Verify explain does not modify data.

---

## Test 4 — Trace Event

Verify trace event emitted with:

```
type = explain
```

---

## Test 5 — Raw SQL Explain

Ensure explain works with:

```
Sql::of(...)
```

---

# Documentation Updates

Update:

```
docs/query-cookbook.md
docs/spec.md
docs/architecture.md
```

Add section:

```
Query Plan Inspection
```

Example:

```
$db->select()
   ->from('employees')
   ->where('id',$id)
   ->explain();
```

---

# Acceptance Criteria

All must be satisfied:

```
explain() terminator implemented
explainAnalyze() implemented
dialect explain logic implemented
trace integration works
tests pass
documentation updated
```

---

# Evidence Required

Provide:

```
modified files
phpunit output
example explain output for at least two dialects
trace output showing explain event
```

---

# Non-Goals

This work order does not implement:

```
query plan visualization
cost analysis
optimizer tuning
automatic query optimization
```

These belong to future work orders.

---

# Philosophy

As systems grow, the difficulty shifts from writing queries to **understanding how the database executes them**.

Explain support allows developers to inspect query behavior without leaving the UDA environment, strengthening debugging, optimization, and production observability.
