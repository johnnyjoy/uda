# UDA Query Cookbook

This document demonstrates how to express common SQL patterns in **UDA**.

Goals:

* Keep SQL centralized in repositories.
* Keep grammar readable and predictable.
* Support complex logic without abstraction sprawl.
* Avoid raw SQL unless necessary.
* Empower developers to express complex queries **without escaping the system**.
* Keep query syntax identical across databases—dialects handle vendor-specific clauses.

> **Dialect note:** Builders never embed vendor branching. When `$db->select()` or `$db->insert()` is called, UDA binds the connection’s SQL dialect behind the scenes so LIMIT/OFFSET, `ON CONFLICT`, `MERGE`, etc. are emitted correctly for PostgreSQL, SQLite, SQL Server, Sybase, Oracle, MariaDB, and DB2. Stick to the fluent API; the dialect layer handles SQL nuances.

> **Cache hint:** Every `Database` helper (`rows`, `row`, `value`, `values`, `list`, `each`, `exec`, `returning`) accepts an optional `$tableHints` array. Pass table names when issuing raw SQL so cache metadata stays accurate; see `docs/caching.md#raw-sql-table-hints` for details.

---

# Basic SELECT

```php
$rows = $db->select()
    ->from('employees')
    ->rows();
```

Equivalent SQL:

```sql
SELECT * FROM employees
```

---

# Selecting Specific Columns

```php
$rows = $db->select(
        'id',
        'first_name',
        'last_name'
    )
    ->from('employees')
    ->rows();
```

Produces:

```sql
SELECT id, first_name, last_name
FROM employees
```

---

# Table Aliases

```php
$db->select('e.id','e.first_name')
   ->from('employees e')
   ->rows();
```

Produces:

```sql
SELECT e.id, e.first_name
FROM employees e
```

---

# WHERE (Simple)

```php
->where('id', $id)
```

Produces:

```sql
WHERE id = :p1
```

---

# WHERE (Boolean Composition)

Complex boolean logic uses a **fluent expression chain**.

```php
->where('active', 1)
    ->and('department_id')->in([10,20,30])
    ->or(fn($w) =>
        $w->and('title')->like('%Engineer%')
          ->and('hire_date')->between('2020-01-01','2024-12-31')
    )
    ->rows();
```

Equivalent SQL:

```sql
WHERE active = :p1
AND department_id IN (:p2,:p3,:p4)
OR (
    title LIKE :p5
    AND hire_date BETWEEN :p6 AND :p7
)
```

---

# Optional Filters (Dynamic Queries)

A common real-world pattern is **optional filters**.

```php
$q = $db->select()
    ->from('employees');

if ($deptId) {
    $q->where('department_id', $deptId);
}

if ($title) {
    $q->and('title')->like("%$title%");
}

return $q->rows();
```

No SQL concatenation required.

---

# Conditional Query Blocks

Closure blocks make complex filters readable.

```php
->where(fn($w) =>
    $w->and('active',1)
      ->and('department_id')->in([1,2,3])
)
```

Produces:

```sql
WHERE (
    active = :p1
    AND department_id IN (:p2,:p3,:p4)
)
```

---

# IN

```php
->where('department_id')->in([1,2,3])
```

Produces:

```sql
WHERE department_id IN (:p1,:p2,:p3)
```

### Empty Lists

```php
->where('department_id')->in([])
```

Produces:

```sql
WHERE 1 = 0
```

This prevents invalid SQL.

---

# WHERE IN (subquery)

```php
$allowedDepartments = $db->select('id')
    ->from('departments')
    ->where('name', 'Engineering')
    ->end();

$db->select('id')
    ->from('employees')
    ->where('status','active')
        ->and('department_id')->in($allowedDepartments)
    ->rows();
```

---

# BETWEEN

```php
->where('hire_date')->between('2020-01-01','2024-01-01')
```

Produces:

```sql
WHERE hire_date BETWEEN :p1 AND :p2
```

---

# LIKE

```php
->where('title')->like('%Engineer%')
```

Produces:

```sql
WHERE title LIKE :p1
```

---

# EXISTS

```php
->whereExists(
    $db->select('1')
        ->from('payroll p')
        ->whereRaw('p.employee_id = e.id')
)
```

Produces:

```sql
WHERE EXISTS (
    SELECT 1
    FROM payroll p
    WHERE p.employee_id = e.id
)
```

---

# NOT EXISTS

