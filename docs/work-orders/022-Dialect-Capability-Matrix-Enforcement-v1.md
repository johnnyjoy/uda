# Work Order 022 — Dialect Capability Matrix Enforcement

## Authority

Documentation precedence:

1. constitution.md + style-guide.md  
2. contract.md  
3. spec.md  
4. design.md  

The **Query Cookbook** is the north-star reference for developer behavior.

If code contradicts documented capability guarantees, code is wrong unless documentation is outdated.

---

# Goal

Implement a **Dialect Capability Matrix** that explicitly defines which SQL features are supported by each backend.

The system must:

- prevent builders from generating unsupported SQL
- fail **early and clearly**
- avoid silent dialect fallbacks
- maintain deterministic builder behavior
- preserve the UDA execution model

This ensures developers receive **immediate feedback** when using features that are unsupported by the current database engine.

---

# Problem This Work Order Solves

UDA now supports a wide SQL surface:

- RETURNING
- MERGE UPSERT
- UNION / INTERSECT / EXCEPT
- CTE
- recursive CTE
- subqueries
- pagination
- window functions (future)
- dialect-specific constructs

But not all databases support all features.

Example failures that must be caught early:

```php
$db->insert()
   ->into('employees')
   ->returning('id')
   ->exec();
````

Running this on **MariaDB** should fail immediately:

```
QueryException: Dialect 'MariaDB' does not support RETURNING
```

Not after generating SQL or hitting the server.

This work order formalizes **dialect capability awareness**.

---

# Scope

Allowed modifications:

```
src/UDA/Query/Dialect/*
src/UDA/Query/*
src/UDA/Database.php
tests/Query/*
docs/spec.md
docs/query-cookbook.md
```

Do not modify:

```
src/UDA/Cache/*
src/UDA/Config/*
```

unless strictly necessary for capability propagation.

---

# Dialect Capability System

Each dialect must declare capabilities.

Example interface:

```php
interface DialectCapabilities
{
    public function supportsReturning(): bool;

    public function supportsMerge(): bool;

    public function supportsRecursiveCte(): bool;

    public function supportsIntersect(): bool;

    public function supportsExcept(): bool;

    public function supportsWindowFunctions(): bool;

    public function supportsWritableCte(): bool;
}
```

Each dialect implementation must override only what differs.

---

# Required Capability Flags

Minimum feature set:

| Capability              | Description                            |
| ----------------------- | -------------------------------------- |
| supportsReturning       | INSERT/UPDATE/DELETE RETURNING support |
| supportsMerge           | MERGE statement support                |
| supportsRecursiveCte    | WITH RECURSIVE support                 |
| supportsIntersect       | INTERSECT operator                     |
| supportsExcept          | EXCEPT operator                        |
| supportsWindowFunctions | OVER() / window function support       |
| supportsWritableCte     | INSERT/UPDATE/DELETE inside CTE        |

---

# Initial Capability Matrix

The following defaults must be implemented.

| Feature          | PostgreSQL | SQLite    | SQL Server | Sybase | Oracle | MariaDB | DB2 |
| ---------------- | ---------- | --------- | ---------- | ------ | ------ | ------- | --- |
| RETURNING        | ✓          | ✓ (3.35+) | ✓ (OUTPUT) | ✓      | ✓      | ✗       | ✗   |
| MERGE            | ✗          | ✗         | ✓          | ✓      | ✓      | ✗       | ✓   |
| Recursive CTE    | ✓          | ✓         | ✓          | ✓      | ✓      | ✓       | ✓   |
| INTERSECT        | ✓          | ✓         | ✓          | ✓      | ✓      | ✓       | ✓   |
| EXCEPT           | ✓          | ✓         | ✓          | ✓      | ✓      | ✓       | ✓   |
| Window Functions | ✓          | ✓         | ✓          | ✓      | ✓      | ✓       | ✓   |
| Writable CTE     | ✓          | ✗         | ✓          | ✓      | ✓      | ✗       | ✓   |

These must match documented backend capabilities.

---

# Enforcement Locations

Capability checks must occur at **builder compile time**, not runtime.

Examples:

### RETURNING

Inside builder:

```
Insert::returning()
Update::returning()
Delete::returning()
```

Check:

```php
if (!$dialect->supportsReturning()) {
    throw QueryException("Dialect '{$dialect->name()}' does not support RETURNING");
}
```

---

### MERGE UPSERT

Inside Upsert builder compile:

```
if (!$dialect->supportsMerge()) {
    fallback or throw
}
```

MariaDB and PostgreSQL use alternative upsert forms.

---

### Recursive CTE

Inside:

```
Select::withRecursive()
```

Verify:

```
dialect->supportsRecursiveCte()
```

---

### INTERSECT / EXCEPT

Inside compound query builder.

---

### Writable CTE

When compiling:

```
WITH x AS (INSERT ...)
```

Check:

```
supportsWritableCte()
```

---

# Error Handling

All failures must produce **clear, deterministic exceptions**.

Example:

```
QueryException:
Dialect 'MariaDB' does not support RETURNING
```

Not:

```
PDOException
SQL syntax error
```

The goal is **developer clarity before SQL execution**.

---

# Tests Required

Add new tests under:

```
tests/Query/DialectCapabilitiesTest.php
```

---

### Test 1 — Returning Unsupported

MariaDB dialect:

```
$db->insert()->returning('id')
```

Must throw:

```
QueryException
```

---

### Test 2 — Recursive CTE Unsupported (Simulated)

If dialect capability toggled off.

Verify builder fails.

---

### Test 3 — MERGE Support

Oracle:

```
supportsMerge() === true
```

MariaDB:

```
supportsMerge() === false
```

---

### Test 4 — Window Function Capability

Verify capability detection without executing SQL.

---

### Test 5 — Capability Matrix Integrity

Verify each dialect exposes capability flags.

---

# Documentation Updates

Update:

```
docs/spec.md
docs/query-cookbook.md
```

Add **Dialect Support Matrix** section.

Example:

```markdown
## Dialect Feature Support

| Feature | PG | SQLite | SQLServer | Sybase | Oracle | MariaDB | DB2 |
|--------|----|--------|----------|--------|--------|--------|------|
| Returning | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| Merge | ✗ | ✗ | ✓ | ✓ | ✓ | ✗ | ✓ |
```

---

# Acceptance Criteria

All conditions must be met:

* dialect capabilities defined
* builders enforce capability checks
* unsupported features fail before SQL execution
* exception messages are clear
* PHPUnit tests confirm capability enforcement
* documentation updated

---

# Evidence Required

Provide:

* modified files
* PHPUnit output
* example thrown exceptions
* dialect capability matrix verification

---

# Non-Goals

This work order does **not introduce new SQL features**.

It only enforces support boundaries.

Future work orders will add:

* window function builders
* advanced analytic queries
* optimizer hints
* materialized CTE options

---

# Philosophy

A robust SQL abstraction layer must not only generate correct SQL.

It must also:

* prevent illegal SQL generation
* communicate backend limitations clearly
* preserve developer trust in the abstraction

This work order transforms UDA’s dialect layer from **implicit knowledge** into an explicit, enforceable contract.
