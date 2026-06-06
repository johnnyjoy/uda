---
name: uda-queries
description: >-
  Execute SQL correctly through UDA: named parameters, choosing the right
  terminator for the expected result shape, table hints for cache invalidation,
  transactions, error handling (QueryException), streaming large results with
  each(), and when to use flushCache. Use when writing queries, debugging stale
  cache results, or reviewing SQL code for correctness.
---

# UDA: SQL execution

## Named parameters — non-negotiable

```php
// Correct — always:
$db->row('SELECT * FROM orders WHERE id = :id', ['id' => $orderId], ['orders']);

// Rejected before PDO — rewrite any pasted SQL that uses ?:
$db->row('SELECT * FROM orders WHERE id = ?', [$orderId]);   // WRONG
```

Positional `?` is rejected by UDA at the query layer. Named params drive cache key
generation, observer logging, and cross-engine compatibility.

## Terminators — pick the one that matches what you expect

| Method | Returns | On empty result |
|---|---|---|
| `rows()` | `list<array>` — all rows | `[]` |
| `row()` | `?array` — exactly one row | `null` |
| `value()` | `mixed` — first column of first row | `null` |
| `values()` | `list` — first column of every row | `[]` |
| `list()` | `?array` — first row, numerically indexed (`[0 => v1, 1 => v2]`) | `null` |
| `exec()` | `int` — rows affected | `0` |
| `each($fn)` | `int` — rows processed | `0` |

Use `row()` not `rows()` when at most one row is expected — it signals intent and saves a needless loop.

**`each()` streams rows one-by-one to your callback** — use it for large result sets to avoid loading everything into memory:

```php
$count = $db->each(
    'SELECT id, payload FROM events WHERE processed = 0',
    [],
    ['events'],
    function (array $row): void {
        process($row['payload']);
    }
);
```

`each()` is not a logging hook — that's what `setQueryObserver()` is for.

**All terminators throw `UDA\Exception\QueryException` on failure.** Catch it explicitly when you need to distinguish a database error from other application exceptions:

```php
try {
    $db->exec('INSERT INTO users ...', [...], ['users']);
} catch (\UDA\Exception\QueryException $e) {
    // $e->getMessage() contains the driver error
    log_error($e->getMessage());
    throw new AppException('Could not create user', 0, $e);
}
```

## Table hints

Third argument to raw-SQL terminators: the table names the query reads or writes.

```php
// Read — UDA checks cache before executing and stores the result keyed to these tables:
$db->rows('SELECT * FROM products WHERE active = 1', [], ['products']);

// Write — UDA invalidates cache for these tables after a successful exec:
$db->exec(
    'UPDATE products SET price = :p WHERE id = :id',
    ['p' => $price, 'id' => $id],
    ['products']
);
```

**What happens when you omit hints on a caching-enabled connection:**
- Reads: the result is not cached (no silent corruption, just no cache benefit).
- Writes: cache for the affected tables is not invalidated — callers see stale data.

Query builders (`$db->select()`, `$db->update()`, etc.) derive hints from `from()` / `into()`
automatically. Hints are optional on builder terminators.

## Builders

```php
$rows = $db->select('id', 'name')
    ->from('users')
    ->where('active', 1)
    ->orderBy('name')
    ->rows();

$affected = $db->update('users')
    ->set('name', ':name')
    ->where('id', ':id')
    ->exec(['name' => 'Ada', 'id' => 1]);
```

`toSql()` returns the compiled SQL string for debug/logging only — it does not execute.

## Transactions

```php
$db->transaction(function (Database $db): void {
    $db->exec('INSERT INTO orders (user_id) VALUES (:uid)', ['uid' => $uid], ['orders']);
    $db->exec('UPDATE inventory SET qty = qty - 1 WHERE id = :id', ['id' => $id], ['inventory']);
});
```

Callback receives the same `Database` instance. If the callback throws, the transaction rolls
back and the exception propagates. On a dropped connection, UDA retries once — **only outside
open transactions**.

## Dynamic SQL helpers

When column names or sort order must be dynamic, use helpers instead of string concatenation:

```php
// Safe IN list — binds values, not interpolation:
$rows = $db->rows(
    'SELECT * FROM products WHERE id IN (' . $db->inList($ids) . ')',
    [],
    ['products']
);

// Allowlist-validated ORDER BY:
$rows = $db->rows(
    'SELECT * FROM logs ORDER BY ' . $db->orderByAllowed($col, ['created_at', 'level']),
    [],
    ['logs']
);
```

`unsafe(string $fragment)` exists for fragments that no helper covers. It emits the string
verbatim — document the justification in code review. User-controlled values must never reach `unsafe()`.

## Cache: flush vs clear

| Situation | API | Effect |
|---|---|---|
| Stale reads after a migration or bad deploy | `$db->flushCache()` | Purges all cache data for this connection from Redis/Memcached |
| Clear in-process handles only (tests) | `Cache::clear()` | Process-local only — does **not** touch Redis or Memcached |
| Suppress caching for a specific table | `cache.tables.<name>.disable: true` in config | Permanent per config change |

## Checklist (every new query)

- [ ] Named params only (`:name`)
- [ ] Terminator matches expected cardinality (`row()` not `rows()` for single rows)
- [ ] Table hints on raw SQL when cache is on
- [ ] Write paths include hints so cache invalidates
- [ ] Large result sets use `each()` to avoid memory issues
- [ ] `QueryException` caught where callers need to distinguish DB errors

## Authority

`docs/public-api.md` (§ terminators, § caching, § builders), `docs/caching.md`.
