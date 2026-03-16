# UDA Caching

## Purpose

Caching in UDA provides **transparent read acceleration**.

Caching exists for exactly two reasons:

1. **Speed**
2. **Resilience**

Caching must not introduce alternate execution paths or additional public APIs.

---

# Core Principle

> Cache is not called. Cache happens.

Caching is **configuration-driven**.

If caching is enabled for a connection:

* read helpers automatically consult cache
* write helpers automatically trigger invalidation

Application code continues to use the same methods:

```
row()
rows()
value()
values()
list()
each()
```

No public cache API exists.

The caching system is **completely transparent** to callers.

---

# Runtime Ownership

Only the **Driver domain** interacts with Cache during execution.

Driver responsibilities:

1. evaluate cache policy
2. retrieve cache metadata
3. determine cache usability
4. execute database query when required
5. populate cache after successful reads
6. notify cache of writes

The runtime pipeline is fixed:

```
Repository → Database → Driver → Cache → Executor → PDO
```

No alternate cache execution path may exist.

---

# Shared Cache Infrastructure

Each Driver receives a **connection-scoped cache controller** during construction.

The controller is created via configuration wiring:

```
$setup = new \UDA\Cache\Setup($store, $tracker, $serializer, ...);
$cache = Cache::fromSetup($connectionName, $setup);
```

The setup encapsulates the cache store, serializer, table write tracker, namespace, and policy data for that connection.
The controller is private to the Driver and never exposed publicly.

---

# Store Backends

Cache stores live under:

```
UDA\Cache\Store
```

Supported backends:

| Backend       | Class            | Extension     | Notes                              |
| ------------- | ---------------- | ------------- | ---------------------------------- |
| Redis         | `RedisStore`     | ext-redis     | Prefix optional (default `UDA:`)   |
| Memcached     | `MemcachedStore` | ext-memcached | TTL > 30 days uses absolute expiry |
| Process-local | `ArrayStore`     | none          | In-memory only                     |

Store configuration occurs in the **top-level config cache block**.

---

# Transparent Read Behavior

When caching is enabled for a connection:

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
Cache store update
```

If caching is disabled, the path becomes:

```
Repository → Database → Driver → Executor → PDO
```

Cache code must not execute.

---

# Metadata-First Doctrine

Cache decisions must use **metadata only**.

Payload retrieval must occur only if the metadata indicates the cached entry is usable.

Decision sequence:

1. retrieve metadata
2. evaluate TTL
3. evaluate table write timestamps
4. determine usability
5. retrieve cached payload if selected

Deserializing unused payload is forbidden.

---

# Cache Key Scheme

```
UDA|{serializer_id}|v{format_version}|{connection}|{tables}|{query_hash}
```

Components:

| Component      | Description                          |
| -------------- | ------------------------------------ |
| serializer_id  | serializer implementation identifier |
| format_version | cache format version                 |
| connection     | connection name                      |
| tables         | normalized table list                |
| query_hash     | hash of SQL and parameters           |

---

## Table Segment

Tables are:

* normalized
* sorted
* joined with `+`

If the segment exceeds `MAX_TABLES_SEGMENT_LENGTH`, it becomes:

```
t:{sha256(tablesJoined)}
```

---

## Query Hash

```
sha256(normalized_sql + "\n" + stable_param_encoding)
```

This ensures deterministic cache keys.

---

# Serializer

Serialization strategy:

1. igbinary (if available)
2. PHP `serialize()`

Serializer identity is embedded in the cache key to prevent collisions between formats.

---

# TTL Model

Every cached entry must have a TTL.

Infinite TTL is forbidden.

TTL resolution order:

1. per-call override
2. per-table override
3. per-connection default
4. global default

```
ttlSeconds <= 0
```

disables caching.

---

# Interval Gating

`minIntervalSeconds` throttles repeated database queries.

Within the interval window:

* cached results are served
* database queries are suppressed

This reduces load for frequently requested queries.

---

# Stale-on-Error

If a database execution fails and the error is considered transient:

```
allowStaleOnError = true
```

and:

```
cache_age <= maxStaleSeconds
```

then stale results may be returned.

Otherwise the database exception propagates.

Transient detection occurs in:

```
Driver::isTransient()
```

---

# Table Write Tracking

Cache invalidation relies on table write timestamps.

Driver must notify the tracker when a write succeeds.

Tracked operations:

* INSERT
* UPDATE
* DELETE
* UPSERT

Write tracking occurs only when:

```
affectedRows > 0
```

Fluent write helpers automatically notify the tracker.

For raw SQL writes:

```
$driver->touchTables(['table1','table2'])
```

must be called.

## Raw SQL table hints

All public read helpers on `UDA\Database` now accept an optional `$tableHints` array argument. When you execute literal SQL outside the query builders, pass the list of tables touched by the statement so the cache and tracing layers receive accurate metadata:

```php
$db->rows('SELECT * FROM users WHERE id = :id', ['id' => 7], ['users']);
```

Providing hints keeps cache invalidation and query traces accurate even before the builders can infer table names. If you omit the parameter, UDA falls back to whatever metadata it can derive from builders or cached plans.

---

# Table Staleness

A cached entry becomes stale when:

```
lastTouched(table) >= cachedEntry.createdAt
```

This comparison is performed for every table involved in the query.

---

# Per-Connection Policy

Connections may define default caching policy.

Example:

```json
"cache": {
  "defaultPolicy": {
    "ttlSeconds": 60,
    "minIntervalSeconds": 5,
    "allowStaleOnError": false,
    "maxStaleSeconds": 0
  },
  "namespace": "app1",
  "tables": {
    "users": {"ttlSeconds": 30},
    "audit_log": {"disable": true}
  }
}
```

---

# Table Policy Merge

When multiple tables appear in a query, policies are merged conservatively.

| Field              | Merge Rule  |
| ------------------ | ----------- |
| ttlSeconds         | minimum     |
| minIntervalSeconds | maximum     |
| allowStaleOnError  | logical AND |
| maxStaleSeconds    | minimum     |

If any table rule specifies:

```
disable: true
```

caching is disabled.

---

# Performance Behavior

Cache writes occur:

* on initial cache population
* on cache refill

Cache hits do not rewrite entries unless interval gating requires it.

This minimizes write amplification.

---

# Anti-Goals

The caching system must never introduce:

* Scope classes
* alternate read APIs
* explicit cache invocation
* SQL parsing for table discovery
* application-controlled cache execution

Caching must remain **fully transparent**.

---

# Architectural Invariant

All cached reads must follow:

```
Repository → Database → Driver → Cache → Executor → PDO
```

If any component bypasses this path, the architecture is invalid.
