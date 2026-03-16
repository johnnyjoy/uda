# Work Order 009 — Query Dialect Layer

## Authority

Documentation precedence:

1. constitution.md + style-guide.md
2. contract.md
3. spec.md
4. design.md

If code conflicts with docs, code is wrong.

---

# Goal

Implement the **Query Dialect layer** inside the Query domain.

The Dialect participates in the **query compilation process** so builders can produce correct SQL for multiple database engines **without embedding backend logic in the builders**.

Dialect exists to help builders generate SQL while keeping:

- builders simple
- SQL grammar deterministic
- backend branching centralized
- Driver free of SQL compilation logic

Dialect operates during the builder → SQL compilation process.

The Query Cookbook remains the north star for fluent usage.

---

# Scope (Allowed Changes)

Only modify:

- `src/UDA/Query/*`
- `src/UDA/Query/Dialect/*`
- `src/UDA/SQL/*` (if needed for immutable Sql value objects)
- `src/UDA/Database.php` (only if needed for builder ↔ dialect wiring)
- `src/UDA/Driver.php` (only if needed to expose backend identity or quoting hooks cleanly)
- `src/UDA/Driver/*` (only to expose backend identity or capabilities; do not move SQL compilation here)
- `tests/Query/*`
- `tests/Query/Dialect/*`
- `query-cookbook.md`
- `drivers.md`
- `architecture.md`
- `spec.md` (only if wording alignment is required)
- `design.md` (only if structural alignment is required)

No cache or config behavior may change in this work order.

---

# Architectural Intent

## 1. Domain Placement

Dialect belongs under **Query**.

Suggested structure:

```
src/UDA/Query/
    Dialect/
        PostgreSQL.php
        Oracle.php
        SQLite.php
        MariaDb.php
        SqlServer.php
        Sybase.php
        Db2.php
```

Dialect must not become a separate top-level domain.

---

## 2. Responsibility Split

### Query + Dialect

Own:

- SQL compilation
- clause ordering
- backend-specific SQL grammar
- rendering builders into immutable `Sql` objects

Dialect assists builders during compilation so builders do not contain backend conditionals.

### Driver

Owns:

- PDO
- connection / DSN
- query execution
- transactions
- runtime cache behavior
- backend capability exposure

Driver must **never compile SQL**.

### Builders

Builders must:

- maintain builder state
- validate grammar
- remain immutable
- delegate SQL rendering to the dialect
- expose fluent query construction

Builders must not:

- branch on backend names
- talk to Driver
- execute SQL

---

## 3. Dialect Usage in Query Compilation

Compilation flow:

```
Builder state
    ↓
Dialect compiler
    ↓
Sql value object
```

Dialect implementations should extend a base SQL compiler and override only backend-specific grammar differences rather than re-implementing full query compilation.

Example internal flow:

```
SelectQuery::toSql()
    → Dialect::compileSelect(queryState)
    → Sql object
```

Dialect participates **during compilation**, not after SQL exists.

---

## 4. Fluent Terminators Remain

The developer model must remain unchanged.

Valid usage:

```php
$db->select()
    ->from('employees')
    ->where('id', 5)
    ->rows();
```

Internally:

```
builder terminator
    → builder compile
    → Database execution
    → Driver
    → PDO
```

Dialect participates in compilation only.

Execution remains exclusively Database → Driver.

---

# Requirements

## 1. Dialect Contract

Create a base dialect abstraction under:

```
src/UDA/Query/Dialect/Dialect.php
```

It must support compilation of:

- SELECT
- INSERT
- UPDATE
- DELETE
- UPSERT

and provide helpers for:

- pagination syntax
- RETURNING / output syntax
- identifier rendering hooks
- expression rendering differences where necessary
- CTE hooks if supported by builders

Compilation output must always be an immutable `Sql` object.

Dialect implementations must be **stateless**.

---

## 2. Backend Coverage

