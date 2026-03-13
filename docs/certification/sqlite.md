# SQLite Certification (Core Scope)

_Last updated: 2026-03-13_

## Summary

- ✅ Connection bootstrap (memory + temp file DSNs)
- ✅ Builders: SELECT/INSERT/UPDATE/DELETE/UPSERT/RETURNING, CTEs, window clauses, pagination
- ✅ CRUD + transaction behavior validated end-to-end
- ✅ Performance benchmarks captured (see below)
- ⚠️ Operational suite (guardrails, tracing, replay, metrics, retry, cache stores) **blocked** pending committed modules & API fixes

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

## Blockers for Operational Suite (R01b)

1. **Guardrails / Tracing / Replay / Metrics / Retry modules** are untracked in this worktree. Need committed sources so PHPUnit can autoload them.
2. **Cache certification (R01c)** – the API blocker is now resolved via R00x (raw SQL callers can pass `tableHints`, and cache/tracing layers consume them). The remaining work is writing the certification harness (Redis/Memcached suites + documentation).

Once those are addressed we can add:

- Guardrail enforcement tests (unsafe deletes, audit traces)
- Replay capture + NDJSON verification
- Metrics aggregation counters
- Retry policy coverage on SQLite
- Cache store certification (Array/Redis/Memcached)

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
