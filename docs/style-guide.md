# UDA Style Guide

This document defines how code in **Universal Data Abstractor (UDA)** must be written.

It exists to prevent:

* architectural drift
* abstraction creep
* duplicated logic
* accidental complexity

If code violates this document, it is incorrect.

---

# 1. Purpose Statement (Mandatory)

Every source file must begin with a **Purpose statement**.

Example:

```php
/**
 * PHPDoc metadata
 */

/*
 * Purpose: SQLite driver implementation.
 *
 * Provides SQLite-specific behavior such as DSN construction,
 * identifier quoting, and pagination syntax. This class does not
 * execute SQL directly; execution occurs through the Driver runtime
 * pipeline.
 */
```

Purpose statements must explain:

| Requirement    | Description                 |
| -------------- | --------------------------- |
| Responsibility | What this file does         |
| Context        | Where it fits in the system |
| Boundaries     | What it must NOT do         |

They should be **short and precise**.

Purpose statements exist to prevent scope creep.

CI must fail if any source file lacks a `Purpose:` statement.

---

# 1.1 PHPDoc Blocks Use PEAR Style

PHPDoc blocks must follow PEAR-style formatting.

Method docblocks must:

* include `@param` for every declared parameter
* include `@return` for every non-constructor method
* include `@throws` when the method raises a domain exception
* align `@param` type and variable columns
* separate `@param`, `@return`, and `@throws` groups with blank docblock lines
* use meaningful descriptions instead of generated filler

Example:

```php
/**
 * Build SQL Server-style pagination for DBLib.
 *
 * @param int $limit   Maximum number of rows.
 * @param int $offset  Number of rows to skip.
 *
 * @return string Pagination SQL fragment.
 *
 * @throws QueryException If limit or offset is negative.
 */
```

Inline examples are not the boundary of the rule. If another PEAR-standard
tag improves clarity or static analysis, use it.

---

# 2. Named Parameters Only

All SQL must use **named parameters**.

Allowed:

```sql
WHERE id = :id
```

Forbidden:

```sql
WHERE id = ?
```

If positional placeholders are detected, the query must fail before reaching PDO.

---

# 3. Execution Path Discipline

SQL execution must occur through a **single implementation**.

Only one location in the codebase may:

* call `prepare()`
* bind parameters
* call `execute()`

Duplicate execution paths are forbidden.

---

# 4. Domain Responsibilities

Domains must respect strict boundaries.

| Domain   | Responsibility              |
| -------- | --------------------------- |
| Database | public entrypoint           |
| Driver   | engine behavior + execution |
| Query    | SQL construction            |
| Cache    | result storage              |
| Config   | configuration loading       |

Forbidden dependencies:

* Query importing Cache
* Cache importing Driver
* Query executing SQL

---

# 5. Cache Transparency

Caching must be **automatic**, not a caller decision.

Code must never include logic like:

```php
$db->cache(...)
```

Caching behavior must be determined entirely by configuration.

Application code must always execute queries the same way.

---

# 6. Metadata-First Cache Reads

Cache backends must evaluate metadata before payload.

Correct order:

1. retrieve metadata
2. evaluate TTL and invalidation rules
3. retrieve payload only if cache is usable

Deserializing payload before the decision is forbidden.

---

# 7. Naming Rules

UDA favors **simple nouns**.

Allowed:

```
Cache
Policy
Key
RedisStore
SelectQuery
```

Forbidden suffixes:

```
Manager
Service
Engine
Facade
Handler
```

Avoid namespace repetition.

Example:

```
UDA\Driver\Postgres
```

Not:

```
UDA\Driver\PostgresDriver
```

---

# 8. Avoid Clever Abstractions

Prohibited patterns:

* forwarding facades
* duplicate builder layers
* execution wrappers
* alternate runtime paths
* “scope”-style query modifiers

If a layer exists only to forward calls, delete it.

---

# 9. State Discipline

State is permitted only where necessary.

Allowed state locations:

| Component       | State            |
| --------------- | ---------------- |
| Driver          | connection state |
| Cache backend   | stored results   |
| Config snapshot | configuration    |

Everything else should be:

* immutable
* stateless

---

# 10. Deterministic Query Builders

Query builders must behave predictably.

They must:

* never silently reorder clauses
* never infer developer intent
* fail early on invalid usage

SQL generation must be deterministic.

---

# 11. Prefer Deletion

When improving code:

* remove code before adding new code
* merge abstractions instead of creating parallel ones
* delete unused features immediately

Less code is usually better code.

---

# 12. Documentation Discipline

Behavior changes require updating:

* `spec.md`
* `caching.md`
* cookbook examples
* tests

Code without documentation updates is incomplete.

---

# 13. Performance First

UDA exists to:

1. make database access uniform
2. keep it fast

Any change that adds measurable overhead without clear benefit must be rejected.

---

# 14. Static Analysis Rules

The following rules must be enforced automatically in CI.
If a rule fails, the build must fail.

The purpose is to ensure that architectural guarantees remain true even as the codebase evolves.

---

# 14.1 PDO Usage Guard

PDO must only appear inside the **Driver execution implementation**.

Forbidden everywhere else.

Allowed locations:

```
src/UDA/Driver/*
```

Forbidden:

```
src/UDA/Query/*
src/UDA/Cache/*
src/UDA/Config/*
src/UDA/*
```

CI rule example:

```
grep -R "new PDO" src/UDA | grep -v "src/UDA/Driver"
```

and

```
grep -R "->prepare(" src/UDA | grep -v "src/UDA/Driver"
```

