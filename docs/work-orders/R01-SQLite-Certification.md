# Work Order R01 — SQLite Certification

**Status:** Planned
**Priority:** High
**Category:** Release Hardening
**Phase:** Backend Certification
**Applies To:** Entire UDA stack

---

# Objective

Certify that UDA works **correctly, deterministically, and performantly** against SQLite.

This certification establishes the **baseline backend support** required for the first public release.

SQLite is chosen first because it is:

* extremely stable
* easy to spin up
* deterministic
* widely used for testing
* ideal for validating builder/dialect correctness

The goal is to verify that **every supported query path works end-to-end**.

---

# Success Criteria

SQLite certification is complete when:

* all integration tests pass against SQLite
* all supported SQL grammar compiles correctly
* dialect behavior matches SQLite semantics
* retry/guardrails/tracing/replay/metrics operate correctly
* performance baselines are recorded
* documentation clearly lists SQLite support

---

# Environment Setup

SQLite requires no external server.

Use:

```text
sqlite3 >= 3.35
```

Required for:

```text
RETURNING support
```

Test connection DSN:

```php
sqlite::memory:
```

and

```php
sqlite:/tmp/uda_test.db
```

Both must be tested.

---

# Test Categories

Certification requires **five test layers**.

---

# 1. Dialect Compilation Tests

Verify SQL generation matches SQLite syntax.

Location:

```
tests/Dialect/SQLiteDialectTest.php
```

Test cases:

### SELECT

```sql
SELECT id,name FROM employees WHERE id = :p1
```

Verify:

* limit
* offset
* order by
* joins
* group by
* having
* window functions

---

### INSERT

```sql
INSERT INTO employees(name) VALUES(:p1)
```

---

### INSERT RETURNING

SQLite ≥ 3.35:

```sql
INSERT INTO employees(name) VALUES(:p1) RETURNING id
```

---

### UPSERT

SQLite syntax:

```sql
INSERT INTO employees(id,name)
VALUES(:id,:name)
ON CONFLICT(id) DO UPDATE SET name=:name
```

Verify builder produces correct clause.

---

### DELETE

```sql
DELETE FROM employees WHERE id=:p1
```

---

### UPDATE

```sql
UPDATE employees SET name=:p1 WHERE id=:p2
```

---

### CTE

```sql
WITH active AS (
  SELECT id FROM employees WHERE active=1
)
SELECT * FROM active
```

---

### UNION

```sql
SELECT id FROM employees
UNION
SELECT id FROM contractors
```

---

# 2. Execution Tests

Verify queries execute correctly.

Location:

```
tests/Integration/SQLiteExecutionTest.php
```

Use temporary SQLite database.

Test:

### Basic CRUD

* insert
* update
* delete
* select

Verify:

* row count
* returned values
* parameter binding

---

### RETURNING

Verify:

```php
$db->insert()
   ->into('employees')
   ->set('name','Alice')
   ->returning('id')
   ->row();
```

returns generated id.

---

### Transactions

Test:

```php
$db->transaction(function($tx) {
   $tx->insert()->into('employees')->set('name','A')->exec();
});
```

Verify commit.

Also test rollback.

---

### Nested Transactions

Verify savepoint behavior.

---

### CTE execution

Ensure recursive and non-recursive CTEs execute correctly.

---

# 3. Operational Feature Tests

Verify adjacent features operate correctly.

Location:

```
tests/Integration/SQLiteOperationalTest.php
```

---

### Guardrails (WO029)

Verify:

```php
$db->delete()->table('employees')->exec();
```

throws exception.

---

### Replay (WO030)

Verify:

* snapshot created
* SQL stored
* replay executes successfully.

---

### Metrics (WO031)

Verify:

* metrics aggregator records counts
* latency values captured
* slow query threshold works.

---

### Retry (WO032)

Simulate transient failure by injecting driver stub.

Verify:

* retry attempts executed
* trace metadata correct.

---

# 4. Cache Integration Tests

SQLite certification must verify cache layer.

Backends:

* Redis
* Memcached

Location:

```
tests/Integration/SQLiteCacheTest.php
```

Verify:

### Result caching

Query:

```php
$db->select()->from('employees')->rows();
```

Second call must hit cache.

---

### Cache invalidation

After:

```php
$db->insert()->into('employees')->set('name','A')->exec();
```

Verify cache invalidated.

---

### Metadata-first payload

Ensure cached response includes:

```
tables
query fingerprint
execution metadata
```

---

# 5. Performance Baseline

Measure baseline throughput.

Location:

```
tests/Performance/SQLiteBenchmark.php
```

Tests:

### Builder overhead

Loop:

```
100k select builders
```

Measure compile speed.

---

### Execution speed

Loop:

```
50k selects
```

Measure:

* latency
* qps

---

### Cache hit performance

Repeat cached query 100k times.

Measure improvement.

---

# SQLite Feature Support Matrix

Create:

```
docs/support/sqlite.md
```

Matrix example:

| Feature          | Supported   |
| ---------------- | ----------- |
| SELECT           | Yes         |
| INSERT           | Yes         |
| UPDATE           | Yes         |
| DELETE           | Yes         |
| UPSERT           | Yes         |
| RETURNING        | Yes (3.35+) |
| CTE              | Yes         |
| Recursive CTE    | Yes         |
| UNION            | Yes         |
| INTERSECT        | Yes         |
| EXCEPT           | Yes         |
| Window Functions | Yes         |
| Retry            | Yes         |
| Guardrails       | Yes         |
| Metrics          | Yes         |
| Replay           | Yes         |

---

# Edge Case Tests

Add tests for:

### NULL comparisons

SQLite treats NULL comparisons differently.

---

### Boolean handling

SQLite lacks true boolean type.

Verify builder emits compatible SQL.

---

### Date handling

Ensure parameters bind correctly.

---

### Parameter reuse

Verify repeated parameter usage works.

---

# Documentation Updates

Update:

```
docs/query-cookbook.md
```

Add:

```
SQLite examples verified by certification suite
```

Add SQLite DSN usage examples.

---

# CI Integration

Add GitHub workflow job:

```
SQLite Certification
```

Steps:

1. install PHP
2. install dependencies
3. run tests

```
vendor/bin/phpunit
```

SQLite should run automatically.

---

# Evidence Required

Certification must produce:

* full PHPUnit output
* performance results
* SQL samples executed
* cache behavior verification
* feature support matrix

These results must be committed to:

```
docs/certification/sqlite.md
```

---

# Expected Outcome

Once R01 is complete:

* SQLite becomes the **first officially supported backend**
* UDA has a verified integration baseline
* builder correctness is validated
* operational features are confirmed functional
* performance baselines are known

This becomes the **foundation for public release credibility**.
