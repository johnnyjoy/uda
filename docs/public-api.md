# UDA Public API

One clear way to do common database operations. Cross-DB, migration-friendly, no ceremony.

**Purpose:** Define the public API surface: connect, raw SQL (named params), fluent builders, safe fragments, optional typed parameters, and the optional class link.
**Anti-goals:** No `Driver` in userland. No `Identifier` objects. No cache API on the read path. No “Connection” objects.

**Read first:** [building-your-dal.md](building-your-dal.md) (how to structure your layer).
Then §1–§10 here for exact method semantics. Contributors: pipeline detail in
`docs/architecture.md`.

---

## 0. The One Handle Rule

**`UDA\Database` is the database** from the perspective of application code.

* Application code **MUST** treat `Database` as the only ingress and only handle.
* Application code MAY use `UDA\Link` as an optional trait that resolves the
  same `Database` handle.
* Application code **MUST NOT** use a static `Query` entry (removed) or reference
  `UDA\Driver` / `UDA\Driver\*`.
* There are **no Connection objects** in the public model.

“Connection” means **a config name**, nothing more.

---

## 1. Entry point

UDA enters through `Database`. Prefer the **named** connect methods for new code; `connect(string ...$args)` remains for terse call sites.

| Method | Use |
| ------ | --- |
| `connectDefault()` | `UDA_CONFIG` + default connection |
| `connectNamed(string $name)` | `UDA_CONFIG` + named connection |
| `connectWithConfig(string $file, ?string $name = null)` | explicit JSON file; optional connection name |
| `connect(string ...$args)` | same behavior as above; see argument rules |

> Config is validated and normalized at ingestion. UDA does not “sanitize on use.”

**Named methods (preferred):**

```php
$db = Database::connectDefault();
$reporting = Database::connectNamed('reporting');
$generated = Database::connectWithConfig('/tmp/uda.generated.json');
$tenant = Database::connectWithConfig('/tmp/uda.generated.json', 'tenant_001');
```

**Varargs `connect(string ...$args)` (same pooling and config rules):**

```php
Database::connect(string ...$args): Database
```

* If an argument is a JSON file path (ends in `.json` or `is_file($arg)`), it is treated as the config file.
* Otherwise it is treated as the connection name.
* Passing neither uses the default connection from the config.
* Passing only a config file uses that file and its default connection.
* Passing only a connection name uses env config + that connection.
* Passing both uses that config file + that connection (order-independent).

```php
$db = Database::connect();                          // same as connectDefault()
$db = Database::connect('reporting');               // same as connectNamed('reporting')
$db = Database::connect('/tmp/uda.generated.json'); // same as connectWithConfig(file)
$db = Database::connect('gen_001', '/tmp/uda.generated.json'); // same as connectWithConfig(file, name)
```

**Connection pooling and self-healing reconnect:**

`Database::connect()` returns the same instance for the same connection name.
The second call for a known name is a single array lookup — no filesystem syscall,
no config re-read.

The `Driver` instance held by `Database` is permanent. If `prepare()` or `execute()`
fails with a reconnectable connection-lost error, `Driver` calls `reconnect()` and
retries that operation once, then re-runs any init SQL. There is no extra round-trip
on the happy path.

In PHP-FPM the pool resets per request, so there is nothing to configure. In
long-running processes (Swoole, RoadRunner, Octane) the pool persists across
requests and transparent reconnect covers dropped server connections. **Concurrency
rules** (shared handles, transactions, `lastSql()` / `lastParams()`) are documented
in `docs/architecture.md` § Concurrency in long-running workers.

---

## 1.1 Optional Class Link

`UDA\Link` lets an external class keep SQL and database access behind its own
domain methods without creating a second runtime path. A linked class should be
built around one configured connection name.

```php
use UDA\Link;

final class Users
{
    use Link;

    protected static string $connection = 'app';

    public function rename(int $id, string $name): void
    {
        $this->exec(
            'UPDATE users SET name = :name WHERE id = :id',
            ['id' => $id, 'name' => $name],
            ['users']
        );
    }
}
```

`$connection` is `static` because it is a fact about the class, not about any
individual instance. Every instance of `Users` talks to `'app'` — that never
varies. The `Database` handle is memoized once per class, not once per object.

The trait exposes protected methods (`row()`, `rows()`, `value()`, `exec()`,
`transaction()`, and all builder entrypoints) so application classes can behave
just short of extending `Database` while still using the same
`Database -> Driver -> PDO` execution path. It does not expose `Driver`, PDO,
cache, dialect, backend rules, or a public Connection object.

---

## 2. Raw SQL API (named parameters only)

Raw SQL is first-class. It must use **named parameters only**.

* ✅ `WHERE id = :id` with `['id' => 1]`
* ❌ positional `?` is forbidden in public API

