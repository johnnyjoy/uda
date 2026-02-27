# UDA Architecture

## Mission

Uniform database access.
Maximum performance.
Minimum abstraction.

---

## Domains

- Database
- Driver
- Query
- Dialect
- Config
- Cache

---

## Boundaries

- Only Driver touches PDO.
- Only Driver touches Cache.
- Query never imports Cache.
- Config does not execute logic.
- Cache does not execute SQL.

---

## Execution Path

Select/Insert/Update/Delete → Driver → Executor → PDO

If cache enabled:

Driver → Cache decision → Executor if needed

There is exactly one execution path.

---

## State Locations

State exists only in:

- Driver (connection + executor)
- Cache backend (persistent storage)
- Configuration snapshot

Everything else is stateless or immutable.

---

## File System Discipline

- No duplicate logic.
- No parallel abstractions.
- No Scope-like alternate execution trees.

---

## Deletion Rule

If a layer does not improve:

- Uniformity
- Performance

It must be removed.
