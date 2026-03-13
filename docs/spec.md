Below is a **fully revised `spec.md`** aligned with:

* the **RFC constitution**
* your **Database-as-handle rule**
* **DSN never in config**
* **Config sanitization on ingestion**
* **Driver as runtime orchestrator**
* **single execution path**
* **transparent cache**
* **no alternate APIs**

I also corrected several subtle contradictions that existed between the previous `spec.md`, the **constitution**, and your **recent design decisions**.

The structure remains familiar so the project does not experience documentation shock, but the rules are now **architecturally locked down**.

---

# UDA — Universal Data Abstractor

# Specification 2.1

**Status:** Authoritative
**Language:** PHP ≥ 8.2 (strict types required globally)
**Dependencies:** PDO only
Optional: `redis`, `memcached`, `igbinary`

**CI Enforcement:** `.github/workflows/sqlite-cert.yml` runs on every push + pull request, starting Redis/Memcached services and executing `vendor/bin/phpunit tests/SQLite tests/Cache`. Certification status is invalid if this workflow fails.

---

# 1. System Definition

Universal Data Abstractor (UDA) is a deterministic SQL execution engine with transparent read acceleration.

UDA exists to:

1. Centralize SQL.
2. Enforce execution discipline.
3. Accelerate reads safely.
4. Minimize system entropy.

UDA is **not**:

* an ORM
* ActiveRecord
* schema reflection
* query inference
* SQL parsing
* automatic table discovery

UDA executes SQL deterministically.

---

# 2. Domain Architecture

UDA is composed of a small number of strictly separated domains.

| Domain       | Responsibility                           |
| ------------ | ---------------------------------------- |
| **Database** | Public API and coordination layer        |
| **Driver**   | Runtime execution engine                 |
| **Query**    | SQL construction                         |
| **Config**   | Configuration ingestion and sanitization |
| **Cache**    | Result caching and invalidation          |
| **Dialect**  | SQL dialect differences                  |

Each domain has a **single master class** responsible for its rules.

---

# 3. Core Architectural Laws

## 3.1 One Execution Path Law

There SHALL be exactly one implementation of:

* prepare
* bind
* execute

This implementation SHALL exist inside **Driver**.

All read and write operations MUST converge to this implementation.

If more than one prepare/execute implementation exists, the system is invalid.

---

## 3.2 Database Domain Master Law

`Database` is the **public database handle**.

Application code MUST treat `Database` as the database.

Responsibilities:

* select connection
* initialize configuration
* lazily bind Driver
* expose execution methods
* create query builders

`Database` MUST remain thin.

---

### Database MUST NOT

* construct SQL
* interact with PDO
* compute DSN
* implement caching
* perform IO
* parse configuration structure
* track table writes

Those responsibilities belong to other domains.

---

## 3.3 Public Surface Contract

### Connect

```
Database::connect(?string $connectionName=null, ?string $configFile=null): Database
```

Arguments are **position independent**.

Examples:

```php
Database::connect()
Database::connect('analytics')
Database::connect('/config/db.json')
Database::connect('analytics', '/config/db.json')
```

Return type is **Database**, never Driver.

---

### Execution Surface

Reads:

```
row()
rows()
value()
values()
list()
each()
```

Writes:

```
exec()
returning()
```

Transactions:

```
transaction(callable $fn)
```

Debug:

```
lastSql()
lastParams()
```

Plan inspection:

```
plan()
explain(callable|Sql|Select $query, bool $analyze = false)
```

- `plan()` terminates any builder and returns the compiled `SqlMessage` (SQL text, parameter map, dialect, referenced tables) without touching PDO. Repositories use it to capture deterministic statements or feed observability tooling.
- `explain()` exposes the connection’s native EXPLAIN surface. Passing `$analyze = true` (or using `explainAnalyze()` on builders) runs the query so the engine can attach actual timing/row data.
- Both entrypoints reuse the single execution path—Database fingerprints the builder, checks dialect capabilities, and flips dialect-specific switches (PostgreSQL `EXPLAIN`, SQL Server `SET SHOWPLAN_XML`, MariaDB `EXPLAIN FORMAT=JSON`, etc.). Unsupported dialects fail fast with `QueryException` so PDO never receives an illegal verb.

---

### Fluent Queries

Query builders originate from `Database`:

```
$db->select()
$db->insert()
$db->update()
$db->delete()
$db->upsert()
```

