---
name: uda-sql-and-cache
description: >-
  Execute SQL through UDA with correct terminators, named parameters, table hints,
  and cache semantics. Use when writing queries, debugging stale cache, or calling
  flushCache. Covers read path (transparent) vs ops flush.
---

# UDA: SQL execution and cache

## Named parameters (non-negotiable)

- Binds are **`:name`** keys in SQL and in the params array.
- Positional `?` is **rejected before PDO** — rewrite pasted SQL.

```php
$db->row('SELECT id FROM users WHERE id = :id', ['id' => $id], ['users']);
```

## Table hints (cache + invalidation)

Third argument: `['table_name', ...]` for raw SQL when caching is enabled.

| If you omit hints | Effect |
|-------------------|--------|
| Read | May not cache correctly or may not invalidate with writes |
| Write with `$affected > 0` | `Cache::touch()` only runs when hints are non-empty |

Builders attach table metadata from `from()` / `into()` — hints optional on builder terminators.

## Terminators (pick one per statement)

| Method | Returns | Empty result |
|--------|---------|--------------|
| `rows()` | `list<array>` | `[]` |
| `row()` | `?array` | `null` |
| `value()` | `mixed` | `null` |
| `values()` | `list` first column | `[]` |
| `list()` | `?array` numeric first row | `null` |
| `exec()` | `int` affected | `0` |
| `each()` | `int` processed | `0` |

Do not use `rows()` when you need at most one row — use `row()` or `value()`.

## Builders

- Entry: `$db->select(...)` / `insert()` / `update()` / `delete()` / `upsert()`.
- Terminators on the builder call the same pipeline as raw SQL.
- `toSql()` is for **debug/logging only** — does not execute.

## Transactions

```php
$db->transaction(function (Database $db): void {
    $db->exec('...', [...], ['users']);
});
```

Dropped connection: one reconnect + retry **outside** open transactions. Mid-transaction failure → rollback (expected).

## Cache: read path vs ops

| Concern | API | Scope |
|---------|-----|--------|
| Normal reads | (none) — automatic when config enables cache | Transparent |
| After bad deploy / emergency | `$db->flushCache()` or `Cache::flush('connectionName')` | Deletes payload + metadata + table mtimes for **that connection** in the store |
| Drop PHP client handles only | `Cache::clear()` | Process-local; **does not** purge Redis/Memcached keys |

`flush()` uses prefix delete on Redis (`SCAN`), not `FLUSHDB`. Memcached flush needs `getAllKeys()` or throws `NotSupportedException`.

## Dynamic SQL (allowed helpers)

Use `Database` helpers (`inList`, `orderByAllowed`, `limitOffset`, etc.) — still bind values via named params.

`unsafe()` exists for exceptional raw fragments — document why in code review.

## Checklist (every new query)

- [ ] Named params only
- [ ] Correct terminator for cardinality
- [ ] Table hints on raw SQL if cache enabled for those tables
- [ ] Write paths pass hints when you need invalidation
- [ ] No `$db->cache(...)` or application-level cache branching

## Authority

`docs/public-api.md` (§ terminators, § caching), `docs/caching.md`, `docs/configuration.md`.
