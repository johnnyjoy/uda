# Cache Doctrine

## Purpose

Caching in UDA exists for exactly two reasons:

1. **Speed**
2. **Resilience**

Nothing else.

If caching does not improve one of these outcomes, it must not exist in the system.

---

# Core Principle

> Cache is not called. Cache happens.

Caching is **configuration-driven**.

If caching is enabled for a connection:

* read operations automatically consult cache
* write operations automatically trigger invalidation

Application code must **never explicitly invoke cache**.

There is **no public cache API**.

All caching behavior is internal to the execution pipeline.

---

# Runtime Ownership

The **Driver domain** is the only runtime component allowed to interact with Cache.

Driver responsibilities regarding cache:

1. Evaluate cache policy
2. Consult cache metadata
3. Decide cache usability
4. Execute database query if needed
5. Populate cache on successful reads
6. Notify cache of writes

Cache logic must never appear outside Driver.

---

# Execution Flow

Read path when caching is enabled:

```
Repository
    ↓
Database
    ↓
Driver
    ↓
Cache metadata decision
    ↓
Cache hit → return result
Cache miss → Executor → PDO
    ↓
Store result in Cache
```

Write path:

```
Repository
    ↓
Database
    ↓
Driver
    ↓
Executor → PDO
    ↓
TableWriteTracker update
    ↓
Cache invalidation decision
```

There must never be an alternate cache execution path.

---

# Metadata-First Rule

Cache evaluation must occur using metadata only.

Payload retrieval must occur **only after the decision to use cached data**.

Decision sequence:

1. Retrieve metadata
2. Evaluate TTL
3. Evaluate table write timestamps
4. Determine usability
5. Retrieve cached payload only if selected

Deserializing payload before the decision is forbidden.

---

# Metadata Model

Each cached entry consists of two keys.

| Key        | Purpose               |
| ---------- | --------------------- |
| `m:{root}` | metadata record       |
| `r:{root}` | cached result payload |

Metadata must contain sufficient information to determine cache validity without retrieving the result payload.

Typical metadata fields include:

| Field             | Description                  |
| ----------------- | ---------------------------- |
| `createdAt`       | cache creation timestamp     |
| `ttlSeconds`      | entry TTL                    |
| `tables`          | tables involved in the query |
| `tableWriteTimes` | write timestamps at creation |

Metadata must remain small and cheap to retrieve.

---

# Staleness Model

UDA permits stale results in exactly two situations.

No other stale conditions are allowed.

---

## 1. TTL-as-Interval Mode

If policy flag `ignoreTableMtimeWithinTtl` is enabled:

* cached results may be served while TTL is valid
* table write timestamps are ignored during this window

Purpose:

* reduce invalidations
* throttle database load

TTL acts as an **interval control**, not a guarantee of freshness.

---

## 2. Stale-on-Error

If all conditions are true:

* database execution fails
* policy allows stale results
* cache age ≤ `maxStaleSeconds`

Then cached data may be returned.

Otherwise the database exception must propagate.

---

# Table Write Tracking

Cache invalidation depends on table write timestamps.

Driver must notify **TableWriteTracker** when a write operation succeeds.

Tracked operations:

* INSERT
* UPDATE
* DELETE
* UPSERT

Write notification occurs only when:

```
affectedRows > 0
```

Write timestamps are maintained:

* per connection
* per table

Query domain must not parse SQL to determine table involvement.

Query builders must provide table lists explicitly.

Raw SQL may include explicit table hints if invalidation is required.

---

# Cache Policy Hierarchy

Cache policy may exist at multiple levels.

Resolution order:

```
request override
    ↓
table rule
    ↓
connection default
    ↓
global default
```

The most specific rule applies.

Policy parameters may include:

* `ttlSeconds`
* `minIntervalSeconds`
* `allowStaleOnError`
* `maxStaleSeconds`

---

# Multi-Table Policy Merge

When multiple tables are involved in a query, policies must be merged conservatively.

Merge rules:

| Field              | Merge Rule  |
| ------------------ | ----------- |
| ttlSeconds         | minimum     |
| minIntervalSeconds | maximum     |
| allowStaleOnError  | logical AND |
| maxStaleSeconds    | minimum     |

This ensures caching behavior remains safe across joins.

---

# Cache Bypass Conditions

Cache must be bypassed when:

* caching disabled for the connection
* TTL ≤ 0
* operation is a write
* table policy disables caching

When bypassed, execution path becomes:

```
Repository → Database → Driver → Executor → PDO
```

Cache code must not execute.

---

# Anti-Goals

UDA caching explicitly forbids:

* Scope classes
* alternate read paths
* explicit cache invocation
* SQL parsing for table detection
* cache behavior controlled by application code

Caching must remain **fully transparent**.

---

# Architectural Invariant

All cached reads follow the canonical execution path:

```
Repository → Database → Driver → Cache → Executor → PDO
```

If a component bypasses this path, the architecture is invalid.