Execution terminates through the same execution path.

Returning rows (e.g., PostgreSQL `RETURNING`, SQL Server `OUTPUT`, Oracle `RETURNING ... INTO`) use the same path via `Database::returning()` or builder terminators (`row()`, `rows()`, etc.). Dialects translate those clauses per engine, but callers never branch on driver names. On Oracle (validated via `tests/Oracle/ReturningAndMergeTest.php`) the `Insert`/`Update`/`Delete` builders still compile without a literal `RETURNING` clause; the runtime driver appends `RETURNING … INTO :uda_return_n` when executing, binds each placeholder as input/output, normalizes the output keys to lowercase, and rewrites multi-row inserts into per-row statements to avoid ORA-63809.

Supported engines: PostgreSQL, SQLite (3.35+), SQL Server, Sybase, Oracle. MariaDB and DB2 intentionally throw `QueryException` when `returning()` is requested.

### Subqueries & Derived Tables

- `Select::fromSub(Select|Sql $subquery, string $alias)` embeds derived tables (alias required).
- `Select::joinSub()` / `leftJoinSub()` / `rightJoinSub()` join subqueries while keeping the fluent API.
- `WhereBuilder::in()` / `notIn()` accept `Select|Sql` instances in addition to arrays, enabling `WHERE column IN (subquery)`.
- `Select::whereExists()` / `whereNotExists()` accept either `Sql` or another `Select` builder and propagate table attribution.
- `WhereBuilder::whereRaw()` allows precise ON/EXISTS expressions, with placeholder validation.
- `Select::union()` / `unionAll()` compose compound selects. Branches remain standalone builders; ordering/pagination configured on the final builder applies to the combined result set.
- Pagination is implemented once in the builder layer; dialects render the correct syntax (e.g., Oracle emits `OFFSET … ROWS FETCH NEXT … ROWS ONLY`, which has been verified against a live database in the Oracle test suite). MERGE-based UPSERTs now follow the same rule: PostgreSQL/MariaDB generate their native `INSERT … ON CONFLICT/ON DUPLICATE`, while SQL Server/Sybase/Oracle emit MERGE only when the dialect advertises `supportsMerge()`.
- `UDA\Query\Expr` provides optional helpers for structured expressions (aggregates, COALESCE, trusted raw fragments). Builders accept `string|Expr` for select columns, HAVING filters, and ORDER BY clauses while reusing the parent parameter bag so placeholder ordering stays deterministic.
- Every builder memoizes its compiled `Sql` value object per instance. The first `toSql()` call invokes the dialect; subsequent calls on the same builder reuse the cached result to avoid redundant compilation while preserving immutability (cloned builders rebuild their own cache).
- `with()` / `withRecursive()` now work on `Select`, `Insert`, `Update`, and `Delete`. CTEs participate in the builder’s parameter bag (deterministic ordering, no collisions), dialects render `WITH`/`WITH RECURSIVE` per engine, and referenced tables from CTE bodies propagate into the final `Sql` cache hints. Writable CTEs are currently enabled for PostgreSQL and SQLite; other dialects throw explicit `QueryException`s when asked to attach a CTE to INSERT/UPDATE/DELETE so the failure mode stays obvious.
- Optional `materialized()` / `notMaterialized()` toggles apply to the previously declared CTE. PostgreSQL and SQLite emit `AS MATERIALIZED` / `AS NOT MATERIALIZED`; other dialects ignore the hint while still accepting the fluent call. Hints attach to the fingerprint payload so the plan cache distinguishes otherwise identical builders. `tests/Query/CteMaterializationTest.php` proves both emission and fallback behavior.
- `Insert::columns()` + `Insert::select()` introduce `INSERT ... SELECT` support that cooperates with CTEs. `Update`/`Delete` expose `whereRaw()` for the occasional subquery-driven predicate (e.g., `id IN (SELECT ...)`).

### Writable CTE support matrix

| Dialect     | INSERT | UPDATE | DELETE |
| ----------- | :----: | :----: | :----: |
| PostgreSQL  |  ✔️    |  ✔️    |  ✔️    |
| SQLite      |  ✔️    |  ✔️    |  ✔️    |
| SQL Server  |  ✖️    |  ✖️    |  ✖️    |
| Oracle      |  ✖️    |  ✖️    |  ✖️    |
| MariaDB     |  ✖️    |  ✖️    |  ✖️    |
| DB2         |  ✖️    |  ✖️    |  ✖️    |
| Sybase      |  ✖️    |  ✖️    |  ✖️    |

