# UDA Public API

One clear way to do common database operations. Cross-DB, migration-friendly, no ceremony.

**Purpose:** Public API surface: connect, raw SQL (named params), fluent builders, Param, safe fragments; no Identifier/Driver in public API.

**Entry point:** `Database::connect(?string $name = null, ?string $configFile = null)` — returns a bound **Driver**. That Driver is the **only** SQL execution domain: use it for raw SQL (row, rows, value, values, list, each, exec, transaction) and for fluent builders (select(), insert(), update(), delete(), upsert()). There are **no Connection objects**; "connection" in config is just the name used to look up a validated connection config (array). Driver selection is via `Driver::fromName($def['driver'])`; no engine branching outside Driver and `src/UDA/Driver/*`.

---

## 1. Raw SQL API (named parameters only)

All raw SQL uses **named parameters** only. Example: `WHERE id = :id` with `['id' => 1]`. Positional `?` is not part of the public API.

| Method | Description |
|--------|-------------|
| `row(string|Sql $sql, array $params = []): ?array` | Run query; at most one row or null; throws if >1 row. |
| `rows(string|Sql $sql, array $params = []): array` | Run query; return all rows (buffered). |
| `value(string|Sql $sql, array $params = []): mixed` | Single column, at most one row; null if 0 rows; throws if >1 row or >1 column. |
| `values(string|Sql $sql, array $params = []): array` | Single column across rows; [] if 0 rows. |
| `list(string|Sql $sql, array $params = []): array` | Alias of values(). |
| `each(string|Sql $sql, array|callable $params, callable $fn = null): int` | Stream rows to callable; returns row count. |
| `exec(string|Sql $sql, array $params = []): int` | Run INSERT/UPDATE/DELETE; return rows affected. |
| `transaction(callable $fn): mixed` | Run callback in a transaction; receives the Driver; commits or rolls back; supports nesting. |
| `lastSql(): ?string` | Last executed SQL string (debugging). |
| `lastParams(): array` | Last bound parameters (debugging). |

**Example**

```php
$driver = Database::connect();

$row = $driver->row('SELECT * FROM users WHERE id = :id', ['id' => 1]);
$rows = $driver->rows('SELECT * FROM users WHERE ctime > :since', ['since' => '2026-01-01']);

$driver->each('SELECT id, name FROM users', [], function (array $row): void {
    // process each row
});

$affected = $driver->exec('INSERT INTO logs (msg) VALUES (:msg)', ['msg' => 'done']);
$affected = $driver->exec('UPDATE users SET name = :name WHERE id = :id', ['name' => 'Jane', 'id' => 1]);

$driver->transaction(function ($driver): void {
    $driver->exec('INSERT INTO orders (user_id) VALUES (:uid)', ['uid' => 42]);
    $driver->exec('UPDATE users SET last_order_at = :t', ['t' => date('c')]);
});
// lastSql() / lastParams() available on the Driver for debugging.
```

Results are **associative arrays** (e.g. `$row['id']`). Business code never touches PDO.

---

## 2. Fluent queries (driver-bound, execute directly)

Query objects are created from the **Driver** returned by `Database::connect()`. Call `$driver->select()`, `$driver->insert()`, etc. They validate and quote identifiers internally (strings only).

| Entry | Returns | Execution methods |
|-------|---------|--------------------|
| `$driver->select()` | SelectQuery | `row()`, `rows()`, `value()`, `values()`, `list()`, `count(?expr)`, `each(callable)`, `toSql()` (debug) |
| `$driver->insert()` | InsertQuery | `exec()`, `executeReturning()` (where supported) |
| `$driver->update()` | UpdateQuery | `exec()` |
| `$driver->delete()` | DeleteQuery | `exec()` |
| `$driver->upsert()` | UpsertQuery | `exec()` |

**SelectQuery:** `from(table, ?alias)`, `join(...)`, `select(...columns)`, `where(column, value)`, `whereColumn(left, right)`, `groupBy(...)`, `having(column, value)`, `orderBy(column, direction)`, `limit(n)`, `offset(n)`, then `row()`, `rows()`, `value()`, `values()`, `list()`, `count(?expr)`, `each(fn)`, or `toSql()`.

**InsertQuery:** `into(table)`, `set(column, value)` (chain), then `exec()` or `executeReturning()`.

**UpdateQuery:** `table(name)`, `set(column, value)`, `where(column, value)`, then `exec()`.

**DeleteQuery:** `table(name)`, `where(column, value)`, then `exec()`.

**UpsertQuery:** `into(table)`, `values(row)`, `key(cols)`, `update(cols)` or `doNothing()`, then `exec()`.

**Example** (Driver from `Database::connect()` does everything)

```php
$driver = Database::connect();

$row = $driver->select()->from('users')->where('id', 1)->row();
$rows = $driver->select()->from('users')->orderBy('name', 'ASC')->limit(10)->rows();
$driver->select()->from('users')->each(fn (array $r) => print_r($r));

$driver->insert()->into('users')->set('name', $n)->set('email', $e)->exec();
$id = $driver->insert()->into('users')->set('name', $n)->executeReturning(); // when supported

$driver->update()->table('users')->set('name', $n)->where('id', $id)->exec();
$driver->delete()->table('users')->where('id', $id)->exec();
```

`toSql()` returns the parameterized SQL (and params) for debugging/logging only; execution uses `row()`, `rows()`, `each()`, or `exec()`.

---

## 3. Massive queries (raw SQL + safe fragments)

For CTEs, window functions, engine-specific SQL: keep the query as raw SQL (e.g. heredoc) and use **safe fragment helpers** for the dangerous dynamic parts.

