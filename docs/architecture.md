# UDA Architecture

## Mission

UDA exists to provide:

* **Uniform database access**
* **Maximum read performance**
* **Minimum abstraction**

UDA does not model relational structure.
UDA executes SQL deterministically.

## CI Enforcement

SQLite certification is continuously validated by `.github/workflows/sqlite-cert.yml`. The `sqlite-cert` job runs on every push and pull request, starts Redis and Memcached service containers, installs PHP 8.2 with `redis`/`memcached` extensions, and executes `SQLITE_REDIS_HOST=127.0.0.1 SQLITE_MEMCACHED_HOST=127.0.0.1 vendor/bin/phpunit tests/SQLite tests/Cache`. Any certification regression fails the build immediately.

---

# Canonical Runtime Diagram

Every database operation in UDA follows this exact path.

```
Application
     ↓
Repository
     ↓
Database
     ↓
RetryPolicy (optional)
     ↓
Driver
     ↓
Cache (decision)
     ↓
Executor
     ↓
PDO
     ↓
Database Engine
```

There is **exactly one execution pipeline**.

No alternate execution paths may exist.

---

# Write Execution Flow

```
Application
     ↓
Repository
     ↓
Database
     ↓
Driver
     ↓
Executor
     ↓
PDO
     ↓
Database Engine
     ↓
TableWriteTracker
     ↓
Cache invalidation
```

---

# Read Execution Flow

```
Application
     ↓
Repository
     ↓
Database
     ↓
Driver
     ↓
Cache metadata decision
     ↓
Cache hit → return result
Cache miss → Executor → PDO
     ↓
Store result in Cache
```

---

# Domains

UDA contains six domains.

| Domain       | Role                     |
| ------------ | ------------------------ |
| **Database** | Public database API      |
| **Driver**   | Runtime execution engine |
| **Query**    | SQL construction         |
| **Dialect**  | SQL dialect differences  |
| **Config**   | Configuration ingestion  |
| **Cache**    | Result caching           |

Each domain has a **single domain master** responsible for its behavior.

---

# Domain Responsibilities

## Database

Database is the **public handle**.

Responsibilities:

* select connection
* initialize configuration
* lazily bind Driver
* expose execution methods
* create query builders
* route RETURNING/OUTPUT requests through `returning()` so writes still flow through the single execution path
* inject cross-cutting execution layers (retry, tracing) **after** guardrails but before Driver

Database does **not execute SQL**.

### Retry Policy Placement

When `Database::setRetryPolicy()` receives a `RetryPolicy`, the database wraps every execution closure with that policy:

```
builder/raw sql
    ↓ guardrails
Database retry wrapper (optional)
    ↓ Driver
```

The wrapper respects the single-path rule by looping on the same driver call rather than branching execution. Guardrails always run before the loop so unsafe queries are blocked once, even when retries are enabled.

---

## Driver

Driver is the **runtime orchestrator**.

Responsibilities:

* construct DSN
* manage PDO connection
* execute SQL
* consult cache
* populate cache
* manage transactions
* track table writes
* inject dialect behavior

Driver is the **only domain allowed to interact with PDO**.

---

## Query

Query domain constructs SQL.

Query produces immutable **Sql value objects** containing:

* SQL string
* named parameters
* explicit table list
* optional metadata hints
* optional returning column metadata for engines that support OUTPUT/RETURNING clauses
* embedded subqueries with alias metadata (for derived tables and JOINs)

Builders are immutable—every fluent mutation (e.g., `->where()`) returns a cloned builder so repositories can reuse base chains safely.