### Methods

| Method | Description |
| --- | --- |
| `row(string|SqlMessage|Sql $sql, array $params = [], ?array $tableHints = null): ?array` | Run query; return the **first** row or `null`; constrain SQL (for example `LIMIT 1`) when exactly one row matters. |
| `rows(string|SqlMessage|Sql $sql, array $params = [], ?array $tableHints = null): array` | Run query; return all rows. |
| `value(string|SqlMessage|Sql $sql, array $params = [], ?array $tableHints = null): mixed` | Return one column from at most one row. |
| `values(string|SqlMessage|Sql $sql, array $params = [], ?array $tableHints = null): array` | Return the first column across all rows; no rows returns `[]`. |
| `list(string|SqlMessage|Sql $sql, array $params = [], ?array $tableHints = null): ?array` | Return the first row as a numeric array; no row returns `null`. |
| `each(string|SqlMessage|Sql $sql, array|callable $params, callable $fn = null, ?array $tableHints = null): int` | Stream each row to a callable; returns row count. |
| `exec(string|SqlMessage|Sql $sql, array $params = [], ?array $tableHints = null): int` | Run INSERT/UPDATE/DELETE; return affected rows. |
| `returning(string|SqlMessage|Sql $sql, array $params = [], ?array $tableHints = null): array` | Run DML with RETURNING/OUTPUT metadata and return emitted rows. |
| `transaction(callable $fn): mixed` | Run callback in a transaction; supports nesting. |
| `lastSql(): ?string` | Last executed SQL string for debugging. |
| `lastParams(): array` | Last bound parameters for debugging. |
| `setQueryObserver(?callable $observer): void` | **Static.** Optional ops callback after each execute or read-cache hit (`UDA\Query\Observer`). Null = disabled. See `docs/metrics.md`. |

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

`row()`, `value()`, and `list()` are singular reads and return `null` when no
row exists. `row()` uses a single-row fetch path (not full-result materialization).
`rows()` and `values()` are set reads and return `[]` when no rows exist.
Business code never touches PDO.

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

| Entry           | Returns  | Terminators                                                                                     |
| --------------- | -------- | ----------------------------------------------------------------------------------------------- |
| `$db->select()` | Select   | `row()`, `rows()`, `value()`, `values()`, `list()`, `count(?expr)`, `each(callable)`, `toSql()` |
| `$db->insert()` | Insert   | `exec()`, optional `returning(...)` + `row()`/`value()`/`list()` where supported                |
| `$db->update()` | Update   | `exec()`, optional `returning(...)` + `row()`/`value()`/`list()` where supported                |
| `$db->delete()` | Delete   | `exec()`, optional `returning(...)` + `row()`/`value()`/`list()` where supported                |
| `$db->upsert()` | Upsert   | `exec()`                                                                                        |

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

On **SELECT**, `where()` returns `WhereBuilder`. Read terminators and `toSql()` on that
object call `end()` internally, then forward to `Select` — so `->where(...)->toSql()` and
`->where(...)->rows()` work without a manual `end()`. You still need `end()` before
`orderBy`, `limit`, or `groupBy`, and before `exec()` / `returning()` on update/delete.

### 3.1 What is `UDA\Query` (the abstract base)?

**`UDA\Query` is the shared chassis for fluent query builders** — not a type you use or extend in application code.

