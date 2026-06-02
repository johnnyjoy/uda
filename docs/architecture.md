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
Driver
     ↓
Cache (decision)
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
PDO
     ↓
Database Engine
     ↓
Cache table touch
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
Cache miss → PDO through Driver hot path
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
* keep application code on the single `Database -> Driver` path

Database does **not execute SQL**.

### Public API Placement

`Database` is intentionally thin. It normalizes raw SQL and builder output into
the execution envelope, then delegates to `Driver`. Retry, tracing, replay, and
plan-cache layers are deferred from v1 until they can be proven without adding
another public model or execution path.

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

### Deferred Runtime Extensions

Plan caches, prepared-statement caches, tracing, replay, and retry behavior are
not part of the v1 architecture. They must not appear in user-facing docs or
code paths until they are explicitly reaccepted and proven to preserve the
single execution path.

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
Repository → Database → Driver → PDO
```

If any component bypasses this path, the architecture is invalid.

---

# Connection Pool

UDA maintains one Database handle and one PDO connection per named connection
per process lifetime. There is **one pool** — `Database::$databases`.

**`Database::connect($name)`** is the entry point and the pool gate. The first
call for a given name constructs and caches a `Database` instance (which owns
a `Driver` instance, which owns the `PDO`). Every subsequent call for the same
name returns the cached instance with a single array lookup. When the name is
already known and pooled, argument parsing and `Config::init()` are bypassed
entirely.

**`Driver` has no static pool.** The `Driver` instance is long-lived because
`Database` holds a stable reference to it. The `Driver` replaces only its
internal `PDO` when `executeInternal()` hits a reconnectable connection failure
(SQLSTATE class `08`, common MySQL `2006`/`2013`, or similar): it calls
`reconnect()` and retries the same prepare/execute **once**. There is no
proactive `SELECT 1` ping on the happy path.

**Prepared statement LRU (per `Driver`):** Up to 64 distinct normalised SQL
strings map to reused `PDOStatement` instances for the current `PDO`, reducing
repeat `prepare()` cost in long-lived workers. The cache is keyed by the exact
query string passed to `prepare()`. It is **cleared entirely on `reconnect()`**
because statements are invalid after the `PDO` handle is replaced.

Optional external timing: `tools/benchmark-prepared-lru.php` exercises the production
LRU path only (no in-process disable switch). Compare runs across revisions or
database targets; use APM/profiling for production evidence.

**`Link` classes** cache the `Database` handle in a `private static ?Database`
property on the consuming class. After the first call, `handle()` costs one null
check — no syscalls, no Config reads, no pool lookups.

## Process model notes

| Process model | Pool behaviour |
|---|---|
| PHP-FPM (one process per request) | Pool resets between requests. One PDO per named connection per request. |
| Long-running (Swoole, RoadRunner, Octane) | Pool persists across requests. Dropped connections are detected when a statement fails, then transparent reconnect + single retry. See [Concurrency in long-running workers](#concurrency-in-long-running-workers). |

## Concurrency in long-running workers

PHP-FPM gives each request a fresh process, so UDA's pool, transaction state, and
debug fields behave like request-scoped resources. **Long-running workers do not.**

In Swoole, RoadRunner, Laravel Octane, and similar runtimes, `Database::connect()`
returns the **same** `Database` instance (and the same underlying `PDO`) for a
given connection name for the lifetime of that worker process. That is intentional
for performance; it is **not** safe to treat the handle as request-isolated when
multiple requests or coroutines can run concurrently in one process.

### Rules

1. **One in-flight transaction per pooled handle.** `Database::transaction()` and
   nested savepoints assume sequential use of one `Driver` / `PDO`. Do not start
   or interleave transactions on the same connection name from concurrent
   coroutines, async tasks, or parallel fibers without your own locking.

2. **Do not share a connection name across concurrent work without serialization.**
   If two coroutines call `Database::connect('app')` at the same time, they receive
   the **same** object. Concurrent `prepare()` / `execute()` on one PDO is undefined
   at the application layer and can corrupt transaction state. Use separate worker
   processes, separate connection names per isolation boundary, or an explicit
   mutex around all use of that handle.

3. **`Link` uses the same pool.** A class with `use Link` memoizes one `Database`
   handle per class (static). All instances share that handle — same concurrency
   rules as `Database::connect()`.

4. **`lastSql()` and `lastParams()` are not request-safe under concurrency.**
   They record the **last operation on that pooled handle**, not "the current
   HTTP request." In Octane/RoadRunner, logging them from middleware after the
   response can show another request's SQL.    Use only for single-threaded debugging, or add application-level query instrumentation.

5. **Reconnect does not fix concurrent misuse.** Transparent reconnect replaces a
   dropped `PDO` and retries **one** failed operation. It does not merge or isolate
   overlapping callers on the same handle.

### Operational checklist (workers)

- Prefer **one logical flow at a time** per connection name inside a worker, or
  isolate with locks / separate connection names.
- Keep transactions **short**; mid-transaction connection loss still fails the
  transaction (expected).
- Do not rely on `lastSql()` / `lastParams()` for production request tracing in
  pooled workers.
- For high concurrency across many clients, use an external pooler (PgBouncer,
  ProxySQL) **in addition to** UDA's process-level pool — see below.

## PDO error mode

UDA enforces `PDO::ERRMODE_EXCEPTION` unconditionally. Consumer config may add
or override other PDO options but cannot silence exceptions. This is required
for reliable error handling and reconnect classification.

For high-concurrency long-running deployments an external connection pooler
(PgBouncer for PostgreSQL, ProxySQL for MySQL) is still recommended alongside
UDA's process-level pool.

## Driver runtime decomposition

`UDA\Driver` remains the only PDO owner. Internal slices live under `UDA\Driver\`
(e.g. `Transport`, `Oracle\Returning`) per [architecture-driver-runtime.md](architecture-driver-runtime.md).