If found outside Driver, the build fails.

---

# 14.2 Single Execution Path

There must be exactly **one implementation** that performs:

```
prepare()
bind
execute()
```

The static check must confirm:

```
count(prepare() usage) == 1
count(execute() usage) == 1
```

Any additional execution logic must fail CI.

---

# 14.3 Positional Parameter Ban

The public API forbids positional placeholders.

CI must detect SQL containing:

```
?
```

unless inside test fixtures or documentation.

Example rule:

```
grep -R "?" src/UDA | grep -E "SELECT|UPDATE|INSERT|DELETE"
```

If detected, fail the build.

---

# 14.4 Forbidden Class Names

Certain suffixes are banned because they create abstraction creep.

Forbidden suffixes:

```
Manager
Service
Engine
Facade
Handler
Controller
```

CI rule:

```
grep -R "class .*Manager" src/UDA
```

Repeat for each forbidden suffix.

---

# 14.5 Namespace Boundary Enforcement

Domains must not import forbidden domains.

Forbidden imports:

| Domain | Cannot import |
| ------ | ------------- |
| Query  | Cache         |
| Cache  | Driver        |
| Query  | PDO           |

CI rule example:

```
grep -R "use UDA\\Cache" src/UDA/Query
```

If detected, fail.

---

# 14.6 Purpose Statement Enforcement

Every PHP source file must contain:

```
Purpose:
```

within the first docblock.

CI rule:

```
grep -L "Purpose:" src/UDA/*.php
```

Any file returned by this command fails the build.

---

# 14.7 Public API Surface Guard

The public API must remain minimal.

Allowed public classes:

```
Database
Query Builders
Exception types
Sql value object
```

Forbidden exposure:

```
Driver internals
Cache implementation classes
PDO
```

Static analysis must ensure public namespaces do not expose internal components.

---

# 14.8 Duplicate Logic Detection

When similar code blocks appear in multiple files, this usually indicates architectural drift.

CI should detect duplicate blocks larger than a threshold (example: 15 lines).

Tools:

```
phpcpd
```

CI must fail if duplication exceeds allowed limits.

---

# 14.9 Cache Metadata Enforcement

Cache implementations must access metadata before payload.

Static rule:

Payload deserialization functions must not appear before metadata checks.

Example heuristic check:

```
grep for unserialize/json_decode before metadata read
```

If found, flag for review.

---

# 14.10 Dependency Graph Guard

Domain dependencies must remain:

```
Database
   ↓
Driver
   ↓
Query
```

Cache exists beside Driver.

Forbidden dependency cycles must be detected.

Tool examples:

```
deptrac
phpmetrics
```

CI must fail on dependency violations.

---

# What CI enforces today (enforcement map)

This table is the **maintainer-facing truth** for automation that actually runs
under `composer check` and related scripts. It sits alongside aspirational rules
elsewhere in this document: if a rule is not listed here as enforced, treat it
as **review-enforced** until a tool is added.

| Style-guide topic | Verified by | Scope / notes |
| ----------------- | ------------- | --------------- |
| §1 `Purpose:` in PHP sources | `tools/check-purpose.php` | `src/UDA/**/*.php` only (`tests/` and `tools/` are not scanned) |
| §1.1 PEAR PHPDoc layout | **Review** + optional `vendor/bin/php-cs-fixer fix` (`.php-cs-fixer.dist.php` enables `phpdoc_align`, `@PSR12`, etc.) | **Not** part of `composer check` |
| §2 Named parameters only | Runtime normalisation + integration tests | Positional `?` rejected before PDO |
| §3 / §14.2 Single `prepare` / `execute` ownership | `tools/check-pdo-usage.php`, `tools/check-execution-path.php` | Ensures PDO prepare/execute stay on the Driver hot path |
| §4 Query↔Cache / boundary imports | `tools/check-imports.php` | Query→Cache, Cache→Driver, Query→PDO `use` lines |
| §7 / §14.4 Forbidden class suffixes | `tools/check-forbidden-names.php` | Banned suffixes under `src/UDA` |

**PHP-CS-Fixer dry-run (representative):** On 2026-05-12, `php-cs-fixer fix --dry-run`
under PHP 8.2 reported diffs in **51 of 71** tracked PHP files, mostly vertical
`@param` alignment. CI does **not** gate on fixer today; a repo-wide alignment PR
is optional and separate from architectural checks.

**PHPDoc:** Treat full PEAR docblock compliance as **review-enforced** unless
maintainers add PHP-CS-Fixer (dry-run or fix) to CI.

---

# CI Philosophy

Static analysis rules exist to enforce architecture automatically.

Developers should not have to remember the rules.

Instead:

```
violations → CI failure
```

Architecture remains stable because the tooling refuses drift.

---

# Enforcement Principle

Rules that protect architecture should be automated where practical so that
`violations → CI failure`. A few normative rules (PEAR PHPDoc prose quality,
cache heuristics beyond import checks, duplication budgets) remain **review-enforced**
until a specific tool is adopted; those are called out in the **enforcement map**
above rather than pretending they are already CI-gated.

Architecture should be protected by tools, not by memory alone.

---

# Design Philosophy

UDA prioritizes:

* simplicity
* determinism
* performance
* explicit behavior

If a design requires cleverness to understand, it is probably wrong.

---

Optional follow-up section for stronger CI enforcement:

### “Static Analysis Rules”

These allow CI to enforce things like:

* PDO usage outside Driver
* forbidden class suffixes
* positional parameters
* missing Purpose docblocks
* forbidden namespace imports
