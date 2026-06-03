# Firebird integration

## Status

**Enforced in CI** on every push and pull request (`.github/workflows/firebird-integration.yml`).

**Connect path:** `driver: firebird` (alias `interbase`) with bundled PHP **`pdo_firebird`**.

Image: official [`firebirdsql/firebird:5-noble`](https://hub.docker.com/r/firebirdsql/firebird).
Expect **~2–5 minutes** per run (container start + extension install via setup-php).

**Branch protection:** add required check `firebird-integration` with the other `*-integration` jobs.

Full engine matrix: [README.md](README.md).

## Requirements

- PHP 8.2+
- Extension **`pdo_firebird`**
- Firebird 5+ recommended (MERGE + RETURNING)

## Configuration

```json
{
  "connections": {
    "firebird": {
      "driver": "firebird",
      "params": {
        "host": "127.0.0.1",
        "port": 3050,
        "database": "/var/lib/firebird/data/app.fdb"
      },
      "user": "app_user",
      "pass": "your-password"
    }
  }
}
```

Alternatively set `params.dsn` to a full `firebird:dbname=host/port:path` fragment.

When connecting over TCP (`host` + `port`), `database` must be the **absolute path on the
Firebird server**. With the official Docker image, files live under
`/var/lib/firebird/data/` (e.g. `/var/lib/firebird/data/app.fdb`). A bare filename such as
`app.fdb` is resolved on the PHP client host and will fail in CI.

## Local integration tests

With a running Firebird instance and `pdo_firebird`:

```bash
FIREBIRD_HOST=127.0.0.1 \
FIREBIRD_PORT=3050 \
FIREBIRD_DATABASE=/var/lib/firebird/data/uda_test.fdb \
FIREBIRD_USER=uda_test \
FIREBIRD_PASSWORD=secret \
vendor/bin/phpunit --bootstrap tests/firebird-bootstrap.php tests/Firebird
```

Default `composer test` excludes `tests/Firebird`.

## Capability summary

| Feature | Integration coverage |
| ------- | -------------------- |
| Named-parameter CRUD | Yes |
| Transactions | Commit + rollback |
| Pagination | Builder limit/offset (ORDER BY required) |
| MERGE upsert | Yes |
| RETURNING | Insert `returning()` |
| Writable CTE | No (dialect rejects) |

## Transactions (`pdo_firebird`)

UDA disables PDO autocommit on Firebird connect (`PDO::ATTR_AUTOCOMMIT = false`). The bundled
`pdo_firebird` driver commit-retains each DML while autocommit is on, which makes
`rollBack()` a no-op ([PHP #8735](https://github.com/php/php-src/issues/8735)). With autocommit
off, `Database::transaction()` commit and rollback behave as on other engines.

## CI environment variables

| Variable | Default in workflow |
| -------- | ------------------- |
| `FIREBIRD_HOST` | `127.0.0.1` |
| `FIREBIRD_PORT` | `3050` |
| `FIREBIRD_DATABASE` | `/var/lib/firebird/data/uda_test.fdb` (server-side path in Docker image) |
| `FIREBIRD_USER` | `uda_test` |
| `FIREBIRD_PASSWORD` | (set in workflow) |

## Related

- [driver.md](../driver.md)
- [deferred.md](deferred.md)
