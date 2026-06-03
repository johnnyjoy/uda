# UDA Drivers

**Purpose:** Provide engine-specific behavior inside the Driver domain: DSN construction, identifier quoting rules, pagination fragments, savepoint syntax, and database-specific UPSERT strategies — while preserving a single execution hot path.

See: `spec.md`, `constitution.md`, and `architecture.md`.

---

## Vocabulary (read this first)

**Both words are correct — they name different roles.** A car has an **engine** (the motor) and a **driver** (who operates it). UDA has the same split:

| Car | UDA | What it is |
| --- | --- | ---------- |
| **Engine** (V8, diesel, electric) | **Engine** (`pgsql`, `sqlserver`, `sybase`, …) | SQL semantics family — dialect, quoting, pagination, UPSERT rules. *What kind of motor.* |
| **Driver** (person at the wheel) | **`UDA\Driver`** (`Driver.php`, instance) | Runtime operator — owns PDO, executes SQL, manages transactions. *Who drives.* |
| **Shop manual for that engine model** | **Per-engine class** (`Driver\PostgreSQL.php`, …) | Static spec sheet: `::dsn()`, `::quoteIdentifier()`, `::limitOffset()`. Tells the driver how to connect and speak SQL for that engine. **Never** owns PDO. |
| **Fuel-line adapter** (which hose fits) | **Transport** (`sqlsrv`, `dblib`, `pgsql`, …) | PDO DSN prefix only — how PHP wires up to the server. Same engine can have more than one adapter (SQL Server over `sqlsrv` or `dblib`). |

```
You configure:  engine (+ optional transport)     ← what motor, which hose
Shop manual:    UDA\Driver\{Engine}::dsn()        ← build connection string
Driver sits in: UDA\Driver                         ← new PDO(), then execute everything
Database calls: $driver->prepare(), ->begin(), …
```

**The naming mess:** config JSON still uses the key `"driver"` for what we now call **engine** — as if the car’s title said “driver: V8” when it meant “engine: V8.” That collided with **`UDA\Driver`** (the actual driver) and with **PDO driver** (PHP extension jargon: `pdo_pgsql`, `pdo_sqlsrv`). Hence **`Config::engine()`** in PHP and **`UDA\Query::$engine`** on builders; config key stays `"driver"` so existing deployments do not break.

| Term | Location | Role |
| ---- | -------- | ---- |
| **`UDA\Driver`** | `src/UDA/Driver.php` | **The driver.** Runtime instance; only place that calls `new PDO()`. |
| **Per-engine class** | `src/UDA/Driver/*.php` | **Engine manual.** Static rules for one SQL family; no PDO ownership. |
| **Config `driver`** | JSON | **Engine identity** (JSON key name; value is the engine). Read via `Config::engine()`. |
| **`Config::engine()`** | PHP API | Canonical engine key from snapshot. |
| **`Config::driver()`** | PHP API | Deprecated alias for `engine()`. |
| **`UDA\Query::$engine`** | Query builders | Engine key bound for identifier quoting. |
| **Config `transport`** | JSON (optional) | PDO prefix when one engine has multiple adapters. |

**Rule of thumb:** *engine* = what SQL family; *`UDA\Driver`* = who runs the connection; never say “driver” alone when you mean database type.

**Connect flow:**

```
Config (engine + transport + params)
    → UDA\Driver\{Engine}::dsn($params)   // per-engine class builds DSN string
    → UDA\Driver::__construct             // new PDO($dsn, $user, $pass, $options)
    → Database uses Driver for all execution
```

For PostgreSQL and SQLite, engine and transport are the same thing — `Driver\PostgreSQL::dsn()` is all you need.

For SQL Server, engine rules live in `Driver\SQLServer.php`, but DSN may come from `Driver\SQLServer::dsn()` (`sqlsrv:`) or `Driver\Dblib::dsn()` (`dblib:`) depending on config `transport`.

---

## Domain Placement

* **Runtime execution engine:** `src/UDA/Driver.php` (namespace `UDA`)
* **Per-engine rule classes:** `src/UDA/Driver/*` (namespace `UDA\Driver`)

Cross-engine dispatch (which class builds DSN, which supplies quoting) lives on `UDA\Driver`; the subdirectory holds engine-specific classes:

* `PostgreSQL` — engine + `pgsql:` DSN
* `SQLite` — engine + `sqlite:` DSN
* `MariaDB` — engine + `mysql:` DSN
* `SQLServer` — SQL Server engine rules + `sqlsrv:` DSN
* `Sybase` — Sybase ASE engine rules + `dblib:` DSN (via `Dblib::dsn`)
* `Dblib` — `dblib:` DSN only (SQL Server over DBLib transport)
* `Oracle` — engine + `oci:` DSN

> Per-engine classes do not create PDO. `UDA\Driver` always performs `new PDO()` after calling their static `::dsn()`.

---

## What Drivers Own

Drivers own anything that differs across RDBMS engines, including:

### Connection establishment

* DSN construction from validated config params (per-engine `::dsn()`)
* transport selection only where an engine supports multiple PDO prefixes (SQL Server: `sqlsrv` vs `dblib`)
* baseline PDO options and engine-specific options
* init SQL execution (if configured)

All of the above is orchestrated by `UDA\Driver`; per-engine classes supply strings and rules only.