| Helper | Purpose |
|--------|---------|
| `$driver->q(string $identifier): string` | Validate and quote identifier (table/column). |
| `$driver->orderByAllowed(string $col, array $allowlist, string $dir = 'ASC'): string` | ORDER BY clause with allowlist. |
| `$driver->limitOffset(int $limit, int $offset): SqlFragment` | LIMIT/OFFSET fragment (driver-specific). |
| `$driver->inList(array $values, string $hint = 'p'): SqlFragment` | Placeholders and params for IN (...); empty → 1=0. |

**Pattern:** Keep the big query as a string; splice in ORDER BY and pagination via helpers; keep all values in named parameters.

**Example (skeleton)**

```php
$driver = Database::connect();
$allowlist = ['name' => true, 'created_at' => true];
$orderCol = 'name'; // from request, validated against allowlist
$dir = 'DESC';
$limit = 20;
$offset = 0;

$orderClause = $driver->orderByAllowed($orderCol, $allowlist, $dir);
$page = $driver->limitOffset($limit, $offset);

$sql = <<<SQL
WITH ranked AS (
  SELECT id, name, ROW_NUMBER() OVER (ORDER BY created_at) AS rn
  FROM users
  WHERE status = :status
)
SELECT * FROM ranked
SQL;
$sql .= ' ' . $orderClause . ' ' . $page->sql;

$params = ['status' => 'active'];
$rows = $driver->rows($sql, $params);
```

Use `$driver->q($identifier)` when building table/column names from variables. Use `$driver->inList($ids, 'id')` to build `IN (:id_0, :id_1, ...)` and merge `$frag->params` into your params.

---

## 4. Debug / inspection

- **`$driver->lastSql(): ?string`** — Last executed SQL string. For logging/debugging.
- **`$driver->lastParams(): array`** — Last bound parameters.
- **`$query->toSql()`** on a fluent query — Returns the built SQL (and params) without executing.

---

## 4.1 Optional result caching (transparent + explicit scopes)

When a connection specifies a `cache` section, the default read helpers (`$driver->row`, `$driver->rows`, `select()->rows()`) automatically build a cache scope that respects the connection defaults, table rules, TTL, and min-interval settings. You do not need to call `Driver::cache()` to use caching—just read through the normal API and the cache will be consulted whenever the scope resolves to a TTL > 0. Use **Driver::cache(...)** only when you need to override TTL/policy/namespace or pass custom `tables`/`hint` data for a single fetch. See [caching.md](caching.md) for the policy/key/invalidations details.

**Ergonomic API:** `cache()` accepts TTL (int), policy array, `Policy`, `Hint`, or `null` (connection/global default). Optional `tables` and `namespace` improve invalidation and key isolation; **tables are recommended** in repositories for write invalidation.

```php
use UDA\Cache\CacheDefaults;

$driver = Database::connect();
CacheDefaults::setStore($yourStore);

// TTL only (simplest)
$rows = $conn->cache(300)->rows('SELECT * FROM users WHERE active = :a', ['a' => 1]);

// Policy array + optional tables/namespace
$rows = $conn->cache([
    'ttlSeconds' => 60,
    'minIntervalSeconds' => 5,
    'allowStaleOnError' => true,
    'maxStaleSeconds' => 3600,
    'tables' => ['users'],
    'namespace' => 'tenantA',
])->rows('SELECT * FROM users', []);

// Named params: tables/namespace without full array
$rows = $conn->cache(60, tables: ['users'])->rows('SELECT * FROM users', []);

// Connection default when configured in config (cache(null))
$rows = $conn->cache(null)->rows('SELECT * FROM users', []);

// Backward compatible: Hint still works
$rows = $conn->cache(new Hint(new Policy(60), ['users'], null))->rows('SELECT * FROM users', []);

// Uncached (always hits DB)
$rows = $driver->rows('SELECT * FROM users', []);
```

---

## 5. Exceptions

| Exception | When |
|-----------|------|
| `ConfigException` | Missing/invalid config, missing `UDA_CONFIG`. |
| `ConnectionException` | Cannot connect. |
| `QueryException` | Execution failure; includes SQLSTATE and sanitized SQL snippet; never includes secrets. |

---

## 6. API stability rules

- **Named parameters only** in raw SQL (public API and docs).
- **No public Identifier objects** — identifiers are strings; quoting is internal.
- **Query objects are driver-bound** — created only via `$driver->select()`, `$driver->insert()`, etc.
- **Raw SQL is first-class** — use `row` / `rows` / `each` / `exec` for complex or engine-specific SQL.
- **Safe fragments** exist for dynamic ORDER BY, LIMIT/OFFSET, IN lists, and identifier quoting.

---

## 7. Cross-DB contract

- **Common operations** behave the same across engines: parameter binding (named), result shape (associative arrays), `each()` streaming, transaction API, pagination and order-by helpers.
- **Differences** (e.g. LIMIT/OFFSET vs OFFSET/FETCH) are handled by the driver.
- **Engine-specific features** (e.g. `executeReturning()`) are opt-in and fail clearly when unsupported.

---

## 8. Advanced: typed parameters (transport-level)

UDA does not implement a schema type system. For transport correctness (LOB, JSON, UUID, datetime), an optional **Param** wrapper can be used so the driver binds values with the correct type and optional cast.

- `Param::binary(string|resource)` — BLOB.
- `Param::json(mixed)` — JSON-encoded string.
- `Param::uuid(string)` — UUID (format validation).
- `Param::dateTimeImmutable(DateTimeImmutable)` — ISO string.

Normal scalars remain the default; Param is for edge cases only.
