# SQLite Support Matrix

| Capability | Status | Notes |
| --- | --- | --- |
| Connections (`sqlite::memory:`, `sqlite:/tmp/...`) | ✅ | Verified via shared `SQLiteTestCase` helpers |
| Raw SQL execution (`row`, `rows`, `value`, `values`, `list`) | ✅ | Covered by CRUD + transaction tests |
| Fluent query builders (SELECT/INSERT/UPDATE/DELETE/UPSERT) | ✅ | Dialect snapshots ensure SQL parity |
| RETURNING clauses | ✅ | Supported and tested for INSERT/UPDATE/DELETE |
| Transactions & savepoints | ✅ | Commit/rollback + nested savepoint coverage |
| Recursive / non-recursive CTEs | ✅ | Execution tests assert results |
| Window functions | ✅ | Exercised via builders + snapshots |
| UNION/INTERSECT/EXCEPT | ✅ | Dialect fixtures cover `UNION ALL` etc. |
| EXPLAIN / EXPLAIN ANALYZE | ✅ | Database `explain*` routes verified |
| Result cache integration | ⚠️ Blocked | Harness exists (Redis/Memcached tests) and runs in `sqlite-cert` workflow; CI depends on services/extensions being available |
| Guardrails | ✅ | Guardrail violation + unsafe bypass covered in `SQLiteOperationalTest` (enforced via CI) |
| Tracing / Replay / Metrics / Retry | ✅ | Operational suite covers replay capture, metrics aggregator, retry policy (enforced via `sqlite-cert` workflow) |

**Legend**: ✅ implemented & tested · ⚠️ pending/blocker