PostgreSQL and SQLite have been validated across all write statements; other dialects intentionally throw `QueryException`s so we never emit illegal SQL until their writable CTE semantics have been verified.

### Dialect Capability Matrix

Builders now consult the dialect’s declared capabilities before emitting SQL. Unsupported features fail immediately with `QueryException` messages such as “MariaDB dialect does not support RETURNING clauses,” ensuring developers never reach PDO with invalid SQL.

| Feature / Dialect | PostgreSQL | SQLite | SQL Server | Sybase | Oracle | MariaDB | DB2 |
| ----------------- | :--------: | :----: | :--------: | :----: | :----: | :-----: | :--: |
| RETURNING         |     ✔️     |   ✔️   |     ✔️     |   ✔️   |   ✔️   |    ✖️   |  ✖️  |
| MERGE (Upsert)    |     ✖️     |   ✖️   |     ✔️     |   ✔️   |   ✔️   |    ✖️   |  ✔️  |
| Recursive CTE     |     ✔️     |   ✔️   |     ✔️     |   ✔️   |   ✔️   |    ✔️   |  ✔️  |
| Writable CTE      |     ✔️     |   ✔️   |     ✖️     |   ✖️   |   ✖️   |    ✖️   |  ✖️  |
| CTE Materialization Hints |  ✔️  |  ✔️  |    ✖️     |   ✖️   |   ✖️   |    ✖️   |  ✖️  |
| Window Functions  |     ✔️     |   ✔️   |     ✔️     |   ✔️   |   ✔️   |    ✔️   |  ✔️  |
| EXPLAIN           |     ✔️     |   ✔️   |     ✔️     |   ✖️   |   ✔️   |    ✔️   |  ✔️  |
| EXPLAIN ANALYZE   |     ✔️     |   ✔️   |     ✔️     |   ✖️   |   ✖️   |    ✔️   |  ✖️  |

* PostgreSQL/SQLite use `INSERT ... ON CONFLICT` and therefore do not require MERGE support.
* SQL Server/Sybase/Oracle/DB2 compile UPSERT through MERGE; MariaDB uses `ON DUPLICATE KEY`.
* Dialect capability flags are validated by `tests/Query/DialectCapabilitiesTest.php`, covering returning enforcement, recursive CTE gating, materialization hint exposure, window-function guards, and the per-dialect matrix above.
- Window helpers (`Expr::rowNumber()`, `Expr::sum(...)->over()` etc.) live entirely within `Expr`. They expose `over()`, `partitionBy()`, `orderBy()`, and frame helpers, remain immutable, merge their parameters into the parent builder deterministically, and let dialects render `OVER (...)` clauses without modifying the core builder grammar.
- Window functions support ranking (`rowNumber()`, `rank()`, `denseRank()`), offsets (`lag()`, `lead()`), and aggregates (`sum/avg/count/min/max`). Call `over()` to begin the window definition, then chain `partitionBy()`, `orderBy()`, and frame helpers (`rowsBetween()`, `rowsBetweenUnboundedPreceding()`, `rowsCurrentRow()`, `rangeBetween()`, `rangeBetweenUnboundedPreceding()`, `rangeCurrentRow()`). All official dialects report `supportsWindowFunctions() === true`, so WO022’s capability checks allow window usage everywhere.

### Window Functions

```php
Expr::rowNumber()
    ->over()
    ->partitionBy('department_id')
    ->orderBy('salary', 'DESC')
    ->rowsBetweenUnboundedPreceding()
    ->as('dept_rank');
```

Compiles to:

```sql
ROW_NUMBER() OVER (
    PARTITION BY department_id
    ORDER BY salary DESC
    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
) AS "dept_rank"
```

Frame helpers support both ROWS and RANGE variants (e.g., `rangeBetween('INTERVAL 7 DAY PRECEDING','CURRENT ROW')`). Window expressions remain regular `Expr` instances, so they work in `select()`, `orderBy()`, subqueries, and everywhere else expressions are accepted.

Parameter placeholders from subqueries are re-allocated within the parent builder to guarantee deterministic naming and prevent collisions. Tables referenced by a subquery are merged into the parent query’s cache hints, so cache invalidation remains accurate.

