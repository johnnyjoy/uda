# UDA Documentation Index (Normative Order)

**UDA — Universal Data Abstractor**

Deterministic SQL abstractor: one pipeline, dialect-aware builders, transparent
read cache. Integrators build domain data layers on `Database` or `Link`.

A small, high-performance SQL execution and query composition system.

Goals:

* **Uniformity** — one clear way to perform common DB operations
* **Performance** — minimal abstraction overhead
* **Leverage** — powerful SQL composition without framework weight

The system intentionally favors:

```
fewer layers
fewer abstractions
fewer execution paths
```

---

# Directive Precedence (Hard Rule)

Documentation is hierarchical.
If two documents disagree, the higher document wins.

Order of authority:

1. **Project constitution / prompts / style guide** — non-negotiable design philosophy
2. **contract.md** — compact architectural invariants
3. **spec.md** — full normative specification
4. **design.md** — implementation structure
5. **All other documents** — explanatory

If **code conflicts with documentation**, the code is incorrect.

---

# Required Reading (Before Changing Code)

Anyone modifying UDA must read these documents first.

| Document            | Purpose                                        |
| ------------------- | ---------------------------------------------- |
| **constitution.md** | design philosophy and project goals            |
| **product-contract-v1.md** | finished v1 product promise and boundaries |
| **style-guide.md**  | formatting, naming, documentation requirements |
| **contract.md**     | hard architectural rules                       |
| **spec.md**         | detailed system contract and invariants        |
| **design.md**       | how the specification is implemented           |

These define the **rules of the system**.

---

# Operational References

Read these when modifying specific subsystems.

| Document                   | Scope                                               |
| -------------------------- | --------------------------------------------------- |
| **architecture.md**        | domain boundaries and component relationships       |
| **cache-doctrine.md**      | transparent caching philosophy                      |
| **caching.md**             | cache key design, TTL policy, invalidation rules    |
| **security.md**            | parameter binding, fragments, identifier validation |
| **configuration.md**       | config structure and validation                     |
| **drivers.md / driver.md** | engine-specific behavior                            |

These documents explain **how subsystems operate**.

---

# Usage Documentation

These documents describe **how application developers should use UDA**.

| Document              | Purpose                         |
| --------------------- | ------------------------------- |
| **public-api.md**     | official API surface            |
| **getting-started.md** | install, config, connect, and common usage examples |
| **repositories.md**   | recommended repository pattern  |
| **query-cookbook.md** | common SQL composition patterns |

These documents are **developer-facing guidance**.

---

# Policy: Documentation First

Behavioral changes must update documentation.

Required updates:

| Change Type                | Required Docs       |
| -------------------------- | ------------------- |
| architecture or invariants | `spec.md`           |
| implementation structure   | `design.md`         |
| public API                 | `public-api.md`     |
| caching behavior           | `caching.md`        |
| developer patterns         | `query-cookbook.md` |

A code change without corresponding documentation updates is incomplete.

---

# Project Philosophy

UDA is intentionally **not a framework**.

It aims to be:

* small
* predictable
* deterministic
* fast

The design avoids:

* abstraction layers that do not add value
* multiple execution paths
* hidden behavior
* unnecessary objects

If a feature increases complexity without improving **uniformity or performance**, it should not exist.

---

## Small optional improvement (recommended)

You could add one short section at the bottom that helps new contributors immediately understand the **core architecture**:

```
# Core Architecture

Application
    ↓
Repository
    ↓
Database
    ↓
Driver
    ↓
PDO
```

And one sentence:

> UDA guarantees a **single execution pipeline** for all SQL operations.

That tiny addition helps readers orient themselves in about **3 seconds**, which maintainers appreciate.