```php
$db->select('e.id')
    ->from('employees e')
    ->whereNotExists(
        $db->select('1')
            ->from('terminations t')
            ->whereRaw('t.employee_id = e.id')
    )
    ->rows();
```

---

# JOIN

Joins remain explicit and readable.

```php
$db->select(
        'e.id',
        'e.first_name',
        'd.name as department'
    )
    ->from('employees e')
    ->join('departments d','d.id = e.department_id')
    ->rows();
```

Produces:

```sql
SELECT e.id, e.first_name, d.name as department
FROM employees e
JOIN departments d ON d.id = e.department_id
```

---

# LEFT JOIN

```php
->leftJoin('payroll p','p.employee_id = e.id')
```

Produces:

```sql
LEFT JOIN payroll p ON p.employee_id = e.id
```

---

# Derived Tables

```php
$totals = $db->select('payroll.employee_id')
    ->selectRaw('SUM(amount) AS total')
    ->from('payroll')
    ->groupBy('payroll.employee_id');

$db->select('p.employee_id','p.total')
    ->fromSub($totals,'p')
    ->rows();
```

Alias (`'p'`) is required when using `fromSub()`.

---

# Subquery JOIN

```php
$totals = $db->select('payroll.employee_id')
    ->selectRaw('SUM(amount) AS total')
    ->from('payroll')
    ->groupBy('payroll.employee_id');

$db->select('e.id','t.total')
    ->from('employees','e')
    ->joinSub($totals,'t','t.employee_id = e.id')
    ->rows();
```

---

# GROUP BY

```php
->groupBy('department_id')
```

Produces:

```sql
GROUP BY department_id
```

---

# HAVING

```php
->groupBy('department_id')
->havingRaw('COUNT(id) > ?', [5])
```

Or fluent:

```php
->groupBy('department_id')
->having('COUNT(id)')->gt(5)
    ->and('AVG(salary)')->gt(120000)
    ->rows();
```

---

# ORDER BY (Safe Dynamic Sorting)

Always validate external column input.

```php
$allowed = ['last_name','hire_date','salary'];

$sort = in_array($sort,$allowed,true)
    ? $sort
    : 'last_name';

$rows = $db->select()
    ->from('employees')
    ->orderBy($sort,'ASC')
    ->rows();
```

---

# Pagination

```php
->limit(50)
->offset(0)
```

Produces:

```sql
LIMIT 50 OFFSET 0
```

---

# Streaming Large Result Sets

For very large datasets:

```php
$db->select()
   ->from('employees')
   ->each(function($row){
       process($row);
   });
```

Benefits:

* iterates rows directly from the PDO cursor
* avoids materializing the full result set at once

> Memory usage still depends on the PDO driver and cursor type. Use forward-only cursors for the most predictable footprint.

---

# Counting Rows

`count()` is a read terminator that returns an integer. It wraps the query so the
database counts rows without sending them back.

```php
$total = $db->select()
    ->from('employees')
    ->count();
```

Equivalent SQL:

```sql
SELECT COUNT(1) AS total FROM (SELECT * FROM employees) uda_count
```

Count a filtered set — `count()` closes the WHERE chain for you:

```php
$active = $db->select()
    ->from('employees')
    ->where('active', 1)
    ->count();
```

Pass an expression only when you need non-NULL or DISTINCT semantics:

```php
$withEmail   = $db->select()->from('employees')->count('email');
$departments = $db->select()->from('employees')->count('DISTINCT department_id');
```

Row counts default to `COUNT(1)`. `COUNT(*)` and `COUNT(1)` count the same rows, so
the default avoids any column expansion; supply a column only to count non-NULL values
(`count('email')`) or distinct values (`count('DISTINCT department_id')`).

---

# INSERT

```php
$db->insert()
    ->into('employees')
    ->set('employee_no',$empNo)
    ->set('first_name',$first)
    ->set('last_name',$last)
    ->set('department_id',$deptId)
    ->exec();
```

Produces:

```sql
INSERT INTO employees
(employee_no, first_name, last_name, department_id)
VALUES (:p1,:p2,:p3,:p4)
```

---

# INSERT with RETURNING / OUTPUT

Builders support returning rows even though each engine implements it differently (PostgreSQL/SQLite use `RETURNING`, SQL Server/Sybase use `OUTPUT`, Oracle uses `RETURNING ... INTO`). You always write the same fluent code:

```php
$row = $db->insert()
    ->into('employees')
    ->set('employee_no', $empNo)
    ->set('first_name', $first)
    ->set('last_name', $last)
    ->returning('id', 'employee_no')
    ->row();

// $row === ['id' => 123, 'employee_no' => 'E999']
```