Example:

```php
$db->select()->from('users')->where('id = :id')->row();
```

---

### Query Plan Cache

UDA reuses compiled SQL plans for identical builder structures. When a builder executes through `Database`, the database computes a structural fingerprint (columns, joins, WHERE/HAVING clauses, unions, window definitions, CTEs, etc.) that explicitly excludes runtime parameter values. Example key:

```
sqlite:0446d0c8a89f0b6a0ee8e0d66f7cb069bdfe4c89ff7e4dfcdc3ce66d6a9da3f7
```

The prefix is the lowercase dialect name; the suffix is `hash('sha256', fingerprintPayload)`. A cache hit clones the stored `SqlMessage` and skips builder->toSql() entirely; a miss compiles the builder once, stores the cloned `SqlMessage`, and returns it. Dialect separation is guaranteed because the dialect name participates in the key (`postgres:...` and `sqlite:...` never collide).

Implementation rules:

* Cache lives in `UDA\Query\QueryPlanCache` (process-wide, in-memory, FIFO eviction)
* Default capacity: 1000 compiled statements; configurable for tests/benchmarks
* Explicit enable/disable toggle (benchmarks compare both modes)
* Cached `SqlMessage` instances are cloned on put/get so parameter binding cannot mutate the stored plan
* Cache key intentionally ignores runtime parameter values—`WHERE id = :q1` is compiled once and reused for `id = 1`, `id = 2`, etc.

This optimization is internal to `Database::executeBuilder()`—repositories and builders retain the same public grammar.

---

### Prepared Statement Cache

After the plan cache hands back a `SqlMessage`, the driver reuses PDO prepared statements whenever the SQL text, connection, and dialect match. Each driver instance keeps a FIFO-bounded (`statement_cache_limit`, default 500) map:

```
{dialect}:{connectionHash}:{sql} → PDOStatement
```

Example key:

```
sqlite:128:SELECT "id" FROM "ps_reuse" WHERE "id" = :q1
```

Every execution performs:

```
lookup prepared statement
  ↓ miss → PDO::prepare() → store
bind named parameters
execute
close cursor so it can be reused immediately
```

Statements never cross connections because the cache is owned by the driver instance. Setting `statement_cache_limit` to `0` disables reuse entirely (useful for benchmarks). Returning/OUTPUT statements participate in the same cache—drivers still re-bind each execution so output buffers remain isolated.

---

### Query Tracing & Retries

Every query execution emits a `QueryTrace` event from the `Database` domain. When a `RetryPolicy` is installed, the policy hooks into the same instrumentation: each retry attempt dispatches `traceType = retry_attempt`, and the final trace (success or failure) carries retry metadata so observability pipelines stay consistent. The trace object contains:

* operation (rows/row/exec/returning/each/value/etc.)
* SQL text and merged parameters (optionally redacted)
* connection identity + dialect name
* execution time in milliseconds
* derived row count or affected rows
* cache signals: plan cache hit, prepared-statement hit, result-cache hit
* referenced tables (carried forward from the originating `SqlMessage`)
* slow-query flag (based on `trace.slow_query_ms`)

Configuration lives under each connection’s `trace` block:

```
"trace": {
    "enabled": true,
    "slow_query_ms": 100,
    "log_slow_queries": true,
    "redact_parameters": false
}
```

* `enabled` toggles tracing even if no listeners are registered.
* `slow_query_ms` defines the threshold (ms). Any trace meeting or exceeding the value is marked `slow = true`.
* `log_slow_queries` writes slow traces to the error log automatically.
* `redact_parameters` replaces every parameter value with `***` before dispatching the trace.

Plan inspection surfaces enter the tracer as `plan`, `explain`, or `explain_analyze` operations. The payload extends with `planSql`/`planDialect` when builders call `plan()`, and with `explainFormat`, `planOutput`, and an `analyze` flag when Database issues engine-level EXPLAINs. Slow-query detection still applies to analyze runs because they execute the underlying SQL; pure logical explains bypass slow classification because Driver never hits PDO.

