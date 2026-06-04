# UDA Repository Patterns Cookbook

**Prerequisites:** [building-your-dal.md](building-your-dal.md) (`Link` vs `Database`,
layer rules). This cookbook adds **SQL recipes** for repository methods.

This document demonstrates **practical repository patterns** using UDA.

Goals:

* Centralize SQL logic
* Encourage predictable data access patterns
* Avoid ORM-style abstraction creep
* Keep repositories explicit and readable
* Support complex applications without leaking infrastructure concerns

---

# Repository Structure

Repositories represent **data access boundaries**, not entities.

Example:

```
App
 └─ Repository
     ├─ EmployeeRepository.php
     ├─ PayrollRepository.php
     └─ DepartmentRepository.php
```

Typical repository:

```php
class EmployeeRepository
{
    public function __construct(
        private Database $db
    ) {}

    public function findById(int $id): ?array
    {
        return $this->db->select()
            ->from('employees')
            ->where('id', $id)
            ->row();
    }
}
```

Repositories should contain **all SQL touching that table or domain**.

---

# Pattern: Simple Lookup

```php
public function findByEmployeeNo(string $empNo): ?array
{
    return $this->db->select()
        ->from('employees')
        ->where('employee_no', $empNo)
        ->row();
}
```

Purpose:

* deterministic lookup
* safe parameter binding
* simple read surface

---

# Pattern: Existence Check

Use `value()` for fast existence checks.

```php
public function employeeExists(string $empNo): bool
{
    return (bool) $this->db->select('1')
        ->from('employees')
        ->where('employee_no', $empNo)
        ->value();
}
```

Equivalent SQL:

```sql
SELECT 1
FROM employees
WHERE employee_no = :p1
```

---

# Pattern: Domain List

Fetching lists for UI.

```php
public function listActive(): array
{
    return $this->db->select(
            'id',
            'first_name',
            'last_name'
        )
        ->from('employees')
        ->where('active', 1)
        ->orderBy('last_name')
        ->rows();
}
```

---

# Pattern: Pagination

```php
public function listPage(int $limit, int $offset): array
{
    return $this->db->select(
            'id',
            'first_name',
            'last_name'
        )
        ->from('employees')
        ->orderBy('last_name')
        ->limit($limit)
        ->offset($offset)
        ->rows();
}
```

---

# Pattern: Cursor Pagination

Better for large datasets.

```php
public function listAfterId(int $cursor, int $limit): array
{
    return $this->db->select(
            'id',
            'first_name',
            'last_name'
        )
        ->from('employees')
        ->where('id')->gt($cursor)
        ->orderBy('id')
        ->limit($limit)
        ->rows();
}
```

Advantages:

* stable pagination
* no large OFFSET scans

---

# Pattern: Optional Filters

```php
public function search(array $filters): array
{
    $q = $this->db->select()
        ->from('employees');

    if (!empty($filters['department'])) {
        $q->where('department_id', $filters['department']);
    }

    if (!empty($filters['title'])) {
        $q->and('title')->like('%'.$filters['title'].'%');
    }

    return $q->rows();
}
```

Avoids:

* dynamic SQL string building
* injection risks

---

# Pattern: Multi-Table Query

```php
public function employeesWithDepartments(): array
{
    return $this->db->select(
            'e.id',
            'e.first_name',
            'e.last_name',
            'd.name as department'
        )
        ->from('employees e')
        ->join('departments d','d.id = e.department_id')
        ->rows();
}
```

---

# Pattern: Aggregate Queries

```php
public function departmentCounts(): array
{
    return $this->db->select(
            'department_id',
            'COUNT(*) as total'
        )
        ->from('employees')
        ->groupBy('department_id')
        ->rows();
}
```

---

# Pattern: Analytics / Reporting Query

```php
public function salaryStats(): array
{
    return $this->db->select(
            'department_id',
            'AVG(salary) as avg_salary',
            'MAX(salary) as max_salary',
            'MIN(salary) as min_salary'
        )
        ->from('employees')
        ->groupBy('department_id')
        ->rows();
}
```

---

# Pattern: Bulk Insert

```php
public function insertEmployees(array $rows): int
{
    return $this->db->insert()
        ->into('employees')
        ->rows($rows)
        ->exec();
}
```

---

# Pattern: Safe Update

```php
public function updateTitle(int $id, string $title): int
{
    return $this->db->update()
        ->table('employees')
        ->set('title', $title)
        ->where('id', $id)
        ->exec();
}
```

---

# Pattern: Domain Upsert

```php
public function saveEmployee(array $data): int
{
    return $this->db->upsert()
        ->into('employees')
        ->values($data)
        ->key(['employee_no'])
        ->update([
            'first_name',
            'last_name',
            'department_id',
            'updated_at'
        ])
        ->exec();
}
```

---

# Pattern: Write + Audit Transaction

```php
public function createEmployee(array $data): void
{
    $this->db->transaction(function($tx) use ($data) {

        $tx->insert()
            ->into('employees')
            ->values($data)
            ->exec();

        $tx->insert()
            ->into('audit_log')
            ->set('event','employee_created')
            ->set('employee_no',$data['employee_no'])
            ->exec();
    });
}
```

---

# Pattern: Streaming Large Data

```php
public function exportEmployees(callable $consumer): void
{
    $this->db->select()
        ->from('employees')
        ->orderBy('id')
        ->each($consumer);
}
```

Useful for:

* exports
* ETL
* reporting pipelines

---

# Pattern: Multi-Connection Repository

Repositories may use multiple databases.

```php
class EmployeeRepository
{
    public function __construct(
        private Database $primary,
        private Database $analytics
    ) {}
}
```

Example:

```php
return $this->analytics->select()
    ->from('employee_stats')
    ->rows();
```

---

# Pattern: Raw SQL Escape Hatch

When builders cannot express a query:

```php
use UDA\Query\Sql;

public function employeesHiredAfter(string $date): array
{
    $q = Sql::of(
        "SELECT * FROM employees WHERE hire_date > :d",
        ['d' => $date],
        ['employees']
    );

    return $this->db->rows($q);
}
```

Table attribution ensures cache invalidation works correctly.

---

# Repository Design Rules

Repositories should:

* contain SQL logic
* return simple arrays
* avoid ORM-style entity mapping
* avoid business logic
* remain predictable

Repositories should **not**:

* construct DSNs
* use PDO
* contain caching logic
* embed infrastructure code

---

# Philosophy

UDA repositories aim to give developers:

* **the control of SQL**
* **the safety of structured builders**
* **the performance of transparent caching**
* **the discipline of centralized data access**

Without introducing the complexity of ORMs.