Need multiple rows (e.g., bulk insert)? Use `->rows([...])` with your payload and then call `rows()` (no arguments) to fetch all returned values.

Supported engines for `returning()`:

- PostgreSQL
- SQLite (3.35+)
- SQL Server
- Sybase
- Oracle

Unsupported (calling `returning()` throws a `QueryException`):

- MariaDB
- DB2

This produces a vendor-specific statement via the dialect—for example PostgreSQL emits `RETURNING`, SQL Server emits `OUTPUT INSERTED...`. You never hand-roll those clauses yourself.

> **Fast failure**: Capability checks now run when you call `returning()`. If the active dialect cannot satisfy the request (e.g., MariaDB), UDA throws `MariaDB dialect does not support RETURNING clauses.` immediately, long before SQL reaches PDO.

**Oracle specifics (WO021):** Oracle’s dialect compiles the plain `INSERT/UPDATE/DELETE` while the driver appends `RETURNING ... INTO` at execution time. PDO OCI requires binding those `INTO` placeholders as input/output strings *before* running the statement, so UDA seeds empty strings, trims the results, and casts numerics back to PHP ints/floats. Oracle forbids multi-row `VALUES (...), (...) RETURNING` statements (ORA-63809); the driver automatically rewrites multi-row inserts into N individual statements and concatenates the returned rows so your builder API remains consistent.

Refer to `docs/spec.md#dialect-capability-matrix` for the authoritative feature grid (RETURNING, MERGE, writable CTEs, window functions, etc.). Every builder references those capabilities before generating SQL so unsupported features fail fast.

---

# Bulk INSERT

```php
$db->insert()
    ->into('employees')
    ->rows([
        ['employee_no'=>'E100','first_name'=>'A','last_name'=>'One'],
        ['employee_no'=>'E101','first_name'=>'B','last_name'=>'Two']
    ])
    ->exec();
```

---

# UPDATE

```php
$db->update()
    ->table('employees')
    ->set('title',$title)
    ->set('updated_at',$now)
    ->where('id',$id)
    ->exec();
```

---

# DELETE

```php
$db->delete()
    ->table('employees')
    ->where('id',$id)
    ->exec();
```

---

# UPSERT

Insert if missing, update if conflict occurs.

```php
$db->upsert()
    ->into('employees')
    ->values([
        'employee_no'=>$empNo,
        'first_name'=>$first,
        'last_name'=>$last
    ])
    ->key(['employee_no'])
    ->update(['first_name','last_name'])
    ->exec();
```

---

# UPSERT DO NOTHING

```php
$db->upsert()
    ->into('employees')
    ->values([
        'employee_no'=>$empNo,
        'first_name'=>$first
    ])
    ->key(['employee_no'])
    ->doNothing()
    ->exec();
```

---

# Window Functions

Window helpers live in `UDA\Query\Expr`. Chain `over()` and fluent partition/order/frame helpers on any expression.

## Ranking

```php
$rows = $db->select(
        'employee_id',
        Expr::rowNumber()
            ->over()
            ->partitionBy('department_id')
            ->orderBy('salary', 'DESC')
            ->as('dept_rank')
    )
    ->from('employees')
    ->rows();
```

SQL:

```sql
SELECT
    employee_id,
    ROW_NUMBER() OVER (
        PARTITION BY department_id
        ORDER BY salary DESC
    ) AS "dept_rank"
FROM "employees"
```

## Lag / Lead

```php
Expr::lag('price')
    ->over()
    ->orderBy('captured_at')
```

→ `LAG(price) OVER (ORDER BY captured_at ASC)`

## Running Totals

```php
Expr::sum('sales')
    ->over()
    ->orderBy('sale_date')
    ->rowsBetweenUnboundedPreceding()
```

→ `ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW`

Use `rowsBetween('1 PRECEDING','CURRENT ROW')` for sliding windows or `rangeBetween('INTERVAL 7 DAY PRECEDING','CURRENT ROW')` for RANGE frames. Helpers `rowsBetweenUnboundedPreceding()`, `rowsCurrentRow()`, `rangeBetweenUnboundedPreceding()`, and `rangeCurrentRow()` keep the grammar terse.

> All supported dialects (PostgreSQL, SQLite, SQL Server, Sybase, Oracle, MariaDB, DB2) advertise `supportsWindowFunctions()`, so capability enforcement from WO022 never blocks window usage.

---

# Transactions

