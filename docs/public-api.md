# UDA Public API

One clear way to do common database operations. Cross-DB, migration-friendly, no ceremony.

**Purpose:** Define the only public API surface: connect, raw SQL (named params), fluent builders, safe fragments, optional typed parameters.
**Anti-goals:** No `Driver` in userland. No `Identifier` objects. No cache API. No “Connection” objects.

---

## 0. The One Handle Rule

**`UDA\Database` is the database** from the perspective of application code.

* Application code **MUST** treat `Database` as the only ingress and only handle.
* Application code **MUST NOT** reference or depend on `UDA\Driver` or `UDA\Driver\*`.
* There are **no Connection objects** in the public model.

“Connection” means **a config name**, nothing more.

---

## 1. Entry point

### `Database::connect(...)`

`Database::connect()` is the only supported entry into UDA.

* Default path: `UDA_CONFIG` environment variable points to the JSON config file.
* Optional: pass a config file path to connect using a specific config file.
* Optional: pass a connection name to select a non-default connection from that config.

> Config is validated and normalized at ingestion. UDA does not “sanitize on use.”

**Signature (public contract):**

```php
Database::connect(string ...$args): Database
```

**Argument rules (ergonomic + deterministic):**

* If an argument is a JSON file path (ends in `.json` or `is_file($arg)`), it is treated as the config file.
* Otherwise it is treated as the connection name.
* Passing neither uses the default connection from the config.
* Passing only a config file uses that file and its default connection.
* Passing only a connection name uses env config + that connection.
* Passing both uses that config file + that connection.

Examples:

```php
$db = Database::connect();                          // env config + default connection
$db = Database::connect('reporting');               // env config + named connection
$db = Database::connect('/tmp/uda.generated.json'); // file config + default connection
$db = Database::connect('gen_001', '/tmp/uda.generated.json'); // file + named connection
```

---

## 2. Raw SQL API (named parameters only)

Raw SQL is first-class. It must use **named parameters only**.

* ✅ `WHERE id = :id` with `['id' => 1]`
* ❌ positional `?` is forbidden in public API

### Methods

| Method                             | Description                                                             |                                                                               |                                             |
| ---------------------------------- | ----------------------------------------------------------------------- | ----------------------------------------------------------------------------- | ------------------------------------------- |
| `row(string                        | Sql $sql, array $params = []): ?array`                                  | Run query; return at most one row or null; throw if >1 row.                   |                                             |
| `rows(string                       | Sql $sql, array $params = []): array`                                   | Run query; return all rows (buffered).                                        |                                             |
| `value(string                      | Sql $sql, array $params = []): mixed`                                   | Single column; at most one row; null if 0 rows; throw if >1 row or >1 column. |                                             |
| `values(string                     | Sql $sql, array $params = []): array`                                   | First column across all rows; `[]` if 0 rows.                                 |                                             |
| `list(string                       | Sql $sql, array $params = []): array`                                   | Alias of `values()`.                                                          |                                             |
| `each(string                       | Sql $sql, array                                                         | callable $params, callable $fn = null): int`                                  | Stream rows to callable; returns row count. |
| `exec(string                       | Sql $sql, array $params = []): int`                                     | Run INSERT/UPDATE/DELETE; return affected rows.                               |                                             |
| `transaction(callable $fn): mixed` | Run callback in a transaction; receives a `Database`; supports nesting. |                                                                               |                                             |
| `lastSql(): ?string`               | Last executed SQL string (debug).                                       |                                                                               |                                             |
| `lastParams(): array`              | Last bound parameters (debug).                                          |                                                                               |                                             |

### Example

```php
$db = Database::connect();

$row = $db->row('SELECT * FROM users WHERE id = :id', ['id' => 1]);

$rows = $db->rows(
    'SELECT * FROM users WHERE ctime > :since',
    ['since' => '2026-01-01']
);

$db->each('SELECT id, name FROM users', [], function (array $row): void {
    // process each row
});

$affected = $db->exec(
    'INSERT INTO logs (msg) VALUES (:msg)',
    ['msg' => 'done']
);

$db->transaction(function (Database $tx): void {
    $tx->exec('INSERT INTO orders (user_id) VALUES (:uid)', ['uid' => 42]);
    $tx->exec('UPDATE users SET last_order_at = :t', ['t' => date('c')]);
});
```

Results are associative arrays. Business code never touches PDO.

---

## 3. Fluent queries (Database-created, execute via the same path)

Query objects are created from `Database`:

* `$db->select()`
* `$db->insert()`
* `$db->update()`
* `$db->delete()`
* `$db->upsert()`

They compile to SQL + params and **terminate back into the same execution path**.

> Builders never execute directly. Execution always converges through the same internal hot path.

### Builder entrypoints

