# Work Order 020 — Oracle Pagination Verification

## Execution Context

Target coding agent: **OpenAI GPT-5.1-codex** operating in an Opencode workflow.

A live Oracle instance is available and basic execution through UDA has already been verified (WO019).

This work order verifies that **UDA pagination compiles and executes correctly on Oracle**.

Pagination is one of the most common sources of SQL dialect errors. Oracle in particular has historically used different syntaxes:

- modern Oracle: `OFFSET … FETCH`
- legacy Oracle: `ROWNUM` wrapping

UDA currently targets **modern Oracle syntax** using:

```

OFFSET n ROWS FETCH NEXT m ROWS ONLY

```

This work order ensures the dialect implementation behaves correctly with:

- `limit()`
- `offset()`
- `orderBy()`
- combined pagination
- builder execution path
- deterministic ordering

The goal is **real execution verification**, not theoretical compilation.

---

# Authority

Documentation precedence:

1. constitution.md + style-guide.md
2. contract.md
3. spec.md
4. design.md

If code conflicts with documentation, code is wrong unless documentation is clearly outdated.

---

# Goal

Verify that pagination works correctly through UDA against Oracle.

Tests must confirm:

- SQL compilation correctness
- correct row counts
- correct row ordering
- correct interaction with WHERE and ORDER BY
- deterministic behavior

The tests must use **real Oracle execution**, not mocks.

---

# Scope (Allowed Changes)

Modify only:

```

tests/Oracle/*
tests/Query/*
docs/oracle-testing.md
docs/query-cookbook.md
docs/spec.md

```

Small dialect fixes are allowed **only if Oracle execution proves them necessary**.

Do not modify:

```

Query grammar
Builder architecture
Driver communication layer
Cache system

````

---

# Test Data

Use the existing Oracle table or create a deterministic dataset.

Example setup:

```sql
CREATE TABLE uda_test_numbers (
    id NUMBER PRIMARY KEY,
    value NUMBER
);
````

Populate predictable rows:

```sql
INSERT INTO uda_test_numbers VALUES (1,10);
INSERT INTO uda_test_numbers VALUES (2,20);
INSERT INTO uda_test_numbers VALUES (3,30);
INSERT INTO uda_test_numbers VALUES (4,40);
INSERT INTO uda_test_numbers VALUES (5,50);
INSERT INTO uda_test_numbers VALUES (6,60);
INSERT INTO uda_test_numbers VALUES (7,70);
INSERT INTO uda_test_numbers VALUES (8,80);
INSERT INTO uda_test_numbers VALUES (9,90);
INSERT INTO uda_test_numbers VALUES (10,100);
COMMIT;
```

This deterministic data is required for reliable pagination tests.

---

# Required Tests

Create tests under:

```
tests/Oracle/PaginationTest.php
```

---

# Test 1 — LIMIT Only

Verify basic limit behavior.

```php
$rows = $db->select('id')
    ->from('uda_test_numbers')
    ->orderBy('id')
    ->limit(3)
    ->rows();
```

Expected result:

```
[1,2,3]
```

Expected SQL shape:

```sql
SELECT id
FROM uda_test_numbers
ORDER BY id
FETCH FIRST 3 ROWS ONLY
```

---

# Test 2 — OFFSET Only

Verify offset works.

```php
$rows = $db->select('id')
    ->from('uda_test_numbers')
    ->orderBy('id')
    ->offset(5)
    ->rows();
```

Expected result:

```
[6,7,8,9,10]
```

Expected SQL shape:

```sql
OFFSET 5 ROWS
```

---

# Test 3 — LIMIT + OFFSET

Verify combined pagination.

```php
$rows = $db->select('id')
    ->from('uda_test_numbers')
    ->orderBy('id')
    ->limit(3)
    ->offset(4)
    ->rows();
```

Expected result:

```
[5,6,7]
```

Expected SQL shape:

```sql
ORDER BY id
OFFSET 4 ROWS FETCH NEXT 3 ROWS ONLY
```

---

# Test 4 — Deterministic Ordering

Verify results depend on ORDER BY.

```php
$rows = $db->select('id')
    ->from('uda_test_numbers')
    ->orderBy('value','DESC')
    ->limit(3)
    ->rows();
```

Expected result:

```
[10,9,8]
```

Purpose:

Ensure ordering affects pagination results.

---

# Test 5 — Pagination with WHERE

Verify pagination works with filters.

```php
$rows = $db->select('id')
    ->from('uda_test_numbers')
    ->where('value')->gt(30)
    ->orderBy('id')
    ->limit(3)
    ->rows();
```

Expected result:

```
[4,5,6]
```

---

# Test 6 — Pagination with Builder + Params

```php
$rows = $db->select('id')
    ->from('uda_test_numbers')
    ->where('value')->gt(20)
    ->orderBy('id')
    ->limit(2)
    ->offset(2)
    ->rows();
```

Expected result:

```
[5,6]
```

Purpose:

* verify parameter binding
* verify correct ordering

---

# Test 7 — Builder Determinism

Execute identical builder multiple times.

```php
$q = $db->select('id')
    ->from('uda_test_numbers')
    ->orderBy('id')
    ->limit(3);

$q->rows();
$q->rows();
$q->rows();
```

Verify:

* identical results
* identical SQL
* identical param ordering

---

# Test 8 — Streaming Pagination

Verify streaming iteration with pagination.

```php
$db->select('id')
   ->from('uda_test_numbers')
   ->orderBy('id')
   ->limit(5)
   ->each(function($row){
       assert(isset($row['id']));
   });
```

Purpose:

Confirm cursor iteration works with pagination.

---

# Error Handling Test

Invalid pagination must fail cleanly.

Example:

```php
$db->select('id')
   ->from('uda_test_numbers')
   ->limit(-1)
   ->rows();
```

Expected:

* QueryException
* descriptive error message

---

# SQL Compilation Verification

Capture compiled SQL for each test.

Example:

```
SELECT id
FROM uda_test_numbers
ORDER BY id
OFFSET 4 ROWS FETCH NEXT 3 ROWS ONLY
```

Ensure SQL is valid for Oracle.

---

# Documentation Updates

Update:

```
docs/query-cookbook.md
```

Add Oracle pagination examples.

Example:

```php
$rows = $db->select('id','name')
    ->from('employees')
    ->orderBy('id')
    ->limit(10)
    ->offset(20)
    ->rows();
```

Generated SQL (Oracle):

```
ORDER BY id
OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY
```

---

# Acceptance Criteria

All must be true:

```
limit() works
offset() works
limit + offset works
ORDER BY interacts correctly
WHERE interacts correctly
pagination deterministic
streaming works
invalid inputs fail clearly
```

All tests must pass using the **live Oracle instance**.

---

# Evidence Required

Provide:

1. PHPUnit output for `tests/Oracle/PaginationTest.php`
2. Example compiled SQL
3. Oracle version information

Example:

```sql
SELECT * FROM v$version
```

4. confirmation that each pagination scenario returned correct rows.

---

# Non-Goals

This work order does not test:

```
CTE pagination
compound query pagination
window function pagination
Oracle MERGE
RETURNING clauses
```

Those belong to later Oracle verification work orders.

---

# Philosophy

Pagination is a foundational SQL capability and one of the most common dialect failure points.

Verifying pagination against a live Oracle instance ensures that UDA’s dialect compilation is correct and reliable before additional Oracle feature verification proceeds.
