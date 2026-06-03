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
        "database": "app.fdb"
      },
      "user": "app_user",
      "pass": "your-password"
    }
  }
}
```

Alternatively set `params.dsn` to a full `firebird:dbname=host/port:path` fragment.

## Local integration tests

With a running Firebird instance and `pdo_firebird`:

```bash
FIREBIRD_HOST=127.0.0.1 \
FIREBIRD_PORT=3050 \
FIREBIRD_DATABASE=uda_test.fdb \
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

## CI environment variables

| Variable | Default in workflow |
| -------- | ------------------- |
| `FIREBIRD_HOST` | `127.0.0.1` |
| `FIREBIRD_PORT` | `3050` |
| `FIREBIRD_DATABASE` | `uda_test.fdb` |
| `FIREBIRD_USER` | `uda_test` |
| `FIREBIRD_PASSWORD` | (set in workflow) |

## Related

- [driver.md](../driver.md)
- [deferred.md](deferred.md)
