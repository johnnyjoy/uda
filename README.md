# Universal Data Abstractor (UDA)

**Slim SQL execution for PHP repositories** — one API across major databases, optional
read cache, explicit queries under project control.

PHP APIs and services can keep **SQL in repository classes** — methods the team
names, SQL visible in PR review. UDA is the **engine under that layer**: connect, run
named-parameter SQL, use dialect-aware fluent builders when they help, and keep the
same code when the database changes.

- **Readable data layer** — SQL stays in methods that are reviewable in PRs and explainable to ops.
- **Many databases, one habit** — Postgres today, SQL Server or Oracle tomorrow; edit `uda.json`, not every query file.
- **Stable team contract** — one handle (`Database` or `Link`), named params only, integration-tested engines in CI.
- **More speed when needed** — transparent read cache (Redis, Memcached, in-memory, …); enable in config, keep table hints on reads.
- **Explicit query ownership** — repositories and `Link`; domain methods, not generated `find()` helpers.

## What UDA does

| Area | UDA provides… |
| ---- | -------- |
| **Connect** | `connectDefault()`, `connectNamed()`, `connectWithConfig()`; one pooled `Database` + PDO per connection name per process; transparent reconnect on dropped connections |
| **Raw reads** | `row`, `rows`, `value`, `values` (first column of each row), `list` (first row as numeric list) — all accept SQL string, `Sql::of()`, or compiled `Query\Sql` |
| **Large reads** | `each($sql, $params, $callback)` — row-by-row without loading the full result into memory |
| **Raw writes** | `exec`, `returning()` (engines that support it) |
| **Fluent** | `select()`, `insert()`, `update()`, `delete()`, `upsert()` — joins, CTEs (`with` / `withRecursive`), `union` / `unionAll`, `groupBy` / `having`, subqueries, `RETURNING` / `OUTPUT` where supported |
| **Bulk & copy** | `insert()->rows([...])`, `insert()->columns(...)->select($subquery)` |
| **Upsert** | `upsert()->into()->values()` or `rows()`, `key()`, `update()`, `doNothing()` — dialect-specific `ON CONFLICT` / `MERGE` / etc. |
| **Reuse** | Same SQL + new binds; partial fluent clones + `->end()`; `toSql()` on another same-engine connection; `Sql::of()` templates |
| **Safe dynamic SQL** | `inList()`, `q()`, `orderByAllowed()`, `limitOffset()`, `whereRaw()` with named params — not string-concatenated values |
| **Transactions** | `transaction(callable)`; savepoints where the engine supports them |
| **Guardrails** | Unbounded `UPDATE` / `DELETE` blocked by default; `->unsafe()` when intentionally global |
| **Cache** | Config-driven read cache; table hints on raw reads; `flushCache()` for ops — repositories stay unchanged |
| **Debug** | `lastSql()`, `lastParams()`, builder `toSql()` (compile only) |
| **Errors** | `QueryException` with `category()`, `sqlState()`, `driverCode()` for mapping in the application API layer |
| **Structure** | `UDA\Link` on repository classes, or dependency injection of `Database` from the project container |
| **Observe** | Optional `Database::setQueryObserver()` at bootstrap ([metrics.md](docs/metrics.md)) |

```text
HTTP / CLI / worker
    → application controller or job
    → application repository (SQL or builders here)
    → UDA\Database or UDA\Link
    → (internal) Driver → PDO → engine
```

## Supported databases

| Engine | In UDA | Tested in GitHub Actions on every push/PR |
| ------ | ------ | ------------------------------------------- |
| SQLite | Yes | Yes |
| PostgreSQL | Yes | Yes |
| MariaDB / MySQL | Yes | Yes |
| SQL Server | Yes | Yes (`pdo_dblib` on Linux CI) |
| Oracle | Yes | Yes |
| DB2 | Yes | Yes (`pdo_ibm`) |
| Firebird | Yes | Yes (`pdo_firebird`) |
| Sybase ASE | Yes | No — no public CI license; local opt-in only |
| Informix | No | Coming soon |
| CUBRID | No | Coming soon |

