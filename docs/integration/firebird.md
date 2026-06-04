# Firebird integration

## Status

**Enforced in CI** (`.github/workflows/firebird-integration.yml`). Connect with `driver: firebird`
(alias `interbase`) and **`pdo_firebird`**. Image: [`firebirdsql/firebird:5-noble`](https://hub.docker.com/r/firebirdsql/firebird).
Add required check `firebird-integration` with the other `*-integration` jobs. Consumer config: [engines.md](../engines.md#firebird).

## Requirements

- PHP 8.2+, extension **`pdo_firebird`**
- Firebird 5+ (MERGE, RETURNING)

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

`params.dsn` may hold a full `firebird:dbname=host/port:path` fragment.

Over TCP, `database` is the **absolute path on the Firebird server** (e.g.
`/var/lib/firebird/data/app.fdb` in the official Docker image). A bare filename resolves on the
PHP host and fails in CI.

## Local integration tests

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
| Writable CTE | No |

## Transactions

UDA sets `PDO::ATTR_AUTOCOMMIT = false` on connect and reconnect. With autocommit on,
`pdo_firebird` commit-retains each DML so `rollBack()` is a no-op ([PHP #8735](https://github.com/php/php-src/issues/8735)).

## CI environment variables

| Variable | Default in workflow |
| -------- | ------------------- |
| `FIREBIRD_HOST` | `127.0.0.1` |
| `FIREBIRD_PORT` | `3050` |
| `FIREBIRD_DATABASE` | `/var/lib/firebird/data/uda_test.fdb` |
| `FIREBIRD_USER` | `uda_test` |
| `FIREBIRD_PASSWORD` | (set in workflow) |

## Related

- [driver.md](../driver.md)
- [deferred.md](deferred.md)
