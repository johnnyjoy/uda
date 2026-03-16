# Work Order 017 — Window Functions (OVER / PARTITION / FRAME)

## Execution Context

Target coding agent: **OpenAI GPT-5.1-codex** operating in an Opencode workflow.

This work order introduces **SQL window function support** to UDA.

Window functions allow queries to compute values across a set of rows related to the current row without collapsing rows like `GROUP BY`.

Examples include:

- `ROW_NUMBER()`
- `RANK()`
- `DENSE_RANK()`
- `LAG()`
- `LEAD()`
- `SUM(...) OVER (...)`
- `AVG(...) OVER (...)`

Window functions must integrate with the **existing expression system (`Expr`) introduced in WO013**.

Window functions must remain:

- immutable
- dialect-compiled
- composable
- deterministic
- compatible with subqueries, CTEs, and compound queries

Window functions must **not** modify the core builder grammar.

---

# Authority

Documentation precedence:

1. constitution.md + style-guide.md  
2. contract.md  
3. spec.md  
4. design.md  

If code conflicts with docs, code is wrong unless documentation is clearly outdated.

The **Query Cookbook remains the canonical usage reference**.

---

# Goal

Allow developers to express SQL window functions fluently via `Expr`.

Target usage:

```php
$rows = $db->select(
        'id',
        'department_id',
        Expr::rowNumber()
            ->over()
            ->partitionBy('department_id')
            ->orderBy('salary','DESC')
            ->as('rank')
    )
    ->from('employees')
    ->rows();
````

Expected SQL:

```sql
SELECT
    id,
    department_id,
    ROW_NUMBER() OVER (
        PARTITION BY department_id
        ORDER BY salary DESC
    ) AS rank
