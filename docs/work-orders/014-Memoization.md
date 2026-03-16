# Work Order 014 — Immutable Builder Compilation Memoization

## Execution Context

Target coding agent: **OpenAI GPT-5.1-codex** operating in an Opencode workflow.

This work order introduces **per-instance compilation memoization** for Query builders.

Because UDA builders are immutable and SQL compilation is deterministic, compiled SQL can be cached **inside the builder instance** after the first compilation.

This prevents repeated SQL compilation for the same builder state.

The optimization must:

- preserve deterministic SQL generation
- preserve builder immutability
- not introduce global caches
- not cache execution results
- not change the execution model

This optimization targets **high-throughput systems** where the same builder instance may be executed repeatedly.

---

# Authority

Documentation precedence:

1. constitution.md + style-guide.md  
2. contract.md  
3. spec.md  
4. design.md  

If code conflicts with documentation, code is wrong unless the documentation is clearly outdated.

---

# Goal

Ensure that once a builder has been compiled to a `Sql` value object, subsequent calls reuse the compiled result rather than recompiling.

Example:

```php
$q = $db->select('id','name')
    ->from('users')
    ->where('active',1)
    ->orderBy('name');

$q->rows();
$q->rows();
$q->values();
$q->row();
````

Currently this likely causes multiple dialect compilations.

After this work order:

```
compile once
reuse compiled Sql object
```

---

# Architectural Intent

Query compilation currently follows:

```
Builder
→ Dialect compile
→ Sql value object
→ Database execution
→ Driver
→ PDO
```

Compilation memoization modifies only the **Builder stage**:

```
Builder
→ memoized compiled Sql
→ Database
→ Driver
→ PDO
```

The `Sql` value object remains immutable.

No runtime state must leak into the memoized result.

---

# Scope (Allowed Changes)

Modify only:

```
src/UDA/Query/*
src/UDA/Query/Dialect/*
src/UDA/SQL/* (only if minor adjustments are required)
tests/Query/*
docs/architecture.md
docs/spec.md
```

Do **not modify**:

```
cache subsystem
config subsystem
driver execution logic
Database execution pipeline
```

Memoization must remain **local to builder instances**.

---

# Requirements

## 1. Memoization Field

Each builder must store a compiled result:

Example:

```php
private ?Sql $compiled = null;
```

This field must remain private.

---

## 2. Compilation Entry Point

Wherever builders currently produce SQL (typically `toSql()` or equivalent), memoization must occur.

Example pattern:

```php
public function toSql(): Sql
{
    if ($this->compiled !== null) {
        return $this->compiled;
    }

    $this->compiled = $this->dialect->compileSelect($this);

    return $this->compiled;
}
```

The compile step must execute only once per builder instance.

---

## 3. Immutable Safety

Memoization relies on builder immutability.

All builder mutation methods must continue to return **new builder instances**.

Example:

```
$q1 = $db->select()->from('users');

$q2 = $q1->where('id',1);
```

`$q1` and `$q2` must have separate memoization state.

Memoization must never leak across instances.

---

## 4. Subquery Integration

Subqueries introduced in **WO011** must remain safe.

Example:

```
parent builder
child builder (subquery)
```

Both may memoize independently.

Compilation order must remain deterministic.

---

## 5. UNION Integration

Compound queries introduced in **WO012** must also benefit from memoization.

Example:

```
base query
union branches
```

Compilation must memoize the final compound SQL result.

---

## 6. Expression Integration

Expression helpers introduced in **WO013** must remain compatible.

Expressions must not introduce mutable state that would invalidate memoized compilation.

---

## 7. Dialect Stability

Memoization must remain valid for the current dialect.

A builder instance must always compile against the dialect that created it.

If dialect identity is part of builder state, memoization is safe.

---

## 8. Sql Object Integrity

The memoized object must remain an immutable `Sql` value object.

Memoization must not alter:

* parameter ordering
* table attribution
* metadata hints

---

## 9. No Execution Caching

Memoization must **never cache execution results**.

Forbidden:

```
query result rows
PDO statements
driver state
transaction state
```

Only compiled SQL structure may be cached.

---

# Tests Required

## Compilation Memoization

Create tests verifying compilation happens only once.

Example test structure:

```
builder.toSql()
builder.toSql()
builder.toSql()
```

Dialect compile method should be invoked exactly once.

---

## Deterministic SQL

Ensure memoization does not alter SQL output.

```
first compile
memoized compile
```

Both must produce identical:

```
SQL string
parameter map
table list
```

---

## Builder Immutability

Verify memoization does not leak across builders.

```
$q1 = base query
$q2 = $q1->where(...)
```

Both must compile independently.

---

## Subquery Safety

Test memoization with nested subqueries.

```
parent query
child subquery
```

Ensure both memoize independently and produce correct SQL.

---

## UNION Safety

Test memoization for compound queries.

```
q1 union q2 union q3
```

Ensure deterministic compiled output.

---

# Acceptance Criteria

All must be true:

* builders memoize compiled SQL
* repeated compilation is avoided
* deterministic SQL output preserved
* builder immutability preserved
* subqueries unaffected
* compound queries unaffected
* expressions unaffected
* execution pipeline unchanged
* tests verify compile-once behavior

---

# Non-Goals

Do not introduce:

```
global SQL caches
cross-builder memoization
PDO statement caching
result caching
query plan caching
```

Those belong to other subsystems.

This work order concerns **only builder-level SQL compilation memoization**.

---

# Evidence Required

Provide:

1. list of modified files
2. PHPUnit results for `tests/Query/*`
3. demonstration test confirming compile-once behavior
4. example SQL output for:

   * standard select
   * subquery
   * union query
   * expression query

---

# Philosophy

SQL compilation is deterministic and builders are immutable.

This combination makes per-instance memoization both **safe and extremely efficient**.

The goal is simple:

```
compile once
reuse forever
```

for the lifetime of the builder instance.

This removes unnecessary CPU work in high-throughput environments while keeping the architecture clean.
