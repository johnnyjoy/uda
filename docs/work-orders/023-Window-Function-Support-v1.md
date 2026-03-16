# Work Order 023 — Window Function Support

## Authority

Documentation precedence:

1. `constitution.md` + `style-guide.md`  
2. `contract.md`  
3. `spec.md`  
4. `design.md`

The **Query Cookbook** defines the developer-facing grammar and must remain the canonical guide for how window functions are expressed in UDA.

If the implementation contradicts documented grammar, the code is incorrect unless the documentation is clearly outdated.

---

# Goal

Add **first-class support for SQL window functions** to the UDA query builder.

Window functions must be:

- expressed through the **Expr system**
- composable
- deterministic
- portable across supported dialects
- compatible with existing builder grammar

This work order completes the **core analytical SQL grammar** of UDA.

---

# Why This Matters

Window functions are fundamental for analytical queries.

Examples developers must be able to express:

```sql
ROW_NUMBER() OVER (PARTITION BY department ORDER BY salary DESC)
````

```sql
SUM(sales) OVER (ORDER BY sale_date)
```

```sql
LAG(price) OVER (ORDER BY timestamp)
```

Without native window support, developers fall back to raw SQL:

```php
Expr::raw('ROW_NUMBER() OVER (...)')
```

This breaks the goals of:

* structured query composition
* dialect portability
* deterministic SQL generation

UDA must provide a **structured representation of window expressions**.

---

# Design Principle

**Window functions are expressions.**

They must live in:

```
UDA\Query\Expr
```

They must **not** become top-level query builder constructs.

Incorrect design (many libraries):

```
Select::window(...)
```

Correct design:

```
Expr::rowNumber()->over(...)
```

This preserves grammar symmetry.

---

# Scope

Allowed modifications:

```
src/UDA/Query/Expr/*
src/UDA/Query/Select.php
src/UDA/Query/Dialect/*
tests/Query/*
docs/query-cookbook.md
docs/spec.md
```

Do not modify:

```
Cache subsystem
Driver layer
Config subsystem
```

Window functions are **pure SQL expression features**.

---

# Expression API

Introduce new Expr factory methods.

## Ranking Functions

```php
Expr::rowNumber()
Expr::rank()
Expr::denseRank()
```

Example:

```php
Expr::rowNumber()
    ->partitionBy('department_id')
    ->orderBy('salary','DESC')
    ->as('rank')
```

---

## Offset Functions

```php
Expr::lag('column')
Expr::lead('column')
```

Example:

```php
Expr::lag('salary')
    ->orderBy('hire_date')
```

---

## Aggregate Windows

```php
Expr::sum('sales')
Expr::avg('sales')
Expr::count('sales')
Expr::min('value')
Expr::max('value')
```

Example:

```php
Expr::sum('sales')
    ->orderBy('sale_date')
```

---

# Window Builder

Window functions require an `OVER()` clause builder.

Introduce internal object:

```
WindowDefinition
```

Example usage:

```php
Expr::rowNumber()
    ->partitionBy('department_id')
    ->orderBy('salary','DESC')
```

Produces:

```sql
ROW_NUMBER() OVER (
    PARTITION BY department_id
    ORDER BY salary DESC
)
```

---

# Partition Support

```php
->partitionBy('column')
->partitionBy('a','b','c')
```

Produces:

```sql
PARTITION BY a, b, c
```

---

# Order Support

```php
->orderBy('salary')
->orderBy('salary','DESC')
```

Produces:

```sql
ORDER BY salary DESC
```

---

# Frame Support

Frame clauses must support:

```
ROWS BETWEEN ...
RANGE BETWEEN ...
```

Supported APIs:

```php
->rowsBetweenUnboundedPreceding()
->rowsBetween('1 PRECEDING','CURRENT ROW')

->rangeBetweenUnboundedPreceding()
```

Example:

```php
Expr::sum('sales')
    ->orderBy('sale_date')
    ->rowsBetweenUnboundedPreceding()
```

Produces:

```sql
SUM(sales) OVER (
    ORDER BY sale_date
    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
)
```

---

# Alias Support

All window expressions must support aliases:

```php
->as('rank')
```

Result:

```sql
ROW_NUMBER() OVER (...) AS rank
```

Alias behavior must match existing `Expr` alias semantics.

---

# Dialect Considerations

Window functions are supported by all target engines.

Supported dialects:

| Dialect       | Window Support |
| ------------- | -------------- |
| PostgreSQL    | ✓              |
| SQLite ≥3.25  | ✓              |
| SQL Server    | ✓              |
| Sybase        | ✓              |
| Oracle        | ✓              |
| MariaDB ≥10.2 | ✓              |
| DB2           | ✓              |

Therefore:

```
supportsWindowFunctions() === true
```

for all current dialects.

Capability enforcement still runs through WO022.

---

# SQL Compilation

The dialect layer does **not need special logic**.

Window SQL syntax is standardized.

The builder should emit standard SQL.

Example:

```sql
ROW_NUMBER() OVER (PARTITION BY dept ORDER BY salary)
```

No vendor branching required.

---

# Integration with Select Builder

Window expressions must be allowed anywhere expressions are allowed.

Example:

```php
$db->select(
        'employee_id',
        Expr::rowNumber()
            ->partitionBy('department_id')
            ->orderBy('salary','DESC')
            ->as('rank')
    )
    ->from('employees')
```

Produces:

```sql
SELECT
    employee_id,
    ROW_NUMBER() OVER (
        PARTITION BY department_id
        ORDER BY salary DESC
    ) AS rank
FROM employees
```

---

# Advanced Example

Running totals.

```php
$db->select(
        'sale_date',
        'sales',
        Expr::sum('sales')
            ->orderBy('sale_date')
            ->rowsBetweenUnboundedPreceding()
            ->as('running_total')
    )
    ->from('daily_sales')
```

Produces:

```sql
SELECT
    sale_date,
    sales,
    SUM(sales) OVER (
        ORDER BY sale_date
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS running_total
FROM daily_sales
```

---

# Tests Required

Add:

```
tests/Query/WindowFunctionTest.php
```

---

## Test 1 — Row Number

Verify compilation:

```
ROW_NUMBER() OVER (ORDER BY id)
```

---

## Test 2 — Partitioned Ranking

Verify:

```
ROW_NUMBER() OVER (PARTITION BY dept ORDER BY salary)
```

---

## Test 3 — LAG

Verify:

```
LAG(price) OVER (ORDER BY ts)
```

---

## Test 4 — Running SUM

Verify:

```
SUM(sales) OVER (ORDER BY date)
```

---

## Test 5 — Frame Clause

Verify:

```
ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
```

---

## Test 6 — Alias Handling

Verify:

```
AS rank
```

---

## Test 7 — Integration with Select

Full query compile test.

---

# Documentation Updates

Update:

```
docs/query-cookbook.md
docs/spec.md
```

Add section:

```
Window Functions
```

Include examples for:

* ranking
* lag/lead
* running totals
* partitioned aggregates

---

# Acceptance Criteria

All conditions must be met:

* window expressions compile correctly
* window expressions integrate with Select builder
* partition and order clauses compile correctly
* frame clauses compile correctly
* aliases behave correctly
* tests pass across all dialects
* documentation updated

---

# Evidence Required

Provide:

* modified files
* PHPUnit test output
* compiled SQL examples
* Query Cookbook additions

---

# Non-Goals

This work order does **not implement**:

```
Named windows
Window reuse
Materialized windows
Optimizer hints
Dialect-specific analytic extensions
```

Those belong to future work orders.

---

# Philosophy

Window functions complete the **analytical SQL grammar** of UDA.

After this work order, the builder will support:

```
joins
subqueries
CTE
recursive CTE
unions
returning
upsert
pagination
window analytics
```
