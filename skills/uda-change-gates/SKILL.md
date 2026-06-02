---
name: uda-change-gates
description: >-
  Verify UDA application or library changes: composer check guardrails, PHPUnit,
  PHPStan. Use before merge or when reviewing DAL PRs. Fails closed on architecture
  violations (PDO outside Driver, import rules).
---

# UDA: change verification

Run from repo root. PHP **≥ 8.2**.

## Required commands

```bash
composer install --no-interaction --prefer-dist
composer check    # architecture scripts (no autoload required for checks)
composer stan     # static analysis on src/
composer test     # PHPUnit
```

High-memory stan:

```bash
php -d memory_limit=-1 vendor/bin/phpstan analyse src/
```

## What `composer check` enforces

- `Purpose:` in every `src/UDA/**/*.php`
- `@license MIT` in every `src/**/*.php` header
- No placeholder `uda.example.com` URLs in `src/`
- PDO usage **only** in `UDA\Driver`
- Single `prepare`/`execute` hot path
- Forbidden cross-domain imports
- Forbidden class suffixes (Manager, Service, …)

Application DAL code under your app namespace is **not** scanned — your discipline is import boundaries (see `uda-dal-layer`).

## Application PR review (hostile)

Reject if the diff:

- Imports `UDA\Driver`, `UDA\Cache`, `UDA\Config`, or `PDO` in application code
- Adds a second SQL execution wrapper
- Uses `?` placeholders
- Adds read-path cache branching (`if (cached)`)
- Omits table hints on raw SQL where production cache is enabled
- Introduces `clearForTests` naming or fake “test-only” public APIs
- Registers `setQueryObserver()` inside hot loops or repository methods (bootstrap only)
- Leaves observer enabled in PHPUnit without `setQueryObserver(null)` in test bootstrap

## Library PR review (UDA repo)

Additionally:

- Run `roam preflight <symbol>` before editing hot symbols
- Run `roam diff` after edits for blast radius
- Update `CHANGELOG.md` `[Unreleased]` for caller-visible changes
- Do not edit `docs/query-cookbook.md` without explicit approval

## Certification (optional, cache/Postgres)

```bash
# SQLite + cache + runtime invariants
vendor/bin/phpunit tests/SQLite tests/Cache tests/Runtime

# Postgres (service + pdo_pgsql)
PGHOST=127.0.0.1 PGPORT=5432 PGDATABASE=testdb PGUSER=postgres PGPASSWORD=postgres \
  vendor/bin/phpunit --bootstrap tests/postgres-bootstrap.php tests/Postgres
```

## Checklist (before merge)

- [ ] `composer check` green
- [ ] `composer stan` green
- [ ] `composer test` green
- [ ] Caller-visible behavior documented in CHANGELOG if this is the UDA package
- [ ] No new public surface on `Driver`/`Cache` for application consumers

## Authority

`CONTRIBUTING.md`, `docs/contract.md`, `tools/check-*.php`.
