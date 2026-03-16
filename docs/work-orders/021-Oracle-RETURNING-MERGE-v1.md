# Work Order 021 — Oracle RETURNING / MERGE Verification

## Execution Context

Target coding agent: **OpenAI GPT-5.1-codex** operating in an Opencode workflow.

A live Oracle instance is available and the following have already been verified through UDA:

- Oracle connectivity
- raw SQL execution
- builder execution
- parameter binding
- pagination through the builder stack

This work order verifies Oracle behavior for:

- `RETURNING ... INTO`
- `MERGE`
- UDA `Upsert`
- write statements that return rows through UDA’s normal execution path

The goal is to prove that Oracle-specific write semantics work correctly **through UDA**, not just in isolated PDO OCI experiments.

Execution pipeline remains:

```text
Builder
→ Dialect compiler
→ Sql value object
→ Database
→ Driver
→ PDO OCI
→ Oracle
````

---

# Authority

Documentation precedence:

1. constitution.md + style-guide.md
2. contract.md
3. spec.md
4. design.md

If code conflicts with docs, code is wrong unless docs are clearly outdated.

The Query Cookbook remains the north star.

---

# Goal

Verify that Oracle-specific write behavior functions correctly through UDA for:

* `INSERT ... RETURNING`
* `UPDATE ... RETURNING`
* `DELETE ... RETURNING`
* `MERGE`-based upsert behavior
* `Upsert` builder execution on Oracle
* interaction between Oracle returning semantics and UDA’s builder terminators

This work order must prove:

* Oracle driver behavior is correct
* dialect output is correct
* returned rows/values come back through UDA correctly
* Oracle `MERGE` behavior is real and stable
* unsupported combinations fail clearly

---

# Scope (Allowed Changes)

Modify only:

* `tests/Oracle/*`
* `tests/Query/*`
* `docs/oracle-testing.md`
* `docs/query-cookbook.md`
* `docs/spec.md`

Small fixes are allowed only if live Oracle behavior proves them necessary in:

* `src/UDA/Driver/Oracle.php`
* `src/UDA/Query/Dialect/Oracle.php`
* `src/UDA/Query/*`
* `src/UDA/Database.php`

Do not modify:

* cache subsystem
* config subsystem
* general execution architecture
* non-Oracle drivers unless absolutely required for shared contract correctness

This work order is primarily **verification**, with small corrective fixes allowed if Oracle proves a real defect.

---

# Test Data

Create deterministic Oracle test tables.

## Base table

```sql
CREATE TABLE uda_test_employees (
    id NUMBER PRIMARY KEY,
    employee_no VARCHAR2(20) UNIQUE,
    first_name VARCHAR2(50),
    last_name VARCHAR2(50),
    salary NUMBER
);
```

Seed rows:

```sql
INSERT INTO uda_test_employees (id, employee_no, first_name, last_name, salary)
VALUES (1, 'E001', 'Alice', 'One', 100000);

INSERT INTO uda_test_employees (id, employee_no, first_name, last_name, salary)
VALUES (2, 'E002', 'Bob', 'Two', 120000);

COMMIT;
```

## Optional source table for MERGE verification

```sql
CREATE TABLE uda_test_employee_updates (
    employee_no VARCHAR2(20) PRIMARY KEY,
    first_name VARCHAR2(50),
    last_name VARCHAR2(50),
    salary NUMBER
);
```

Seed rows as needed per test.

---

# Required Tests

Create integration tests under:

```text
tests/Oracle/
```

Suggested file:

```text
tests/Oracle/ReturningAndMergeTest.php
```

---

# Test 1 — INSERT ... RETURNING via Builder

Verify:

```php
$row = $db->insert()
    ->into('uda_test_employees')
    ->set('id', 3)
    ->set('employee_no', 'E003')
    ->set('first_name', 'Carol')
    ->set('last_name', 'Three')
    ->set('salary', 130000)
    ->returning('id', 'employee_no')
    ->row();
```

Expected result:

```php
['id' => 3, 'employee_no' => 'E003']
```

Purpose:

* verify Oracle returning support through builder
* verify returned row comes back through UDA
* verify Oracle driver returning path

---

# Test 2 — INSERT ... RETURNING via value()

Verify:

```php
$value = $db->insert()
    ->into('uda_test_employees')
    ->set('id', 4)
    ->set('employee_no', 'E004')
    ->set('first_name', 'David')
    ->set('last_name', 'Four')
    ->set('salary', 140000)
    ->returning('id')
    ->value();
```

Expected:

```php
4
```

Purpose:

* verify first-column extraction for Oracle returning

---

# Test 3 — UPDATE ... RETURNING

Verify:

```php
$row = $db->update()
    ->table('uda_test_employees')
    ->set('salary', 150000)
    ->where('employee_no', 'E001')
    ->returning('employee_no', 'salary')
    ->row();
```

Expected result:

```php
['employee_no' => 'E001', 'salary' => 150000]
```

Purpose:

* verify update returning
* verify Oracle `RETURNING ... INTO` path for updates

---

# Test 4 — DELETE ... RETURNING

Verify:

```php
$row = $db->delete()
    ->table('uda_test_employees')
    ->where('employee_no', 'E002')
    ->returning('employee_no', 'id')
    ->row();
```

Expected result:

```php
['employee_no' => 'E002', 'id' => 2]
```

Purpose:

* verify delete returning
* verify deleted-row values are surfaced correctly

---

# Test 5 — INSERT ... RETURNING via Database explicit path

Verify explicit execution remains valid:

```php
$q = $db->insert()
    ->into('uda_test_employees')
    ->set('id', 5)
    ->set('employee_no', 'E005')
    ->set('first_name', 'Eve')
    ->set('last_name', 'Five')
    ->set('salary', 160000)
    ->returning('id', 'employee_no');

$row = $db->row($q);
```

Expected result:

```php
['id' => 5, 'employee_no' => 'E005']
```

Purpose:

* verify explicit Database execution path still works
* builder terminators are not the only valid path

---

# Test 6 — Bulk INSERT returning behavior

Verify current Oracle behavior for multi-row insert returning.

If supported by current implementation, prove it works.

If current implementation splits into per-row statements, verify:

* correct rows returned
* deterministic ordering
* no silent corruption

Example:

```php
$rows = $db->insert()
    ->into('uda_test_employees')
    ->rows([
        [
            'id' => 6,
            'employee_no' => 'E006',
            'first_name' => 'Frank',
            'last_name' => 'Six',
            'salary' => 170000,
        ],
        [
            'id' => 7,
            'employee_no' => 'E007',
            'first_name' => 'Grace',
            'last_name' => 'Seven',
            'salary' => 180000,
        ],
    ])
    ->returning('id', 'employee_no')
    ->rows();
```

Expected result:

```php
[
    ['id' => 6, 'employee_no' => 'E006'],
    ['id' => 7, 'employee_no' => 'E007'],
]
```

If Oracle/builder limitations prevent this cleanly, fail explicitly and document it.

Purpose:

* verify Oracle multi-row returning behavior through UDA
* validate existing Oracle driver strategy

---

# Test 7 — Upsert builder compiles to Oracle MERGE

Verify Oracle dialect output for `Upsert` uses `MERGE`.

Example:

```php
$q = $db->upsert()
    ->into('uda_test_employees')
    ->values([
        'employee_no' => 'E001',
        'first_name' => 'Alice',
        'last_name' => 'One',
        'salary' => 155000,
    ])
    ->key(['employee_no'])
    ->update(['first_name', 'last_name', 'salary']);
```

Assert compiled SQL shape includes Oracle MERGE semantics.

Purpose:

* verify Oracle dialect path for Upsert
* ensure Oracle is not using non-Oracle upsert syntax

---

# Test 8 — MERGE updates existing row

Execute Oracle upsert against an existing row:

```php
$count = $db->upsert()
    ->into('uda_test_employees')
    ->values([
        'employee_no' => 'E001',
        'first_name' => 'Alice',
        'last_name' => 'Updated',
        'salary' => 165000,
    ])
    ->key(['employee_no'])
    ->update(['first_name', 'last_name', 'salary'])
    ->exec();
```

Then verify row contents through a select.

Purpose:

* prove MERGE updates matched rows correctly

---

# Test 9 — MERGE inserts missing row

Execute Oracle upsert for a non-existing row:

```php
$count = $db->upsert()
    ->into('uda_test_employees')
    ->values([
        'employee_no' => 'E099',
        'first_name' => 'New',
        'last_name' => 'Person',
        'salary' => 111000,
    ])
    ->key(['employee_no'])
    ->update(['first_name', 'last_name', 'salary'])
    ->exec();
```

Then verify inserted row exists.

Purpose:

* prove MERGE inserts unmatched rows correctly

---

# Test 10 — Upsert doNothing behavior

Verify Oracle behavior for:

```php
->doNothing()
```

If supported by current dialect strategy, prove it.

If not supported, fail explicitly and document it.

No fake support.

Purpose:

* verify honest behavior for Oracle do-nothing semantics

---

# Test 11 — RETURNING + terminator semantics

Verify terminators behave correctly on Oracle returning queries:

* `row()`
* `rows()`
* `value()`
* `values()`

Example expectations:

* `row()` returns first returned row or null
* `rows()` returns all returned rows
* `value()` returns first column of first row or null
* `values()` returns first column of all returned rows

Purpose:

* ensure Oracle driver integrates correctly with UDA result semantics

---

# Test 12 — Error propagation

Verify Oracle errors propagate cleanly through UDA.

Examples:

* duplicate unique key on insert
* invalid returning column
* malformed MERGE state if reachable
* unsupported builder/dialect combination

Expected:

* clear exception
* no silent fallback
* no swallowed Oracle error details

---

# SQL Compilation Verification

Capture and inspect compiled Oracle SQL for:

* INSERT ... RETURNING
* UPDATE ... RETURNING
* DELETE ... RETURNING
* MERGE-based upsert

Examples of what to inspect:

## Returning

Oracle may compile core write SQL and rely on driver augmentation for `RETURNING ... INTO`.

That is acceptable, but the test output must make the split clear.

## MERGE

Compiled SQL must reflect real Oracle merge shape.

---

# Documentation Updates

Update:

* `docs/oracle-testing.md`
* `docs/query-cookbook.md`
* `docs/spec.md`

Document:

* what Oracle returning paths are supported
* whether Oracle bulk returning is supported and how
* whether `Upsert` maps to `MERGE`
* whether `doNothing()` is supported or explicitly unsupported on Oracle

The docs must reflect observed behavior from the live Oracle instance.

---

# Acceptance Criteria

All must be true:

* INSERT returning works on Oracle through UDA
* UPDATE returning works on Oracle through UDA
* DELETE returning works on Oracle through UDA
* explicit Database execution path works
* Oracle upsert compiles to MERGE
* MERGE updates existing rows correctly
* MERGE inserts missing rows correctly
* unsupported combinations fail clearly
* Oracle errors propagate correctly
* docs reflect live tested behavior

---

# Evidence Required

Provide:

1. Changed files
2. PHPUnit output for Oracle integration tests
3. Example compiled SQL for:

   * Oracle insert returning
   * Oracle update returning
   * Oracle delete returning
   * Oracle MERGE upsert
4. One result example each for:

   * insert returning row
   * update returning row
   * delete returning row
   * merge update
   * merge insert
5. Oracle version information from the live instance

---

# Non-Goals

Do not expand scope into:

* full Oracle writable CTE verification
* Oracle compound-query verification
* Oracle window-function verification
* optimizer hints
* materialized CTE options
* general identifier-rule redesign

Those belong to separate work orders.

---

# Philosophy

Oracle is one of the strictest and most revealing SQL backends in UDA’s support matrix.

If UDA can correctly execute:

* returning write statements
* MERGE-based upserts
* builder-driven result extraction

against a live Oracle instance, then Oracle support moves from “theoretically correct” to “operationally credible.”

This work order is about proving that credibility with evidence.