| Entry           | Returns     | Terminators                                                                                     |
| --------------- | ----------- | ----------------------------------------------------------------------------------------------- |
| `$db->select()` | SelectQuery | `row()`, `rows()`, `value()`, `values()`, `list()`, `count(?expr)`, `each(callable)`, `toSql()` |
| `$db->insert()` | InsertQuery | `exec()`, optional `returning(...)` + `row()`/`value()` where supported                         |
| `$db->update()` | UpdateQuery | `exec()`                                                                                        |
| `$db->delete()` | DeleteQuery | `exec()`                                                                                        |
| `$db->upsert()` | UpsertQuery | `exec()`                                                                                        |

### Example

```php
$db = Database::connect();

$row = $db->select()
    ->from('users')
    ->where('id', 1)
    ->row();

$rows = $db->select()
    ->from('users')
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->rows();

$db->insert()
    ->into('users')
    ->set('name', $name)
    ->set('email', $email)
    ->exec();

$db->update()
    ->table('users')
    ->set('name', $name)
    ->where('id', $id)
    ->exec();

$db->delete()
    ->table('users')
    ->where('id', $id)
    ->exec();
```

`toSql()` exists for debugging/logging; it does not execute.

---

## 4. Massive queries (raw SQL + safe fragments)

For CTEs, window functions, or engine-specific SQL: keep the query as raw SQL and use **safe fragment helpers** for the dangerous dynamic parts.

These helpers exist to prevent developers from “escaping the system” into string concatenation.

### Helpers

| Helper                                                                            | Purpose                                            |
| --------------------------------------------------------------------------------- | -------------------------------------------------- |
| `$db->q(string $identifier): string`                                              | Validate + quote identifier (table/column).        |
| `$db->orderByAllowed(string $col, array $allowlist, string $dir = 'ASC'): string` | Safe ORDER BY clause from allowlist.               |
| `$db->limitOffset(int $limit, int $offset): SqlFragment`                          | LIMIT/OFFSET fragment (engine correct).            |
| `$db->inList(array $values, string $hint = 'p'): SqlFragment`                     | IN-list placeholders + params; empty list → `1=0`. |

> All values still bind through named parameters. These helpers only produce safe SQL structure.

### Example (CTE + safe ordering + pagination)

```php
$db = Database::connect();

$allow = ['name' => true, 'created_at' => true];
$orderCol = 'name';     // from request
$dir      = 'DESC';
$limit    = 20;
$offset   = 0;

$orderClause = $db->orderByAllowed($orderCol, $allow, $dir);
$page        = $db->limitOffset($limit, $offset);

$sql = <<<SQL
WITH ranked AS (
  SELECT id, name,
         ROW_NUMBER() OVER (ORDER BY created_at) AS rn
  FROM users
  WHERE status = :status
)
SELECT *
FROM ranked
SQL;

$sql .= ' ' . $orderClause . ' ' . $page->sql;

$params = ['status' => 'active'] + $page->params;

$rows = $db->rows($sql, $params);
```

### Example (IN list safely)

```php
$db = Database::connect();

$ids = [10, 20, 30];
$in  = $db->inList($ids, 'id');

$sql = 'SELECT * FROM users WHERE id ' . $in->sql;

$rows = $db->rows($sql, $in->params);
```

Empty list becomes a deterministic `WHERE 1=0` behavior, not invalid SQL.

---

## 5. Debug / inspection

* `lastSql(): ?string` — last executed SQL string
* `lastParams(): array` — last bound parameters
* `$query->toSql()` — view SQL+params before execution (debug only)

---

## 6. Caching behavior (transparent)

Caching is **configuration-driven** and **implicit**.

* If caching is enabled for the connection, reads automatically consult cache.
* If disabled, cache code must not run.
* There is no public “cache API” and no explicit cache invocation in userland.

> Cache is not called. Cache happens.

Repository code remains identical whether cache is enabled or not.

---

## 7. Exceptions

| Exception             | When                                                                                  |
| --------------------- | ------------------------------------------------------------------------------------- |
| `ConfigException`     | Missing/invalid config, missing `UDA_CONFIG`, missing connection definition.          |
| `ConnectionException` | Cannot connect.                                                                       |
| `QueryException`      | Execution failure; includes SQLSTATE and a sanitized snippet; never includes secrets. |

---

## 8. Optional typed parameters (transport-level)

UDA does not implement schema typing. For edge cases where bind type matters, a lightweight `Param` wrapper may be used.

Examples (conceptual):

* `Param::binary(...)` — BLOB
* `Param::json(...)` — JSON encoding
* `Param::uuid(...)` — UUID validation
* `Param::dateTimeImmutable(...)` — consistent formatting

Most code should use scalars normally; `Param` exists for correctness at boundaries.

---

## 9. API stability rules

* Named parameters only (public API).
* Database is the only user handle.
* No public Driver / Identifier / Connection concepts.
* One execution path, always.
* Cache remains transparent.
* Helpers exist to keep dynamic SQL safe without framework sprawl.