Listeners implement `QueryTraceListener` and are registered via `Database::addTraceListener()`. Built-ins include a logging listener (`QueryTraceLogger`) for human-readable diagnostics and an in-memory collector (`QueryTraceCollector`) for tests/benchmarks. Traces cover `rows`, `row`, `exec`, `returning`, `each`, `value`, `plan`, `explain`, `explain_analyze`, and the builder delegation path (`executeBuilder`, `executeBuilderReturning`). Tests live in `tests/Query/QueryTracingTest.php`.

A sample payload:

```
{
  "operation": "row",
  "sql": "SELECT \"label\" FROM \"trace_items\" WHERE \"id\" = :q1",
  "parameters": {"q1": 42},
  "dialect": "sqlite",
  "connection": "trace_db",
  "executionTimeMs": 0.87,
  "rowCount": 1,
  "planCacheHit": true,
  "statementCacheHit": true,
  "resultCacheHit": false,
  "tables": ["trace_items"],
  "slow": false
}
```

When tracing is disabled (and no listeners exist) Database short-circuits immediately so overhead remains negligible (<1%). Retry metadata is included only when a policy is installed; otherwise the fields remain `null`/`false` as today.

### Retry Layer

UDA’s retry system is a thin execution wrapper that lives **inside Database** directly above the driver. It obeys the single-path rule by wrapping the canonical execution closure rather than forking logic.

Key rules:

1. **Explicit installation.** Retries activate only when `Database::setRetryPolicy()` receives a `RetryPolicy` instance. The default is “no retry” so the hot path remains untouched.
2. **Guardrails first.** Guardrail validation and builder normalization still run before the policy loops, ensuring unsafe queries cannot sneak through under retried executions.
3. **Read-only by default.** Safe read operations (`rows`, `row`, `value`, `values`, `list`, `each`, `explain`, `explain_analyze`) retry automatically. Writes (`exec`, `returning`, builder terminators that mutate data) require both `retryWrites=true` and a per-query override.
4. **Transaction boundaries respected.** `retryInTransactions=false` blocks retries whenever `Database` is inside a transaction. Enabling it is opt-in and discouraged unless the backend guarantees idempotent blocks.
5. **Builder metadata.** Immutable builders expose `allowRetry()` / `noRetry()` helpers which translate into `SqlMessage->retryAllowed` so retries can be forced on/off per statement even when the operation defaults differ.
6. **Classifier driven.** `TransientErrorClassifier` determines retriable exceptions via driver hints (`Driver::isTransientError()`), curated SQLSTATE tables, and message heuristics. Non-transient exceptions surface immediately.
7. **Instrumentation friendly.** Each attempt emits a `retry_attempt` trace and the final trace stores `retryCount`, `retried`, `finalFailure`, and `retryReasons`. Replay snapshots persist the same metadata so recorded NDJSON streams remain faithful.

See `docs/retry.md` for configuration guidance and code examples.

---

### Forbidden Public APIs

The following SHALL NOT exist:

* `cache()` public API
* scope objects
* alternate execution engines
* explicit cache handles
* direct driver access

There must be **one execution surface only**.

---

# 4. Driver Doctrine

Driver is the **runtime orchestrator**.

Driver SHALL:

* own PDO
* construct DSN
* execute SQL
* enforce a single `prepare/execute` hot path (`Driver::executeInternal`)
* reject positional (`?`) placeholders before hitting PDO
* consult cache
* populate cache
* manage transactions
* inform cache of writes
* inject dialect behavior

---

### Driver SHALL NOT

* build SQL
* parse SQL
* infer table names
* perform query construction

---

# 5. Query Domain

Query domain produces **immutable Sql value objects**.

A Sql object contains:

* SQL string
* named parameters
* explicit table list
* optional metadata hints

Query objects SHALL NOT:

* execute
* interact with PDO
* access cache
* branch on driver name

Driver-specific SQL differences are injected through **Dialect**.

---

# 6. State Minimalism Mandate

State SHALL exist only in:

| Component       | Reason                  |
| --------------- | ----------------------- |
| Driver          | connection runtime      |
| Cache backend   | cache store             |
| Config Snapshot | immutable configuration |

Everything else must remain:

* immutable
* clone-on-write
* stateless

Query builders must not hold runtime state.

---

# 7. Configuration

The Config domain is governed by **Config**, its Domain Master.

Configuration responsibilities:

* load JSON configuration
* validate structure
* resolve environment secrets
* normalize values
* construct immutable Snapshot

All sanitization occurs **during ingestion**.

Runtime code must never normalize configuration values.

---

## 7.1 Configuration Source