Per-engine config and CI detail: [engines.md](docs/engines.md), [integration/README.md](docs/integration/README.md).

## Install

PHP 8.2+, ext-pdo. MIT.

```bash
composer require johnnyjoy/uda
```

[github.com/johnnyjoy/uda](https://github.com/johnnyjoy/uda)

## Quick start

```bash
export UDA_CONFIG=/etc/app/uda.json
```

Repository with **`Link`** — SQL on the class; controllers call it:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use UDA\Link;

final class UserRepository
{
    use Link;

    protected static string $connection = 'app';

    public function findName(int $id): ?string
    {
        $name = $this->value(
            'SELECT name FROM users WHERE id = :id',
            ['id' => $id],
            ['users']
        );

        return is_string($name) ? $name : null;
    }
}
```

See **Configuration**, **Fluent queries**, and **Caching** below. Full layer guide:
[building-your-dal.md](docs/building-your-dal.md).

## Configuration

UDA loads **one JSON file** (`.json` extension). Production default: environment variable
`UDA_CONFIG` points at the file. A path can also be passed to `Database::connect()`.

```json
{
  "defaults": { "connection": "app" },
  "connections": {
    "app": {
      "driver": "pgsql",
      "params": {
        "host": "postgres",
        "port": 5432,
        "dbname": "myapp"
      },
      "user": { "env": "DB_USER" },
      "pass": { "env": "DB_PASS" }
    },
    "reporting": {
      "driver": "pgsql",
      "params": {
        "host": "postgres-replica",
        "port": 5432,
        "dbname": "analytics"
      },
      "user": { "env": "DB_USER" },
      "pass": { "env": "DB_PASS" }
    }
  }
}
```

| Topic | Detail |
| ----- | ------ |
| **Top level** | `defaults.connection` (optional), `connections` (required, at least one) |
| **Secrets** | `user` / `pass` as `{ "env": "VAR_NAME" }` — not DSN strings in repo |
| **Engines** | `driver` names the SQL family; aliases like `dblib` normalize at load — see [engines.md](docs/engines.md) |
| **Multiple DBs** | `Database::connectDefault()`, `connectNamed('reporting')`, `connectWithConfig('/path/uda.json', 'tenant')`, or `Link` with `protected static string $connection` |
| **Pooling** | Second `connectNamed('app')` is a handle lookup — no config re-read; long-lived workers reuse one PDO per name |
| **Persistent** | Connections are **always persistent** — the PDO handle is reused across requests (php-fpm/containers), skipping the connect handshake, with stray transactions rolled back on checkout. Intrinsic, not a setting (like exception error mode) — see [configuration.md](docs/configuration.md#persistent-connections) |
| **Reconnect** | Driver retries once after connection-lost errors, then re-runs `init_sql` from config |
| **Containers** | Use the DB **service hostname** as `host` (`postgres`), not `localhost` inside the app container |
| **Validation** | Loaded once at ingestion; failures throw `ConfigException` |

Full schema (PDO options, `init_sql`, trace, templates): [configuration.md](docs/configuration.md).

## Raw SQL

Named parameters only — positional `?` is rejected before PDO.

| Method | Returns |
| ------ | ------- |
| `row()` | One associative row or `null` (single-row fetch, does not materialize all rows) |
| `rows()` | All rows |
| `value()` | First column of first row |
| `values()` | First column of every row |
| `list()` | First row as a numeric list |
| `each()` | Invokes callback per row; returns row count |
| `exec()` | Affected row count |
| `returning()` | Rows from `INSERT`/`UPDATE`/`DELETE … RETURNING` (where supported) |

```php
use UDA\Database;

$db = Database::connectDefault();

$user = $db->row(
    'SELECT id, name FROM users WHERE id = :id',
    ['id' => 42],
    ['users']
);

$names = $db->values(
    'SELECT name FROM users WHERE active = :active ORDER BY name',
    ['active' => 1],
    ['users']
);

// Large result sets — callback per row, not one giant array
$db->each(
    'SELECT id, payload FROM events WHERE processed = :p',
    ['p' => 0],
    function (array $row): void {
        // process one row
    },
    ['events']
);

$affected = $db->exec(
    'UPDATE users SET name = :name WHERE id = :id',
    ['name' => 'Ada', 'id' => 42],
    ['users']
);

$inserted = $db->returning(
    'INSERT INTO users (name) VALUES (:name) RETURNING id',
    ['name' => 'Ada'],
    ['users']
);

$db->transaction(function (Database $db): void {
    $db->exec(
        'INSERT INTO users (id, name) VALUES (:id, :name)',
        ['id' => 1, 'name' => 'Ada'],
        ['users']
    );
});
```

The third argument on reads/writes is the **table hint** array (e.g. `['users']`) — required
for correct cache behaviour when caching is enabled. Fluent builders pick up hints from
`from()` / `into()` / `table()`.

## Fluent queries

Builders compile dialect-aware SQL and run through the **same** `Database → Driver → PDO`
path as raw SQL. Obtain builders only from `$db->select()` (etc.) — not `new Select()`.

### How chaining works

A fluent query is a **chain of steps**, then a **terminator** that finishes the job.

```text
$db->select()          ← entry (wired to this connection’s engine + dialect)
    ->from('users')    ← chain step: add SQL shape (no DB round-trip yet)
    ->where('active', 1)
    ->orderBy('name')
    ->rows();          ← terminator: compile + execute, return data
```

| Kind | Examples | What happens |
| ---- | -------- | -------------- |
| **Chain steps** | `from`, `join`, `where`, `set`, `into`, `with`, `orderBy`, `limit` | Each call returns a **new clone** of the builder. Earlier variables are not mutated — safe to keep a partial `select()` and branch with different `where()` chains. |
| **Terminators** | `rows`, `row`, `value`, `exec`, `toSql`, … | **Compile** the chain to SQL + named params, then **execute** (except `toSql()`, which only compiles). Same internal path as `$db->rows('SELECT …', $params)`. |

**WHERE is a sub-chain.** `where()`, `whereIn()`, `whereExists()`, and `whereRaw()` return a
`WhereBuilder`. Stack predicates there (`where` chains to `where` on the same `WhereBuilder`).

**What `end()` actually means.** The name is easy to misread: `end()` does **not** mean “the
query is done.” It means **end the WHERE sub-chain** — flush predicates onto the
parent `Select` / `Update` / `Delete` and return that parent so chaining can continue.

| Situation | What to do |
| --------- | ------------ |
| **Finish a SELECT with a read** | Usually **omit** `end()` — `->where(...)->rows()` works because `WhereBuilder` calls `end()` internally, then runs the read. |
| **More SELECT after WHERE** | **Need** `end()` — `orderBy`, `limit`, `groupBy`, and `union` live on `Select`, not on `WhereBuilder`. |
| **Compile SELECT (`toSql`) after WHERE** | **Omit** `end()` — `toSql()` on `WhereBuilder` ends WHERE and compiles, same as `rows()`. |
| **Finish UPDATE / DELETE** | **Need** `end()` before `exec()` or `returning()` — those terminators are not on `WhereBuilder`. |
| **Subquery value for `whereExists` / `joinSub`** | **Need** `end()` or `toSql()` on the inner query so the value passed is `Select` or `Sql`, not `WhereBuilder`. |

```php
// Read terminator straight off WHERE (proxy end)
$name = $db->select()->from('users')->where('id', 42)->value();

// ORDER BY after WHERE — explicit end
$rows = $db->select('id', 'name')
    ->from('users')
    ->where('active', 1)
    ->end()
    ->orderBy('name')
    ->rows();

// UPDATE — end before exec
$db->update()
    ->table('users')
    ->set('name', 'Ada')
    ->where('id', 42)
    ->end()
    ->exec();
```

### Terminators (pick one to finish the chain)

Terminators are the **last** method. They compile the builder and run it (or, for `toSql()`,
return an immutable `Query\Sql` value for reuse / logging).

**`select()` — reads**

| Terminator | Returns | Use when |
| ---------- | ------- | -------- |
| `row()` | `?array` assoc row | Zero or one row (`null` if none) |
| `rows()` | `array` of rows | Many rows (`[]` if none) |
| `value()` | `mixed` | Single scalar (first column, first row) |
| `values()` | `array` | First column from every row |
| `list()` | `?array` numeric | First row as a list (`null` if none) |
| `count($expr = '*')` | `int` | `COUNT` shortcut |
| `each(callable $fn)` | `int` | Process rows one at a time (row count returned) |
| `toSql()` | `Query\Sql` | Debug or defer execution — **does not run** the query |

**`insert()` / `update()` / `delete()` — writes**

| Terminator | Returns | Use when |
| ---------- | ------- | -------- |
| `exec()` | `int` | Affected row count |
| `returning(...)` then `row()` / `value()` / `list()` | Same as reads | Engines with `RETURNING` / `OUTPUT` (see [patterns.md](docs/patterns.md)) |

**`upsert()`** — `exec()` only.

Avoid `rows()` when only one row is needed — use `row()` or `value()`.

### Builder entrypoints (chain steps vs terminators)

| Builder | Chain steps (describe SQL) | Terminators (compile + run) |
| ------- | -------------------------- | --------------------------- |
| `select()` | `from`, `join` / `joinSub`, `where`→`end()`, `groupBy`, `having`, `orderBy`, `limit` / `offset`, `distinct`, `union`, `with` | `row`, `rows`, `value`, `values`, `list`, `count`, `each`, `toSql` |
| `insert()` | `into`, `set` / `values` / `rows`, `columns`+`select`, `with`, `returning` | `exec`, or `row` / `value` / `list` after `returning` |
| `update()` | `table`, `set`, `where`→`end()`, `with`, `returning` | `exec`, or row/value/list after `returning` |
| `delete()` | `table`, `where`→`end()`, `with`, `returning` | `exec`, or row/value/list after `returning` |
| `upsert()` | `into`, `values` / `rows`, `key`, `update` / `doNothing` | `exec` |

```php
$name = $db->select()
    ->from('users')
    ->where('id', 42)
    ->value();

$active = $db->select('id', 'name')
    ->from('users')
    ->where('active', 1)
    ->orderBy('name')
    ->limit(100)
    ->rows();

// EXISTS — pass a compiled subquery or Select (then ->end() back to Select)
$activeOnly = $db->select('1')->from('managers')->where('active', 1)->end();
$withManagers = $db->select('e.id')
    ->from('employees e')
    ->whereExists($activeOnly)
    ->end()
    ->rows();

// Dynamic filters — each where() returns a clone
$q = $db->select()->from('employees');
if ($departmentId !== null) {
    $q = $q->where('department_id', $departmentId);
}
$rows = $q->rows();

$db->insert()
    ->into('users')
    ->values(['id' => 1, 'name' => 'Ada'])
    ->exec();

$db->update()
    ->table('users')
    ->set('name', 'Ada')
    ->where('id', 42)
    ->end()
    ->exec();

// INSERT … SELECT (copy rows from a query)
$db->insert()
    ->into('users_archive')
    ->columns('id', 'name', 'archived_at')
    ->select(
        $db->select('id', 'name')->from('users')->where('deleted', 1)->end()
    )
    ->exec();

// Postgres-style RETURNING on builders (where supported)
$newId = $db->insert()
    ->into('users')
    ->set('name', 'Ada')
    ->returning('id')
    ->value();
```

### Upsert

Engine-specific merge/upsert SQL from one builder shape:

```php
$db->upsert()
    ->into('users')
    ->values(['id' => 42, 'name' => 'Ada', 'email' => 'a@example.com'])
    ->key(['id'])
    ->update(['name', 'email'])
    ->exec();

$db->upsert()
    ->into('events')
    ->rows([
        ['external_id' => 'e1', 'payload' => '{}'],
        ['external_id' => 'e2', 'payload' => '{}'],
    ])
    ->key(['external_id'])
    ->doNothing()
    ->exec();
```

Capability matrix per engine: [patterns.md](docs/patterns.md).

### Guardrails and `unsafe()`

`update()` and `delete()` without a `where()` clause throw `QueryException` by default.
For intentional full-table writes (staging reset, flag flip on every row), opt in:

```php
$db->update()
    ->table('import_staging')
    ->set('processed', 1)
    ->unsafe()
    ->exec();
```

### Reuse: bind, fluent templates, and fragments

UDA separates **compiling SQL** from **binding values**. Pick the pattern that matches
how much of the query stays fixed.

#### Bind at execute time (same SQL, new values)

Keep the SQL text stable; pass a new parameter array on each call. The driver can reuse
the prepared statement when the SQL string matches (same connection).

```php
$sql = 'SELECT id, name FROM users WHERE department_id = :dept AND active = :active';

$east = $db->rows($sql, ['dept' => 10, 'active' => 1], ['users']);
$west = $db->rows($sql, ['dept' => 20, 'active' => 1], ['users']);
```

Same idea with a saved `Sql` template and fresh binds:

```php
use UDA\Query\Sql;

$template = Sql::of('SELECT id, name FROM users WHERE id = :id', [], ['users']);

$alice = $db->row($template, ['id' => 1], ['users']);
$bob   = $db->row($template, ['id' => 2], ['users']);
```

#### Fluent partial builder (reuse the FROM / SELECT shape)

Because chain steps clone the builder (see **How chaining works**), a shared
`from` / column list and fork different `where` branches — each branch finishes with a read
terminator (`rows()`, etc.); `WhereBuilder` ends the WHERE clause automatically:

```php
$list = $db->select('id', 'name', 'department_id')->from('users');

$east = $list->where('department_id', 10)->where('active', 1)->rows();
$west = $list->where('department_id', 20)->where('active', 1)->rows();
```

`$list` itself was never mutated; each `where()` started a new branch from the same template.
Values in `->where('col', $value)` are bound when that branch’s terminator compiles and runs.

#### Saved subqueries and CTEs (fluent fragments)

Compile once with `toSql()`, or pass a live `Select`, into another builder:

```php
$activeUsers = $db->select('id')
    ->from('users')
    ->where('active', 1)
    ->toSql();

$rows = $db->select('e.id', 'e.name', 'u.id AS manager_id')
    ->from('employees e')
    ->joinSub($activeUsers, 'u', 'u.id = e.manager_id')
    ->rows();

$report = $db->with('active', $activeUsers)
    ->select('*')
    ->from('active')
    ->rows();
```

`fromSub()`, `joinSub()`, `leftJoinSub()`, `whereExists()`, and `with()` / `withRecursive()`
all accept `Select` or `Sql`. Use the **same engine** that compiled the fragment.

Run compiled SQL on another connection (e.g. replica) when the engine matches:

```php
$sql = $db->select('id', 'name')->from('users')->where('active', 1)->toSql();

$primary = Database::connectNamed('app')->rows($sql);
$replica = Database::connectNamed('replica')->rows($sql);
```

`toSql()` compiles only — it does not execute. If literals are baked in at compile time
(`->where('active', 1)`), change binds by recompiling or by using a parameterized SQL
string. See [public-api.md](docs/public-api.md) (compiled `Sql` and safe fragments).

#### Safe SQL fragments (`inList`, identifiers, pagination)

For dynamic pieces inside raw SQL, use **helpers** so structure stays parameterized:

```php
[$inSql, $inParams] = $db->inList($ids, 'id');
$sql = 'SELECT * FROM users WHERE id ' . $inSql;
$rows = $db->rows($sql, $inParams, ['users']);
```

In fluent chains, `whereRaw()` merges named parameters into the builder bag. Reuse the same
SQL fragment with different binds — no `end()` before `rows()` (the read terminator ends
WHERE clause automatically):

```php
$statusFilter = 'o.status = :status';

$open = $db->select()->from('orders o')
    ->whereRaw($statusFilter, ['status' => 'open'])
    ->rows();

$shipped = $db->select()->from('orders o')
    ->whereRaw($statusFilter, ['status' => 'shipped'])
    ->rows();
```

More helpers: `$db->q()` (quoted identifiers), `orderByAllowed()`, `limitOffset()` —
[public-api.md § 4](docs/public-api.md#4-massive-queries-raw-sql--safe-fragments).

### Bulk insert (multiple rows)

```php
$db->insert()
    ->into('employees')
    ->rows([
        ['employee_no' => 'E100', 'first_name' => 'Ann', 'last_name' => 'One'],
        ['employee_no' => 'E101', 'first_name' => 'Bob', 'last_name' => 'Two'],
    ])
    ->exec();
```

One row: chain `->set('column', $value)` or `->values(['id' => 1, 'name' => 'Ada'])->exec()`.
`RETURNING` / `OUTPUT` on supported engines: [patterns.md](docs/patterns.md) and
[public-api.md](docs/public-api.md).

With **`Link`**, use `$this->select()`, `$this->insert()`, `$this->fromSub()`, etc. inside
the repository class. More recipes: [patterns.md](docs/patterns.md).

## Errors

UDA throws typed exceptions — map them in the HTTP or job layer, not by parsing message text.

| Exception | When |
| --------- | ---- |
| `ConfigException` | Bad or missing `uda.json`, env, connection name |
| `ConnectionException` | Cannot open PDO |
| `QueryException` | SQL failure, guardrail, unsupported engine feature |

`QueryException` exposes `category()` (`guardrail`, `constraint`, `connection`, `syntax`,
`unsupported`, `execution`, `binding`), plus `sqlState()` and `driverCode()` when the driver
provides them. Full mapping notes: [public-api.md § 7](docs/public-api.md#7-exceptions).

## Caching

Read caching is **on in config**, **transparent in code**. Repositories always call
`row` / `rows` / builders the same way; UDA decides hit or miss on the read path.

**How it is designed to work** ([cache-doctrine.md](docs/cache-doctrine.md)): **interval
caching** — if cache exists and policy allows, use it and hit the DB at most every
`minIntervalSeconds`; **max age** (`ttlSeconds`) ends an entry’s lifetime; **metadata**
is evaluated per query key (no single global TTL); writes invalidate via table mtimes;
optional **stale-on-error** serves last good cache when the DB is down (`allowStaleOnError`).
**v1 ships** mtime invalidation + transparent reads; interval/max-age/stale-on-error policy
fields in config are not enforced yet ([caching.md](docs/caching.md)).

Enable per connection in `uda.json`:

```json
{
  "connections": {
    "app": {
      "driver": "sqlite",
      "params": { "path": "/var/app/data.sqlite" },
      "cache": {
        "namespace": "APP",
        "store": { "type": "redis", "host": "redis", "port": 6379 },
        "tables": {
          "audit_log": { "disable": true }
        },
        "require_table_hints": true
      }
    }
  }
}
```

| Topic | Detail |
| ----- | ------ |
| **Stores** | `array` (dev/tests), `redis`, `memcached`, `off` |
| **Table hints** | Third argument on raw SQL reads: `['users']`. Builders carry table metadata from `from()` / `into()`. |
| **Interval** | `minIntervalSeconds` — use cache when available; max DB read frequency (**not wired in v1**) |
| **Max age** | `ttlSeconds` on `defaultPolicy` / per table — entry lifetime (**not wired in v1**) |
| **Invalidation** | Writes with hints → `Cache::touch()`; reads compare metadata to table mtimes (**wired in v1**) |
| **Stale on error** | `allowStaleOnError` + `maxStaleSeconds` when DB fails (**not wired in v1**) |
| **Layering** | Stricter table/connection policy wins on multi-table reads ([caching.md](docs/caching.md#layering-connection-default-30s-vs-table-300s-when-policy-ttl-exists)) |
| **Backend expiry** | Redis/Memcached/array keys for payload/metadata expire after **3600s** (orphan cleanup, not application policy) |
| **Not TTL** | `store.timeout` is Redis **connect** timeout — see [caching.md § TTL map](docs/caching.md#ttl-and-freshness--full-map) |
| **Strict mode** | `require_table_hints: true` rejects hintless raw reads when cache is on (builders unaffected) |
| **Ops** | `Database::flushCache()` for deploy/incident purge — not for normal app logic |

Details: [caching.md](docs/caching.md), [cache-doctrine.md](docs/cache-doctrine.md).

## `Link` vs inject `Database`

| | `Link` trait | Inject `Database` |
| -- | ------------ | ------------------- |
| **Use when** | Many methods on a dedicated repository class | Container/bootstrap owns one handle |
| **Import** | `UDA\Link` | `UDA\Database` |
| **Connection** | `protected static string $connection = 'app';` | `connectDefault()` / `connectNamed()` |

Both expose raw SQL, builders, and transactions on the same pipeline.
[building-your-dal.md — Database or Link](docs/building-your-dal.md#database-or-link)

## Run in production

Typical setup: **php-fpm** (or equivalent) behind nginx/Apache, often in a container.
Set `UDA_CONFIG`, mount `uda.json`, call `Database::setQueryObserver()` once in
`public/index.php` when slow-query or error logging is required.

Long-lived workers (Octane, RoadRunner, Swoole): shared handle per worker — read
[getting-started.md](docs/getting-started.md) and [architecture.md](docs/architecture.md).
Deploy checklist: [skills/uda-config-deploy/SKILL.md](skills/uda-config-deploy/SKILL.md).

## Documentation

| Doc | Purpose |
| --- | ------- |
| [building-your-dal.md](docs/building-your-dal.md) | Layer shape, rules, examples |
| [getting-started.md](docs/getting-started.md) | Connect variants, sharp edges |
| [engines.md](docs/engines.md) | `uda.json` per database |
| [patterns.md](docs/patterns.md) | Pagination, filters, joins, upsert per engine |
| [query-cookbook.md](docs/query-cookbook.md) | Copy-paste SQL recipes (maintainer-owned) |
| [configuration.md](docs/configuration.md) | Full config schema |
| [caching.md](docs/caching.md) | Hints, invalidation, backend TTL vs deferred policy |
| [public-api.md](docs/public-api.md) | Builders and terminators |

Map: [docs/README.md](docs/README.md)

## Agent skills

[skills/](skills/README.md) — checklists for any AI tool that loads skills.
Start: [uda-data-access/SKILL.md](skills/uda-data-access/SKILL.md),
[uda-sql-and-cache/SKILL.md](skills/uda-sql-and-cache/SKILL.md).

## Contributing & license

[CONTRIBUTING.md](CONTRIBUTING.md) · [docs/README.md#for-contributors](docs/README.md#for-contributors) ·
[LICENSE.md](LICENSE.md) (MIT) · [SECURITY.md](SECURITY.md) · [CHANGELOG.md](CHANGELOG.md)
