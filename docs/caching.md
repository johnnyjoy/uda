# UDA Caching

**Purpose:** Transparent caching support: when the connection config enables a `cache` block, the default read helpers (`$driver->row`, `$driver->rows`, `select()->rows()`) automatically run through a cached scope that honors the configured policy. The cache stack is completely skipped when caching is disabled or the scope resolves to `ttlSeconds <= 0`.

Caching is **opt-in via configuration**, but it is **implicit at the API level**: you can keep calling `$driver->row` and the cache will be used whenever allowed. **Driver::cache(...)** still exists for per-call overrides (custom TTL/policy/namespace/tables) and for targeting multiple tables at once. Always call `Driver::touchTables(...)` after raw writes so cached rows become stale (fluent write helpers already do this).

## Shared store (process-wide)

* Configure the cache store via the top-level `cache` block in config or override it through `Database::connect(..., $options)`.
* The shared **Infra** (store, serializer, tracker, key builder) is built once per process and passed to drivers. Each driver keeps a single entrypoint (`UDA\Cache`) created via `Cache::forDriver()` when the driver binds to a connection.

## Store backends

Three `CacheStore` implementations live under `UDA\Cache\Store`:

| Backend | Class | Extension | Notes |
|--------|--------|-----------|--------|
| Redis | `Redis` | ext-redis | Prefix optional (default `UDA:`). Throws if ext-redis missing. |
| Memcached | `Memcached` | ext-memcached | Prefix optional (default `UDA:`). TTL > 30 days uses absolute expiry. |
| Process-local | `ArrayStore` | none | In-memory, TTL with oldest-first eviction. Single process only. |

Configure via the top-level `cache` section or pass a fully built `Infra` instance into `Driver::fromConfig(..., $infra)`. See [configuration.md](configuration.md).

## Public API (explicit cache scope)

Driver read helpers already run through a cache scope when the connection enables caching. You only need to call `Driver::cache(...)` when you require per-call overrides (custom TTL/policy/namespace or explicit `tables`). The method accepts an `int` (TTL), an `array` (policy + optional `tables`/`namespace`), a `Policy`, a `Hint`, or `null` (links to the connection/global default). Named arguments `tables` and `namespace` are also supported.

```php
$driver = Database::connect('clientA');

$rows = $driver->cache(300)->rows('SELECT * FROM events WHERE id = :id', ['id' => 1]);
$rows = $driver->cache(['ttlSeconds' => 60, 'tables' => ['events'], 'namespace' => 'tenant1'])->rows('SELECT * FROM events', []);
$rows = $driver->cache(null)->rows('SELECT * FROM events', []); // uses connection default
```

## Goals

1. **Result cache** — `row` / `rows` executed through a scope hit cache when populated.
2. **Interval gating** — repeated requests within `minIntervalSeconds` serve cached data (no DB hit).
3. **Stale-on-error** — on transient DB failures, clients may serve stale data when allowed (`allowStaleOnError`, `maxStaleSeconds`).

## TTL (mandatory)

* Every cache entry **must** have a TTL; infinite TTL is forbidden.
* Resolution order: (1) per-query `Policy->ttlSeconds`, (2) per-connection default (`cache.defaultPolicy`), (3) global default (`Scope::DEFAULT_CACHE_TTL_SECONDS`).
* `ttlSeconds <= 0` disables caching for that scope call.

## Key scheme

```
UDA|{serializer_id}|v{format_version}|{connection_name}|{tables_segment}|{query_hash}
```

* Segments split by `|`; tables inside `tables_segment` use `+`.
* **Tables segment:** normalized, sorted. When length > `MAX_TABLES_SEGMENT_LENGTH`, use `t:{sha256(tablesJoined)}`.
* **query_hash:** `sha256(normalized_sql + "\n" + stable_param_encoding)`.
* `serializer_id` and `format_version` live in the key so format evolution stays safe.

## Serializer

* `getSerializer()` prefers igbinary (if available) else falls back to PHP `serialize()`.
* Serializer `id()` is baked into the key so serializers never collide.

## Table write invalidation

* `TableWriteTracker::touch(connectionName, table)` and `lastTouched(connectionName, table): ?int` track mutations.
* Fluent write helpers (`insert`, `update`, `delete`, `upsert`) touch their tables automatically after executing successfully.
* After raw SQL writes, call `$driver->touchTables(['table1', 'table2'])` so cached reads know the tables are stale.
* A cached entry is stale if any involved table’s `lastTouched` timestamp ≥ the cached `createdAt`.

## Config-driven defaults and per-table rules

Connections can specify cache defaults and per-table overrides:

```json
{
  "connections": {
    "mem": {
      "driver": "sqlite",
      "params": {"path": ":memory:"},
      "cache": {
        "defaultPolicy": {
          "ttlSeconds": 60,
          "minIntervalSeconds": 5,
          "allowStaleOnError": false,
          "maxStaleSeconds": 0
        },
        "namespace": "app1",
        "tables": {
          "users": {"disable": false, "ttlSeconds": 30},
          "audit_log": {"disable": true}
        }
      }
    }
  }
}
```

* `defaultPolicy` applies when `cache(null)` is called or no per-call policy exists. `ttlSeconds` must be > 0.
* `tables` maps table names to `{disable, ttlSeconds, minIntervalSeconds, allowStaleOnError, maxStaleSeconds}`.
* Table rules merge strictly: if any table is `disable: true`, caching is disabled. Otherwise `ttlSeconds` = min, `minIntervalSeconds` = max, `allowStaleOnError` = AND, `maxStaleSeconds` = min. Table names are normalized to lowercase.

## Performance: lastServedAt persistence

* **TTL-only** reads do not update the store on hits; only initial writes and refills update the cache.
* **Interval gating** (`minIntervalSeconds > 0`) updates the store at most once per interval boundary to minimize write amplification.

## Stale-on-error

* On cache miss/stale, Driver reads the DB. If a transient exception occurs, `allowStaleOnError` and `maxStaleSeconds` control whether stale data is returned.
* `Driver::isTransient(QueryException)` identifies recoverable errors (disconnects, deadlocks); engine drivers can override it.

## Config (reserved)

Reserved for future options: connection-level overrides for `serializer`, `formatVersion`, or `maxTablesSegmentLength`. Currently only `defaultPolicy`, `tables`, and `namespace` are used.