Configuration is loaded from exactly one JSON file.

Two routes exist:

Environment variable:

```
UDA_CONFIG=/path/to/config.json
```

Explicit override:

```php
Database::connect(null, '/path/to/config.json')
```

Configuration is loaded once and cached for the process lifetime.

---

## 7.2 Configuration Format

Configuration must:

* be JSON
* have `.json` extension
* contain a JSON object root

PHP configuration files are not supported.

---

## 7.3 Connection Identity

Connections are **configuration snapshots**.

There is no Connection class.

Driver instances are created lazily.

---

## 7.4 DSN Construction Doctrine

Applications MUST NOT supply DSN strings.

Configuration provides:

```
driver + params
```

Drivers construct DSNs internally.

This prevents DSN leakage to application code.

---

# 8. Transparent Cache Model

Caching is **configuration-driven**.

Cache is never explicitly requested.

If caching is enabled:

* all read terminators consult cache
* cache miss triggers DB query
* successful reads populate cache

If caching is disabled:

* cache code must not execute.

---

# 9. Cache Metadata Doctrine

Cache decisions must be **metadata-first**.

Cache backend must provide:

```
getMeta(string $key)
getResult(string $key)
set(string $key, Meta $meta, mixed $result, int $ttl)
```

Decision flow:

1. retrieve metadata
2. evaluate TTL
3. evaluate table mtime
4. determine action
5. retrieve payload if selected

Payload deserialization before decision is forbidden.

---

# 10. TTL Model

Every cache entry must have TTL.

TTL resolution hierarchy:

1. per-call override
2. per-table override
3. per-connection default
4. global default

TTL ≤ 0 disables caching.

Infinite TTL is forbidden.

---

# 11. TTL-as-Interval Mode

If `ignoreTableMtimeWithinTtl = true`:

* cached result returned if TTL valid
* table writes ignored within TTL

If false:

* table mtime invalidates immediately

This mode throttles write-heavy tables.

---

# 12. Stale-on-Error Doctrine

If:

* database throws transient exception
* policy allows stale
* cache age ≤ maxStaleSeconds

then cached result SHALL be returned.

Otherwise exception propagates.

---

# 13. Table Write Tracking

Driver informs **TableWriteTracker** when DML succeeds.

Write timestamps are tracked:

* per connection
* per table

Query builders must supply table list explicitly.

Raw SQL requires table hints to enable invalidation.

Query domain SHALL NOT parse SQL.

---

# 14. Fluent Grammar Law

Builders enforce deterministic grammar.

Supported clauses:

```
where()
and()
or()
not()
in()
between()
exists()
distinct()
groupBy()
having()
orderBy()
limit()
offset()
upsert()
```

Builders SHALL NOT:

* execute SQL
* produce alternate read surfaces
* generate phase-specific builder classes

Grammar enforcement must use an internal state machine.

---

# 15. UPSERT Doctrine

Upsert requires explicit conflict key.

Engine-specific behavior is implemented in Driver.

Silent replace is forbidden.

Bulk upsert must not create alternate execution paths.

---

# 16. Transactions

Nested transactions are required.

If backend supports savepoints:

* savepoints SHALL be used.

Otherwise:

* nested counter emulation.

Driver owns transaction orchestration.

Dialect provides savepoint SQL.

---

# 17. Repository Boundary Doctrine

Application code must interact with data via **repository classes**.

Repositories:

* use Database
* centralize SQL
* manage query logic

Application code must never:

* use PDO
* construct DSN
* execute SQL outside repositories

---

# 18. Performance Mandate

If cache hit rate ≥ 80%:

UDA must outperform direct PDO usage.

Metadata-first evaluation is mandatory to maintain performance.

---

# 19. Enforcement Requirements

Specification violations must be test-detectable.

Tests must detect:

* duplicate prepare/execute implementations
* forbidden classes (`Scope`, `Connection`)
* PDO usage outside Driver
* alternate cache execution paths
* positional `?` parameters
* DSN in configuration

---

# 20. Compliance Definition

The system is compliant when:

* exactly one execution path exists
* exactly one read surface exists
* cache is transparent
* metadata-first caching implemented
* TTL layering implemented
* stale-on-error implemented
* no DSN leakage exists
* no Scope classes exist
* configuration ingestion validated
* SQLite integration tests pass
* policy guard tests pass