Provide dialect classes or stubs for:

- PostgreSQL
- Oracle
- SQLite
- MariaDB
- SQL Server
- Sybase
- IBM DB2

If a dialect is incomplete it must **fail explicitly** when unsupported features are used.

Silent fallback is forbidden.

---

## 3. SELECT Compilation Support

Dialect must support rendering:

- SELECT
- DISTINCT
- JOIN
- WHERE boolean chains
- EXISTS
- GROUP BY
- HAVING
- ORDER BY
- pagination
- CTEs if builder supports them

---

## 4. INSERT Compilation

Dialect must support:

- single-row inserts
- multi-row inserts where backend supports them
- RETURNING support where backend supports it

---

## 5. UPDATE Compilation

Dialect must support:

- standard updates
- backend-specific returning/output syntax where supported

---

## 6. DELETE Compilation

Dialect must support:

- standard delete syntax
- backend-specific returning/output syntax where supported

---

## 7. UPSERT Compilation

Dialect must translate the builder's normalized upsert intent.

Examples:

PostgreSQL  
`INSERT ... ON CONFLICT`

SQLite  
modern UPSERT syntax

MariaDB  
appropriate backend syntax

SQL Server  
backend strategy

Oracle  
`MERGE`

DB2  
backend-specific strategy

If unsupported the dialect must fail clearly.

---

## 8. Pagination

Dialect must render correct pagination syntax.

Examples:

PostgreSQL / SQLite / MariaDB  
`LIMIT … OFFSET`

SQL Server / Sybase  
`OFFSET … FETCH`

Oracle / DB2  
backend-correct pagination

If pagination requires `ORDER BY` the dialect must enforce or fail.

---

## 9. Identifier Handling

Identifier quoting must remain controlled by Driver.

Dialect may call quoting hooks but must not introduce public identifier value objects.

No new public identifier types may be added.

---

## 10. Deterministic SQL

Dialect compilation must be deterministic.

Given the same builder state it must produce identical:

- SQL string
- parameter names
- table list
- metadata hints

No hidden clause reordering.

No backend inference inside public builder methods.

---

# Tests Required

Create tests covering:

### Base compilation

- Select → Sql
- Insert → Sql
- Update → Sql
- Delete → Sql
- Upsert → Sql

### Backend behavior

Minimum coverage:

- PostgreSQL SELECT + UPSERT + pagination
- Oracle UPSERT/MERGE + pagination
- SQLite SELECT + UPSERT
- MariaDB pagination
- SQL Server pagination

### Determinism

- identical builder state produces identical SQL
- invalid builder state fails early

### Fluent integrity

- fluent terminators still function
- cookbook examples compile and execute correctly

---

# Acceptance Criteria

All must be true:

- Dialect layer exists under `src/UDA/Query/Dialect`
- Builders contain no backend branching
- SQL compilation occurs via dialect
- Fluent query terminators still work
- SQL compilation produces immutable `Sql`
- PostgreSQL, Oracle, SQLite, MariaDB, SQL Server, Sybase, DB2 have dialect classes
- Unsupported dialect features fail clearly
- Query Cookbook remains accurate

---

# Non-Goals

Do not:

- move SQL compilation into Driver
- move execution into Query
- change Config behavior
- introduce ORM/entity mapping
- add public Identifier objects
- remove fluent builder terminators
- introduce AST/ORM-style query trees

---

# Evidence Required

Provide:

1. Changed files
2. PHPUnit output for `tests/Query/*` and `tests/Query/Dialect/*`
3. Example compiled SQL for:

   - PostgreSQL UPSERT
   - Oracle MERGE UPSERT
   - SQL Server pagination
   - SQLite pagination

4. Short explanation of dialect selection and injection
````

There is one architectural choice you should confirm:

**When is the dialect selected?**

Option A (most common)

```
Database::connect()
    → determines driver
    → creates dialect
    → builders receive dialect
```

A
