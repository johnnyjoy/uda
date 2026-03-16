# PostgreSQL Certification (R02 in progress)

_Last updated: 2026-03-14_

## Current Scope

- ✅ Base fixture + schema harness (`tests/Postgres/PostgresTestCase.php`) recreates deterministic tables (departments, employees, audit_log, tree_nodes, transactions) and seeds fixtures before every test.
- ✅ `tests/Postgres/PostgresTestCaseTest.php` validates schema creation, fixture contents, sequence resets, and traces (via `QueryTraceCollector`).
- 🔄 Dialect, execution, operational, cache, and performance suites still pending per work order R02.
- ⚠️ CI workflow (`postgres-cert`) not yet defined; will land after suites exist.

## Base Fixture Validation

Command:

```
vendor/bin/phpunit tests/Postgres/PostgresTestCaseTest.php
```

Environment (defaults in helper, override via env vars):

| Variable | Default | Notes |
| --- | --- | --- |
| `PGHOST` | `127.0.0.1` | Real PostgreSQL host |
| `PGPORT` | `5432` | Must allow TCP connections |
| `PGDATABASE` | `testdb` | Database created ahead of time |
| `PGUSER` | `postgres` | User with schema privileges |
| `PGPASSWORD` | `postgres` | Matching password |

Result (2026-03-14):

```
OK (1 test, 8 assertions)
```

Evidence captured with `pdo_pgsql` enabled and Dockerized PostgreSQL exposed on `127.0.0.1:5432` (user `postgres`, db `testdb`).

### Trace Evidence

- `PostgresTestCaseTest::testSchemaAndFixturesAreDeterministic` registers a `QueryTraceCollector`, runs all schema + fixture assertions, and asserts at least one trace was captured. This ensures guardrail/operational suites can rely on trace plumbing before hitting PostgreSQL-specific behavior.
- When the suite runs with PostgreSQL available, attach the PHPUnit output (and optionally `var_export($collector->getTraces())` summaries) here to prove bootstrap behavior.

## Dialect Snapshots

Command:

```
vendor/bin/phpunit tests/Postgres/PostgresDialectTest.php
```

Result (2026-03-14):

```
OK (9 tests, 18 assertions)
```

Coverage:

- `tests/Postgres/PostgresDialectTest.php` + fixtures under `tests/Postgres/fixtures/dialect/`
- Validates SELECT builder snapshots with ROW_NUMBER window expressions, multi-row INSERTs, RETURNING clauses for INSERT/UPDATE/DELETE, `INSERT ... ON CONFLICT DO UPDATE/NOTHING`, `WITH RECURSIVE` materialized CTE chains, and UNION ALL ordering.
- Provides deterministic SQL evidence mirroring the SQLite certification layout.

## Execution Scenarios

Command:

```
vendor/bin/phpunit tests/Postgres/PostgresExecutionTest.php
```

Result (2026-03-14):

```
OK (8 tests, 26 assertions)
```

Highlights:

- Exercises SELECT/WHERE/LIMIT/OFFSET ordering, INSERT/UPDATE/DELETE with RETURNING, and ON CONFLICT upserts (both DO UPDATE + DO NOTHING) against live fixtures.
- Validates recursive CTEs using `tree_nodes`, unions between employees/departments, ROW_NUMBER window functions, and raw `Sql::of()` executions feeding `Database::rows()`.
- Each scenario runs through `withPostgresDb`, relying on the shared schema + deterministic fixtures added in Task 1.

## Transaction Semantics

Command:

```
vendor/bin/phpunit tests/Postgres/PostgresTransactionTest.php
```

Result (2026-03-14):

```
OK (3 tests, 11 assertions)
```

Focus areas:

- Verifies commit persistence, exception-triggered rollbacks, and nested `Database::transaction()` calls that rely on PostgreSQL savepoints.
- Uses deterministic inserts into `transactions` with explicit IDs to prove state isolation and cleanup.

## Explain / Plan Evidence

Command:

```
vendor/bin/phpunit tests/Postgres/PostgresExplainTest.php
```

Result (2026-03-14):

```
OK (4 tests, 7 assertions)
```

Highlights:

- `Database::select()->explain()` and `->explainAnalyze()` produce non-empty plan rows (PostgreSQL `QUERY PLAN` output) against seeded tables.
- Safe-write builders (`Insert`) can be explained without mutating tables, ensuring certification harnesses remain deterministic.
- Raw SQL passed via `Database::explain()` returns plan text as expected, establishing parity with SQLite’s explain suite.

## Next Steps

1. Expand core suites (dialect/execution/transactions/explain) following `docs/plans/2026-03-13-r02-postgres-plan.md` and capture their outputs in this document.
2. Add operational/cache/performance suites plus `.github/workflows/postgres-cert.yml`, mirroring the SQLite certification pipeline.
