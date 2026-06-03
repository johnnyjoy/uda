# MariaDB Integration

## Status

**Enforced in CI** on every push and pull request (`.github/workflows/mariadb-integration.yml`).

Full engine matrix: [README.md](README.md).

Uses official `mariadb:11` service container and `pdo_mysql`. Local runs need PHP 8.2+,
`ext-pdo_mysql`, and env vars below. Tests skip when the extension or bootstrap is missing.

## Suite

`tests/MariaDb/MariaDbIntegrationTest.php` with `tests/mariadb-bootstrap.php`.

v1 scope:

* `Database::connect()` against `driver: mariadb`
* named-parameter INSERT/SELECT on a live server

Builder/upsert/cache paths remain integrator-validated until expanded.

## Command

```bash
MARIADB_HOST=127.0.0.1 \
MARIADB_PORT=3306 \
MARIADB_DATABASE=testdb \
MARIADB_USER=root \
MARIADB_PASSWORD=root \
vendor/bin/phpunit --bootstrap tests/mariadb-bootstrap.php tests/MariaDb
```

## CI Enforcement

GitHub Actions: `.github/workflows/mariadb-integration.yml`

The `mariadb-integration` job:

1. Starts `mariadb:11` with `healthcheck.sh`.
2. Installs PHP 8.2 with `pdo_mysql`.
3. Runs `composer check`.
4. Runs PHPUnit with the MariaDB bootstrap.
