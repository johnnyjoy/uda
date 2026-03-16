# Work Order 018 — Writable CTE Support (WITH + INSERT / UPDATE / DELETE)

## Execution Context

Target coding agent: **OpenAI GPT-5.1-codex** operating in an Opencode workflow.

This work order extends the CTE system introduced in **WO015** so Common Table Expressions may be used with write statements where the backend supports them.

This work order must preserve:

- immutable builders
- deterministic SQL compilation
- dialect-owned SQL rendering
- Database as the sole execution surface
- Driver as the sole PDO owner
- existing builder → dialect → Sql → Database → Driver → PDO pipeline

Writable CTEs must be implemented as **structured builder state**, not as raw SQL prefixes.

---

# Authority

Documentation precedence:

1. constitution.md + style-guide.md
2. contract.md
3. spec.md
4. design.md

If code conflicts with docs, code is wrong unless docs are clearly outdated.

The Query Cookbook remains the north star.

---

# Goal

Extend `with(...)` and `withRecursive(...)` support to applicable write builders so developers can express real writable CTE workflows without escaping the system.

Target usage examples:

## INSERT with CTE

```php
$recent = $db->select('id', 'first_name', 'last_name')
    ->from('staging_employees')
    ->where('import_batch_id', $batchId);

$count = $db->insert()
    ->with('recent', $recent)
    ->into('employees')
    ->columns('id', 'first_name', 'last_name')
    ->select(
        $db->select('id', 'first_name', 'last_name')
            ->from('recent')
    )
    ->exec();
````

Expected SQL shape:

```sql
WITH recent AS (
    SELECT id, first_name, last_name
    FROM staging_employees
    WHERE import_batch_id = :p1
)
INSERT INTO employees (id, first_name, last_name)
SELECT id, first_name, last_name
FROM recent
```

## UPDATE with CTE

```php
$raises = $db->select('employee_id', 'new_salary')
    ->from('salary_adjustments')
    ->where('batch_id', $batchId);

$count = $db->update()
    ->with('raises', $raises)
    ->table('employees e')
    ->set('salary', $db->raw('r.new_salary'))
    ->from('raises r')
    ->whereRaw('r.employee_id = e.id')
    ->exec();
```

Expected SQL shape where supported:

```sql
WITH raises AS (
    SELECT employee_id, new_salary
    FROM salary_adjustments
    WHERE batch_id = :p1
)
UPDATE employees e
SET salary = r.new_salary
FROM raises r
WHERE r.employee_id = e.id
```

## DELETE with CTE

```php
$expired = $db->select('id')
    ->from('sessions')
    ->where('expires_at < :now', ['now' => $now]);

$count = $db->delete()
    ->with('expired', $expired)
    ->table('sessions')
    ->where('id')->in(
        $db->select('id')->from('expired')
    )
    ->exec();