| | |
| --- | --- |
| **Full name** | `UDA\Query` (class in namespace `UDA`, file `src/UDA/Query.php`) |
| **Coexists with** | Namespace `UDA\Query\` — `Select`, `Insert`, `Sql`, dialects, … |
| **Extends** | Nothing public — five `final` builders extend it via `extends \UDA\Query` |
| **You obtain builders via** | `$db->select()`, `$db->insert()`, … — never `new Select()` |

**What `UDA\Query` owns (shared infrastructure):**

| Concern | Role |
| ------- | ---- |
| **`$engine`** | Engine key for identifier quoting (`pgsql`, `sqlserver`, …). Set by `Database::bindBuilder()` from the live connection. |
| **`ParamBag`** | Named placeholders (`:q1`, `:q2`, …) so nested/subquery params never collide. |
| **`quote()`** | Delegates to `SQL\Identifier` using `$engine` — the only quoting path from builders. |
| **`bindDatabase()` / `bindDialect()`** | Wires the builder to the connection’s `Database` handle and `Query\Dialect\*` renderer. |
| **Guardrail flags** | Tracks statement type, whether WHERE/LIMIT were used, and `unsafe()` bypass — attached when SQL becomes a `SqlMessage`. |
| **`toSql()`** | Abstract: each concrete builder compiles itself to immutable `Query\Sql`. |
| **Terminators** | `row()`, `exec()`, etc. live on concrete builders; they call `delegateThroughDatabase()` → same `Database → Driver → PDO` path as raw SQL. |

**What `UDA\Query` is not:**

| Confusable | Difference |
| ---------- | ---------- |
| **Static facade (removed)** | Old `UDA\Query::…` ingress was deleted; execution ingress is `Database` only. This class is the abstract builder base, not a static entry point. |
| **`UDA\Driver`** | Executes SQL. `UDA\Query` only *builds* SQL and hands off to `Database`. |
| **`Query\Dialect\SQLite`** (etc.) | Renders engine-specific SQL *text* for the builder. `UDA\Query` holds the dialect reference; concrete builders call into it. |
| **`Query\Sql` vs `SQL\SqlMessage`** | `Query\Sql` = builder output (immutable value). `SQL\SqlMessage` = execution envelope with params + guardrail metadata — built inside `UDA\Query::buildSql()`. |
| **Public API** | Application code never type-hints `UDA\Query`. Use `Select`, `Insert`, … returned from `Database`, or don’t name the type at all. |

**Lifecycle (one connection, one builder):**

```
$db->select()
  → Database::bindBuilder(new Select())
       → bindDatabase($this)
       → $builder->engine = $driver->engineName()
       → bindDialect(queryDialect())
  → ->from('users')->where('id', 1)->row()
       → Select::toSql()  // uses dialect + quote()
       → UDA\Query::delegateThroughDatabase('row', ...)
       → Database::executeBuilder(...) → Driver → PDO
```

Concrete builders live in namespace `UDA\Query\` and **must** extend `\UDA\Query` (leading `\`) so PHP does not resolve the parent as `UDA\Query\Query`. **Do not extend.**

### 3.2 Compiled `Query\Sql` and connection deferral

Builders bind **engine and dialect at creation** (`$db->select()`). Compilation is not cross-engine portable: `toSql()` emits dialect-specific text.

You **may** defer which **named connection** executes that text when both connections use the **same engine** (e.g. primary vs read replica):

```php
$db = Database::connect('app');
$sql = $db->select('id', 'name')
    ->from('users')
    ->where('active', 1)
    ->toSql();

$rows = Database::connect('replica')->rows($sql);
```

Do not execute compiled SQL on a connection whose engine differs from the one that built it. There is no unbound builder or neutral query IR in v1 — only immutable `Query\Sql` after compile.

---

## 4. Massive queries (raw SQL + safe fragments)

For CTEs, window functions, or engine-specific SQL: keep the query as raw SQL and use **safe fragment helpers** for the dangerous dynamic parts.

These helpers exist to prevent developers from “escaping the system” into string concatenation.

### Helpers

| Helper | Purpose |
| --- | --- |
| `$db->q(string $identifier): string` | Validate and quote identifier (table/column). |
| `$db->orderByAllowed(string $col, array $allowlist, string $dir = 'ASC'): string` | Safe ORDER BY clause from allowlist. |
| `$db->limitOffset(int $limit, int $offset): string` | LIMIT/OFFSET fragment rendered for the configured backend. |
| `$db->inList(array $values, string $hint = 'p'): array{0:string,1:array<string,mixed>}` | Complete `IN (...)` SQL fragment and params; empty list returns `1=0` with no params. |

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

$sql .= ' ' . $orderClause . ' ' . $page;

$params = ['status' => 'active'];

$rows = $db->rows($sql, $params);
```

### Example (IN list safely)

```php
$db = Database::connect();

$ids = [10, 20, 30];
[$inSql, $inParams] = $db->inList($ids, 'id');

$sql = 'SELECT * FROM users WHERE id ' . $inSql;

$rows = $db->rows($sql, $inParams);
```

Empty list becomes a deterministic `WHERE 1=0` behavior, not invalid SQL.

---

## 5. Debug / inspection

* `lastSql(): ?string` — last executed SQL string on **this pooled handle**
* `lastParams(): array` — last bound parameters on **this pooled handle**
* `$query->toSql()` — view SQL+params before execution (debug only)

In PHP-FPM, one request owns the process so these usually match "this request."
In long-running workers (Octane, RoadRunner, Swoole), the same handle serves many
concurrent or sequential requests — **do not treat `lastSql()` / `lastParams()` as
request-scoped.** See `docs/architecture.md` § Concurrency in long-running workers.

---

## 6. Caching behavior (transparent)

Caching is **configuration-driven** and **implicit**.

* If caching is enabled for the connection, reads automatically consult cache.
* If disabled, cache code must not run.
* There is no public “cache API” on the read path and no explicit cache invocation in ordinary repository code.

> Cache is not called. Cache happens.

Repository code remains identical whether cache is enabled or not.

**Ops-only flush:** `Database::flushCache()` deletes cached payload, metadata, and table-mtime keys for the current connection's configured store. Use for deploy hooks or incident response — not for normal queries. See `docs/caching.md`.

