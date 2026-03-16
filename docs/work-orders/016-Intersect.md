# Work Order 016 — INTERSECT / EXCEPT Compound Queries

## Execution Context

Target coding agent: **OpenAI GPT-5.1-codex** operating in an Opencode workflow.

This work order adds support for SQL compound query operators:

- INTERSECT
- INTERSECT ALL
- EXCEPT
- EXCEPT ALL

These operators must integrate with the **existing compound query system introduced in Work Order 012 (UNION)**.

The goal is to complete SQL set operator support without introducing new abstractions or changing the execution pipeline.

Compound queries must remain:

- immutable
- dialect-compiled
- deterministic
- composable with subqueries, CTEs, and expressions

---

# Authority

Documentation precedence:

1. constitution.md + style-guide.md  
2. contract.md  
3. spec.md  
4. design.md  

If code conflicts with docs, code is wrong unless documentation is clearly outdated.

The **Query Cookbook remains the canonical usage reference**.

---

# Goal

Allow builders to compose compound queries using INTERSECT and EXCEPT.

Target usage:

```php
$q1 = $db->select('id')
    ->from('employees')
    ->where('active',1);

$q2 = $db->select('id')
    ->from('contractors');

$rows = $q1
    ->intersect($q2)
    ->rows();
````

Expected SQL:

```sql
SELECT id
FROM employees
WHERE active = :p1
INTERSECT
SELECT id
FROM contractors
```

---

Example with EXCEPT:

```php
$all = $db->select('id')
    ->from('employees');

$terminated = $db->select('employee_id')
    ->from('terminations');

$rows = $all
    ->except($terminated)
    ->rows();
