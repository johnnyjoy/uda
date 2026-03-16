# Work Order 006 — Backend Drivers (PostgreSQL, Oracle, SQLite, MariaDB, SQL Server, Dblib)

## Authority

Documentation precedence:

1. constitution.md + style-guide.md
2. contract.md
3. spec.md
4. design.md

---

## Goal

Implement and align backend-specific Driver classes under `src/UDA/Driver/*`.

These classes own only backend-specific behavior:

- DSN construction
- identifier quoting
- pagination syntax
- savepoint syntax
- UPSERT strategy
- engine-specific session/init behavior where needed

Base `Driver` remains abstract.

---

## Scope (Allowed Changes)

Only modify:

- `src/UDA/Driver.php`
- `src/UDA/Driver/*`
- `tests/Driver/*`
- `drivers.md`
- `driver.md`
- `configuration.md`
- `spec.md` (if wording corrections are required)

No builder or cache files may be changed in this work order.

---

## Required Backends

Implement/support:

- `PostgreSQL`
- `Oracle`
- `SQLite`
- `MariaDB`
- `SQLServer`
- `Dblib`

---

## Requirements

### 1. Driver Class Model

Base class:

```php
abstract class Driver
````

Concrete implementations live in:

```php
src/UDA/Driver/
```

---

### 2. DSN Construction

Configuration must never contain DSN strings.

Each backend builds its DSN from validated config params.

#### PostgreSQL

Use PDO pgsql DSN from params.

#### Oracle

Support PDO_OCI only.

Use OCI DSN construction from params such as host / port / service or SID according to the chosen schema.

#### SQLite

Support path-based SQLite DSN.

#### MariaDB

Use PDO mysql DSN.

#### SQLServer

Support sqlsrv DSN.

#### Dblib

Support dblib DSN for SQL Server/Sybase style transport.

---

### 3. Identifier Quoting

Each backend must provide correct identifier quoting rules.

Examples:

* PostgreSQL: `"identifier"`
* Oracle: `"IDENTIFIER"` or exact backend-safe quoting rules
* SQLite: `"identifier"`
* MariaDB: `` `identifier` ``
* SQL Server / Dblib: `[identifier]` or chosen safe dialect rule

---

### 4. Pagination

Drivers must provide backend-correct pagination fragments.

Examples:

* PostgreSQL / SQLite / MariaDB: `LIMIT ... OFFSET ...`
* SQL Server / Dblib: `OFFSET ... ROWS FETCH NEXT ...`
* Oracle: backend-correct pagination strategy

If ORDER BY is required by backend, builder/dialect must later enforce it, but driver must provide the fragment rules here.

---

### 5. Savepoints

Drivers provide backend savepoint SQL fragments or rules.

Nested transactions are required.

---

### 6. UPSERT Strategy

Backend-specific UPSERT support:

* PostgreSQL → `ON CONFLICT`
* SQLite → modern UPSERT
* MariaDB → backend-appropriate upsert strategy
* SQL Server → SQL Server strategy
* Dblib → SQL Server-compatible strategy if possible
* Oracle → `MERGE`

If unsupported, fail clearly with `NotSupportedException`.

---

### 7. PostgreSQL Distinguishing Behavior

PostgreSQL driver must include the things that distinguish PostgreSQL:

* DSN builder
* quoting rules
* pagination
* savepoint behavior
* RETURNING support hooks if required by architecture
* optional session init behavior driven by validated config

---

### 8. Oracle Distinguishing Behavior

Oracle driver must include:

* PDO_OCI DSN builder
* quoting rules
* Oracle pagination support
* savepoint support
* `MERGE`-based UPSERT support

---

## Tests Required

Create or update backend tests for:

* DSN construction
* identifier quoting
* pagination fragments
* savepoint SQL
* backend-specific UPSERT compilation hooks

At minimum add tests for:

* PostgreSQL
* Oracle
* SQLite

---

## Acceptance Criteria

All of the following must pass:

* backend driver tests
* docs no longer claim DSN strings live in config
* Oracle PDO_OCI backend exists
* PostgreSQL backend covers PG-specific behavior

---

## Evidence Required

Provide:

* list of concrete backend classes
* PHPUnit output for backend tests
* examples of DSN strings generated for PostgreSQL and Oracle
