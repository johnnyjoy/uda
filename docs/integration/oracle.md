# Oracle Integration

## Status

**Enforced in CI** on every push and pull request (`.github/workflows/oracle-integration.yml`).

Full engine matrix: [README.md](README.md).

## Licensing (CI)

CI uses [gvenzl/oracle-free](https://hub.docker.com/r/gvenzl/oracle-free) (Oracle Database
**Free** for development and testing). Image build scripts are Apache-2.0; database use
must comply with Oracle’s Free license terms.

## Suite

`tests/oracle-bootstrap.php` loads config; PHPUnit runs all of `tests/Oracle/`:

| Class | Proves |
| ----- | ------ |
| `OracleIntegrationTest` | Connect + named CRUD smoke |
| `PaginationTest` | `OFFSET … FETCH NEXT`, streaming, invalid limit |
| `ReturningAndMergeTest` | RETURNING INTO (insert/update/delete), MERGE upsert, multi-row returning |

Shared harness: `OracleTestCase` (identifier warning suppression, table fixtures).

## Command

```bash
UDA_ORACLE_HOST=127.0.0.1 \
UDA_ORACLE_PORT=1521 \
UDA_ORACLE_SERVICE=FREEPDB1 \
UDA_ORACLE_USER=uda_test \
UDA_ORACLE_PASSWORD=uda_test_pw \
vendor/bin/phpunit --bootstrap tests/oracle-bootstrap.php tests/Oracle
```

## CI Enforcement

Starts `gvenzl/oracle-free:23-slim-faststart`, PHP 8.2 + `oci8` + `pdo_oci`, `composer check`,
then full `tests/Oracle` with `--process-isolation` (avoids `pdo_oci` statement-GC segfaults in
one long-lived process). Expect several minutes on cold start.

See [oracle-testing.md](../oracle-testing.md) for troubleshooting and version evidence.