```php
$db->transaction(function($tx){

    $tx->insert()
        ->into('employees')
        ->set('first_name','Alice')
        ->exec();

    $tx->insert()
        ->into('audit_log')
        ->set('event','employee_created')
        ->exec();
});
```

Nested transactions are supported automatically.

---

# UNION

```php
$active = $db->select('id','name')
    ->from('employees')
    ->where('active',1)
    ->end();

$retired = $db->select('id','name')
    ->from('retirees');

$rows = $active
    ->union($retired)
    ->rows();
```

---

# UNION ALL

```php
$rows = $active
    ->unionAll($retired)
    ->orderBy('name')
    ->rows();
```

Ordering and pagination apply to the combined result:

```php
$active
    ->unionAll($retired)
    ->orderBy('name')
    ->limit(50)
    ->offset(0)
    ->rows();
```

---

# Raw SQL (When Necessary)

UDA allows raw SQL through the `Sql` value object.

```php
use UDA\Query\Sql;

$q = Sql::of(
    "SELECT * FROM employees WHERE hire_date > :d",
    ['d'=>'2024-01-01'],
    ['employees']
);

$rows = $db->rows($q);
```

The table list allows cache invalidation without SQL parsing.

---

# Key Design Principles

* Named parameters only.
* SQL belongs in repository classes.
* Query builders construct SQL but never execute it.
* Database is the public execution surface.
* Cache behavior is transparent.
* Grammar remains readable and predictable.

---

# When to Use Raw SQL

Raw SQL is acceptable when:

* vendor-specific features are required
* complex CTEs are needed

However, most queries should remain in the builder for consistency.

---

# Philosophy

UDA is designed to give developers:

* the **power of SQL**
* the **safety of structured builders**
* the **performance of transparent caching**
* the **discipline of centralized queries**

Without introducing ORM complexity or abstraction overhead.
# Aggregate Expressions

```php
use UDA\Query\Expr;

$db->select(
        'department_id',
        Expr::count('id')->as('employee_count')
    )
    ->from('employees')
    ->groupBy('department_id')
    ->rows();
```

You can combine expressions with HAVING:

```php
$db->select(
        'department_id',
        Expr::avg('salary')->as('avg_salary')
    )
    ->from('employees')
    ->groupBy('department_id')
    ->having(Expr::avg('salary'))->gt(120000)
    ->rows();
```

And use `coalesce` helpers for computed columns:

```php
$db->select(
        'id',
        Expr::coalesce('title', 'Unknown')->as('title_display')
    )
    ->from('employees')
    ->rows();
```

Need a trusted raw expression? `Expr::raw()` accepts named parameters:

```php
$db->select(
        Expr::raw('COALESCE(title, :fallback)', ['fallback' => 'Unknown'])->as('title_display')
    )
    ->from('employees')
    ->rows();
```

Expressions also participate in ORDER BY clauses while keeping parameters deterministic:

```php
$lastSeen = Expr::raw('COALESCE(last_login, :fallback)', ['fallback' => '1970-01-01']);

$db->select(
        'id',
        $lastSeen->as('last_seen')
    )
    ->from('users')
    ->orderBy($lastSeen, 'DESC')
    ->rows();
```

Strings remain the default; expressions are optional helpers when structure matters.

---

# Common Table Expressions

> Note: CTE helpers currently apply to SELECT builders. Write builders will adopt the same surface in a later work order.

## Basic CTE

```php
$totals = $db->select(
        'department_id',
        Expr::count('id')->as('employee_count')
    )
    ->from('employees')
    ->groupBy('department_id');

$rows = $db->select('department_id', 'employee_count')
    ->with('totals', $totals)
    ->from('totals')
    ->where('employee_count')->gt(5)
    ->rows();
```

## Multiple CTEs

```php
$active = $db->select('id', 'department_id')
    ->from('employees')
    ->where('active', 1)
    ->end();

$regions = $db->select('id', 'region')
    ->from('departments');

$rows = $db->select('a.id', 'd.region')
    ->with('active_employees', $active)
    ->with('department_regions', $regions)
    ->from('active_employees', 'a')
    ->join('department_regions', 'd.id', 'a.department_id', 'INNER', 'd')
    ->rows();
```

## Recursive CTE

```php
$seed = $db->select('id', 'parent_id')
    ->from('nodes')
    ->where('id', $rootId)
    ->end();

$step = $db->select('n.id', 'n.parent_id')
    ->from('nodes', 'n')
    ->join('tree', 't.id', 'n.parent_id', 'INNER', 't');

$tree = $seed->unionAll($step);

$rows = $db->select()
    ->withRecursive('tree', $tree)
    ->from('tree')
    ->rows();
```

