# Work Order 005 — Driver Execution Hot Path

## Authority

Documentation precedence:

1. constitution.md + style-guide.md
2. contract.md
3. spec.md
4. design.md

---

## Goal

Implement and lock the single execution hot path inside abstract `UDA\Driver`.

Driver must remain the only place that:

- prepares SQL
- binds parameters
- executes statements

All reads and writes must converge through this path.

---

## Scope (Allowed Changes)

Only modify:

- `src/UDA/Driver.php`
- `src/UDA/Exception/QueryException.php`
- `tests/Driver/*`
- `security.md`
- `spec.md` (only if wording must be aligned)

No other files may be changed.

---

## Requirements

### 1. Single Hot Path

There must be exactly one internal method that performs:

- SQL normalization
- `prepare`
- `execute`

That method must be used by:

- `row()`
- `rows()`
- `value()`
- `values()`
- `list()`
- `each()`
- `exec()`

---

### 2. Named Parameters Only

Public raw SQL APIs must reject positional `?` placeholders before reaching PDO.

Allowed:

```sql
WHERE id = :id
````

Forbidden:

```sql
WHERE id = ?
```

Reject with `QueryException`.

---

### 3. Sql Input Support

The public read/write methods must continue to accept:

* raw SQL strings
* `Sql` value objects

Example:

```php
$db->rows('SELECT * FROM users WHERE id = :id', ['id' => 1]);

$db->rows(Sql::of(
    'SELECT * FROM users WHERE id = :id',
    ['id' => 1],
    ['users']
));
```

---

### 4. Result Shapes

* `row()` → associative array or null
* `rows()` → array of associative arrays
* `value()` → one scalar or null
* `values()` / `list()` → first-column array
* `each()` → uses `fetchAll(PDO::FETCH_ASSOC)` and then iterates in PHP
* no cursor streaming

`PDO::FETCH_ASSOC` is the required fetch mode.

---

### 5. Transaction Support

Driver owns transaction orchestration.

Requirements:

* nested transactions supported
* savepoints used when supported by backend
* exceptions roll back correctly
* callback receives `Database` or internal execution handle as currently designed, but no public Driver exposure is introduced

---

### 6. Debug State

Driver must expose:

* `lastSql()`
* `lastParams()`

These reflect the most recent executed statement.

---

## Tests Required

Create or update tests covering:

### Execution

* all public execution methods converge into the same internal path
* named parameter queries work
* positional placeholder queries fail

### Result semantics

* `row()` throws on >1 row
* `value()` throws on >1 row or >1 column
* `values()` returns first column only
* `each()` iterates buffered rows

### Transactions

* nested transactions
* rollback on exception
* savepoint use where supported

---

## Acceptance Criteria

All of the following must pass:

* Driver execution tests
* no positional placeholders reach PDO
* only one prepare/execute path exists

---

## Evidence Required

Provide:

* PHPUnit output for `tests/Driver/*`
* location of the single hot path method
* examples of rejected `?` SQL
