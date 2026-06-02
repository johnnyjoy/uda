# PostgreSQL Certification

## Status

**Enforced in CI** on every push and pull request (`.github/workflows/postgres-cert.yml`).

Full engine matrix: [README.md](README.md).

The job starts PostgreSQL 16 plus Redis and Memcached service containers. Local runs
require PHP 8.2+, `ext-pdo_pgsql`, and matching env vars (see Command). Tests skip when
`pdo_pgsql` or the service is unavailable.

## Suite

The v1 certification test lives in `tests/Postgres` and is run with
`tests/postgres-bootstrap.php` so it can use a PostgreSQL-specific config
without conflicting with the default SQLite test bootstrap.

It proves:

* `Database::connect()` can select a PostgreSQL connection from JSON config
* named-parameter writes execute through UDA
* named-parameter reads return expected values
* CI can certify a real PostgreSQL service separately from the default test suite

## Command

```bash
PGHOST=127.0.0.1 \
PGPORT=5432 \
PGDATABASE=testdb \
PGUSER=postgres \
PGPASSWORD=postgres \
vendor/bin/phpunit --bootstrap tests/postgres-bootstrap.php tests/Postgres
```

## CI Enforcement

GitHub Actions workflow: `.github/workflows/postgres-cert.yml`

The `postgres-cert` job:

1. Starts PostgreSQL 16, Redis, and Memcached services.
2. Installs PHP 8.2 with `pdo_pgsql`, `redis`, and `memcached`.
3. Installs Composer dependencies.
4. Runs architecture guardrails with `composer check`.
5. Runs `vendor/bin/phpunit --bootstrap tests/postgres-bootstrap.php tests/Postgres`.
