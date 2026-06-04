# Getting Started With UDA

**Building repositories?** Read [**building-your-dal.md**](building-your-dal.md) first
(layer shape, `Link` examples, rules). This page continues with install, connect,
builders, and operational sharp edges.

UDA is used from application or repository classes through one public handle:

```php
use UDA\Database;
```

Application code should not import `Driver`, PDO, cache, dialect, or backend
rule classes.

Classes that want to keep SQL inside their own abstraction layer can optionally
use the link form:

```php
use UDA\Link;
```

The link still resolves the same `Database` handle — not a second runtime or query API.

## Install

```bash
composer require johnnyjoy/uda
```

UDA requires PHP 8.2+ and PDO.

## Configure

Create a JSON config file and point `UDA_CONFIG` at it:

```json
{
  "defaults": {
    "connection": "app"
  },
  "connections": {
    "app": {
      "driver": "sqlite",
      "params": {
        "path": "/tmp/app.sqlite"
      }
    }
  }
}
```

```bash
export UDA_CONFIG=/path/to/uda.json
```

Supported engines and sample config: [**engines.md**](engines.md). Upstream CI
integration-gates SQLite, PostgreSQL, MariaDB, SQL Server, Oracle, DB2, and
Firebird. **Sybase** is not run in upstream CI (no SAP license); optional local tests:
`docs/integration/sybase.md`. **Informix** and **CUBRID** are not in UDA yet — **coming soon**
(see `docs/integration/deferred.md`).

## Connect

```php
$db = Database::connectDefault();
$reporting = Database::connectNamed('reporting');
$generated = Database::connectWithConfig('/tmp/generated-uda.json', 'tenant_001');
```

`Connection` means a config name, not a public connection object.

## Three sharp edges (read before debugging)

1. **Positional `?` placeholders** — Rejected before PDO. Always use named
   parameters (`:id`, `:name`, …). SQL copied from tutorials should be rewritten to named binds.

2. **Read cache without table hints** — When caching is enabled, reads that
   should participate in metadata/TTL must pass **table hints** (the string array
   after params). Omitting hints can mean stale or non-invalidating reads for
   those tables. See `docs/caching.md` and `docs/public-api.md`.

3. **Long-running workers and dropped connections** — There is no per-query
   ping. A dropped TCP connection surfaces as a `PDOException`; `Driver`
   reconnects once and retries the same operation. Mid-transaction loss still
   fails the transaction (expected). See `docs/architecture.md` (Connection Pool).

4. **Octane / RoadRunner / Swoole concurrency** — `Database::connect('app')`
   returns one shared handle per worker process, not per request. Do not run
   concurrent coroutines on the same connection name without locking; do not use
   `lastSql()` / `lastParams()` as request-scoped debug in production workers.
   See `docs/architecture.md` § Concurrency in long-running workers.

## Read

```php
$user = $db->row(
    'SELECT id, name FROM users WHERE id = :id',
    ['id' => 42],
    ['users']
);
```

Raw SQL uses named parameters only. Positional `?` placeholders are rejected
before PDO.

## Write

```php
$affected = $db->exec(
    'UPDATE users SET name = :name WHERE id = :id',
    ['name' => 'Ada', 'id' => 42],
    ['users']
);
```

When table hints are supplied, successful writes touch cache metadata for those
tables.

## Transaction

```php
$db->transaction(function (Database $db): void {
    $db->exec(
        'INSERT INTO users (id, name) VALUES (:id, :name)',
        ['id' => 42, 'name' => 'Ada'],
        ['users']
    );
});
```

The callback receives the same `Database` handle, not `Driver` or PDO.

## External Class Link

Use `Link` when a class should expose domain methods while UDA resolves the
configured database behind the class boundary. The class should be built around
one configured connection name:

```php
use UDA\Link;

final class Users
{
    use Link;

    protected static string $connection = 'app';

    public function findName(int $id): ?string
    {
        $value = $this->value(
            'SELECT name FROM users WHERE id = :id',
            ['id' => $id],
            ['users']
        );

        return is_string($value) ? $value : null;
    }
}
```

`$connection` is `static` because it is a fact about the class, not about any
individual instance. Every `Users` object talks to `'app'` — the connection
never varies between instances of the same class. The `Database` handle is
memoized once per class and shared across all instances.

`Link` exposes protected methods (`value()`, `row()`, `rows()`, `exec()`,
`transaction()`, and all builder entrypoints) so the class is a natural home
for SQL statements without extending `Database`. It does not expose `Driver`,
PDO, cache, dialect, or backend rules.

## Builder

```php
$name = $db->select()
    ->from('users')
    ->where('id', 42)
    ->value();
```

Builders construct SQL and terminate through the same `Database -> Driver -> PDO`
execution path.

## Safe Dynamic SQL

```php
[$inSql, $inParams] = $db->inList([10, 20, 30], 'id');

$sql = 'SELECT * FROM users WHERE id ' . $inSql
    . ' ' . $db->orderByAllowed('name', ['name' => true], 'ASC')
    . ' ' . $db->limitOffset(10, 0);

$rows = $db->rows($sql, $inParams, ['users']);
```

Values still bind through named parameters. Helpers only generate safe SQL
structure.

