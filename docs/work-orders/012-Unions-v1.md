# Work Order 012 — Compound Selects (UNION / UNION ALL)

## Execution Context

Target coding agent: **OpenAI GPT-5.1-codex** operating in an Opencode workflow.

This work order introduces **compound select queries** to UDA by supporting:

- `UNION`
- `UNION ALL`

Compound selects allow multiple SELECT queries to be combined into a single result set while preserving the deterministic SQL and fluent API guarantees of UDA.

This feature must integrate cleanly with the existing architecture:

```

Builder
→ Dialect compiler
→ Sql value object
→ Database
→ Driver
→ PDO

````

Compound selects must **not alter the execution model** and must remain purely structural query composition.

---

# Authority

Documentation precedence:

1. constitution.md + style-guide.md  
2. contract.md  
3. spec.md  
4. design.md  

If code conflicts with documentation, **code is wrong** unless the documentation is clearly outdated.

The **Query Cookbook remains the developer contract**.

---

# Goal

Allow developers to combine multiple SELECT queries using fluent methods:

```php
$active = $db->select('id','name')
    ->from('employees')
    ->where('active',1);

$retired = $db->select('id','name')
    ->from('retirees');

$rows = $active
    ->unionAll($retired)
    ->orderBy('name')
    ->rows();
````

This must compile deterministically to:

```sql
SELECT id, name
FROM employees
WHERE active = :p1

UNION ALL

SELECT id, name
FROM retirees

ORDER BY name
```

Compound queries must preserve:

* deterministic parameter naming
* immutable builders
* dialect-neutral query grammar

---

# Scope (Allowed Changes)

Modify only:

```
src/UDA/Query/*
src/UDA/Query/Dialect/*
src/UDA/SQL/*
src/UDA/Database.php (only if normalization adjustments are required)
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
execution pipeline
```

Compound selects must integrate with the **existing builder system**, not introduce a new query type visible to developers.

---

# Architectural Intent

Compound queries combine **multiple Select builders** into a single logical result.

Conceptually:

```
base select
+ union branch
+ union branch
```

Example internal structure:

```
Select
  baseQuery
  unions [
     { type: union, query: Select }
     { type: unionAll, query: Select }
  ]
```

Compilation remains the responsibility of the **Dialect layer**.

---

# Requirements

## 1. Fluent API

Add methods to the `Select` builder:

```
union(Select $query)
unionAll(Select $query)
```

Example:

```php
$q1 = $db->select('id')->from('employees');

$q2 = $db->select('id')->from('contractors');

$q = $q1->unionAll($q2);
```

Builders must remain **immutable**.

---

## 2. Query Compatibility

Both sides of a UNION must represent **SELECT queries**.

Reject invalid usage:

```php
$q->union($db->insert(...));
```

Failure must occur at builder validation time.

---

## 3. Column Compatibility

UDA cannot fully validate SQL types, but the system should ensure:

* both sides are SELECT builders
* column lists are preserved

Developers are responsible for ensuring semantic compatibility.

---

## 4. Ordering Rules

`ORDER BY` must apply to the **final compound query**, not individual branches.

Example:

```php
$q1->unionAll($q2)
   ->orderBy('name');
```

Produces:

```sql
SELECT ...
UNION ALL
SELECT ...
ORDER BY name
```

Ordering inside union branches must remain untouched.

---

## 5. Pagination Rules

Pagination must apply to the **compound result**, not individual branches.

Example:

```php
$q->unionAll($q2)
  ->limit(50)
  ->offset(100);
```

Dialect must render pagination correctly for compound queries.

---

## 6. Parameter Merging

Union queries must merge parameters from all branches.

Example:

```
query A params
query B params
query C params
```

Result must produce a **single deterministic parameter sequence**.

Example output:

```
:p1
:p2
:p3
```

Parameter renaming must avoid collisions.

---

## 7. Table Attribution

All tables used by union branches must propagate to the final `Sql` object.

Example:

```
SELECT ... FROM employees
UNION
SELECT ... FROM contractors
```

Tables must be recorded as:

```
tables = ['employees','contractors']
```

This ensures proper cache invalidation.

---

## 8. Dialect Compilation

Dialect must render compound queries correctly.

Responsibilities:

* compile base SELECT
* compile union branches
* join with `UNION` or `UNION ALL`
* apply ordering/pagination at final level

Dialect must preserve:

* parentheses when required
* parameter binding
* deterministic output

---

## 9. Deterministic SQL

Given identical builder states, compilation must produce identical:

```
SQL string
parameter names
table attribution
```

Union order must remain stable.

---

# Tests Required

## Compound Query Tests

Create tests for:

### Basic UNION

```
select A
UNION
select B
```

### UNION ALL

```
select A
UNION ALL
select B
```

### Multiple unions

```
A
UNION
B
UNION ALL
C
```

### ORDER BY on compound query

```
(A UNION B) ORDER BY ...
```

### Pagination

```
(A UNION B) LIMIT ...
```

---

## Parameter Tests

Verify deterministic parameter merging across:

```
multiple union branches
nested subqueries within unions
```

---

## Table Attribution Tests

Ensure table tracking includes all union branches.

---

## Immutability Tests

Ensure union operations do not mutate the original query builder.

---

## Dialect Compilation Tests

Confirm compound queries compile correctly for:

```
PostgreSQL
SQLite
SQL Server
Sybase
Oracle
MariaDB
DB2
```

Focus on correct SQL syntax and clause placement.

---

# Cookbook Updates

Add new sections to `query-cookbook.md`:

### UNION

```php
$q1 = $db->select('id','name')
    ->from('employees');

$q2 = $db->select('id','name')
    ->from('contractors');

$rows = $q1
    ->union($q2)
    ->rows();
```

### UNION ALL

```php
$q1->unionAll($q2);
```

### ORDER BY with UNION

```php
$q1->unionAll($q2)
   ->orderBy('name');
```

### Pagination with UNION

```php
$q1->unionAll($q2)
   ->limit(50)
   ->offset(0);
```

Examples must compile correctly across dialects.

---

# Acceptance Criteria

All must be true:

* `union()` and `unionAll()` exist on Select builder
* compound queries compile deterministically
* parameter merging works across branches
* table attribution includes union tables
* builders remain immutable
* dialect renders correct SQL
* ordering and pagination apply to final result
* cookbook examples compile and execute
* tests verify deterministic output

---

# Non-Goals

Do **not implement**:

```
INTERSECT
EXCEPT
CTEs
window functions
query optimizer hints
ORM features
AST query trees
```

These belong in later work orders.

---

# Evidence Required

Provide:

1. list of modified files
2. PHPUnit output for query tests
3. compiled SQL examples for:

   * UNION
   * UNION ALL
   * UNION with ORDER BY
4. example of deterministic parameter merging

---

# Philosophy

Compound queries are a core relational capability.

Supporting UNION and UNION ALL allows developers to:

* combine heterogeneous datasets
* layer analytical queries
* express complex relational logic

UDA must support these operations while maintaining its core principles:

* fluent SQL grammar
* deterministic compilation
* dialect abstraction
* single execution surface
