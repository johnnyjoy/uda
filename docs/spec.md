# UDA — Universal Data Abstractor

# Specification 2.0

**Status:** Authoritative
**Build Mode:** Clean-room
**Language:** PHP ≥ 8.2 (strict types required globally)
**Dependencies:** PDO only (+ optional redis/memcached/igbinary)

---

# 1. System Definition

UDA is a deterministic SQL execution engine with transparent read acceleration.

UDA exists to:

1. Centralize SQL.
2. Enforce execution discipline.
3. Accelerate reads safely.
4. Minimize system entropy.

UDA is not:

* An ORM
* An ActiveRecord
* A schema reflection framework
* A query guessing engine
* A SQL parser

---

# 2. Core Architectural Laws

## 2.1 One Execution Path Law

There SHALL be exactly one implementation of:

* prepare
* bind
* execute

This implementation SHALL live inside Driver.

All read and write operations MUST converge to this implementation.

If more than one prepare/execute implementation exists, the system is invalid.

---

## 2.2 One Read Surface Law

All read terminators SHALL converge to:

```php
Driver::readInternal(Sql $sql): mixed
```

These MUST NOT bypass that method:

* row()
* rows()
* value()
* values()
* list()
* each()
* select()->…->rows()

No alternate read surface SHALL exist.

---

## 2.3 Driver Doctrine

Driver is the only runtime orchestrator.

Driver SHALL:

* Own PDO
* Own execution
* Own cache orchestration
* Own transaction orchestration
* Own table write tracking
* Inject dialect into Query domain

Driver SHALL NOT:

* Build SQL strings
* Parse SQL
* Infer table names
* Perform query building

---

## 2.4 Query Domain Law

Query domain SHALL:

* Produce immutable Sql value objects
* Carry SQL string
* Carry named parameters
* Carry explicit table list
* Carry optional metadata hints

Query SHALL NOT:

* Execute
* Touch PDO
* Touch Cache
* Branch on driver name

Dialect-specific behavior SHALL be injected, not inferred.

---

## 2.5 No Scope / No Parallel Universes

The following SHALL NOT exist:

* Scope
* FetchScope
* PassThroughScope
* cache() public API
* Alternate execution engines
* Builder-exec hybrids

There is one universe.

---

# 3. State Minimalism Mandate

State SHALL exist only in:

* Driver (connection-bound runtime state)
* Cache backend (persistent store)
* Configuration snapshot

Everything else MUST be:

* Immutable
* Clone-on-write
* Stateless

Builders SHALL NOT hold runtime execution state.

---

# 4. Configuration

## 4.1 Configuration Source

Exactly one configuration file path SHALL be provided via:

```
UDA_CONFIG
```

JSON only.

No PHP config files allowed.

---

## 4.2 DSN Construction Doctrine

Applications SHALL NOT supply DSN strings.

Configuration SHALL supply driver + params.

UDA SHALL construct DSN internally.

DSN knowledge SHALL NOT leak to application code.

---

## 4.3 Connection Identity

Connections are configuration snapshots.

There is no Connection class.

Driver instances are created lazily per connection.

---

# 5. Caching Model (Transparent Acceleration)

Caching is configuration-driven.

Cache is never called explicitly.

If enabled:

* All read terminators automatically consult cache.
* No public cache API exists.

If disabled:

* Cache code MUST NOT execute.

---

# 6. Metadata-First Doctrine

Cache decision SHALL occur using metadata only.

Cache backend MUST provide:

```php
getMeta(string $key): ?Meta
getResult(string $key): mixed
set(string $key, Meta $meta, mixed $result, int $ttl): void
```

Decision flow:

1. Retrieve metadata
2. Evaluate TTL
3. Evaluate table mtime (unless interval mode)
4. Decide
5. Retrieve payload only if selected

Deserializing payload just to reject it is forbidden.

---

# 7. TTL Model

Every cache entry MUST have TTL.

TTL resolution hierarchy:

1. Per-call override
2. Per-table override
3. Per-connection default
4. Global default

TTL <= 0 disables caching.

Infinite TTL forbidden.

---

# 8. TTL-as-Interval Mode

If policy flag `ignoreTableMtimeWithinTtl` is true:

* Cached data SHALL be served if within TTL
* Even if table write timestamp is newer

If false:

* Table mtime invalidates immediately

This is a throttle mode.

---

# 9. Stale-on-Error Doctrine

If:

* DB throws transient exception
* Policy allows stale
* Cache age <= maxStaleSeconds

Then cached result SHALL be returned.

Otherwise exception SHALL propagate.

---

# 10. Table Write Tracking

Driver SHALL inform TableWriteTracker when DML succeeds.

Table write timestamps SHALL be:

* Per-connection
* Per-table

Query domain SHALL NOT parse SQL.

Builders SHALL supply table list explicitly.

Raw SQL requires explicit table hint if invalidation desired.

---

# 11. Fluent Grammar Law

Builders SHALL:

* Enforce deterministic SQL grammar
* Fail early on illegal chaining
* Support:

  * where()
  * and()
  * or()
  * not()
  * in()
  * between()
  * exists()
  * distinct()
  * groupBy()
  * having()
  * orderBy()
  * limit()
  * offset()
  * upsert()

Builders SHALL NOT:

* Execute SQL
* Produce alternate read surfaces
* Create phase-class explosions

Grammar enforcement SHALL be internal state machine, not public type hierarchy.

---

# 12. UPSERT Doctrine

Conflict key is mandatory.

Engine-specific implementation SHALL be driver-owned.

Silent replace SHALL NOT be default.

Bulk upsert SHALL NOT introduce alternate execution paths.

---

# 13. Transactions

Nested transactions required.

If backend supports savepoints → use them.

Otherwise emulate via nesting counter.

Driver owns orchestration.

Engine drivers own savepoint SQL fragments.

---

# 14. Repository Boundary Doctrine

Application code SHALL:

* Access data via repository classes
* Never touch PDO
* Never construct DSN
* Never execute raw PDO

One repository class may use multiple connections.

Connection selection is explicit.

---

# 15. Performance Mandate

If cache hit rate ≥ 80%:

UDA MUST outperform direct PDO usage.

Metadata-first evaluation is mandatory to maintain ROI.

---

# 16. Enforcement Requirements

Spec violations MUST be test-detectable.

There MUST be tests that:

* Detect duplicate prepare/execute
* Detect forbidden classes (Scope, Connection)
* Detect PDO usage outside Driver
* Detect cache alternate execution path
* Detect raw '?' parameters

---

# 17. Done Definition (Spec 2.0)

The system is compliant when:

* Exactly one execution path exists
* Exactly one read surface exists
* Cache is transparent
* Metadata-first implemented
* TTL layering implemented
* TTL-as-interval implemented
* Stale-on-error implemented
* No Scope classes exist
* No DSN leakage exists
* All SQLite integration tests pass
* Policy-guard tests pass