Derived tables and subqueries are compiled at the builder layer. `fromSub()` / `joinSub()` / `whereExists()` etc. accept child builders, rename their parameters deterministically, and merge their table lists into the parent query’s cache hints. Aliases are mandatory so derived tables remain addressable within the parent query. Compound selects reuse the same mechanism via `union()`/`unionAll()`: branches are cloned, parameters are reallocated in the parent builder, and ordering/pagination live at the final level. Expression helpers (`UDA\Query\Expr`) stay in the Query domain as optional structural helpers; builders convert them to SQL during compilation, merging their named parameters into the same `ParamBag` so determinism and cache attribution remain intact, while still accepting raw column strings by default. Expression windows (`Expr::rowNumber()->over()->partitionBy()...`) extend that contract: `Expr` tracks the window specification immutably and hands it to the dialect so `OVER (...)` rendering remains centralized and parameters from partition/order clauses reuse the parent `ParamBag`. Common Table Expressions follow the same pattern: `Select`, `Insert`, `Update`, and `Delete` all share the `ConsumesCtes` trait so `with()` / `withRecursive()` behave identically, dialects emit the `WITH` / `WITH RECURSIVE` block, and the CTE bodies share the parent parameter bag and table attribution. (Only PostgreSQL and SQLite currently allow writable CTE emission; other dialects throw early.) Because builders are immutable, each builder instance memoizes the compiled `Sql` object after the first `toSql()` call so repeated executions reuse the same deterministic SQL without invoking the dialect again.

Query objects must never:

* execute SQL
* access PDO
* access cache
* branch on driver name

---

### Query Plan Cache

Repeatedly compiling identical builder trees wastes CPU. Database owns a **plan cache** that stores the `SqlMessage` produced for a builder fingerprint. The fingerprint captures the builder structure (selected columns, joins, where/having clauses, CTE definitions, etc.) but intentionally ignores runtime parameter values—`WHERE id = :q1` and `WHERE id = :q1` with different values reuse the same fingerprint. The plan cache key is `strtolower(dialect->name()) . ':' . fingerprint`. Dialect safety prevents, for example, PostgreSQL plans from being reused under SQLite.

Cache implementation details:

* global in-memory store `UDA\Query\QueryPlanCache`
* FIFO eviction with configurable maximum (default 1000 compiled statements)
* disabled/enabled toggle for benchmarks or diagnostics
* stored `SqlMessage` instances are cloned on insert and retrieval so parameter binding/mutation cannot leak between executions

Execution flow with caching:

```
Builder
    ↓ fingerprint()
Database → QueryPlanCache lookup (dialect + fingerprint)
    ↓ miss → builder->toSql() → SqlMessage stored
    ↓ hit  → cloned SqlMessage reused immediately
Driver executes SqlMessage
```

Drivers remain unaware of caching; they still receive a `SqlMessage`. The optimization is entirely inside Database so query grammar and repositories remain unchanged.

---

### Prepared Statement Cache

Query plan caching stops redundant SQL compilation, but PDO would still re-prepare the same statement over and over. Each `Driver` instance therefore owns a connection-scoped **prepared statement cache**. Keys include the dialect name, an internal connection hash, and the raw SQL string (e.g., `sqlite:42:SELECT "id" FROM "users" WHERE "id" = :q1`). Values are the live `PDOStatement` handles. The cache is FIFO-bounded (default 500 statements per connection); eviction closes the cursor before discarding the handle. Every execution follows the same flow:

```
SqlMessage (from plan cache)
    ↓
Driver looks up statement cache key
    ↓ miss → PDO::prepare() once, store handle
    ↓ hit  → reuse prepared PDOStatement
bind named parameters
execute
close cursor → ready for next bind
```

The cache never crosses connections or dialects because it lives inside each driver instance. Oracle’s `RETURNING … INTO` helpers use the same cache so output buffers remain stable even when statements are reused. Together, the plan cache + prepared statement cache remove both the SQL compilation cost and the database preparation cost while preserving determinism and the single execution path.

---

### Query Tracing & Observability

Every database execution now emits a `QueryTrace` event from the Database domain. The trace records the canonical SQL text, bound parameters (optionally redacted), connection name, dialect, execution time in milliseconds, inferred row/affected counts, cache signals (plan-cache hit, prepared-statement hit, result-cache hit), referenced tables, and a `slow` flag derived from the configured threshold. The capture point sits in `Database::traceOperation()` so both builder-driven and raw SQL paths flow through the same instrumentation without touching Driver.

Traces are delivered to registered `QueryTraceListener` instances (via `Database::addTraceListener()`), which keeps observability pluggable. Two listeners ship by default:

