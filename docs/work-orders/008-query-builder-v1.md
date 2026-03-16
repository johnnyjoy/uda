# Work Order 008 — Query Builder System

## Authority

Documentation precedence:

1. constitution.md + style-guide.md
2. contract.md
3. spec.md
4. design.md

---

## Goal

Implement the query builder system so Query constructs SQL and Database executes it through the single runtime path.

The system must support an **intuitive fluent chain** while preserving the constitution:

```php
$rows = $db->select()
    ->from('users')
    ->where('id', 1)
    ->rows();
```

This is valid **only because terminators delegate back into `Database`**, which remains the execution authority.

Equivalent explicit form must also remain valid:

```php
$q = $db->select()
    ->from('users')
    ->where('id', 1);

$rows = $db->rows($q);
```

Builders do not execute SQL directly. They may expose terminator methods such as `row()`, `rows()`, `value()`, `values()`, `list()`, `each()`, and `exec()`, but those methods must **forward to `Database`**, which normalizes the builder to `Sql` and executes through the single runtime path.

Builders must support the extended feature set.

---

## Scope (Allowed Changes)

Only modify:

* `src/UDA/Query/*`
* `src/UDA/SQL/*` (if used for Sql value objects)
* `src/UDA/Database.php` (only if needed to accept Query/Sql objects cleanly and support builder terminator delegation)
* `tests/Query/*`
* `query-cookbook.md`
* `repositories.md`
* `spec.md` (only if wording alignment is required)

No cache or config files may be changed in this work order unless absolutely necessary for type compatibility.

---

## Requirements

### 1. Builder Execution Model

Builders are created from `Database`, carry a reference to their originating `Database`, and remain **non-executing query objects**.

Both of the following forms are valid:

#### Explicit execution

```php
$q = $db->select()->from('users')->where('id', 1);
$row = $db->row($q);
```

#### Fluent terminator delegation

```php
$row = $db->select()
    ->from('users')
    ->where('id', 1)
    ->row();
```

In the fluent form, the terminator must delegate internally to `Database`, not execute directly.

Required runtime shape:

```php
builder terminator
→ Database::row/rows/value/values/list/each/exec
→ Driver
→ PDO
```

Under no circumstances may builders talk to `Driver` or PDO directly.

---

### 2. Immutable Builders

Builders must be **immutable**.

Every builder method that changes query state must return a **new builder instance** rather than mutating the current one.

This is required so partial queries can be safely reused without `clone`:

```php
$base = $db->select()->from('employees');

$q1 = $base->where('department_id', 10);
$q2 = $base->where('department_id', 20);
```

Immutability must apply across Select, Insert, Update, Delete, and Upsert builders.

`Sql` value objects must also remain immutable.

---

### 3. Supported Builders

Implement:

* Select
* Insert
* Update
* Delete
* Upsert

---

### 4. Extended Feature Coverage

Support:

* where
* and
* or
* not
* in
* between
* exists
* joins
* distinct
* groupBy
* having
* orderBy
* limit
* offset
* upsert
* CTE support if already part of current design, otherwise do not invent it here

---

### 5. Deterministic Grammar

Builders must:

* fail early on invalid chaining
* not reorder clauses silently
* not infer intent

Grammar enforcement must be deterministic.

Examples:

* invalid transitions fail immediately
* the same builder state always produces the same SQL
* parameter naming must be stable for the same builder state

---

### 6. Sql Value Object

Builders must produce immutable `Sql` value objects containing:

* SQL string
* named params
* explicit table list
* optional metadata hints

Raw SQL strings and `Sql::of(...)` must continue to be accepted by `Database::row/rows/exec/...`.

`Database` must normalize builder input, `Sql`, and raw SQL into the single execution path cleanly.

---

### 7. Named Parameters Only

Builder output must always use named parameters.

No positional placeholders.

No mixed placeholder styles.

Stable parameter naming is required for deterministic output.

---

### 8. Table Attribution

Builders must supply explicit table lists for:

* cache invalidation
* table write tracking
* metadata decisions

Attribution rules must include:

* primary table
* joined tables
* nested builder tables for `exists(...)` or similar nested query cases

Raw SQL continues to require explicit table hints via `Sql::of(...)` when invalidation is desired.

---

### 9. Upsert

Implement extended UPSERT builder behavior.

Backend-specific compilation remains driver/dialect owned.

Support:

* conflict key required
* update-on-conflict
* doNothing

The builder must express neutral upsert intent without embedding backend-specific SQL policy into the query layer.

---

### 10. Fluent Terminators

The following terminators may exist on builders as forwarding conveniences:

* `row()`
* `rows()`
* `value()`
* `values()`
* `list()`
* `each()`
* `exec()`

These methods must:

* delegate to the originating `Database`
* preserve the single execution path
* not bypass normalization
* not talk to Driver or PDO directly

This is constitutional and required for ergonomic use.

---

## Tests Required

Create or update tests covering:

### Select grammar

* simple select
* boolean chains
* joins
* grouping
* having
* ordering
* pagination

### Write grammar

* insert
* update
* delete
* upsert

### Determinism

* invalid chains fail early
* named params only
* stable SQL output for the same builder state

### Immutability

* reusing a partial builder does not mutate the original
* diverging branches from the same base builder produce independent SQL

### Execution model

* builders produce `Sql`
* `Database::row/rows/exec` accept builder-produced `Sql`
* builder terminators delegate to `Database`
* builders do not execute directly
* Driver remains the only path to PDO

---

## Acceptance Criteria

All of the following must pass:

* query builder tests
* cookbook examples aligned to actual API
* fluent builder terminators work
* explicit `Database::row/rows/exec($builder)` form works
* builders are immutable
* no builder talks to Driver or PDO directly
* all builder output uses named params

---

## Evidence Required

Provide:

* PHPUnit output for `tests/Query/*`
* example `Sql` outputs for Select and Upsert
* examples from cookbook updated to the actual builder model
* one example showing explicit execution:

  * `$db->rows($q)`
* one example showing fluent terminator delegation:

  * `$db->select()->from('users')->rows()`

---

## Notes for Documentation Alignment

`query-cookbook.md`, `repositories.md`, and `spec.md` must be updated so they no longer claim or imply that builder terminators violate the architecture.

The correct rule is:

* builders do not execute SQL directly
* fluent terminators are allowed
* fluent terminators must delegate to `Database`
* `Database` remains the execution authority
* `Driver` remains the only PDO owner

