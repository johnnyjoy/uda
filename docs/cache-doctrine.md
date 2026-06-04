# UDA Cache Doctrine

> **Status: future-state design doctrine.**  
> This document describes the target cache policy model (interval, max-age, layered TTL, stale-on-error).
> Current v1 runtime behavior is documented in [caching.md](caching.md) and is narrower.

Cache exists to reduce read load **without changing caller behavior**. Repositories
always call `Database` the same way; `Driver` + `Cache` decide whether a read is served
from cache, from the database, or (when policy allows) from **stale cache during an outage**.

This document is the **design intent / target**. See [caching.md](caching.md) for configuration,
runtime path, and what is wired in the current release.

---

## Core idea: interval caching + layered policy

UDA caching is **not** “one TTL for the whole system.” It is **metadata-driven**:

each cached read has its own metadata record (tables, creation time, and eventually
resolved policy). The decision is: **may this payload be used for this read right now?**

Two time axes work together:

### 1. Interval (minimum time between database reads)

Configured as `minIntervalSeconds` on `defaultPolicy` or per-table overrides.

**Meaning:** If a usable cache entry exists, you **use it** and do **not** hit the database
again until the interval has elapsed. You query the DB at most once every N seconds for
that cache key (for that SQL + params + result shape), even if you would otherwise prefer
fresher data.

Example: interval **30 seconds** → while cache metadata and payload are valid under the
rules below, serve cache; only after 30 seconds since the last successful population may
the read path go back to the database (subject to max-age and mtime rules).

This is **interval caching**: the cache is there to be used; the interval caps DB load.

### 2. Max age (how long an entry may exist)

Configured as `ttlSeconds` on `defaultPolicy` or per-table overrides (and resolved per
entry from the tables listed in metadata).

**Meaning:** The payload may remain in the store until this **global max age** for that
policy layer ends it. In principle you would keep hot data forever if you had unlimited
memory; in practice you set a TTL so entries eventually disappear.

Max age and interval are **different**:

| | Interval (`minIntervalSeconds`) | Max age (`ttlSeconds`) |
| - | -------------------------------- | ---------------------- |
| **Question** | “Am I allowed to query the DB again yet?” | “Is this entry too old to exist at all?” |
| **Effect** | Prefer cache; throttle DB round-trips | End of life for the cached result |

Backend store expiry (Redis key TTL, etc.) is a separate, operational cleanup layer — not
the application policy above.

---

## Why metadata is separate from payload

There is **no single TTL** for the caching system. Metadata is read **first** so UDA can
decide without deserializing payload:

1. Load metadata for this cache key (SQL + named params + result shape + namespace).
2. Resolve **effective policy** for this entry (connection default, per-table overrides,
   strictest rule when multiple tables are hinted).
3. Apply **interval**, **max age**, and **table write timestamps (mtime)**.
4. If the database is unavailable, optionally apply **stale-on-error** (see below).
5. Only then fetch payload if the decision allows it.

Payload keys and `m:` metadata keys are paired but stored separately so invalidation and
policy checks stay cheap and explicit.

---

## Layered policy (connection, table, query)

Policy is configured in layers; the runtime resolves **one effective policy per cache
entry** from what that entry’s metadata records.

| Layer | Config | Role |
| ----- | ------ | ---- |
| **Connection** | `cache.defaultPolicy` | Defaults for `ttlSeconds`, `minIntervalSeconds`, `allowStaleOnError`, `maxStaleSeconds` |
| **Table** | `cache.tables.<name>` | Override for any read/write that hints that table; stricter value wins when multiple tables apply |
| **Query** | *(implicit)* | Each distinct SQL + params + shape is its own cache key and metadata row — “this query” vs “that query” is not one shared TTL |

The same connection can therefore use a **30s interval** by default and a **300s max age**
on one table’s reports, while another table keeps **30s** max age. A join that hints both
tables inherits the **stricter** limits.

There is no separate “query TTL” field in v1 config schema; **per-query behavior** comes
from **per-key metadata** plus **table hints** and table-level overrides.

---

## Write invalidation (mtime)

Successful writes with table hints call `Cache::touch()` and bump per-table write
timestamps. If any referenced table was written **after** the entry was stored, the
entry is **stale for correctness** — regardless of interval or max age.

Mtime does not replace interval caching; it answers a different question: “Did the data
change because someone wrote to the database?”

---

## Stale-on-error (optional)

When `allowStaleOnError` is enabled, a read that would normally go to the database (e.g.
outside the interval or after a miss) may still return **existing cache** if the
database call fails — bounded by `maxStaleSeconds`.

**Meaning:** Short outages do not take down read paths that can tolerate slightly old
data. This is **opt-in per policy**; not every table or connection should serve stale
data on error.

Normal operation still prefers fresh policy: interval + max age + mtime. Stale-on-error is
the exception when the DB is down but payload still exists.

---

## Transparent read path

Application code never branches on cache state:

```php
$rows = $db->rows($sql, $params, ['users']);
```

`Driver` consults cache when enabled and hints are present; on miss it executes PDO and
`Cache::put()` stores payload + metadata.

---

## Anti-goals

Cache must not introduce:

* explicit cache calls in repositories on the read path
* scope objects
* alternate read paths that bypass `Driver`
* SQL parsing for table detection (hints and builders supply tables)
* cache-owned SQL execution

---

## Implementation status

| Capability | Design | v1 runtime |
| ---------- | ------ | ---------- |
| Metadata-first read | Yes | **Yes** |
| Table-mtime invalidation | Yes | **Yes** |
| Interval caching (`minIntervalSeconds`) | Yes | **Not wired** |
| Max age (`ttlSeconds`) | Yes | **Not wired** |
| Stale-on-error | Yes | **Not wired** |
| Layered policy resolution | Yes | **Partial** (`disable` per table only) |

v1 is **not broken**: mtime-based transparent caching is tested (`tests/Cache/`). The
policy layers above are the **target** documented in [spec.md](spec.md) and
[config/cache-config.json](../config/cache-config.json); implementing them is remaining
product work, not a fix to a non-functional cache.
