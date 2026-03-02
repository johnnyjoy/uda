# UDA Style Guide

This document defines how code in Universal Data Abstractor (UDA) must be written.

It exists to prevent drift, duplication, abstraction creep, and accidental complexity.

If code violates this document, it is wrong.

---

# 1. Purpose Statement (Mandatory)

Every source file must begin with a comprehensive purpose statement following John Ousterhout's philosophy:

```php
/**
 * @package     UDA
 * @subpackage  [Driver|Query|Cache|Config|SQL|Exception]
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/[subpackage]/[filename]
 * @since       1.0.0
 *
 * [Clear, detailed description of what this file does and why it exists]
 *
 * The purpose of this [class/file] is to [specific responsibility] while
 * [important constraints or boundaries]. It [interaction with other components]
 * and [what problem it solves]. This file must not [what it should NOT do]
 * to maintain architectural integrity and prevent scope creep.
 */
````

The purpose statement must include:

1. **Clarity**: Straightforward, non-technical language that any developer can understand
2. **Functionality**: What the file does and its main responsibilities
3. **Context**: How it interacts with other components or modules
4. **Usage**: How to use the file, if applicable
5. **Boundaries**: What the file should NOT do (to prevent scope creep)

Example for a Query Builder:
```php
/**
 * @package     UDA
 * @subpackage  Query
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/query/select
 * @since       1.0.0
 *
 * This file provides a fluent, type-safe SELECT query builder that constructs
 * parameterized SQL statements without executing them. It offers a chainable API
 * for building complex SELECT queries with joins, filtering, ordering, and pagination
 * while preventing SQL injection through parameter binding. The builder produces
 * Sql objects that are executed by the Driver class, maintaining clear separation
 * between query construction and execution as required by UDA's architectural principles.
 */
````

The purpose statement must be detailed enough to prevent scope creep - if a feature request would cause the file to violate its stated purpose, the request must be rejected or implemented elsewhere.

**Philosophical Basis**: This approach follows John Ousterhout's software design philosophy from "A Philosophy of Software Design." Each module/file should have a clear purpose statement that defines its role within the larger system, guides developers in understanding its functionality, and prevents scope creep by explicitly stating what the file should NOT do.

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
