# SQLite Certification

## Status

**Enforced in CI** on every push and pull request (`.github/workflows/sqlite-cert.yml`).

Local runs require PHP 8.2+. Array-cache proof runs without extra services; Redis and
Memcached store coverage in broader suites requires `ext-redis` / `ext-memcached` and
reachable backends when those tests are included.

## Suite

The v1 certification suite lives in:

* `tests/SQLite`
* `tests/Cache`
* `tests/Runtime`

It proves the current v1 product contract:

* external classes import only `UDA\Database`
* raw SQL uses named parameters
* positional placeholders are rejected before PDO
* multiple same-backend named SQLite connections stay isolated
* builders terminate through `Database -> Driver`
* safe dynamic SQL helpers are available on `Database`
* array cache reads are metadata-first and invalidate after successful writes
* cache flush ops (`Database::flushCache()`) purge connection-scoped keys when used
* PDO usage remains restricted to the Driver domain

## Command

```bash
vendor/bin/phpunit tests/SQLite tests/Cache tests/Runtime
```

## CI Enforcement

GitHub Actions workflow: `.github/workflows/sqlite-cert.yml`

The `sqlite-cert` job runs on every push and pull request. Steps:

1. Spin up Redis (`redis:7`) and Memcached (`memcached:1.6`) services.
2. Install PHP 8.2 with `redis`/`memcached` extensions and Composer dependencies.
3. Run architecture guardrails with `composer check`.
4. Run `vendor/bin/phpunit tests/SQLite tests/Cache tests/Runtime`.

Outcome: build fails immediately if core/cache certification regresses.
