````markdown
# WORK ORDER 009 — Extract Identifier Quoting (Dialect) into Driver

---

## Objective

Move **identifier quoting responsibility** (currently handled via centralized Dialect / Database leakage) into the appropriate **backend-specific Driver classes**, while preserving all existing behavior and public API.

This is the **first extraction step** in draining backend-specific SQL behavior out of `Database`.

---

## Architectural Position

### Core Truths

- `Database` is a **service surface and coordinator**
- `Driver` is the **source of backend truth**
- Dialect is **not a standalone system**
- Dialect is **behavior owned by the Driver**

---

## Scope

### INCLUDED

- Identifier quoting (`q()` behavior)
- Any logic determining:
  - quote characters (`"`, `` ` ``, `[ ]`)
  - escaping rules for identifiers
- Routing of `Database::q()` to Driver
- Removal of dependency on centralized Dialect for identifier quoting

---

### EXCLUDED (DO NOT TOUCH)

- Retry logic
- Trace / instrumentation
- Plan cache
- Query builders
- Execution flow
- Transactions
- Result handling
- Value quoting (`PDO::quote`, parameter binding)
- Any other dialect concerns (limit, offset, returning, etc.)

---

## Definitions

### Identifier Quoting

Refers strictly to:

```sql
SELECT * FROM "users"
SELECT * FROM [users]
SELECT * FROM `users`
````

NOT:

```sql
WHERE name = 'john'
```

---

## Current Problem

* `Database` knows backend-specific quoting behavior
* Centralized `Dialect` is acting as an indirect dependency
* Drivers contain little to no backend truth
* Responsibility is inverted

---

## Target State

* `Driver` owns identifier quoting behavior
* `Database::q()` delegates to Driver
* No direct dialect knowledge exists in `Database`
* No centralized Dialect is required for quoting

---

## Design Requirements

### Driver Responsibilities

Each backend-specific Driver must provide:

```php
public function quoteIdentifier(string $identifier): string;
```

Behavior must:

* match current system output exactly
* be backend-correct
* be deterministic

---

### Database Responsibilities

`Database::q()` must:

* remain public
* remain unchanged in signature
* delegate to active Driver

Example:

```php
public function q(string $identifier): string
{
    return $this->driver->quoteIdentifier($identifier);
}
```

---

## Implementation Plan

### Step 1 — Trace Current Flow

* Identify how `Database::q()` currently resolves quoting
* Identify all Dialect dependencies involved in quoting
* Identify backend-specific branches

---

### Step 2 — Implement in Driver

For each backend Driver:

* Implement `quoteIdentifier()`
* Ensure correct quoting syntax per backend

Minimum targets:

* PostgreSQL
* SQLite
* MariaDB
* SQL Server (dblib/sqlsrv behavior)
* Sybase
* Oracle
* DB2

---

### Step 3 — Route Through Driver

Modify `Database::q()`:

* remove dialect dependency
* call Driver method directly

---

### Step 4 — Maintain Behavior

* Ensure output is byte-for-byte identical
* No API changes
* No semantic changes

---

### Step 5 — Remove Old Path (ONLY IF SAFE)

* Remove Dialect usage for identifier quoting
* Only after:

  * no references remain
  * all tests pass

---

## Constraints

* No new abstractions unless strictly required
* No renaming unrelated code
* No formatting-only changes
* No speculative cleanup
* No combining with other refactors

---

## Testing Requirements

### Must Validate

* All existing queries using `q()` behave identically
* Cross-backend correctness:

  * PostgreSQL → `"identifier"`
  * MySQL/MariaDB → `` `identifier` ``
  * SQL Server → `[identifier]`
  * SQLite → `"identifier"`

### Must Not Break

* existing query builders
* existing execution paths
* existing integrations

---

## Failure Conditions

Immediate failure if:

* `Database` still contains backend-specific quoting logic
* Driver does not fully own quoting behavior
* Behavior changes in any backend
* New abstractions are introduced unnecessarily
* Refactor touches unrelated systems

---

## Deliverables

1. List of changed files
2. Summary of what moved
3. Before/after call path for `q()`
4. Test results
5. Any blocker encountered

---

## Definition of Done

* `Database::q()` delegates to Driver
* Driver owns all identifier quoting behavior
* No dialect dependency exists for quoting
* Behavior is unchanged
* Tests pass
* Codebase has **less backend leakage in Database**

---

## Follow-Up Work (NOT PART OF THIS ORDER)

Future extractions will include:

* LIMIT / OFFSET behavior
* RETURNING / OUTPUT
* UPSERT strategies
* EXPLAIN support
* Capability flags

Each must be handled as a **separate work order**

---

## Final Directive

This is a **surgical extraction**, not a redesign.

Do not improve.
Do not generalize.
Do not expand scope.

Move one responsibility.
Prove it works.
Then stop.

```
```