```

Expected SQL:

```sql
SELECT id
FROM employees
EXCEPT
SELECT employee_id
FROM terminations
```

---

# Scope (Allowed Changes)

Modify only:

```
src/UDA/Query/*
src/UDA/Query/Dialect/*
tests/Query/*
docs/query-cookbook.md
docs/spec.md
docs/architecture.md
```

Do not modify:

```
Database execution pipeline
Driver communication layer
cache subsystem
config subsystem
```

Compound queries must remain **builder-level constructs compiled by dialects**.

---

# Architectural Intent

Compound queries form a **chain of query segments**.

Conceptually:

```
query
  root select
  compound operations [
    { operator, query }
  ]
```

Compilation order:

```
compile base query
append compound operations
merge parameters
merge table attribution
produce Sql value object
```

Example structure:

```
SELECT ...
UNION
SELECT ...
INTERSECT
SELECT ...
EXCEPT
SELECT ...
```

This work order must reuse the existing compound query infrastructure introduced in **WO012**.

---

# Requirements

## 1. Builder API

Add the following fluent methods to applicable query builders:

```
intersect(Select $query)
intersectAll(Select $query)
except(Select $query)
exceptAll(Select $query)
```

These must return **new builder instances**.

Example:

```php
$q3 = $q1->intersect($q2);
```

Original queries must remain unchanged.

---

## 2. Supported Base Builders

Compound queries must work with:

```
Select
Select derived tables
Select with subqueries
Select with CTEs
```

Compound operations must only be allowed on **Select builders**.

Attempting to compound:

```
Insert
Update
Delete
Upsert
```

must fail early with a clear exception.

---

## 3. Compound Operator Storage

The builder must store compound operations internally.

Example structure:

```
[
  { type: UNION, query: q2 },
  { type: INTERSECT, query: q3 },
  { type: EXCEPT, query: q4 }
]
```

Order must remain deterministic and reflect chaining order.

---

## 4. Parameter Merging

Parameters from all compound segments must merge deterministically.

Example:

```
q1 params
q2 params
q3 params
```

Final result must:

* maintain stable param ordering
* avoid name collisions
* preserve deterministic compilation

---

## 5. Table Attribution

All referenced tables must propagate to the final `Sql` object.

Example:

```
SELECT FROM employees
INTERSECT
SELECT FROM contractors
```

Final table attribution must contain:

```
employees
contractors
```

---

## 6. Dialect Compilation

Dialect classes must render compound operators correctly.

Standard syntax:

```
INTERSECT
INTERSECT ALL
EXCEPT
EXCEPT ALL
```

Dialect differences must be handled internally.

Example concerns:

```
Oracle: supports INTERSECT and MINUS (EXCEPT equivalent)
SQL Server: supports INTERSECT and EXCEPT
PostgreSQL: full support
SQLite: support depends on version
MariaDB/MySQL: historically limited support
DB2: supports INTERSECT and EXCEPT
```

Dialect responsibilities:

```
translate EXCEPT → MINUS for Oracle
reject unsupported operators
compile compound chains correctly
```

Builders must not branch on backend names.

---

## 7. Subquery Composition

Compound queries must remain composable as subqueries.

Example:

```php
$union = $q1->union($q2);

$db->select()
   ->fromSub($union,'combined')
   ->rows();
```

The same must work with:

```
INTERSECT
EXCEPT
```

---

## 8. Interaction with CTEs

Compound queries must work inside CTE definitions.

Example:

```php
$combined = $q1->intersect($q2);

$db->select()
   ->with('shared',$combined)
   ->from('shared')
   ->rows();
```

Dialect compilation must preserve correct order:

```
WITH ...
SELECT ...
INTERSECT ...
```

---

## 9. Deterministic SQL

Identical builder state must produce identical:

```
SQL string
parameter map
table attribution
metadata
```

Operator ordering must remain exactly as defined.

---

# Tests Required

## Basic INTERSECT

Test:

```
select intersect select
```

Verify SQL generation.

---

## INTERSECT ALL

Test compilation and dialect support behavior.

---

## EXCEPT

Test standard EXCEPT operator.

---

## EXCEPT ALL

Test compilation and dialect support.

---

## Parameter Merging

Test multiple compound queries with parameters.

Verify deterministic param ordering.

---

## Table Attribution

Ensure tables from all segments propagate correctly.

---

## Subquery Integration

Test compound query used in:

```
fromSub()
joinSub()
CTE
```

---

## Dialect Tests

Verify compilation behavior for:

```
PostgreSQL
SQL Server
Oracle (EXCEPT → MINUS)
SQLite
MariaDB (error if unsupported)
DB2
```

Tests must assert either:

```
correct SQL
explicit unsupported error
```

---

# Acceptance Criteria

All must be true:

```
intersect() implemented
intersectAll() implemented
except() implemented
exceptAll() implemented
builder immutability preserved
compound ordering preserved
parameter merging deterministic
table attribution correct
dialect translation correct
CTE integration works
subquery integration works
tests pass
cookbook examples compile
```

---

# Non-Goals

This work order must not introduce:

```
query planner features
query rewriting
duplicate elimination logic
AST frameworks
execution caching
builder semantic validation
```

These belong to database engines, not query builders.

---

# Evidence Required

Provide:

1. list of changed files
2. PHPUnit output for `tests/Query/*`
3. compiled SQL examples for:

   * intersect query
   * except query
   * intersect chain
   * except inside CTE
4. demonstration of deterministic param merging

---

# Cookbook Updates

Add sections:

## INTERSECT

```php
$q1 = $db->select('id')->from('employees');
$q2 = $db->select('id')->from('contractors');

$rows = $q1->intersect($q2)->rows();
```

---

## EXCEPT

```php
$active = $db->select('id')
    ->from('employees')
    ->where('active',1);

$terminated = $db->select('employee_id')
    ->from('terminations');

$rows = $active
    ->except($terminated)
    ->rows();
```

---

## Compound Chains

```php
$q1->union($q2)
   ->intersect($q3)
   ->except($q4)
   ->rows();
```

---

# Philosophy

Compound queries are fundamental relational algebra operations.

Supporting:

```
UNION
INTERSECT
EXCEPT
```

completes the SQL set-operator family.

The builder must allow developers to express these operations fluently while preserving:

```
immutability
determinism
dialect abstraction
execution transparency
```

without introducing abstraction overhead or hiding SQL semantics.
