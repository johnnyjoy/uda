# SQLite Certification (Core Scope)

_Last updated: 2026-03-13_

## Summary

- ✅ Connection bootstrap (memory + temp file DSNs)
- ✅ Builders: SELECT/INSERT/UPDATE/DELETE/UPSERT/RETURNING, CTEs, window clauses, pagination
- ✅ CRUD + transaction behavior validated end-to-end
- ✅ Performance benchmarks captured (see below)
- ✅ Operational suite (guardrails, replay, metrics, retry) – see “Operational Scenarios”
- ⚠️ Cache certification (Redis/Memcached) – depends on services/extensions being available

## Test Evidence

Command:

```
vendor/bin/phpunit \
  tests/SQLite/SQLiteTestCaseTest.php \
  tests/SQLite/SQLiteDialectTest.php \
  tests/SQLite/SQLiteExecutionTest.php \
  tests/SQLite/SQLitePerformanceTest.php
```

Result: `OK (27 tests, 90 assertions)` (runtime ≈ 5.8 s on PHP 8.2)

### Dialect Snapshots

Fixtures live under `tests/SQLite/fixtures/dialect/` and assert emitted SQL for:

| Pattern | Notes |
| --- | --- |
| SELECT | DISTINCT, joins, GROUP BY/HAVING, ORDER/LIMIT |
| INSERT | multi-row VALUES |
| INSERT…RETURNING | returning projections |
| UPSERT | `ON CONFLICT … DO UPDATE/NOTHING` |
| DELETE/UPDATE | RETURNING support |
| CTE | recursive + materialized CTE chains |
| UNION | `UNION ALL` w/ ORDER/LIMIT |

### Execution Scenarios

`tests/SQLite/SQLiteExecutionTest.php` runs each scenario against both DSNs:

- CRUD lifecycle (INSERT/UPDATE/DELETE + row verification)
- RETURNING clauses
- Transactions (commit + rollback)
- Nested transactions via savepoints
- Recursive & non-recursive CTE execution

### Performance Benchmarks

Artifacts: `build/sqlite-cert/benchmarks.json`

| Benchmark | Iterations | Seconds | Ops/sec |
| --- | ---:| ---:| ---:|
| Builder compilation | 10 000 | 2.08 | 4 818 |
| SELECT execution | 2 000 | 0.08 | 26 123 |
| EXPLAIN plan generation | 100 | 0.005 | 21 440 |

## Operational Scenarios (R01b)

Operational certification runs `vendor/bin/phpunit tests/SQLite/SQLiteOperationalTest.php` and covers:

- Guardrail enforcement (blocked deletes, unsafe bypass) with trace verification
- Replay capture (NDJSON) + `QueryReplayer` execution
- Metrics aggregation via `MetricsAggregator`
- Retry policy integration (successful retry + writes_disabled blocking)

## Cache Certification Status (R01c)

Cache harness now runs against live Redis/Memcached services.

```
SQLITE_REDIS_HOST=127.0.0.1 SQLITE_MEMCACHED_HOST=127.0.0.1 \
vendor/bin/phpunit tests/SQLite/SQLiteCacheTest.php
```

Result: `OK (4 tests, 34 assertions)` – each test is executed twice (Redis + Memcached) via data providers.

Highlights:

- First read emits `resultCacheHit=false`; second read hits cache (`resultCacheHit=true`) with `tables=['employees']`.
- Writes invalidate cached entries; subsequent reads emit `resultCacheHit=false` before warming again.
- Metadata-first traces include fingerprint/table information for both stores.

Full SQLite + cache suite:

```
SQLITE_REDIS_HOST=127.0.0.1 SQLITE_MEMCACHED_HOST=127.0.0.1 \
vendor/bin/phpunit tests/SQLite tests/Cache
```

Result: `OK (43 tests, 178 assertions)` – no skips.

## CI Enforcement

GitHub Actions workflow: `.github/workflows/sqlite-cert.yml`

The `sqlite-cert` job runs on every push and pull request. Steps:

1. Spin up Redis (`redis:7`) and Memcached (`memcached:1.6`) services.
2. Install PHP 8.2 with `redis`/`memcached` extensions and Composer deps.
3. Run `SQLITE_REDIS_HOST=127.0.0.1 SQLITE_MEMCACHED_HOST=127.0.0.1 vendor/bin/phpunit tests/SQLite tests/Cache`.

Outcome: build fails immediately if core/operational/cache certification regresses. See workflow logs for details.

## Running Cache Certification Locally

Start disposable services (example using Docker):

```
docker run --rm -p 6379:6379 redis:7
docker run --rm -p 11211:11211 memcached:1.6
```

Set env vars when running PHPUnit:

```
SQLITE_REDIS_HOST=127.0.0.1 SQLITE_MEMCACHED_HOST=127.0.0.1 \
vendor/bin/phpunit tests/SQLite/SQLiteCacheTest.php
```

The test helpers also honor optional `SQLITE_REDIS_AUTH`, `SQLITE_REDIS_PREFIX`, and `SQLITE_MEMCACHED_PREFIX` overrides.
