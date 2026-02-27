# UDA Design 2.0

**Purpose:** Concrete architecture implementing `spec.md 2.0`.
This document defines *how* UDA is built and enforced.
If this conflicts with `spec.md`, `spec.md` wins.

---

# 1. Core Structural Invariants

These are design-level invariants. Violation means architectural failure.

---

## 1.1 One Execution Path

There SHALL be exactly one implementation of:

* `prepare`
* `bind`
* `execute`

This implementation SHALL live inside `UDA\Driver`.

No other class may:

* Prepare SQL
* Bind parameters
* Execute SQL
* Touch `PDOStatement`

This rule is enforceable via static scan.

---

## 1.2 One Read Surface

All read operations SHALL converge to:

```php
Driver::readInternal(Query\Sql $sql, ReadPolicy $policy): mixed
```

The following MUST delegate to this:

* `row()`
* `rows()`
* `value()`
* `values()`
* `list()`
* `each()`
* `select()->...->rows()`

There shall be no alternate read path.

---

## 1.3 Transparent Cache

Cache is not called.

Cache is enabled via configuration.

If enabled for a connection:

* All read terminators automatically consult cache.
* No public `cache()` API exists.
* No Scope classes exist.
* No alternate execution paths exist.

If disabled:

* Cache code must not execute.

Cache is a read backend decision inside Driver.

---

## 1.4 State Minimalism

State may exist only in:

* `Driver`
* Cache backend (persistent store)
* `Config\Snapshot`

Everything else must be:

* Immutable value object
* Clone-on-write
* Stateless

Builders SHALL NOT carry runtime state.
Query\Sql SHALL be immutable.

---

# 2. Architectural Planes

UDA is composed of four planes:

---

## 2.1 Control Plane (Configuration + Binding)

Responsibilities:

* Load JSON config from `UDA_CONFIG`
* Validate once
* Produce immutable `Config\Snapshot`
* Construct Driver with validated definition

Configuration SHALL NOT leak raw arrays beyond validation.

---

## 2.2 Message Plane (Query Domain)

Query domain produces immutable SQL messages.

Query SHALL:

* Build SQL string
* Build named parameters
* Carry explicit table list
* Carry optional policy hints

Query SHALL NOT:

* Execute
* Touch PDO
* Touch Cache
* Branch on driver name

Dialect is injected by Driver.

Query is a courier.

---

## 2.3 Execution Plane (Driver)

Driver is the runtime brain.

Driver SHALL:

* Own PDO lifecycle
* Own execution
* Own transaction orchestration
* Own cache orchestration
* Own table write tracking
* Inject Dialect into Query

Driver SHALL NOT:

* Assemble SQL strings
* Parse SQL
* Infer table names

---

## 2.4 Acceleration Plane (Cache)

Cache is optional and transparent.

Cache SHALL:

* Store metadata + payload
* Provide metadata-first lookup
* Never execute SQL
* Never own PDO
* Never implement prepare/bind/execute

Cache delegates execution back to Driver.

---

# 3. Directory Structure (Conceptual)

```
src/UDA/
  Database.php
  Driver.php
  Driver/
  Config.php
  Config/
  Query.php
  Query/
  Builder/
  Cache/
  Schema/
  Result/
  Exception/
```

No Scope.
No FetchScope.
No PassThroughScope.

---

# 4. Driver Internal Structure

Driver contains:

* PDO instance (lazy)
* Execution hot path
* Cache policy resolver
* TableWriteTracker
* Dialect instance

### 4.1 Internal Read Flow

```
readInternal():
  if cache enabled:
      meta = cache.getMeta(key)
      if meta exists:
          if shouldServe(meta):
              return cache.getResult(key)
  result = executeHotPath()
  if cache enabled:
      cache.set(...)
  return result
```

This flow MUST exist in one place only.

---

# 5. Cache Design

## 5.1 Metadata-First

Cache backend MUST implement:

```php
getMeta(string $key): ?Meta
getResult(string $key): mixed
set(string $key, Meta $meta, mixed $result, int $ttl): void
```

Driver SHALL:

1. Fetch metadata
2. Evaluate TTL
3. Evaluate table mtime (unless interval mode)
4. Decide
5. Fetch payload only if selected

Never deserialize payload to reject.

---

## 5.2 TTL Resolution

TTL hierarchy:

1. Per-call override
2. Per-table policy
3. Per-connection default
4. Global default

TTL <= 0 disables cache.

Infinite TTL forbidden.

---

## 5.3 TTL-as-Interval Mode

If `ignoreTableMtimeWithinTtl` true:

* Within TTL → serve cache even if table mtime newer

Else:

* mtime invalidates immediately

---

## 5.4 Stale-on-Error

If:

* DB throws transient exception
* Policy allows stale
* Age <= maxStaleSeconds

Return stale result.

Else propagate exception.

---

# 6. Table Write Tracking

Driver SHALL call:

```
TableWriteTracker::touch(connectionName, table)
```

After successful DML.

Query builders SHALL supply table list explicitly.

Raw SQL requires explicit table hint for invalidation.

No SQL parsing permitted.

---

# 7. Query Builder Design

Builders SHALL:

* Be clone-on-write
* Maintain internal grammar state
* Prevent illegal chaining
* Fail early

Supported grammar:

* where()
* and()
* or()
* not()
* in()
* between()
* exists()
* distinct()
* groupBy()
* having()
* orderBy()
* limit()
* offset()
* upsert()

Builders SHALL output immutable `Query\Sql`.

Builders SHALL NOT execute.

No phase-class explosion allowed.

---

# 8. Transactions

Driver SHALL:

* Support nested transactions
* Use savepoints when supported
* Emulate otherwise

Only Driver orchestrates transaction state.

---

# 9. Repository Usage Pattern

Application architecture SHALL:

* Inject Driver into repository classes
* Centralize SQL in repository classes
* Never call PDO directly
* Never build DSN externally

A repository may use multiple connections.

Connection choice is explicit.

---

# 10. Performance Expectations

Metadata-first evaluation is mandatory.

If cache hit rate >= 80%:

UDA SHALL outperform raw PDO.

If not, design must be reconsidered.

---

# 11. Enforcement Tests

Test suite SHALL verify:

* Exactly one prepare/execute path exists
* No class outside Driver references PDO
* No Scope classes exist
* No alternate read surface exists
* Named parameters only
* Cache metadata-first behavior
* TTL layering works
* TTL-as-interval works
* Stale-on-error works

---

# 12. Design Philosophy Summary

UDA is:

* Small
* Sharp
* Deterministic
* Transparent
* Accelerated
* Single-path
* Scope-free

UDA rejects:

* Clever indirection
* Parallel universes
* Hidden execution paths
* Silent performance penalties
* Builder-execution hybrids
