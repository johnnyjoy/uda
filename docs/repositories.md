# UDA Repositories

## Purpose

Repositories are the **single boundary** between application code and database interaction.

UDA exists to:
1. Centralize SQL in predictable locations.
2. Make database access uniform.
3. Allow transparent acceleration via cache.
4. Prevent SQL from being scattered throughout a codebase.

A repository is not an ORM entity.
A repository is not an ActiveRecord.
A repository is not magic.

A repository is a structured, explicit interface to data.

---

## Core Rule

> All SQL lives inside repository classes.

Application layers must never:
- Import PDO
- Construct DSNs
- Execute raw SQL
- Interact directly with Cache
- Call Database::connect outside controlled boundaries

Repositories isolate all data access.

---

## Basic Pattern

```php
final class EmployeeRepository
{
    public function find(int $id): ?array
    {
        return Database::connect('hr')
            ->select()
            ->from('employees')
            ->where('id', $id)
            ->row();
    }
}
````

---

## Multi-Connection Repository

A single repository may use multiple databases.

```php
final class EmployeeRepository
{
    public function payrollSummary(int $employeeId): array
    {
        return Database::connect('payroll')
            ->select()
            ->from('payroll')
            ->where('employee_id', $employeeId)
            ->rows();
    }

    public function profile(int $employeeId): ?array
    {
        return Database::connect('hr')
            ->select()
            ->from('employees')
            ->where('id', $employeeId)
            ->row();
    }
}
```

Connection choice is explicit and controlled.

---

## Complex Query Example

```php
->where(function ($w) {
    $w->where('active', 1)
      ->orWhere(function ($w2) {
          $w2->whereNull('terminated_at')
             ->whereBetween('hire_date', '2020-01-01', '2024-01-01');
      });
})
->whereIn('department_id', [1,2,3])
->groupBy('department_id')
->having('COUNT(id) > 5')
->orderBy('last_name', 'ASC')
->limit(50)
->offset(0)
->rows();
```

If this cannot be expressed, UDA is incomplete.

---

## Raw SQL With Table Attribution

```php
use UDA\Query\Sql;

$sql = Sql::of(
    "SELECT * FROM employees WHERE hire_date > :d",
    ['d' => '2024-01-01'],
    ['employees']
);

$rows = $driver->rows($sql);
```

`tables` enables cache invalidation without parsing SQL.

---

## What Repositories Are Not

* They are not models.
* They do not hydrate entities.
* They do not reflect schema.
* They do not auto-map classes.

They are controlled SQL boundaries.