1. **QueryTraceLogger** – logs concise `[connection][dialect][ms][rows] SQL` summaries (slow traces automatically append ` [SLOW]`).
2. **QueryTraceCollector** – retains traces in memory for testing, benchmarks, and diagnostics.

Connection config enables tracing per connection:

```
"trace": {
    "enabled": true,
    "slow_query_ms": 100,
    "log_slow_queries": true,
    "redact_parameters": false
}
```

* `enabled` toggles tracing even without listeners (useful for dev tooling and diagnostics).
* `slow_query_ms` defines the threshold; any trace meeting or exceeding the value sets `slow = true`.
* `log_slow_queries` writes slow-trace summaries to the PHP error log without requiring a listener.
* `redact_parameters` replaces every bound value with `***` in the emitted payload.

Example collector payload:

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

When tracing is disabled and no listeners are registered the instrumentation short-circuits immediately to keep overhead negligible (<1%). When enabled, traces cover every Database entrypoint including `rows`, `row`, `exec`, `returning`, `each`, and the builder delegation path so repositories gain observability without modifying their query code. Plan inspection uses the exact same pathway: `plan()`, `explain()`, and `explainAnalyze()` emit operations of `plan`, `explain`, and `explain_analyze` with extra payload (`planDialect`, `planSql`, `explainFormat`, `planOutput`, `analyze`) so listeners can correlate captured plans with timings and cache signals. Analyze runs still participate in slow-query detection because they execute SQL; logical explains bypass the timer entirely because Driver never reaches PDO.

---

## Dialect

Dialect provides engine-specific SQL differences.

Examples:

* UPSERT syntax
* LIMIT/OFFSET syntax
* SAVEPOINT commands
* identifier quoting
* RETURNING/OUTPUT/RETURNING...INTO clause generation

Dialect is injected into Query by Driver.

---

## Config

Config performs **configuration ingestion**.

Responsibilities:

* load JSON configuration
* validate structure
* resolve environment secrets
* normalize values
* construct immutable Snapshot

Config performs **all sanitization during ingestion**.

Runtime code must never sanitize configuration values.

---

## Cache

Cache provides transparent read acceleration.

Responsibilities:

* metadata-first decision making
* result storage
* stale policy enforcement
* TTL enforcement

Cache does not:

* execute SQL
* interpret SQL
* parse queries

---

# Domain Boundaries

The following boundaries are absolute.

| Rule                               | Explanation                         |
| ---------------------------------- | ----------------------------------- |
| Only **Driver** touches PDO        | centralizes database access         |
| Only **Driver** orchestrates Cache | cache remains transparent           |
| **Query** never imports Cache      | SQL construction remains pure       |
| **Query** never touches PDO        | query objects remain value objects  |
| **Config** performs ingestion only | runtime code never sanitizes config |
| **Cache** never executes SQL       | caching remains passive             |

---

# Metadata-First Cache Model

Cache decisions must use metadata only.

Decision sequence:

```
getMeta(cacheKey)
    ↓
check TTL
    ↓
check table write timestamp
    ↓
determine action
    ↓
getResult() only if selected
```

Payload deserialization before decision is forbidden.

---

# State Locations

UDA minimizes mutable state.

State exists only in:

| Component       | Purpose                  |
| --------------- | ------------------------ |
| Driver          | connection runtime       |
| Cache backend   | persistent cache storage |
| Config Snapshot | immutable configuration  |

Everything else must remain:

* immutable
* stateless
* clone-on-write

---

# File System Discipline

The codebase must remain structurally simple.

Forbidden patterns:

* duplicate execution logic
* alternate execution paths
* scope-based execution trees
* builder/executor hybrids
* multiple query grammars

UDA must maintain **one runtime universe**.

---

# Deletion Rule

If a layer does not improve:

* uniformity
* performance

it must be removed.

Architectural simplicity is mandatory.

---

# Architecture Invariant

Every SQL operation must follow:

```
Repository → Database → Driver → Executor → PDO
```

If any component bypasses this path, the architecture is invalid.
