# Cache Doctrine

## Purpose

Caching in UDA exists for exactly two reasons:

1. Speed.
2. Resilience.

Nothing else.

---

## Core Principle

> Cache is not called. Cache happens.

If caching is enabled in configuration, reads automatically consult cache.
No user code should ever request cache explicitly.

---

## Driver Is The Delivery Man

- Driver gets data.
- Driver checks cache first.
- If cache usable → return.
- Else → query database.
- On write with affectedRows > 0 → Driver informs Cache.

Only Driver touches Cache at runtime.

---

## Staleness Model

There are exactly two allowed stale cases.

### 1. TTL-as-Interval

Serve cached data within TTL **even if table mtime is newer**.

This throttles database load.

TTL is a policy knob, not a law of physics.

---

### 2. Stale-on-Error

If:
- Database fails
- Policy allows stale
- Cached data is within maxStaleSeconds

Then serve stale result.

---

## Metadata First

Cache lookups must:

1. Retrieve metadata only.
2. Decide usability.
3. Retrieve payload only if needed.

Never deserialize unused payload.

Key model example:

- `m:{root}` → metadata
- `r:{root}` → result set

---

## Table Write Timestamps

Driver updates table mtime when:

- INSERT
- UPDATE
- DELETE
- UPSERT

And affectedRows > 0.

Cache uses table mtime for invalidation decisions.

---

## Cache Hierarchy

Policy may be set:

- Global default
- Per connection
- Per table
- Per request override

Resolution order:

request > table > connection > global

---

## Anti-Goals

- No Scope classes.
- No alternate read path.
- No explicit cache invocation.
- No SQL parsing for table detection.
