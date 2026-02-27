# Driver Architecture

This document records the rules, expectations, and implementation notes for every `UDA\Driver` subclass so we can remain faithful to the canonical spec (`docs/spec.md`).

## Purpose

1. Root the codebase around the single execution domain (Section 3)
2. Make each engine-specific driver the only place that touches PDO (Section 4.2)
3. Keep driver creation and selection consistent with Section 4.4’s `Driver::fromName(...)` contract

Any deviation from these rules must be treated as a specification violation.

## Driver responsibilities

- **Execution hot path owner.** The driver must be the only class that prepares statements, binds parameters, executes `$sql`, and interprets `PDOStatement`. Builder/query layers hand off `Sql` value objects; drivers execute them (Section 4.3).
- **PDO exclusive rights.** Only driver subclasses instantiate, hold, or close `PDO` objects. Any helper used during execution must also be owned by the driver (Section 4.2).
- **Dialect provider.** Drivers expose the dialect used by query builders for identifier quoting, pagination, limit/offset, IN-list fragments, and `executeReturning()` semantics. Dialects do not branch on driver name outside the driver itself (Section 4.1).
- **Exception wrapping.** Drivers throw `UDA\Exception\QueryException` (or other driver-specific exceptions) that carry sanitized SQL, SQLSTATE, and driver metadata while omitting secrets (Section 11).
- **Transactions.** Drivers coordinate nested transactions, optionally via savepoints when supported, and emulate them otherwise (Section 10).
- **Caching hooks.** Drivers optionally integrate with caching layers but must delegate the actual execution path to themselves (Section 4.6).

## Naming and layout

- Namespace: `UDA\Driver\{Engine}Driver`. Examples include `UDA\Driver\SqliteDriver`, `UDA\Driver\PostgresDriver`, etc.
- PSR-4: located under `src/UDA/Driver/` per project layout (Section 8). Each driver is one file documenting its purpose with a file-level docblock (Section 6).
- Construction: drivers are instantiated exclusively by `Driver::fromName()` using validated config from `ConfigLoader` + `Validator`. No external consumer may instantiate a driver directly.
- Public surface: implement the interface expected by `Driver` (exec, row, rows, value, values, list, each, transaction, lastSql, lastParams, cache). New helpers must not change the driver’s public API without documentation.

## Adding a new driver

1. Subclass the base `Driver` namespace, declare the supported `driver` name, and register it in the selector used by `Driver::fromName()`.
2. Implement the dialect components required by query builders (identifier quoting, limit/offset fragments, `orderByAllowed`, `inList`, etc.).
3. Keep PDO usage under the driver. Reuse shared execution helpers when possible to avoid duplicate prepare/bind/execute logic.
4. Add targeted tests that prove the driver obeys named parameters, ORDER BY allowlists, empty IN handling, nested transactions, and `executeReturning()` semantics when supported (Sections 9, 10, 11, 12, 13).
5. Document driver-specific behavior in this file and add relevant entries in `docs/public-api.md` if the public surface changes.

## Candidate engines

Even if not all drivers exist yet, these engines are expected:

- **SQLite** – primary verification target; used by the required testing and spec.
- **PostgreSQL** – advanced features like `executeReturning()` and richer dialect.
- **MySQL/MariaDB** – standard connect string and limit handling.
- **SQL Server / SQLSRV** – may support T-SQL specifics and MERGE upserts.

Each engine may include helpers in `src/UDA/Driver/{Engine}` (e.g., `Dialect`, `TransactionCoordinator`). Keep these helpers private to the driver unless shared via clearly documented internal utilities.

## Compliance notes

- Every driver implementation must pass the “violation tests” described in Section 5: no second execution path, no PDO outside drivers, and no public `Connection` objects.
- Document driver-specific differences (e.g., `executeReturning()` availability) both here and in `docs/public-api.md` so consumers understand cross-DB behavior (Section 8 and 9).

Following this guide ensures the driver layer matches the contract expressed in `docs/spec.md` and keeps the execution surface predictable and testable.