### SQL dialect fragments (not SQL building)

Drivers may provide *fragments* and *rules* required for correct SQL compilation, such as:

* identifier quoting rules
* pagination clause form
* RETURNING support (Postgres)
* savepoint syntax and requirements
* UPSERT strategy choice

Drivers do **not** build arbitrary SQL statements. Full SQL compilation belongs to Query/Dialect, but engine-specific compilation rules are provided by Driver and injected into that layer.

---

## What Drivers Must Never Do

Drivers must not:

* expose alternate execution paths
* parse SQL to infer tables
* implement query builders
* leak PDO outside Driver
* create public “Connection” objects

There is one runtime engine and one hot execution path.

---

## Engine Selection

Engine selection is internal to the Driver domain.

* Configuration provides an engine name in the `driver` key (normalized during ingestion to `engine` + `transport`).
* `UDA\Driver::connect($connectionName)` reads config, calls the per-engine `::dsn()`, then opens PDO.

Example engine → class mapping:

* `pgsql` → `UDA\Driver\PostgreSQL` (DSN + rules)
* `sqlite` → `UDA\Driver\SQLite`
* `mariadb` / `mysql` → `UDA\Driver\MariaDB`
* `sqlserver` → `UDA\Driver\SQLServer` (rules); DSN from `SQLServer` or `Dblib` per `transport`
* `sybase` → `UDA\Driver\Sybase` (DSN + rules)
* `oci` / `oracle` → `UDA\Driver\Oracle`

> Normalization happens at **config ingestion**, not on every connect.

---

## DSN Construction Doctrine

Applications must never supply DSN strings.

Config supplies:

* engine (`driver` key)
* optional transport (when engine has multiple PDO prefixes)
* params
* credentials (possibly env-resolved)

The per-engine class builds the DSN string; `UDA\Driver` constructs PDO internally.

### Why

* DSN strings leak transport details into configuration and code.
* SQL Server requires engine/transport separation (`sqlserver` engine with `dblib` vs `sqlsrv` PDO).
* Keeping DSN internal preserves determinism and prevents configuration entropy.

---

## Identifier Quoting

Per-engine classes define identifier quoting rules.

Quoting rules must be:

* deterministic
* minimal overhead
* correct for reserved words and casing rules

| Engine     | Quoting rule          |
| ---------- | --------------------- |
| PostgreSQL | `"identifier"`        |
| SQLite     | `"identifier"`        |
| MariaDB    | `` `identifier` ``    |
| SQL Server | `[identifier]`        |
| Sybase     | `[identifier]`        |
| Oracle     | `"IDENTIFIER"` (upper) |

### Access pattern

Query/Dialect requests quoting behavior from the Driver (or from a Dialect object constructed by the Driver).

Application code should not need to quote identifiers.

---

## Pagination

Pagination is compiled via engine rules.

Per-engine classes provide the correct pagination fragments, such as:

* Postgres/SQLite/MariaDB: `LIMIT {n} OFFSET {m}`
* SQL Server / Sybase: `OFFSET {m} ROWS FETCH NEXT {n} ROWS ONLY` (requires ORDER BY)
* Oracle: `OFFSET {m} ROWS FETCH NEXT {n} ROWS ONLY`

### Enforcement rule

If an engine requires ORDER BY for pagination, the builder/dialect must enforce this deterministically (fail early).

---

## Savepoints (Nested Transactions)

Nested transactions are required.

Per-engine classes provide the engine-specific SQL for:

* create savepoint
* rollback to savepoint
* release savepoint

If an engine lacks savepoints, Driver must emulate nesting using a depth counter (with clear rules and tests).

---

## RETURNING / Output Clauses

Drivers declare whether and how the engine supports returning modified rows:

* Postgres: `RETURNING ...`
* SQL Server: `OUTPUT inserted.*` patterns (if supported in chosen strategy)
* SQLite: depends on version/features; may vary

This impacts how Query/Dialect compiles insert/update terminators when returning data is requested.

---

## UPSERT Strategy

UPSERT compilation is engine-defined:

* Postgres: `ON CONFLICT (...) DO UPDATE`
* SQLite: modern UPSERT syntax
* SQL Server: update-then-insert strategy (or MERGE only if explicitly enabled and tested)

If not supported, the engine must throw `NotSupportedException`.

UPSERT must not create a second execution engine or alternate runtime path.

---

## No Connection Objects

UDA does not expose or depend on a “Connection” class.

* Database selects a connection name.
* Driver binds lazily to that connection.
* Query builders execute through Database → Driver.

Any documentation referring to:

* `Connection::getDriver()`
* “repositories get the driver”
* “connection provides driver injection”

is invalid and must be removed.

---

## Summary

* **`UDA\Driver`** owns PDO and executes SQL.
* **`UDA\Driver\{Engine}`** classes build DSN strings and supply SQL fragment rules.
* **Config `driver`** selects the engine; **config `transport`** selects the PDO prefix only when needed.

If a behavior change requires an engine conditional, it belongs in the per-engine class (or a driver-owned dialect helper), not in Query or userland code.

---

## Engine integration (CI)

Config may list any supported engine; **CI integration** applies to engines with a
`*-integration.yml` workflow in the matrix. Do not imply production readiness for
other engines without naming that workflow.

**Matrix:** `docs/integration/README.md`
