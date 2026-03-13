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
| Result cache integration | ⚠️ Blocked | R00x exposes `tableHints`/trace propagation; full cache certification (Array/Redis/Memcached harness) still pending |
| Guardrails | ⚠️ Blocked | Guardrail module not committed; tests pending R01b |
| Tracing / Replay / Metrics / Retry | ⚠️ Blocked | Modules absent; add once merged |

**Legend**: ✅ implemented & tested · ⚠️ pending/blocker
