# Work Order 024 — Query Plan Caching (Compiled SQL Cache)

## Authority

Documentation precedence:

1. `constitution.md` + `style-guide.md`
2. `contract.md`
3. `spec.md`
4. `design.md`

The **Query Cookbook** defines developer-visible grammar.  
This work order **must not change the public query grammar**.

---

# Goal

Implement **query plan caching** for UDA so that identical query builders reuse a previously compiled SQL plan instead of recompiling SQL every time.

The objective is to reduce CPU overhead in high-throughput environments where the same logical queries execute repeatedly.

This optimization must:

- cache compiled SQL safely
- remain deterministic
- preserve parameter binding semantics
- never reuse cached SQL across incompatible dialects
- integrate cleanly with the existing builder → database → driver pipeline

This is a **performance optimization**, not a functional change.

---

# Problem This Work Order Solves

Currently each builder execution performs:

```

Builder
→ SQL compilation
→ SqlMessage creation
→ Database execution
→ Driver execution

```

SQL compilation cost becomes measurable when queries execute **millions of times per minute**, particularly for:

- short read queries
- simple lookups
- cache-heavy systems
- API endpoints

Repeatedly recompiling identical builder trees wastes CPU.

Query plan caching allows UDA to reuse the compiled SQL.

---

# Scope

Allowed modifications:

```

src/UDA/Query/*
src/UDA/Database.php
src/UDA/Query/Dialect/*
src/UDA/Query/Sql.php
tests/Query/*
docs/architecture.md
docs/spec.md

```

Do not modify:

```

src/UDA/Driver/*
src/UDA/Cache/*
src/UDA/Config/*

```

The optimization must occur **above the driver layer**.

---

# Design Overview

Introduce a **Compiled Query Cache**.

Conceptually:

```

Builder
↓
Compile SQL
↓
Cache compiled plan
↓
Bind parameters
↓
Execute

```

The cache stores:

```

Compiled SQL string
Parameter layout
Referenced tables
Dialect identifier

```

---

# Cache Key

Each compiled query must have a deterministic cache key.

Example key components:

```

Dialect name
Builder type
Builder structure hash

```

Example:

```

pgsql:select:1b6a8a4d3c...

```

The hash must represent **query structure**, not runtime values.

---

# Key Principle

The cache key must **exclude parameters**.

Example:

```

SELECT * FROM users WHERE id = :p1

```

must reuse the same cached plan for:

```

id = 5
id = 42
id = 9001

````

Parameters are bound **after plan retrieval**.

---

# Builder Fingerprint

Introduce a builder fingerprint method.

Example:

```php
$builder->fingerprint()
````

This returns a deterministic structural hash.

Fingerprint inputs:

```
select columns
tables
joins
where clauses
group by
having
order by
limit
offset
cte usage
unions
window expressions
```

Fingerprint must ignore parameter values.

---

# Cache Storage

Implement a static in-memory cache:

```php
class QueryPlanCache
{
    private static array $plans = [];
}
```

Structure:

```
[
  cacheKey => SqlMessage
]
```

SqlMessage contains:

```
compiled SQL
parameter placeholders
table metadata
returning metadata
```

---

# Cache Lifecycle

### Compile path

```
Builder
   ↓
fingerprint()
   ↓
cache lookup
   ↓
miss → compile SQL
   ↓
store plan
```

### Execute path

```
Builder
   ↓
cache hit
   ↓
clone SqlMessage
   ↓
bind parameters
   ↓
execute
```

SqlMessage must be **cloned** to avoid mutation across executions.

---

# Integration Location

Caching should occur in:

```
Database::normalizeToSqlMessage()
```

or equivalent compile entry point.

Example flow:

```
Builder → Database
       → check cache
       → compile if needed
       → return SqlMessage
```

Drivers remain unchanged.

---

# Cache Limits

Implement a configurable maximum cache size.

Default:

```
1000 compiled queries
```

When limit reached:

```
evict oldest entry
```

Eviction policy:

```
simple FIFO
```

No complex LRU required initially.

---

# Cache Invalidation

Plan cache **does not depend on table data**, only query structure.

Therefore:

```
writes do NOT invalidate query plan cache
```

The plan remains valid.

Parameter binding provides runtime values.

---

# Dialect Safety

Cache key must include:

```
dialect identifier
```

Example:

```
pgsql
sqlite
oracle
sqlserver
```

This prevents cross-dialect plan reuse.

---

# Determinism Requirement

Two structurally identical queries must generate identical fingerprints.

Example:

```
where('id',5)
where('id',10)
```

must produce identical fingerprints.

Parameter values must not affect cache identity.

---

# Tests Required

Add:

```
tests/Query/QueryPlanCacheTest.php
```

---

## Test 1 — Cache Hit

Execute identical builder twice.

Verify:

```
compile occurs once
cache hit occurs second time
```

---

## Test 2 — Parameter Variance

Execute:

```
id = 1
id = 2
id = 3
```

Ensure:

```
same cached plan reused
```

---

## Test 3 — Dialect Separation

Same query executed under two dialects.

Ensure:

```
two separate cache entries
```

---

## Test 4 — Cache Eviction

Insert more than cache capacity.

Verify oldest entries evicted.

---

## Test 5 — Plan Cloning

Ensure cached SqlMessage is cloned before parameter mutation.

---

# Performance Verification

Add benchmark script:

```
tests/bench/plan_cache_benchmark.php
```

Example scenario:

```
100000 identical queries
```

Compare:

```
with cache
without cache
```

Expected improvement:

```
20–40% CPU reduction
```

(depending on query complexity)

---

# Documentation Updates

Update:

```
docs/architecture.md
docs/spec.md
```

Add section:

```
Query Plan Cache
```

Explain:

* compilation caching
* structural fingerprinting
* parameter independence
* dialect safety

---

# Acceptance Criteria

All conditions must be satisfied:

```
query plan cache implemented
fingerprint generation deterministic
parameters excluded from cache key
cache size limit enforced
dialect separation enforced
SqlMessage cloning prevents mutation bugs
tests pass
documentation updated
```

---

# Evidence Required

Provide:

```
modified files
phpunit output
benchmark results
example cache keys
```

---

# Non-Goals

This work order does NOT implement:

```
prepared statement caching
driver-level statement pooling
distributed plan caching
persistent disk cache
```

Those belong to future work orders.

---

# Philosophy

Most query builders waste CPU repeatedly compiling identical SQL.

High-throughput systems avoid this by caching compiled query plans.

By introducing deterministic plan caching, UDA achieves:

```
lower CPU usage
faster query execution paths
better scalability
```

without changing the developer-facing API.
