# UDA — Universal Data Abstractor

# Specification 2.1

**Status:** Authoritative
**Language:** PHP ≥ 8.2 (strict types required globally)
**Dependencies:** PDO only
Optional: `redis`, `memcached`, `igbinary`

**CI Enforcement:** `.github/workflows/ci.yml` plus merge-blocking `*-integration` workflows run on every push and pull request (see `docs/integration/README.md`). The SQLite job starts Redis/Memcached and executes `vendor/bin/phpunit tests/SQLite tests/Cache tests/Runtime`. Integration status is invalid if any required workflow fails.

---

# 1. System Definition

Universal Data Abstractor (UDA) is a deterministic SQL execution engine with transparent read acceleration.

UDA is the **abstractor**: one pipeline (`Database` → `Driver` → PDO), dialect-aware
builders, named-parameter discipline, and transparent read caching. Integrators
build repositories, DALs, and domain APIs on `Database` or `Link`.

UDA exists to:

1. Centralize SQL.
2. Enforce execution discipline.
3. Accelerate reads safely.
4. Minimize system entropy.

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
Database::connect(string ...$args): Database
```

Arguments are **position independent**. A JSON file path argument selects the
configuration file; any other argument selects the configured connection name.

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

Plan inspection and retry/replay features are deferred from the v1 public
contract unless explicitly reaccepted. Builders may expose `toSql()` for
debugging, but v1 application code must not depend on plan-cache, replay, retry,
or tracing APIs.

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
- Optional `materialized()` / `notMaterialized()` toggles apply to the previously declared CTE. PostgreSQL and SQLite emit `AS MATERIALIZED` / `AS NOT MATERIALIZED`; other dialects ignore the hint while still accepting the fluent call. Hints remain part of the builder's deterministic SQL output.
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
| MERGE (Upsert)    |     ✖️     |   ✖️   |     ✔️     |   ✖️   |   ✔️   |    ✖️   |  ✔️  |
| Recursive CTE     |     ✔️     |   ✔️   |     ✔️     |   ✔️   |   ✔️   |    ✔️   |  ✔️  |
| Writable CTE      |     ✔️     |   ✔️   |     ✖️     |   ✖️   |   ✖️   |    ✖️   |  ✖️  |
| CTE Materialization Hints |  ✔️  |  ✔️  |    ✖️     |   ✖️   |   ✖️   |    ✖️   |  ✖️  |
| Window Functions  |     ✔️     |   ✔️   |     ✔️     |   ✔️   |   ✔️   |    ✔️   |  ✔️  |
| EXPLAIN           |     ✔️     |   ✔️   |     ✔️     |   ✖️   |   ✔️   |    ✔️   |  ✔️  |
| EXPLAIN ANALYZE   |     ✔️     |   ✔️   |     ✔️     |   ✖️   |   ✖️   |    ✔️   |  ✖️  |

* PostgreSQL/SQLite use `INSERT ... ON CONFLICT` and therefore do not require MERGE support.
* SQL Server/Oracle/DB2 compile UPSERT through MERGE when supported; Sybase UPSERT builders are disabled until ASE MERGE is integration-gated; MariaDB uses `ON DUPLICATE KEY`.
* **DB2:** `Query/Dialect/Db2.php` compiles MERGE/pagination; connect via `driver: db2` and `pdo_ibm`. `returning()` remains unsupported.
* Dialect capability flags are validated by `tests/Query/SybaseCapabilitiesTest.php` and related runtime tests, covering returning enforcement, recursive CTE gating, materialization hint exposure, window-function guards, and the per-dialect matrix above.
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

### Deferred Surfaces

Plan caches, prepared-statement caches, tracing, replay, and retry layers are
not part of the v1 public contract. They may return in a later release only if
they preserve the single execution path and prove they improve uniformity,
performance, safety, determinism, or developer clarity.

The v1 debugging surface is deliberately small:

* `lastSql()`
* `lastParams()`
* builder `toSql()`

These surfaces inspect already-compiled statements; they do not create another
execution model.

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

Decision flow:

1. retrieve metadata
2. evaluate table mtime
3. determine action
4. retrieve payload if selected

Payload deserialization before decision is forbidden.

---

# 10. Deferred Cache Policy

TTL policy, TTL-as-interval behavior, and stale-on-error behavior are deferred
from the v1 runtime. V1 cache usability is determined by metadata presence and
table write timestamps.

---

# 11. Table Write Tracking

Driver updates table write timestamps when DML succeeds.

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
* deferred cache policy state is explicit in docs (TTL layering and stale-on-error are not active in v1 runtime)
* no DSN leakage exists
* no Scope classes exist
* configuration ingestion validated
* SQLite integration tests pass
* active guard tests pass
