# UDA Contract (Hard Rules)

**UDA exists to reduce SQL chaos and increase speed.**

We optimize for two outcomes only:

1. **Uniformity** — one clear way to perform common database operations
2. **Performance** — no avoidable runtime overhead

If a feature improves neither, it must not exist.

---

# 1. Domain Master Pattern

Every domain has **exactly one Master class**.

The Master class is:

* the **entrypoint**
* the **policy owner**
* the **state owner (if state exists)**

Subclasses in the domain are implementation tools used by the Master.

Examples:

| Domain   | Master     |
| -------- | ---------- |
| Database | `Database` |
| Driver   | `Driver`   |
| Cache    | `Cache`    |
| Config   | `Config`   |

Rules:

* No forwarding facades.
* No pass-through layers.
* No extra hops.

If a method can call the real implementation directly, it must.

---

# 2. Execution Path (Single Hot Path)

All SQL execution must converge through one path.

```
Repository
    ↓
Database
    ↓
Driver
    ↓
Executor
    ↓
PDO
```

Constraints:

* **Only Driver owns PDO/PDOStatement**
* Exactly **one implementation** of:

  * prepare
  * bind
  * execute
* No second execution path anywhere in the codebase.

Specifically forbidden:

* cache executing SQL
* helper classes executing SQL
* alternate executor implementations

Tests must fail if more than one execution path exists.

---

# 3. Public Entry Point

From the perspective of application code:

**Database *is* the database.**

Application code interacts with:

```
Database
```

Application code must **never depend on**:

* `Driver`
* `Driver\*`
* PDO
* PDOStatement

Driver exists only as the **internal execution engine**.

---

# 4. Named Parameters Only

Raw SQL APIs accept **named parameters only**.

Allowed:

```
WHERE id = :id
```

Forbidden:

```
WHERE id = ?
```

If SQL contains positional placeholders (`?`), the query must be rejected **before reaching PDO**.

---

# 5. Cache Doctrine (Transparent + Metadata First)

Cache must never be invoked explicitly.

Cache behavior is determined entirely by configuration.

Callers always execute queries the same way.

```
$db->rows(...)
```

Driver decides whether cache is consulted.

---

## Metadata-first evaluation

Cache reads must occur in this order:

1. retrieve metadata only
2. evaluate TTL
3. evaluate table write timestamps
4. determine usability
5. retrieve payload only if selected

Deserializing payload before the decision is forbidden.

---

## Stale Usage Rules

Stale cache may be served only in two cases:

### TTL-as-interval

Cached data may be served within TTL even if table mtime changed.

Purpose: reduce database load.

### Stale-on-error

If:

* database execution fails
* policy allows stale
* cached age ≤ `maxStaleSeconds`

then stale results may be returned.

---

# 6. Table Write Tracking

Driver must update table write timestamps when a write succeeds.

Trigger condition:

```
affectedRows > 0
```

Tracked operations:

* INSERT
* UPDATE
* DELETE
* UPSERT

Tracker records:

```
(connection, table) → lastWriteTimestamp
```

Cache invalidation uses:

```
cacheEntry.createdAt < table.lastWriteTimestamp
```

---

# 7. Mandatory File Purpose

Every source file must begin with a file-level docblock containing:

```
Purpose: <one sentence description>
```

Example:

```php
/**
 * Purpose: SQLite driver implementation.
 */
```

Build/test pipelines must fail if any file lacks a Purpose docblock.

---

# 8. Naming and Structure

Naming rules are enforced to prevent architectural drift.

Forbidden suffixes:

* Manager
* Service
* Engine
* Facade

Allowed style:

```
Cache
Policy
Key
StoreRedis
StoreMemcached
```

Avoid namespace repetition.

Example:

```
UDA\Driver\PostgreSQL
```

Not:

```
UDA\Driver\PostgreSQLDriver
```

---

# 9. State Discipline

State may exist only in:

| Component       | State                       |
| --------------- | --------------------------- |
| Driver          | connection + executor state |
| Cache backend   | cached data                 |
| Config snapshot | configuration               |

Everything else must be:

* immutable
* stateless
* clone-on-write if mutation required

Query builders must not contain runtime execution state.

---

# 10. Tests Enforce the Contract

These invariants must be test-detectable.

Minimum required tests:

| Test                        | Purpose                                        |
| --------------------------- | ---------------------------------------------- |
| Single execution path       | ensure one prepare/bind/execute implementation |
| PDO usage guard             | forbid PDO usage outside Driver                |
| Named parameter enforcement | reject `?` placeholders                        |
| Cache metadata-first        | ensure metadata read before payload            |
| Scope/cache API ban         | prevent alternate read surfaces                |
| File purpose docblock       | ensure documentation discipline                |

If a rule cannot be verified by tests, it should not appear in the contract.

---

# Final Principle

UDA must remain:

* **small**
* **predictable**
* **deterministic**
* **fast**

Any change that introduces additional execution paths, hidden state, or unnecessary abstraction violates the contract.