PostgreSQL, SQLite, MariaDB, and DB2 emit `WITH RECURSIVE`. SQL Server, Oracle, and Sybase use `WITH` for both recursive and non-recursive forms; the builder handles those dialect differences automatically while preserving deterministic parameter ordering.

## CTE Materialization Hints

Some engines let you hint whether a CTE should be materialized (computed once) or inlined (expanded for optimizer freedom). Call `materialized()` or `notMaterialized()` immediately after `with()`/`withRecursive()` to set the hint for the previously declared CTE.

```php
$expensive = $db->select('department_id')->from('employees');

$rows = $db->select('department_id')
    ->with('expensive_data', $expensive)
    ->materialized()
    ->from('expensive_data')
    ->rows();
```

Produces (PostgreSQL/SQLite):

```sql
WITH "expensive_data" AS MATERIALIZED (
    SELECT "department_id" FROM "employees"
)
SELECT "department_id" FROM "expensive_data"
```

Switch to `notMaterialized()` to inline the CTE:

```php
$rows = $db->select('id')
    ->with('recent_transactions', $transactionsQuery)
    ->notMaterialized()
    ->from('recent_transactions')
    ->rows();
```

Multiple CTEs can mix hints independently:

```php
$query = $db->select('a.id', 'r.region')
    ->with('active_employees', $activeQuery)->materialized()
    ->with('regions', $regionQuery)->notMaterialized()
    ->from('active_employees', 'a')
    ->join('regions', 'r.id', 'a.region_id', 'INNER', 'r');
```

PostgreSQL and SQLite emit `AS MATERIALIZED` / `AS NOT MATERIALIZED`. Other dialects ignore the hint automatically so the same builder remains portable.

# Window Functions

Window helpers live entirely inside `Expr` and compose with selects, subqueries, and CTEs.

## Ranking

```php
$rows = $db->select(
        'id',
        'department_id',
        Expr::rowNumber()
            ->over()
            ->partitionBy('department_id')
            ->orderBy('salary', 'DESC')
            ->as('rank')
    )
    ->from('employees')
    ->rows();
```

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

Dialects render the `OVER (...)` clause while `Expr` keeps window definitions immutable and deterministic.

---

# Writable CTEs

`with()` and `withRecursive()` now work on write builders, with deterministic parameter merging and dialect enforcement. Currently PostgreSQL and SQLite render writable CTEs; other dialects raise `QueryException` if you attempt to attach a CTE to INSERT/UPDATE/DELETE.

## INSERT ... SELECT from CTE

```php
$recent = $db->select('id', 'first_name', 'last_name')
    ->from('staging_employees')
    ->where('import_batch_id', $batchId)
    ->end();

$imported = $db->insert()
    ->with('recent', $recent)
    ->into('employees')
    ->columns('id', 'first_name', 'last_name')
    ->select(
        $db->select('id', 'first_name', 'last_name')->from('recent')
    )
    ->exec();
```

## UPDATE using a CTE source

```php
$raises = $db->select('employee_id', 'new_salary')
    ->from('salary_adjustments')
    ->where('batch_id', $batchId)
    ->end();

$db->update()
    ->with('raises', $raises)
    ->table('employees')
    ->set('salary', 123_456)
    ->whereRaw('id IN (SELECT employee_id FROM raises)')
    ->end()
    ->exec();
```

## DELETE using a CTE-fed subquery

```php
$expired = $db->select('id')
    ->from('sessions')
    ->where('expires_at', $now, '<')
    ->end();

$db->delete()
    ->with('expired', $expired)
    ->table('sessions')
    ->whereRaw('id IN (SELECT id FROM expired)')
    ->exec();
```

Unsupported dialects will now throw messages such as `MariaDB dialect does not support CTE clauses for INSERT statements.` so failures remain explicit.

---

# Pagination (Oracle example)

```php
$page = $db->select('id', 'name')
    ->from('employees')
    ->orderBy('id')
    ->limit(10)
    ->offset(20)
    ->rows();
```

Generated SQL (Oracle):

```
SELECT "id", "name"
FROM "employees"
ORDER BY "id" ASC
OFFSET :q1 ROWS FETCH NEXT :q2 ROWS ONLY
```

`Select::limit()` and `Select::offset()` always emit parameterized pagination tokens, so prepared statements remain deterministic while still using Oracle’s modern `OFFSET … FETCH NEXT …` syntax.

---

