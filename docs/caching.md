# UDA Caching

Caching is transparent and configuration-driven on the **read path**. Application
code does not opt in to caching for ordinary queries. **Ops-only** exceptions:
`Database::flushCache()` and `Cache::flush()` for deploy or incident purge — see
[Flush (ops)](#flush-ops) below.

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

## Metadata-First Rule

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
