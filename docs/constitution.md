# RFC-0001 — UDA Architecture and Constitutional Rules

**Status:** Authoritative
**Applies to:** Universal Data Abstractor (UDA)
**Audience:** Maintainers, contributors, and automated agents modifying the codebase

---

# 1. Purpose

Universal Data Abstractor (UDA) exists for exactly two purposes:

1. **Uniform database access**
2. **Performance improvement through transparent caching**

No other objectives exist.

If a feature does not directly serve one of these two goals, it does not belong in UDA.

---

# 2. Core Design Principles

UDA must remain:

* **Small**
* **Sharp**
* **Deterministic**
* **Predictable**
* **Fast**

The system must favor **explicit architecture over clever abstraction**.

---

# 3. Domain Architecture

UDA is composed of a small number of strictly separated domains.

| Domain       | Responsibility                            |
| ------------ | ----------------------------------------- |
| **Database** | Public API and coordination layer         |
| **Driver**   | Execution engine and database interaction |
| **Query**    | SQL construction                          |
| **Config**   | Configuration ingestion and sanitization  |
| **Cache**    | Result caching and invalidation           |
| **Dialect**  | SQL dialect differences                   |

No other architectural domains are permitted.

---

# 4. Database Domain

## 4.1 Role

`UDA\Database` is the **sole public database handle**.

Application code must treat `Database` as the database itself.

---

## 4.2 The One Handle Rule

User code must only interact with:

```php
UDA\Database
```

Application code must never interact with:

```php
UDA\Driver
UDA\Driver\*
PDO
PDOStatement
```

Driver classes are **internal implementation details**.

---

## 4.3 Responsibilities

Database is responsible for:

* Selecting connection (default or named)
* Lazily binding a Driver
* Exposing uniform execution methods
* Providing query builders

Execution methods include:

```php
row()
rows()
value()
values()
list()
each()
exec()
transaction()
```

Query builders include:

```php
select()
insert()
update()
delete()
upsert()
```

Query builders must terminate execution through the Database → Driver path.

---

## 4.4 Forbidden Responsibilities

Database must never:

* construct SQL
* compute DSNs
* interact with PDO
* perform caching
* manage serialization
* inspect configuration structure
* implement write tracking

Database is a **thin coordinator** only.

---

# 5. Driver Domain

## 5.1 Role

Drivers are the **execution engines**.

Each driver implements the runtime behavior for a specific database system.

---

## 5.2 Responsibilities

Drivers must:

* build DSNs from configuration
* create PDO connections
* execute SQL statements
* check cache before executing reads
* populate cache after successful reads
* notify cache of writes

Driver orchestrates runtime execution.

---

## 5.3 Driver Visibility

Drivers are **internal-only** components.

Application code must never instantiate or interact with drivers directly.

---

# 6. Query Domain

Query objects construct SQL.

Query responsibilities:

* build SQL strings
* enforce named parameter usage
* remain database-neutral

Query objects must not:

* execute SQL
* interact with PDO
* access configuration

Execution always flows through Database → Driver.

---

# 7. Configuration Domain

## 7.1 Purpose

Configuration provides sanitized runtime configuration for UDA.

Configuration must be:

* loaded once
* validated once
* immutable after loading

---

## 7.2 Source of Configuration

Configuration is loaded from a **single JSON file**.

Two loading routes exist:

1. Environment variable:

```
UDA_CONFIG=/path/to/config.json
```

2. Explicit override:

```php
Database::connect('name', '/path/to/config.json')
```

---

## 7.3 Configuration Format Rules

Configuration must:

* be JSON
* have `.json` extension
* contain a JSON object root

PHP configuration files are not supported.

---

## 7.4 Configuration Sanitization

All configuration validation and normalization must occur **during ingestion**.

Runtime code must never:

* trim strings
* lowercase identifiers
* infer defaults
* normalize values

If runtime code performs validation, configuration ingestion has failed.

---

## 7.5 DSN Ownership Rule

Configuration must never contain DSN strings.

Configuration provides parameters only.

Drivers build DSNs.

Example flow:

```
config → params → driver → DSN → PDO
```

This prevents driver-specific behavior from leaking into configuration.

---

# 8. Cache Domain

## 8.1 Purpose

Cache improves performance and resilience.

Cache goals:

* reduce database load
* reduce query latency
* serve stale data during outages

---

## 8.2 Transparency

Cache is completely transparent.

Application code must never explicitly request cache behavior.

Cache usage is controlled entirely through configuration.

---

## 8.3 Cache Execution Path

All read queries follow a single path:

```
Query → Database → Driver → Cache → Database
```

There must never be a bypass path.

---

# 9. Stale Data Rules

Cache may serve stale results only when policy allows.

Permitted stale scenarios:

1. TTL-as-interval mode
2. Database failure with stale-on-error enabled

Staleness must always be explicitly policy-driven.

---

# 10. Cache Metadata Rules

Cache must evaluate **metadata first**.

Cache must never:

* deserialize payloads to determine validity
* copy payloads unnecessarily

Payload retrieval must occur only after metadata validation.

---

# 11. State Minimalism

UDA must minimize mutable state.

Permitted stateful components:

| Component              | Reason                  |
| ---------------------- | ----------------------- |
| Driver                 | connection state        |
| Cache backend          | cache store state       |
| Configuration snapshot | immutable configuration |

All other components must remain immutable.

---

# 12. Execution Path

All database operations follow the same path.

```
Application
    ↓
Database
    ↓
Driver
    ↓
Cache (optional)
    ↓
PDO
```

Write operations additionally trigger cache invalidation.

---

# 13. No Parallel Universes Rule

UDA must never contain:

* multiple execution paths
* duplicate abstractions
* multiple caching systems
* alternative query grammars
* competing database handles

There is exactly:

```
one path
one grammar
one runtime
```

---

# 14. Explicitness

UDA does not guess.

UDA must never:

* parse SQL to infer tables
* infer connection names
* auto-discover schema
* infer database structure

All configuration must be explicit.

---

# 15. Feature Deletion Rule

If a feature introduces:

* abstraction without uniformity gain
* overhead without performance gain
* complexity without capability gain

The feature must be removed.

---

# 16. Performance Mandate

UDA must never degrade performance without benefit.

If cache hit rates approach **90%**, UDA must outperform direct database access.

If UDA becomes slower than direct database usage without measurable benefit, the architecture must be reconsidered.

---

# 17. Configuration Snapshot

Configuration ingestion produces a **Snapshot**.

Snapshot properties:

* immutable
* sanitized
* safe for runtime usage

Only Snapshot values may be consumed by runtime domains.

Raw configuration must never escape the Config domain.

---

# 18. Error Handling

Configuration errors must be detected during ingestion.

Database and Driver errors occur during runtime execution.

Each domain must throw domain-specific exceptions.

---

# 19. Prohibited Patterns

The following patterns are unconstitutional:

* configuration mutation after ingestion
* direct PDO access from application code
* DSN storage in configuration
* runtime configuration sanitization
* multiple database execution layers

---

# 20. Final Clause

UDA must remain:

* deterministic
* minimal
* performant

Architectural simplicity is a requirement, not a preference.

If a change contradicts this document, the change is unconstitutional and must be rejected.

