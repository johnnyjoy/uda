# Work Order 011 — Subqueries and Derived Tables

## Execution Context

Target coding agent: **OpenAI GPT-5.1-codex** operating in an Opencode workflow.

This work order expands the expressive power of the query system by introducing **first-class subquery and derived table support** while preserving the core architectural principles of UDA:

- deterministic SQL generation
- immutable builders
- backend-neutral fluent API
- dialect-owned SQL rendering
- Database as the sole execution surface

This work order **does not introduce a full SQL AST** and must remain lightweight.

---

# Authority

Documentation precedence:

1. constitution.md + style-guide.md  
2. contract.md  
3. spec.md  
4. design.md  

If code conflicts with documentation, **code is wrong** unless documentation is clearly outdated.

The **Query Cookbook remains the north star** for developer usage.

---

# Goal

Introduce **subqueries as first-class builder citizens** so UDA can express relational composition cleanly.

This enables:

- `FROM (subquery) alias`
- `JOIN (subquery) alias`
- `WHERE column IN (subquery)`
- `WHERE EXISTS (subquery)`
- derived tables for aggregate filtering
- nested builder composition

Developers must be able to pass a **Select builder anywhere SQL allows a subquery**.

Example target usage:

```php
$totals = $db->select(
        'employee_id',
        'SUM(amount) as total'
    )
    ->from('payroll')
    ->groupBy('employee_id');

$rows = $db->select(
        'e.id',
        'e.first_name',
        'p.total'
    )
    ->from('employees e')
    ->joinSub($totals, 'p', 'p.employee_id = e.id')
    ->where('p.total')->gt(100000)
    ->rows();
````

This must compile deterministically to:

```sql
SELECT e.id, e.first_name, p.total
FROM employees e
JOIN (
    SELECT employee_id, SUM(amount) as total
    FROM payroll
    GROUP BY employee_id
) p ON p.employee_id = e.id
WHERE p.total > :p1
```

---

# Scope (Allowed Changes)

Modify only:

```
src/UDA/Query/*
src/UDA/Query/Dialect/*
src/UDA/SQL/*
src/UDA/Database.php (only if needed for normalization)
tests/Query/*
docs/query-cookbook.md
docs/spec.md
docs/design.md
docs/architecture.md
```

Do **not modify**:

```
cache subsystem
config subsystem
driver connection logic
execution model
```

Subqueries must integrate into the **existing builder system**, not replace it.

---

# Architectural Intent

UDA already supports passing a `Sql` object to the database layer.

Subqueries extend this concept by allowing **builders to appear as structural SQL components inside other builders**.

Key concept:

```
Select builder
→ compiled by dialect
→ embedded into parent query
```

Subqueries remain **pure structure**, not execution surfaces.

Execution still flows only through:

```
builder terminator
→ Database
→ Driver
→ PDO
```

---

# Requirements

## 1. Subquery Embedding

A `Select` builder must be accepted as a **subquery input** in the following locations.

### FROM

```php
->fromSub($query, $alias)
```

Example:

```php
$rows = $db->select()
    ->fromSub($sub, 't')
    ->rows();
```

Produces:

```sql
FROM (subquery) t
```

Alias is required.

---

### JOIN

Add methods:

```
joinSub()
leftJoinSub()
rightJoinSub()
```

Example:

```php
->joinSub($sub, 'p', 'p.employee_id = e.id')
```

Produces:

```sql
JOIN (subquery) p ON p.employee_id = e.id
```

---

### WHERE IN (subquery)

Allow:

```php
->where('department_id')->in($subquery)
```

Produces:

```sql
WHERE department_id IN (subquery)
```

Builder must detect:

```
array → normal IN
Select → subquery IN
```

---

### EXISTS

Ensure clean support:

```php
->whereExists($subquery)
->whereNotExists($subquery)
```

Produces:

```sql
WHERE EXISTS (subquery)
```

---

## 2. Alias Enforcement

Derived tables must have explicit aliases.

The builder must reject:

```php
->fromSub($subquery)
```

Correct form:

```php
->fromSub($subquery, 'alias')
```

Failure must occur at builder compile time.

---

## 3. Builder Immutability

Subquery use must **not mutate the child builder**.

Example:

```php
$sub = $db->select()->from('payroll');

$q1 = $db->select()->fromSub($sub, 'p');
$q2 = $db->select()->whereExists($sub);
```

`$sub` must remain unchanged.

---

## 4. Parameter Propagation

Parameters from subqueries must be **merged into the parent query deterministically**.

Example:

```
parent :p1
subquery :p2
```

The parameter naming system must remain globally deterministic.

No collisions.

---

## 5. Table Attribution

Subqueries must correctly contribute to table metadata.

Example:

```
SELECT ...
FROM (SELECT ... FROM payroll) p
```

The final `Sql` object must include:

```
tables: ['employees','payroll']
```

This is required for cache invalidation and metadata tracking.

---

## 6. Dialect Handling

Dialect compilers must support embedding subqueries without rewriting the parent SQL.

Example responsibilities:

```
wrap subquery with parentheses
render alias
preserve parameter bindings
```

Dialect must **not alter clause ordering**.

---

## 7. Deterministic Compilation

Identical builder states must always produce identical SQL.

This applies to:

```
subquery structure
alias names
parameter ordering
table attribution
```

---

# Tests Required

## Subquery Tests

Create tests for:

### FROM derived table

```
select from (subquery)
```

### JOIN derived table

```
join (subquery)
```

### WHERE IN subquery

```
column IN (subquery)
```

### EXISTS

```
WHERE EXISTS (subquery)
```

### NOT EXISTS

```
WHERE NOT EXISTS (subquery)
```

---

## Parameter Determinism

Test nested parameters:

```
parent query params
subquery params
multiple subqueries
```

Ensure deterministic parameter naming.

---

## Table Attribution

Ensure tables from subqueries propagate correctly.

---

## Immutability

Ensure using a builder as a subquery does not mutate it.

---

## Dialect Tests

Confirm correct SQL compilation across:

```
PostgreSQL
SQLite
SQL Server
Sybase
Oracle
MariaDB
DB2
```

Focus on correct parentheses and alias rendering.

---

# Cookbook Updates

Add sections to `query-cookbook.md`:

### Derived Tables

```
FROM (subquery)
```

### Subquery JOIN

```
JOIN (subquery)
```

### WHERE IN (subquery)

### EXISTS

Provide examples using fluent builders.

---

# Acceptance Criteria

All must be true:

* builders support subqueries in FROM, JOIN, IN, and EXISTS
* subqueries require aliases when used as derived tables
* parameter merging remains deterministic
* table attribution includes subquery tables
* builders remain immutable
* execution path unchanged
* dialects compile subqueries correctly
* cookbook examples compile and execute
* tests confirm determinism

---

# Non-Goals

Do **not implement**:

```
CTE support
window functions
query planner logic
ORM mapping
SQL AST trees
```

These belong in future work orders.

---

# Evidence Required

Provide:

1. list of modified files
2. PHPUnit results for query tests
3. compiled SQL examples for:

   * JOIN subquery
   * FROM derived table
   * EXISTS subquery
4. example of nested subquery parameter propagation

---

# Philosophy

Relational composition is one of SQL’s greatest strengths.

Subqueries allow developers to:

* reuse queries
* build analytical layers
* express relational logic clearly

UDA must support these capabilities **without sacrificing its core design principles**:

* predictable grammar
* deterministic SQL
* dialect abstraction
* single execution surface

This work order introduces those capabilities in the simplest possible way.