---

## 7. Exceptions

| Exception             | When                                                                                  |
| --------------------- | ------------------------------------------------------------------------------------- |
| `ConfigException`     | Missing/invalid config, missing `UDA_CONFIG`, missing connection definition.          |
| `ConnectionException` | Cannot connect.                                                                       |
| `QueryException`      | Execution failure, guardrail violation, or unsupported capability.                    |

### `QueryException` fields (API mapping)

| Method / field | Use |
| -------------- | --- |
| `category()`   | Stable bucket: `guardrail`, `connection`, `constraint`, `syntax`, `unsupported`, `execution`, `binding` |
| `sqlState()`   | SQLSTATE when the driver reported one (often via chained `PDOException`) |
| `driverCode()` | Driver-specific code when available |
| `getPrevious()` | Underlying `PDOException` for driver detail in logs |

**Typical HTTP mapping (application layer):**

| Category | Suggested status |
| -------- | ---------------- |
| `guardrail` | 400 — caller fix (positional `?`, missing table hints, invalid shape) |
| `constraint` | 409 — unique/FK violation |
| `connection` | 503 — retry or fail over |
| `syntax` | 500 — log and alert (usually dev error) |
| `unsupported` | 501 or 400 — engine capability |
| `execution` | 500 — inspect logs; use `sqlState()` when present |

Messages never include secrets. Prefer `category()` + `sqlState()` over parsing message text.

---

## 8. Typed parameters

Typed parameter wrapper helpers are not part of the v1 public surface. Most code
should pass scalar values normally through named parameters.

---

## 9. API stability rules

* Named parameters only (public API).
* Database is the only user handle.
* No public Driver / Identifier / Connection concepts.
* One execution path, always.
* Cache remains transparent.
* Helpers exist to keep dynamic SQL safe without framework sprawl.

---

## 10. Safety: bypassing guardrails

UDA's write builders guard against accidental unbounded writes. A bare
`UPDATE … SET` with no WHERE clause or a `DELETE` with no WHERE clause will
throw `QueryException` by default.

When a statement is **intentionally** unbounded — for example, setting a flag
on every row in a table, or emptying a staging table — call `->unsafe()` on the
builder before the terminating `exec()`:

```php
$db->update()
    ->table('import_staging')
    ->set('processed', 1)
    ->unsafe()
    ->exec();
```

`unsafe()` is a deliberate acknowledgement, not a convenience. Use it only
after confirming the intent is correct. It has no effect on SELECT builders.

---

## 11. Glossary (symbols that confuse newcomers)

| Symbol / name | What it is |
| ------------- | ----------- |
| `UDA\Query` | **Builder chassis** — abstract base at `src/UDA/Query.php`; `Select`/`Insert`/… extend `\UDA\Query`. Param bag, `$engine`, quoting, guardrails. **Do not extend.** See §3.1. |
| `UDA\Query\Sql` | Immutable SQL **value** produced by builders before `Database` turns it into a `SqlMessage` for execution. Not the same as `UDA\SQL\SqlMessage`. Same-engine deferral: §3.2. |
| `UDA\SQL\SqlMessage` | Executable SQL + metadata envelope used on the `Database` → `Driver` boundary. |
| `UDA\Driver` | **The driver** — runtime for one connection; owns PDO and executes SQL. (Car: person at the wheel.) |
| **Engine** | SQL family (`pgsql`, `sqlserver`, …): dialect + quoting + fragments. Config JSON key is still `"driver"`; read via `Config::engine()`. (Car: the motor.) |
| **Transport** | PDO DSN prefix (`sqlsrv`, `dblib`, …). Optional when one engine has multiple adapters. (Car: which fuel-line hose.) |
| `UDA\Driver\SQLite` | **Engine manual** for SQLite — static DSN/quoting rules; does not own PDO. |
| `UDA\Query\Dialect\SQLite` | **Query-builder renderer** for SQLite SQL text. Same word “SQLite”, different package role. |

> **Removed:** `UDA\Query` **static facade** — redundant ingress; deleted. **`UDA\Query` abstract base** (`src/UDA/Query.php`) and the **`UDA\Query\`** namespace (builders, `Sql`, dialects) remain.

---

## 12. Naming review disposition (hostile pass — document-first)

| Issue | Disposition |
| ----- | ----------- |
| `UDA\Query` (abstract base) | **Done** — `src/UDA/Query.php`; coexists with namespace `UDA\Query\`; subclasses use `extends \UDA\Query`. Not the removed static facade. |
| `Sql` vs `SqlMessage` | **Keep**; glossary + table above. |
| Two `SQLite` classes | **Keep**; glossary + driver-vs-dialect note. |

---

