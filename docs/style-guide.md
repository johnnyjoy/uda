# UDA Style Guide

This document defines how code in Universal Data Abstractor (UDA) must be written.

It exists to prevent drift, duplication, abstraction creep, and accidental complexity.

If code violates this document, it is wrong.

---

# 1. Purpose Docblock (Mandatory)

Every source file must begin with:

```php
/**
 * Purpose: One sentence describing what this file does.
 * Domain: (Driver | Query | Cache | Config | Dialect | Database)
 */
````

If you cannot describe the purpose in one sentence, the file is doing too much.

---

# 2. Named Parameters Only

* No positional `?` parameters.
* All SQL must use named parameters (`:name`).
* If positional parameters appear, throw immediately.

---

# 3. One Execution Path

There must be exactly one location in the codebase that:

* calls `prepare()`
* binds parameters
* calls `execute()`

No duplicate execution logic.
No alternate executor.
No helper wrappers duplicating execution.

---

# 4. Driver Is The Only Runtime Orchestrator

* Only Driver may execute SQL.
* Only Driver may inform Cache of table writes.
* Query must never import Cache.
* Cache must never import Driver.

---

# 5. Cache Must Be Transparent

* No `$driver->cache()` API.
* If caching is enabled via configuration, it happens automatically.
* If disabled, no cache code should execute.

---

# 6. Metadata First

Cache backends must:

1. Retrieve metadata only.
2. Decide whether to use cache.
3. Retrieve result only if needed.

Never deserialize a result set just to reject it.

---

# 7. No Clever Abstractions

Prohibited patterns:

* “Manager” classes
* “Service” classes
* “Engine” classes
* Duplicate builder layers
* Alternate execution paths
* Scope-like indirection

If something can be deleted instead of refactored, delete it.

---

# 8. State Discipline

State may only exist in:

* Driver (connection-bound state)
* Cache backend (persistent store)
* Configuration snapshot

Everything else must be immutable or stateless.

---

# 9. Grammar Must Be Deterministic

Query builders must:

* Not reorder clauses silently.
* Not infer intent.
* Fail early if used incorrectly.

---

# 10. Deletion Over Addition

When improving code:

* Prefer removing code over adding new classes.
* Prefer merging abstractions over creating parallel ones.

---

# 11. Documentation Is Part of the Code

When behavior changes:

* Update `spec.md`
* Update `caching.md`
* Update cookbook examples
* Update tests

Code without documentation is incomplete.

---

# 12. Performance Is Mandatory

UDA exists to:

1. Make DB access uniform.
2. Make it faster (or at least not slower).

Any change that adds overhead without measurable benefit is incorrect.
