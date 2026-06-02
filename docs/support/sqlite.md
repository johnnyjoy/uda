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
| EXPLAIN / EXPLAIN ANALYZE | Deferred | Not part of the v1 public contract |
| Result cache integration | ✅ | `tests/Cache` — metadata-first reads, invalidation, flush |
| Guardrails | ✅ | `tests/Runtime` — PDO boundary, architecture invariants |
| Tracing / Replay / Metrics / Retry | Deferred | Not part of the v1 public contract |

**Legend**: ✅ implemented & tested · ⚠️ pending/blocker