FROM employees
```

---

# Scope (Allowed Changes)

Modify only:

```
src/UDA/Query/*
src/UDA/Query/Dialect/*
src/UDA/SQL/*
tests/Query/*
docs/query-cookbook.md
docs/spec.md
docs/architecture.md
docs/design.md
```

Do not modify:

```
Database execution pipeline
Driver layer
Cache subsystem
Config subsystem
```

Window functions must remain **expression-level features**.

---

# Architectural Intent

Window functions are expressions with a **window definition**.

Conceptually:

```
function_call
  OVER
    window_spec
      partition_by
      order_by
      frame
```

Example SQL:

```
SUM(amount) OVER (
  PARTITION BY customer_id
  ORDER BY order_date
  ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
)
```

In UDA, this structure must be represented through the `Expr` API.

Compilation pipeline remains:

```
Builder
→ Dialect compile
→ Sql value object
→ Database
→ Driver
→ PDO
```

Window functions are rendered entirely during **dialect compilation**.

---

# Requirements

## 1. Window Expression Builder

Add window helpers to `Expr`.

Minimum required factories:

```php
Expr::rowNumber()
Expr::rank()
Expr::denseRank()
Expr::lag(string|Expr $value)
Expr::lead(string|Expr $value)
Expr::sum(string|Expr $value)
Expr::avg(string|Expr $value)
Expr::min(string|Expr $value)
Expr::max(string|Expr $value)
Expr::count(string|Expr $value = '*')
```

Each must support window chaining.

Example:

```php
Expr::rowNumber()->over()
```

---

## 2. OVER Clause

Window expressions must support:

```php
->over()
```

Which begins a window specification.

Example:

```php
Expr::rowNumber()->over()
```

This must compile to:

```
ROW_NUMBER() OVER ()
```

---

## 3. PARTITION BY

Support partition clauses.

Example:

```php
Expr::rowNumber()
    ->over()
    ->partitionBy('department_id')
```

Compiled SQL:

```
ROW_NUMBER() OVER (PARTITION BY department_id)
```

Multiple partitions must be supported:

```php
->partitionBy('department_id','team_id')
```

---

## 4. ORDER BY

Support ordering inside window definitions.

Example:

```php
->orderBy('salary','DESC')
```

Compiled SQL:

```
ORDER BY salary DESC
```

Multiple order expressions must be supported.

---

## 5. Frame Clauses

Support row frame definitions.

Minimum required API:

```php
->rowsUnboundedPreceding()
->rowsBetween($start,$end)
->rowsCurrentRow()
```

Examples:

```php
->rowsUnboundedPreceding()
```

Compiles to:

```
ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
```

Example:

```php
->rowsBetween('UNBOUNDED PRECEDING','CURRENT ROW')
```

Compiles to:

```
ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
```

Dialect must render frame clauses appropriately.

---

## 6. Aliasing

Window expressions must support:

```php
->as('alias')
```

Example:

```php
Expr::rowNumber()
    ->over()
    ->orderBy('salary','DESC')
    ->as('rank')
```

---

## 7. Parameter Handling

Window expressions may include parameters.

Example:

```php
Expr::sum('amount')
    ->over()
    ->partitionBy('account_id')
```

Parameters from expressions must merge deterministically with the parent query.

---

## 8. Table Attribution

Window expressions must not introduce new tables unless they contain subqueries.

Normal column references do not affect table attribution.

Example:

```
SUM(amount)
```

does not add tables.

---

## 9. Dialect Compilation

Dialect must render window expressions correctly.

All supported engines provide window support:

```
PostgreSQL
SQLite (3.25+)
SQL Server
Oracle
MariaDB
DB2
```

Dialect responsibilities:

```
render OVER clause
render PARTITION BY
render ORDER BY
render frame clauses
validate unsupported syntax
```

Builders must not branch on backend names.

---

## 10. Deterministic SQL

Identical builder state must produce identical:

```
SQL
parameter order
table attribution
metadata
```

Window definitions must preserve method-chain ordering.

---

# Tests Required

## Row Number

Test:

```
ROW_NUMBER() OVER ()
```

---

## Partitioned Ranking

Test:

```
ROW_NUMBER() OVER (
    PARTITION BY department_id
)
```

---

## Partition + Order

Test:

```
ROW_NUMBER() OVER (
    PARTITION BY department_id
    ORDER BY salary DESC
)
```

---

## Frame Clause

Test frame definitions.

```
ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
```

---

## Window Aggregate

Test:

```
SUM(amount) OVER (...)
```

---

## Alias

Test aliasing:

```
AS rank
```

---

## Determinism

Ensure repeated compilation produces identical SQL.

---

## Dialect Tests

Verify window compilation for:

```
PostgreSQL
SQLite
SQL Server
Oracle
MariaDB
DB2
```

---

# Acceptance Criteria

All must be true:

```
Expr::rowNumber implemented
Expr::rank implemented
Expr::denseRank implemented
Expr::lag implemented
Expr::lead implemented
window chaining implemented
partitionBy supported
orderBy supported
frame clauses supported
aliasing supported
deterministic SQL preserved
dialect compilation correct
tests pass
cookbook examples compile
```

---

# Non-Goals

This work order must not introduce:

```
named window declarations
window reuse definitions
analytic function shortcuts beyond listed functions
query planners
AST frameworks
execution caching
```

These belong to later work orders if required.

---

# Evidence Required

Provide:

1. list of changed files
2. PHPUnit results for `tests/Query/*`
3. compiled SQL examples for:

```
ROW_NUMBER window
partitioned ranking
running total
window with frame clause
```

---

# Cookbook Updates

Add new sections.

---

## Ranking

```php
$rows = $db->select(
        'id',
        'department_id',
        Expr::rowNumber()
            ->over()
            ->partitionBy('department_id')
            ->orderBy('salary','DESC')
            ->as('rank')
    )
    ->from('employees')
    ->rows();
```

---

## Running Totals

```php
$rows = $db->select(
        'account_id',
        'amount',
        Expr::sum('amount')
            ->over()
            ->partitionBy('account_id')
            ->orderBy('txn_date')
            ->rowsUnboundedPreceding()
            ->as('running_total')
    )
    ->from('transactions')
    ->rows();
```

---

## Lag Example

```php
$rows = $db->select(
        'employee_id',
        'salary',
        Expr::lag('salary')
            ->over()
            ->partitionBy('department_id')
            ->orderBy('salary')
            ->as('previous_salary')
    )
    ->from('employees')
    ->rows();
```

---

# Philosophy

Window functions are essential for modern SQL workloads.

They enable:

```
ranking
running totals
gap detection
analytics
efficient pagination
```

UDA must support them while preserving its core principles:

```
SQL transparency
builder composability
dialect abstraction
execution simplicity
```

Window functions belong in the **expression system**, not the core query grammar.

```

---

### Where UDA stands after WO017

At this point your builder supports nearly the **entire serious SQL feature set**:

```

SELECT
JOIN
SUBQUERY
CTE
UNION
INTERSECT
EXCEPT
WINDOW FUNCTIONS
UPSERT
RETURNING
TRANSACTIONS
