# Query Cookbook

This document demonstrates how to express common SQL patterns in UDA.

Goals:

- Keep SQL centralized in repositories.
- Keep grammar readable and predictable.
- Support complex logic without abstraction sprawl.
- Avoid raw SQL unless necessary.

---

## Basic SELECT

```php
$rows = $db->select()
    ->from('employees')
    ->rows();
````

---

## WHERE (Simple)

```php
->where('id', 5)
```

---

## WHERE (Boolean Composition – Fluent)

When boolean logic becomes complex, `where()` can enter *where-chain mode*.

```php
->where('active', 1)
    ->and('department_id')->in([10,20,30])
    ->or(fn($w) =>
        $w->and('title')->like('%Engineer%')
          ->and('hire_date')->between('2020-01-01','2024-12-31')
    )
    ->rows()
```

Notes:

* `where()` begins the WHERE clause.
* `and()`, `or()`, `not()` extend it.
* `in()`, `between()`, `like()`, `exists()` attach operators.
* Closure form groups expressions.

---

## IN

```php
->where('department_id')->in([1,2,3])
```

Empty list behavior:

```php
->where('department_id')->in([])
```

Produces:

```sql
WHERE 1 = 0
```

---

## BETWEEN

```php
->where('hire_date')->between('2020-01-01', '2024-01-01')
```

---

## EXISTS

```php
->whereExists(
    $db->select('1')
       ->from('payroll p')
       ->whereRaw('p.employee_id = e.id')
)
```

---

## DISTINCT

```php
->select('DISTINCT department_id')
```

---

## GROUP BY

```php
->groupBy('department_id')
```

---

## HAVING

```php
->groupBy('department_id')
->having('COUNT(id) > :n', ['n' => 5])
```

Optional fluent style:

```php
->groupBy('department_id')
->having('COUNT(id)')->gt(5)
    ->and('AVG(salary)')->gt(120000)
    ->rows()
```

---

## ORDER BY (Safe)

Always validate externally supplied column names.

```php
$allowed = ['last_name','hire_date','salary'];
$sort = in_array($sort, $allowed, true) ? $sort : 'last_name';

$rows = $db->select()
    ->from('employees')
    ->orderBy($sort, 'ASC')
    ->rows();
```

---

## Pagination

```php
->limit(50)
->offset(0)
```

---

## INSERT

```php
$db->insert()
    ->into('employees')
    ->set('employee_no', $empNo)
    ->set('first_name', $first)
    ->set('last_name', $last)
    ->set('department_id', $deptId)
    ->exec();
```

---

## UPDATE

```php
$db->update()
    ->table('employees')
    ->set('title', $title)
    ->set('updated_at', $now)
    ->where('id', $id)
    ->exec();
```

---

## DELETE

```php
$db->delete()
    ->table('employees')
    ->where('id', $id)
    ->exec();
```

---

## UPSERT

Upsert semantics: “insert if missing; otherwise update specific columns.”

### 1) Basic UPSERT (insert + update on conflict)

```php
$db->upsert()
    ->into('employees')
    ->values([
        'employee_no'   => $empNo,
        'first_name'    => $first,
        'last_name'     => $last,
        'department_id' => $deptId,
        'updated_at'    => $now,
    ])
    ->key(['employee_no'])                 // conflict target
    ->update(['first_name','last_name','department_id','updated_at'])
    ->exec();
```

Notes:

* `key([...])` is mandatory: it defines *how* we detect conflict.
* `update([...])` defines which columns to update on conflict.
* Execution is always `exec(): int` (affected rows).

### 2) UPSERT “DO NOTHING” (insert if missing, ignore if exists)

```php
$db->upsert()
    ->into('employees')
    ->values([
        'employee_no' => $empNo,
        'first_name'  => $first,
        'last_name'   => $last,
    ])
    ->key(['employee_no'])
    ->doNothing()
    ->exec();
```

### 3) Bulk UPSERT (many rows)

If supported by the builder:

```php
$db->upsert()
    ->into('employees')
    ->rows([
        ['employee_no' => 'E100', 'first_name' => 'A', 'last_name' => 'One', 'department_id' => 10],
        ['employee_no' => 'E101', 'first_name' => 'B', 'last_name' => 'Two', 'department_id' => 20],
    ])
    ->key(['employee_no'])
    ->update(['first_name','last_name','department_id'])
    ->exec();
```

If bulk isn’t supported yet, loop rows explicitly in repository code and keep it obvious.

### 4) Engine-specific behavior

UDA compiles UPSERT through the dialect/driver strategy:

* Postgres: `INSERT ... ON CONFLICT (...) DO UPDATE ...`
* SQLite: modern UPSERT (no `INSERT OR REPLACE` unless explicitly requested)
* SQL Server: update-then-insert strategy (or MERGE only if enabled)

If unsupported: throw `NotSupportedException` (clear failure).

---

## Complex Example (SELECT)

```php
$rows = $db->select('e.id','e.first_name','e.last_name')
    ->from('employees e')
    ->where('e.active', 1)
        ->and('e.department_id')->in([10,20,30])
        ->or(fn($w) =>
            $w->and('e.title')->like('%Engineer%')
              ->and('e.hire_date')->between('2020-01-01','2024-12-31')
        )
    ->groupBy('e.department_id')
    ->having('COUNT(e.id) > :n', ['n' => 3])
    ->orderBy('e.last_name', 'ASC')
    ->limit(50)
    ->offset(0)
    ->rows();
```

---

## Raw SQL (with table attribution)

When necessary, use Sql value objects.

```php
use UDA\Query\Sql;

$q = Sql::of(
    "SELECT * FROM employees WHERE hire_date > :d",
    ['d' => '2024-01-01'],
    ['employees'] // enables cache invalidation without SQL parsing
);

$rows = $db->rows($q);
```

---

## Key Design Principles

* Named parameters only.
* SQL lives in repositories.
* Cache is transparent (configuration enables it; users don’t “ask”).
* Driver is the only execution surface.
* Grammar is predictable and readable.
