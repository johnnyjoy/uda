# UDA Caching

Caching is transparent and configuration-driven on the **read path**. Application
code does not opt in to caching for ordinary queries. **Ops-only** exceptions:
`Database::flushCache()` and `Cache::flush()` for deploy or incident purge — see
[Flush (ops)](#flush-ops) below.

**Design intent:** [cache-doctrine.md](cache-doctrine.md) — **interval caching** (use cache
when available; cap DB reads with `minIntervalSeconds`), **max age** (`ttlSeconds`),
**layered policy** (connection + table + per-query cache keys via metadata), **mtime**
invalidation on writes, and optional **stale-on-error** when the DB is down.

**v1 today:** metadata-first reads, mtime invalidation, and backend key expiry work.
Interval, max-age policy, and stale-on-error are **specified but not yet enforced in code**.

## Runtime Path

```text
Repository -> Database -> Driver -> Cache metadata decision
                                      | hit  -> result array
                                      | miss -> PDO through Driver hot path
```

If caching is disabled, the path is:

```text
Repository -> Database -> Driver -> PDO
```

## Configuration

Cache is enabled per connection:

```json
{
  "connections": {
    "app": {
      "driver": "sqlite",
      "params": {"path": "/tmp/app.sqlite"},
      "cache": {
        "namespace": "APP",
        "store": {"type": "array"},
        "tables": {
          "audit_log": {"disable": true}
        }
      }
    }
  }
}
```

Supported `store.type` values are `array`, `redis`, `memcached`, and `off`.

## Table Hints

Raw SQL callers provide table hints when they want cache attribution:

```php
$rows = $db->rows(
    'SELECT id, name FROM users WHERE active = :active',
    ['active' => 1],
    ['users']
);
```

Builders carry their own table metadata when they compile SQL.

### Fail loud (optional)

Set `require_table_hints: true` on the connection cache block to reject hintless
raw SQL reads when the cache store is enabled. Use in production when every
cached read must participate in table-mtime invalidation. Builders are unaffected.

---

Cache reads must inspect metadata before reading payload:

1. compute the cache key from SQL and named parameters
2. read metadata key
3. compare entry creation time with table write timestamps
4. read payload only if metadata is usable

This prevents unnecessary payload deserialization and keeps invalidation tied to
successful writes.

## Write Touch

`Driver` calls `Cache::touch()` only after successful DML with affected rows.
The touch is keyed by connection name and table name so multiple connections of
the same backend stay isolated.

## TTL and freshness — full map

UDA uses several **different time-based ideas**. They are not interchangeable. This section
lists every place “TTL” appears, what it would mean, and what v1 actually does.

### At a glance

| # | Name | Where you set it | What it controls | v1 runtime |
| - | ---- | ---------------- | ---------------- | ---------- |
| A | **Table-mtime invalidation** | Not a TTL — automatic when writes use table hints | “Cache entry older than last write to any referenced table” | **Active** — primary correctness rule |
| B | **Backend key expiry** | Not in `uda.json` — hard-coded in `Cache::putData()` / `putMetadata()` | “Drop this Redis/Memcached/array key after N seconds” | **Active** — fixed **3600s** on payload + metadata |
| C | **Table-mtime key storage** | `Cache::touch()` | Mtime keys (`t:…`) | **Active** — stored with **TTL 0** (no store expiry) |
| D | **Policy `ttlSeconds`** | `cache.defaultPolicy` or `cache.tables.<table>` (sample schema) | “Treat entry as stale after N seconds” (application freshness) | **Not wired** |
| E | **Policy `minIntervalSeconds`** | Same policy blocks | “TTL-as-interval” — minimum time between real DB reads (see constitution) | **Not wired** |
| F | **Policy `allowStaleOnError` + `maxStaleSeconds`** | Same policy blocks | Serve aged cache when the database errors, up to a cap | **Not wired** |
| G | **`store.timeout`** | `connections.<name>.cache.store.timeout` | Redis **TCP connect** timeout | **Active** — not entry TTL |

---

### A. Table-mtime invalidation (not a TTL, but the main v1 rule)

**Meaning:** A cached read is valid only while every table listed in metadata still has
`tableMtime ≤ metadata['ctime']`. A successful `INSERT` / `UPDATE` / `DELETE` with hints
calls `Cache::touch()` and bumps mtimes.

**Where:** Every cached read path — `Driver::cacheHit()` → `Cache::read()` → `isStale()`.

**Different from TTL because:** It is **event-driven** (writes), not “expires after N seconds.”
A row can sit unchanged forever and the cache stays valid until something writes to a hinted table.

---

### B. Backend key expiry (store TTL)

**Meaning:** How long the **cache backend** keeps the raw key before evicting it. This is
Redis `EX`, Memcached expiration, or the in-process `array` store’s `expires` field.

**Where it is applied today:**

```text
Cache::put()
  → putData()      → store(..., payloadKey, ..., 3600)
  → putMetadata()  → store(..., 'm:' . key, ..., 3600)

Cache::touch()
  → store(..., tableMtimeKey(...), ..., 0)   // mtime keys: no store expiry
```

Implementation: `Cache::store()` → `$client->set($key, $value, $ttl)` when `$ttl > 0`.

**Different from policy TTL because:**

| | Backend key expiry | Policy `ttlSeconds` (deferred) |
| - | ------------------ | ------------------------------ |
| **Purpose** | Garbage-collect keys in Redis/Memcached/memory | Business rule: “don’t trust this result after N seconds” |
| **Honoured on read?** | Only if the key still exists; v1 **still** checks mtime first | Would be evaluated in metadata decision **before** payload |
| **Configurable?** | No in v1 (constant 3600) | Intended: per connection / per table in `uda.json` |
| **Affects mtime keys?** | No (`ttl = 0`) | N/A |

If backend TTL fires but mtime says fresh, you get a **miss** (re-query). If mtime says stale
but the key was evicted, you also get a miss. Backend TTL does **not** substitute for write
invalidation.

---

### C–F. Policy block (config places — deferred in v1)

Sample shape: [`config/cache-config.json`](../config/cache-config.json). **Not read by
`Config` or `Cache` today.** Documented target behavior is in [spec.md § 10](spec.md#10-deferred-cache-policy), [contract.md](contract.md), and [constitution.md](constitution.md) §9.

**Where you would configure them (when implemented):**

```json
{
  "connections": {
    "app": {
      "cache": {
        "defaultPolicy": {
          "ttlSeconds": 300,
          "minIntervalSeconds": 60,
          "allowStaleOnError": true,
          "maxStaleSeconds": 600
        },
        "namespace": "APP",
        "store": { "type": "redis", "host": "redis" },
        "tables": {
          "users": { "ttlSeconds": 120 },
          "audit_log": { "disable": true }
        }
      }
    }
  }
}
```

| Field | Level | Intended meaning (vs other TTLs) |
| ----- | ----- | -------------------------------- |
| **`ttlSeconds`** | `defaultPolicy` or per-table override | **Application max age** — entry becomes unusable after N seconds even if no write touched mtimes. Complements mtime; does not replace it. |
| **`minIntervalSeconds`** | Policy | **TTL-as-interval** — do not re-hit the database more often than every N seconds (rate-limit freshness). Different from `ttlSeconds`: interval caps *fetch frequency*, not only “data is old.” |
| **`allowStaleOnError`** | Policy | On DB failure, may return last good cache instead of erroring. |
| **`maxStaleSeconds`** | Policy | Upper bound on how old a stale-on-error entry may be. |
| **`disable`** | Per table | Opt table out of caching (`Config::hasCache()` already supports this in v1). |

**Read-path order (target — from product contract):** metadata → evaluate policy TTL → evaluate mtime → decide → payload. v1 skips the policy TTL step.

**Stale-on-error (F)** is not “TTL” in the same sense: it is **availability** policy when the
DB is down, governed by `allowStaleOnError` / `maxStaleSeconds`, not routine freshness.

---

### G. `store.timeout` (easy to confuse with TTL)

**Where:** `connections.<name>.cache.store.timeout` → `Config::cacheTimeout()` → Redis
`connect($host, $port, $timeout)`.

**Meaning:** Seconds to wait when **opening** the Redis socket. Has nothing to do with how
long cached query results live.

---

### What you can use in v1 (practical)

| Goal | Use today |
| ---- | --------- |
| Correctness after writes | Table hints on reads/writes; rely on mtime invalidation |
| Disable cache for a table | `cache.tables.<name>.disable: true` |
| Disable cache entirely | `store.type: "off"` or omit cache block |
| Force purge | `Database::flushCache()` |
| Per-query max age in seconds | **Not available** — do not set `ttlSeconds` expecting it to work |
| Cap how often you hit DB | **Not available** — `minIntervalSeconds` not wired |
| Stale data when DB is down | **Not available** — stale-on-error not wired |

v1 freshness rule in one line: metadata exists, payload exists, and
`tableMtime(table) ≤ metadata['ctime']` for every hinted table. See [cache-doctrine.md](cache-doctrine.md).

### Layering: connection default 30s vs table 300s (when policy TTL exists)

**v1 today:** Nothing happens — neither value is read. Only mtime + backend 3600s apply.

**There is no per-query TTL** in UDA config. You cannot set `ttlSeconds` on a single SQL
string or `Sql::of()` template. Policy applies at:

1. `connections.<name>.cache.defaultPolicy` — connection-wide default
2. `connections.<name>.cache.tables.<table>` — override for any read/write that **hints that table**

So “connection TTL 30” means `defaultPolicy.ttlSeconds: 30`. “Query TTL 300” only makes sense
as **a table rule** (e.g. `tables.slow_report: { "ttlSeconds": 300 }`) for queries that hint
`slow_report`, not as a property of the query text itself.

**Intended merge when policy TTL is implemented** (per spec “TTL layering”; exact precedence
should be locked in tests when the feature lands):

| Scenario | Effective application TTL |
| -------- | ------------------------- |
| Query hints only `slow_report`; table has `ttlSeconds: 300`, default is `30` | **300** — table override wins for that table |
| Query hints `users` + `orders`; `users` has `300`, `orders` uses default `30` | **30** — **strictest (shortest) TTL across all hinted tables** |
| Query hints one table; only default `30` | **30** |
| Same entry, also fails mtime check | **Stale** — mtime invalidation still applies; policy TTL does not weaken write correctness |

```text
age = now - metadata['ctime']

For each table T in metadata['tables']:
  limit[T] = tables[T].ttlSeconds ?? defaultPolicy.ttlSeconds

If any limit[T] is set and age > limit[T]  →  stale (policy)
If any tableMtime[T] > metadata['ctime']   →  stale (mtime)
Otherwise                                   →  usable (then fetch payload)
```

**Example:** `defaultPolicy.ttlSeconds = 30`, `tables.archive.ttlSeconds = 300`.

- `SELECT … FROM archive` with hint `['archive']` → may stay cached up to **300s** without a write.
- `SELECT … FROM users` with hint `['users']` → max **30s**.
- `SELECT … FROM archive a JOIN users u` with hints `['archive','users']` → max **30s** (join inherits the stricter bound).

`minIntervalSeconds` layers separately: it can throttle **how often** you query the DB even
when policy TTL and mtime would still allow a cached hit. Stale-on-error is orthogonal (DB down).

## Boundaries

Cache must not:

* execute SQL
* expose public cache handles
* parse SQL for table names
* require repository code to branch on cache state

## Process-local reset

`UDA\Cache::clear()` drops in-process static client and namespace maps for the
current PHP process. It does **not** purge Redis, Memcached, or other remote
payload/metadata keys. Normal application flows do not call it; it exists for
test isolation, long-lived workers, or similar controlled reset of client reuse
state.

## Flush (ops)

`UDA\Cache::flush($connectionName)` and `Database::flushCache()` delete cached
payload, metadata (`m:…`), and table-mtime (`t:…`) keys for one configured
connection. Use after deploys or during incidents when automatic invalidation is
not enough.

| Store | Behavior |
| ----- | -------- |
| `array` | Deletes matching in-process keys |
| `redis` | `SCAN` + delete by namespace/connection prefix (not `FLUSHDB`) |
| `memcached` | Deletes keys when `getAllKeys()` is available; otherwise throws `NotSupportedException` |
| `off` | No-op |

`flush()` is not part of the transparent read path. Repository code should not
branch on cache state for ordinary queries.

Do not confuse **`flush()`** (remote keys) with **`clear()`** (process-local client maps).
