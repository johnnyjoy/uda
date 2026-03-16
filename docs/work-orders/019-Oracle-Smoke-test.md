# Work Order 019 — Oracle Smoke Test and Execution Verification

## Execution Context

Target coding agent: **OpenAI GPT-5.1-codex** operating in an Opencode workflow.

A live Oracle instance is now available and reachable via **PDO OCI**.

Before expanding Oracle feature work, UDA must prove that its **core execution pipeline works correctly against a real Oracle database**.

This work order validates the following path:

```

UDA Builder
→ Dialect compilation
→ Sql value object
→ Database
→ Driver
→ PDO (OCI)
→ Oracle

```

The goal is **not full Oracle coverage**.  
The goal is **basic correctness and connectivity through UDA**.

---

# Authority

Documentation precedence:

1. constitution.md + style-guide.md  
2. contract.md  
3. spec.md  
4. design.md  

If code conflicts with documentation, code is wrong unless the documentation is clearly outdated.

---

# Goal

Verify that UDA can successfully execute Oracle queries using:

1. Raw SQL
2. Parameter binding
3. Query builders

against a real Oracle database instance.

These tests confirm:

- Oracle connection configuration
- driver execution path
- named parameter binding
- dialect compatibility for simple queries
- builder execution path

---

# Scope (Allowed Changes)

Modify only:

```

tests/Oracle/*
tests/Query/*
docs/oracle-testing.md
docs/work-orders/*

```

Small driver adjustments are allowed **only if required for basic functionality**.

Do not modify:

```

Query grammar
Builder architecture
Dialect design
Cache system

````

This work order verifies existing architecture, not redesigns it.

---

# Test Environment

The Oracle instance is reachable using PDO OCI.

Example connection:

```php
$pdo = new PDO(
    'oci:dbname=//127.0.0.1:1521/FREEPDB1;charset=AL32UTF8',
    'system',
    'password'
);
````

Test database object must use equivalent configuration through UDA's config system.

---

# Required Tests

Create integration tests in:

```
tests/Oracle/
```

---

# Test 1 — Raw SQL Execution

Verify UDA can execute a simple Oracle query.

Test:

```php
$row = $db->row('SELECT 1 AS one FROM dual');
```

Expected result:

```
['one' => 1]
```

Purpose:

* verify connection
* verify driver execution path
* verify row hydration

---

# Test 2 — Parameter Binding

Verify named parameters function correctly.

Test:

```php
$row = $db->row(
    'SELECT :x AS value FROM dual',
    ['x' => 42]
);
```

Expected result:

```
['value' => 42]
```

Purpose:

* verify parameter binding
* verify PDO named parameter compatibility
* verify Sql object binding pipeline

---

# Test 3 — Query Builder Execution

Verify Select builder execution works against Oracle.

Test:

```php
$row = $db->select('1 AS one')
    ->from('dual')
    ->row();
```

Expected result:

```
['one' => 1]
```

Purpose:

* verify builder compilation
* verify dialect compilation
* verify execution path

---

# Test 4 — Parameterized Builder Query

Test builder-generated parameters.

```php
$row = $db->select(':value AS test_value')
    ->from('dual')
    ->params(['value' => 99])
    ->row();
```

Expected result:

```
['test_value' => 99]
```

Purpose:

* verify builder parameter propagation
* verify deterministic param handling

---

# Test 5 — Table Query

Create a small table for verification.

```sql
CREATE TABLE uda_test_users (
    id NUMBER PRIMARY KEY,
    name VARCHAR2(50)
);
```

Insert test data manually:

```sql
INSERT INTO uda_test_users VALUES (1,'Alice');
INSERT INTO uda_test_users VALUES (2,'Bob');
COMMIT;
```

Then test:

```php
$rows = $db->select('id','name')
    ->from('uda_test_users')
    ->rows();
```

Expected result:

```
[
  ['id'=>1,'name'=>'Alice'],
  ['id'=>2,'name'=>'Bob']
]
```

Purpose:

* verify table queries
* verify row iteration
* verify result hydration

---

# Test 6 — WHERE Clause

Test parameterized WHERE.

```php
$row = $db->select('name')
    ->from('uda_test_users')
    ->where('id',1)
    ->row();
```

Expected:

```
['name'=>'Alice']
```

Purpose:

* verify WHERE builder grammar
* verify parameter binding inside builder

---

# Test 7 — Streaming Iteration

Verify large result streaming.

```php
$db->select('id','name')
   ->from('uda_test_users')
   ->each(function($row){
       assert(isset($row['id']));
   });
```

Purpose:

* verify streaming behavior
* confirm PDO cursor iteration

---

# Error Handling Test

Ensure Oracle errors propagate correctly.

Example invalid query:

```php
$db->row('SELECT * FROM non_existent_table');
```

Expected:

* Oracle exception
* surfaced through UDA exception system

---

# Documentation

Create:

```
docs/oracle-testing.md
```

Include:

* connection instructions
* Oracle configuration notes
* required extensions
* example connection config
* instructions to run tests

---

# Acceptance Criteria

All must be true:

```
Oracle connection works through UDA
Raw SQL executes
Named parameters bind correctly
Select builder executes
Builder WHERE clause works
Table queries work
Streaming iteration works
Errors propagate correctly
```

Tests must pass using the real Oracle instance.

---

# Evidence Required

Provide:

1. PHPUnit output for `tests/Oracle/*`
2. Sample query results
3. Oracle version information

Example:

```
SELECT * FROM v$version
```

4. confirmation that all smoke tests passed.

---

# Non-Goals

This work order does **not** verify:

```
CTE support
Writable CTE
Window functions
Set operators
MERGE
Pagination
Returning clauses
```

Those belong to later Oracle verification work orders.
