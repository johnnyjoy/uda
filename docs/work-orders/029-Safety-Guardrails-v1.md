# Work Order 029 — Safety Guardrails

**Status:** Planned
**Priority:** Medium
**Category:** Operational Safety (Non-Core)
**Applies to:** Database, Driver, Query Builders
**Introduced After:** WO028 (Explain Support)

---

# Objective

Introduce **optional safety guardrails** that prevent common catastrophic database mistakes without altering the normal execution model or adding overhead to high-throughput workloads.

These guardrails protect against developer mistakes such as:

* `UPDATE` without `WHERE`
* `DELETE` without `WHERE`
* unintended full-table modifications
* dangerous bulk operations in production environments

The goal is **preventing foot-guns**, not enforcing ORM-style restrictions.

All guardrails must be:

* **opt-in**
* **configurable**
* **zero-cost when disabled**
* **deterministic**

---

# Design Principles

1. **No Hot Path Impact**

When guardrails are disabled (default), **no extra checks run during query execution**.

Guardrails activate only when explicitly enabled via configuration.

---

2. **Builder Awareness Only**

Safety checks operate on the **compiled SqlMessage metadata**, not the raw SQL string.

This avoids SQL parsing.

Example metadata available:

```
SqlMessage {
    type: select|insert|update|delete|merge
    tables: [...]
    wherePresent: bool
    limitPresent: bool
}
```

---

3. **Fail Fast**

Guardrails must throw:

```
UDA\Exception\QuerySafetyException
```

before the query is executed.

---

4. **Developer Override Available**

Developers must be able to explicitly bypass guardrails when required.

Example:

```
->unsafe()
```

---

# Guardrails Implemented

## 1. UPDATE Without WHERE Protection

Prevents accidental full-table updates.

Example prevented:

```
$db->update()
   ->table('employees')
   ->set('salary',0)
   ->exec();
```

Exception:

```
QuerySafetyException:
UPDATE without WHERE blocked by safety guardrails
```

Allowed if explicitly bypassed:

```
$db->update()
   ->table('employees')
   ->set('salary',0)
   ->unsafe()
   ->exec();
```

---

## 2. DELETE Without WHERE Protection

Prevents accidental full-table deletion.

Example prevented:

```
$db->delete()
   ->table('employees')
   ->exec();
```

Exception:

```
QuerySafetyException:
DELETE without WHERE blocked by safety guardrails
```

Override example:

```
$db->delete()
   ->table('employees')
   ->unsafe()
   ->exec();
```

---

## 3. Optional LIMIT Requirement

Optional guardrail requiring a limit on destructive operations.

Config example:

```
guardrails.requireLimitOnDelete = true
```

Example prevented:

```
$db->delete()
   ->table('audit_log')
   ->where('created_at')->lt($cutoff)
   ->exec();
```

Allowed:

```
->limit(1000)
```

---

## 4. Production Mode Hardening

Optional stricter safety rules when running in production.

Example config:

```
guardrails.productionMode = true
```

Additional restrictions:

* DELETE without WHERE always blocked
* UPDATE without WHERE always blocked
* TRUNCATE blocked unless explicitly unsafe

---

# Configuration

Guardrails are configured in `Config`.

Example:

```
guardrails.enabled = true

guardrails.updateRequiresWhere = true
guardrails.deleteRequiresWhere = true
guardrails.requireLimitOnDelete = false

guardrails.productionMode = false
```

Default configuration:

```
guardrails.enabled = false
```

Meaning **no runtime impact** unless explicitly enabled.

---

# API Surface

## Builder Override

Builders gain:

```
->unsafe()
```

Example:

```
$db->delete()
   ->table('logs')
   ->unsafe()
   ->exec();
```

Internally this sets:

```
SqlMessage->unsafe = true
```

---

## Database Enforcement

Safety validation occurs in the execution surface:

```
Database::exec()
Database::row()
Database::rows()
Database::value()
Database::values()
Database::list()
```

Process:

```
SqlMessage compiled
↓
Guardrails validate(SqlMessage)
↓
Driver executes
```

---

# Guardrail Validator

Introduce new internal class:

```
UDA\Safety\QueryGuardrails
```

Responsibilities:

* inspect SqlMessage metadata
* enforce configuration rules
* throw QuerySafetyException when violated

Example:

```
QueryGuardrails::validate(SqlMessage $sql, Config $config)
```

---

# SqlMessage Metadata Extension

Add fields:

```
SqlMessage {
    type
    tables
    hasWhere
    hasLimit
    unsafe
}
```

These values are already known by builders during compilation.

No SQL parsing required.

---

# Performance Requirements

Guardrails must:

* execute **O(1)** checks
* avoid regex
* avoid SQL string scanning
* inspect only metadata flags

Typical cost:

```
~3–6 branch checks
```

Negligible compared to network latency.

---

# Trace Integration

Guardrail violations emit trace events.

Example:

```
traceType: "guardrail_violation"
operation: "update"
table: "employees"
reason: "missing_where"
```

This allows observability systems to detect repeated safety failures.

---

# Tests

Add new suite:

```
tests/Safety/GuardrailTest.php
```

Test cases:

### Update without WHERE blocked

```
expectException(QuerySafetyException)
```

---

### Delete without WHERE blocked

```
expectException(QuerySafetyException)
```

---

### Unsafe override works

```
->unsafe()->exec()
```

should execute successfully.

---

### Disabled guardrails do nothing

```
guardrails.enabled = false
```

queries must execute normally.

---

# Documentation

Add new documentation:

```
docs/safety.md
```

Sections:

* Safety guardrails overview
* Configuring protection
* Using unsafe()
* Production hardening

Cookbook update:

```
# Safety Guardrails
```

---

# Non-Goals

This work order intentionally **does NOT introduce**:

* SQL linting
* schema validation
* ORM-style restrictions
* automatic limit injection
* query rewriting

UDA remains a **SQL-first abstraction layer**.

Guardrails only prevent **dangerous mistakes**.

---

# Success Criteria

WO029 is complete when:

* guardrail validator implemented
* configuration flags respected
* unsafe() override works
* zero overhead when disabled
* tests pass across dialects
* documentation updated

---

# Expected Developer Experience

Safe by default (when enabled):

```
$db->delete()
   ->table('users')
   ->exec();

QuerySafetyException
```

Intentional operations remain explicit:

```
$db->delete()
   ->table('users')
   ->unsafe()
   ->exec();
```

Clear. Predictable. Minimal.

