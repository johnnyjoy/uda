# UDA Drivers

**Purpose:** Provide backend-specific behavior inside the Driver domain: DSN construction, identifier quoting rules, pagination fragments, savepoint syntax, and database-specific UPSERT strategies — while preserving a single execution hot path.

See: `spec.md`, `constitution.md`, and `architecture.md`.

---

## Domain Placement

* **Base execution engine:** `src/UDA/Driver.php` (namespace `UDA`)
* **Backend implementations:** `src/UDA/Driver/*` (namespace `UDA\Driver`)

Backends are concrete drivers such as:

* `PostgreSQL`
* `SQLite`
* `MariaDB`
* `SQLServer`
* `Dblib` (SQL Server/Sybase transport variant)
* `Oracle` (PDO_OCI)

> The driver is the only place where backend-specific runtime behavior is allowed.

---

## What Drivers Own

Drivers own anything that differs across RDBMS backends, including:

### Connection establishment

* DSN construction from validated config params
* selection of PDO transport driver where backend supports multiple (e.g. SQL Server uses `dblib` or `sqlsrv`)
* baseline PDO options and backend-specific options
* init SQL execution (if configured)

### SQL dialect fragments (not SQL building)

Drivers may provide *fragments* and *rules* required for correct SQL compilation, such as:

* identifier quoting rules
* pagination clause form
* RETURNING support (Postgres)
* savepoint syntax and requirements
* UPSERT strategy choice

Drivers do **not** build arbitrary SQL statements. Full SQL compilation belongs to Query/Dialect, but backend-specific compilation rules are provided by Driver and injected into that layer.

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

## Driver Selection

Driver selection is internal to the Driver domain.

* Configuration provides a backend name (normalized during ingestion by Config/Validator/Snapshot).
* `UDA\Driver::connect($connectionName)` resolves the validated connection config and selects the correct backend driver class.

Example backend mapping (conceptual):

* `pgsql` → `UDA\Driver\PostgreSQL`
* `sqlite` → `UDA\Driver\SQLite`
* `mariadb` / `mysql` → `UDA\Driver\MariaDB`
* `sqlserver` → `UDA\Driver\SQLServer`
* `dblib` → `UDA\Driver\Dblib`
* `oci` / `oracle` → `UDA\Driver\Oracle`

> Backend selection and normalization happen at **config ingestion**, not at runtime on every connect.

---

## DSN Construction Doctrine

Applications must never supply DSN strings.

Config supplies:

* backend name (driver)
* params
* credentials (possibly env-resolved)

The backend Driver constructs DSN internally.

### Why

* DSN strings leak transport details into configuration and code.
* SQL Server requires backend/transport separation (`sqlserver` backend with `dblib` vs `sqlsrv` PDO).
* Keeping DSN internal preserves determinism and prevents configuration entropy.

---

## Identifier Quoting

Drivers define identifier quoting rules for their backend.

Quoting rules must be:

* deterministic
* minimal overhead
* correct for reserved words and casing rules

| Backend    | Quoting rule          |
| ---------- | --------------------- |
| PostgreSQL | `"identifier"`        |
| SQLite     | `"identifier"`        |
| MariaDB    | `` `identifier` ``    |
| SQLServer  | `[identifier]`        |
| Dblib      | `[identifier]`        |
| Oracle     | `"IDENTIFIER"` (upper) |

### Access pattern

Query/Dialect requests quoting behavior from the Driver (or from a Dialect object constructed by the Driver).

Application code should not need to quote identifiers.

---

## Pagination

Pagination is compiled via backend rules.

Drivers provide the correct pagination fragments, such as:

* Postgres/SQLite/MariaDB: `LIMIT {n} OFFSET {m}`
* SQL Server / Dblib: `OFFSET {m} ROWS FETCH NEXT {n} ROWS ONLY` (requires ORDER BY)
* Oracle: `OFFSET {m} ROWS FETCH NEXT {n} ROWS ONLY`

### Enforcement rule

If a backend requires ORDER BY for pagination, the builder/dialect must enforce this deterministically (fail early).

---

## Savepoints (Nested Transactions)

Nested transactions are required.

Drivers provide the backend-specific SQL for:

* create savepoint
* rollback to savepoint
* release savepoint

If a backend lacks savepoints, Driver must emulate nesting using a depth counter (with clear rules and tests).

---

## RETURNING / Output Clauses

Drivers declare whether and how the backend supports returning modified rows:

* Postgres: `RETURNING ...`
* SQL Server: `OUTPUT inserted.*` patterns (if supported in chosen strategy)
* SQLite: depends on version/features; may vary

This impacts how Query/Dialect compiles insert/update terminators when returning data is requested.

---

## UPSERT Strategy

UPSERT compilation is backend-defined:

* Postgres: `ON CONFLICT (...) DO UPDATE`
* SQLite: modern UPSERT syntax
* SQL Server: update-then-insert strategy (or MERGE only if explicitly enabled and tested)

If not supported, the backend must throw `NotSupportedException`.

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

Drivers exist to keep backend-specific behavior:

* inside the Driver domain
* internal to the execution pipeline
* consistent with one execution path
* invisible to application code

If a behavior change requires a backend conditional, it belongs in Driver (or a driver-owned dialect helper), not in Query or userland code.
