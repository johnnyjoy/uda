# Oracle Integration

## Status

**Enforced in CI** on every push and pull request (`.github/workflows/oracle-integration.yml`).

Full engine matrix: [README.md](README.md).

## Licensing (CI)

CI uses [gvenzl/oracle-free](https://hub.docker.com/r/gvenzl/oracle-free) (Oracle Database
**Free** for development and testing). Image build scripts are Apache-2.0; database use
must comply with Oracle’s Free license terms. This is **not** Oracle partner
certification.

## Suite

`tests/Oracle/OracleIntegrationTest.php` with `tests/oracle-bootstrap.php`.

v1 CI scope:

* `Database::connect()` against `driver: oracle`
* named-parameter INSERT/SELECT

RETURNING, MERGE, and pagination suites are documented in
[oracle-testing.md](../oracle-testing.md) for manual or future expansion.

## Command

```bash
UDA_ORACLE_HOST=127.0.0.1 \
UDA_ORACLE_PORT=1521 \
UDA_ORACLE_SERVICE=FREEPDB1 \
UDA_ORACLE_USER=uda_test \
UDA_ORACLE_PASSWORD=uda_test_pw \
vendor/bin/phpunit --bootstrap tests/oracle-bootstrap.php tests/Oracle
```

Local Docker (matches CI user):

```bash
docker run --rm -d -p 1521:1521 \
  -e ORACLE_PASSWORD='Oracle_UDA_CI1' \
  -e APP_USER=uda_test \
  -e APP_USER_PASSWORD=uda_test_pw \
  gvenzl/oracle-free:23-slim-faststart
```

Requires `oci8` and `pdo_oci` PHP extensions.

## CI Enforcement

GitHub Actions: `.github/workflows/oracle-integration.yml`

The `oracle-integration` job:

1. Starts `gvenzl/oracle-free:23-slim-faststart` with `healthcheck.sh` (90s start period).
2. Installs PHP 8.2 with `oci8` and `pdo_oci`.
3. Runs `composer check`.
4. Runs PHPUnit with env vars for `uda_test` application user.