```

Expected SQL shape:

```sql
WITH expired AS (
    SELECT id
    FROM sessions
    WHERE expires_at < :p1
)
DELETE FROM sessions
WHERE id IN (
    SELECT id
    FROM expired
)
```

---

# Scope (Allowed Changes)

Modify only:

* `src/UDA/Query/*`
* `src/UDA/Query/Dialect/*`
* `src/UDA/SQL/*`
* `src/UDA/Database.php` (only if normalization support is needed)
* `tests/Query/*`
* `docs/query-cookbook.md`
* `docs/spec.md`
* `docs/design.md`
* `docs/architecture.md`

Do not modify:

* cache subsystem
* config subsystem
* driver connection logic
* execution pipeline

Writable CTEs must integrate into the existing builders and dialect layer.

---

# Architectural Intent

CTEs remain **query-root structural inputs**.

They are not string prefixes and must not be compiled through ad hoc preamble injection.

Conceptually:

```text
write builder
  ctes [
    { name, query, recursive? }
  ]
  main write statement
```

Compilation order:

```text
builder state
→ dialect compiles WITH / WITH RECURSIVE block
→ dialect compiles write statement
→ Sql value object
```

Execution remains unchanged:

```text
builder terminator
→ Database
→ Driver
→ PDO
```

---

# Requirements

## 1. Supported Builders

Add `with(...)` and `withRecursive(...)` support to:

* `Insert`
* `Update`
* `Delete`

`Upsert` may be supported **only if it falls out cleanly from the existing architecture and dialect rules**. It is not required in this work order.

If a builder cannot legally or cleanly support writable CTEs on a given backend, it must fail explicitly.

No fake support.

---

## 2. Fluent API

Required methods on applicable write builders:

```php
with(string $name, Select $query)
withRecursive(string $name, Select $query)
```

Repeated chaining must be supported:

```php
$db->update()
   ->with('a', $q1)
   ->with('b', $q2)
   ->table('...')
   ->exec();
```

Builders must remain immutable.

---

## 3. Write-Builder Compatibility Rules

Writable CTE support must respect each builder’s real grammar.

### Insert

Writable CTEs must support at minimum:

* `INSERT ... SELECT` patterns using CTE-defined sources

If the builder currently only supports `VALUES(...)` and not `INSERT ... SELECT`, this work order may add the minimum builder support needed to target CTE-fed inserts, but must not destabilize existing insert behavior.

Minimum acceptable fluent shape if needed:

```php
->columns(...)
->select(Select $query)
```

This must remain deterministic and dialect-compiled.

### Update

Writable CTEs must support only backend-legal update shapes.

Where an engine supports `UPDATE ... FROM`, dialect may render that form.

Where the engine uses another legal form, dialect must render that.

If no backend-legal form exists for the current builder state, fail explicitly.

### Delete

Writable CTEs must support backend-legal delete forms.

Subquery-driven delete using a CTE-fed subquery is acceptable and often preferable.

No backend-specific hacks in builder code.

---

## 4. CTE Naming Rules

CTE names must be validated according to UDA identifier rules.

Examples:

Valid:

```text
recent
expired_sessions
cte1
```

Invalid names must fail early.

This work order must not weaken identifier validation casually.

If writable CTE support exposes identifier-regex limitations, document them clearly and fail explicitly rather than silently broadening validation.

---

## 5. Recursive CTEs on Write Builders

`withRecursive(...)` must be available on writable builders where the backend and statement form support it.

Examples that should work if legal for that backend:

* recursive source feeding `INSERT ... SELECT`
* recursive source feeding `DELETE ... WHERE IN (subquery)`

If a backend or statement form does not support recursive CTE + write statement combination, fail explicitly.

No silent degradation.

---

## 6. Parameter Merging

CTE params must merge with write-builder params deterministically.

Given:

* CTE A params
* CTE B params
* main statement params
* subquery params used by the write statement

the final SQL must produce:

* one deterministic param bag
* stable ordering
* no collisions

This requirement is absolute.

---

## 7. Table Attribution

Tables referenced inside writable CTEs must propagate to the final `Sql` object.

Write-target tables must also remain correctly attributed.

Example:

```sql
WITH recent AS (
    SELECT id
    FROM staging_employees
)
INSERT INTO employees ...
```

Final table attribution must include at minimum:

* `staging_employees`
* `employees`

If multiple CTEs or subqueries exist, all referenced tables must be merged.

---

## 8. Dialect Compilation

Dialect must own writable CTE rendering.

Responsibilities:

* compile `WITH`
* compile `WITH RECURSIVE` where supported
* render multiple CTE entries in stable order
* render each CTE query in parentheses
* compile the write statement after the CTE block
* enforce backend-specific writable CTE rules
* fail clearly where unsupported

Dialect must not:

* scatter writable CTE logic into unrelated builder code
* force builders to branch on backend names
* degrade into string-prefix hacks

---

## 9. Backend Support Rules

This work order must recognize that writable CTE support is backend-dependent.

At minimum, tests and documentation must address:

* PostgreSQL
* SQLite
* SQL Server
* Oracle
* MariaDB
* DB2
* Sybase

For each backend / statement combination, the result must be one of:

* supported and compiled correctly
* explicitly unsupported with a clear exception

Do not guess.

Do not silently emit illegal SQL.

---

## 10. Interaction with Existing Features

Writable CTEs must compose correctly with:

* subqueries
* derived tables
* `UNION` / `UNION ALL`
* `INTERSECT` / `EXCEPT` if already present
* `Expr`
* returning/output support where already supported

Examples that must remain legal where backend supports them:

* `WITH cte AS (...) INSERT ... RETURNING ...`
* `WITH cte AS (...) DELETE ... RETURNING ...`
* `WITH cte AS (...) INSERT ... SELECT ... FROM cte`

This work order must not break existing returning/output behavior.

---

## 11. Deterministic SQL

Given the same builder state, compilation must produce the same:

* SQL string
* parameter names
* parameter order
* table list
* metadata hints

Multiple chained `with(...)` calls must preserve insertion order.

No hidden reordering.

---

## 12. Failure Behavior

Unsupported combinations must fail early and clearly.

Examples:

* backend does not support writable CTEs for that statement type
* recursive writable CTE unsupported on that backend
* builder shape cannot be legally compiled for that backend
* attempted `INSERT ... SELECT` shape unsupported by current builder grammar

No silent fallback.

No hidden extra queries.

No fake emulation.

---

# Tests Required

## Insert + CTE Tests

Create tests covering:

### Basic INSERT ... SELECT with CTE

```php
->with('recent', $cte)
->into('employees')
->columns(...)
->select($query)
```

### Multiple CTEs feeding INSERT

### Recursive CTE feeding INSERT ... SELECT where backend supports it

---

## Update + CTE Tests

Create tests covering backend-legal update forms with CTE source.

Where unsupported, tests must assert explicit failure.

---

## Delete + CTE Tests

Create tests covering:

* delete using CTE-fed subquery
* recursive delete source where supported
* explicit failure where unsupported

---

## Parameter Tests

Verify deterministic param merging across:

* one writable CTE + write statement
* multiple writable CTEs + write statement
* writable CTE + subquery in write statement
* writable CTE built from union query

No collisions.

---

## Table Attribution Tests

Ensure final `Sql` carries:

* CTE body tables
* write-target table
* subquery tables where applicable

Examples:

* insert + source CTE
* update + source CTE
* delete + CTE-fed subquery

---

## Returning / Output Integration Tests

Where backend supports both writable CTE and returning/output, verify integration.

Examples:

* PostgreSQL `WITH ... INSERT ... RETURNING`
* SQL Server `WITH ... INSERT ... OUTPUT`
* Oracle writable CTE + supported returning strategy if legal
* explicit failure where unsupported

---

## Dialect Tests

At minimum verify compilation behavior for:

* PostgreSQL writable CTEs
* SQLite writable CTEs where supported
* SQL Server writable CTEs
* Oracle writable CTEs
* MariaDB writable CTE support or explicit failure
* DB2 writable CTE support or explicit failure
* Sybase writable CTE support or explicit failure

Tests must assert either:

* correct SQL output
* explicit unsupported-feature failure

---

## Immutability Tests

Verify writable builders remain immutable:

```php
$base = $db->insert()->into('employees');
$q1 = $base->with('a', $cte1);
$q2 = $base->with('b', $cte2);
```

Ensure `$base` is unchanged.

---

# Acceptance Criteria

All must be true:

* `with()` exists and works on Insert, Update, and Delete where supported
* `withRecursive()` exists and works where supported
* builders remain immutable
* writable CTE compilation is dialect-owned
* params merge deterministically
* table attribution includes CTE body tables and target tables
* composition with subqueries / unions / Expr works
* returning/output integration is preserved where supported
* unsupported backend/stage combinations fail clearly
* cookbook examples compile and execute or are documented as unsupported where appropriate

---

# Non-Goals

Do not implement in this work order:

* arbitrary SQL preambles
* writable CTE materialization hints
* search / cycle clauses
* optimizer hints
* dependency analysis between CTEs
* AST frameworks
* hidden multi-query emulation
* automatic identifier-regex redesign

Those belong in later work orders if needed.

---

# Evidence Required

Provide:

1. Changed files
2. PHPUnit output for `tests/Query/*`
3. Example compiled SQL for:

   * basic `WITH ... INSERT ... SELECT`
   * writable CTE `UPDATE`
   * writable CTE `DELETE`
   * writable CTE + returning/output where supported
4. One example showing deterministic param merging across writable CTE + main statement
5. A short backend support matrix for writable CTEs by statement type

---

# Cookbook Updates

Add sections for:

## INSERT ... SELECT from CTE

```php
$recent = $db->select('id', 'first_name', 'last_name')
    ->from('staging_employees')
    ->where('import_batch_id', $batchId);

$count = $db->insert()
    ->with('recent', $recent)
    ->into('employees')
    ->columns('id', 'first_name', 'last_name')
    ->select(
        $db->select('id', 'first_name', 'last_name')
            ->from('recent')
    )
    ->exec();
```

## UPDATE using CTE source

Provide a backend-neutral example that matches supported builder grammar and note dialect differences where needed.

## DELETE using CTE-fed subquery

```php
$expired = $db->select('id')
    ->from('sessions')
    ->whereRaw('expires_at < :now', ['now' => $now]);

$count = $db->delete()
    ->with('expired', $expired)
    ->table('sessions')
    ->where('id')->in(
        $db->select('id')->from('expired')
    )
    ->exec();
```

Documentation must clearly note backend support differences where they exist.

---

# Philosophy

Writable CTEs are real SQL, not exotic SQL.

They improve:

* decomposition
* staging
* readability
* bulk-write workflows
* recursive write-source workflows

UDA must support them in the same disciplined way it supports readable SELECT-side CTEs:

* fluent
* deterministic
* dialect-aware
* execution-neutral

Writable CTEs are structured query state, not string hacks.
