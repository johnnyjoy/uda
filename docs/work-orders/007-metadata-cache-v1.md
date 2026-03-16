# Work Order 007 — Metadata-First Cache Engine

## Authority

Documentation precedence:

1. constitution.md + style-guide.md
2. contract.md
3. spec.md
4. design.md

---

## Goal

Implement the transparent, metadata-first cache system used by Driver for read operations.

Cache must remain completely internal and configuration-driven.

There must be no public cache API.

---

## Scope (Allowed Changes)

Only modify:

- `src/UDA/Cache/*`
- `src/UDA/Driver.php`
- `src/UDA/Driver/*` (only where needed for cache integration)
- `tests/Cache/*`
- `caching.md`
- `cache-doctrine.md`
- `architecture.md`
- `spec.md` (if wording alignment is required)

No public API docs may introduce a cache() method.

---

## Requirements

### 1. Transparent Cache

If caching is enabled for a connection:

- reads automatically consult cache
- writes automatically trigger invalidation/touch logic

Application code must never explicitly invoke cache.

No `cache()` public method may be added.

---

### 2. Metadata-First Rule

Cache decisions must use metadata before payload retrieval.

Payload must not be deserialized until metadata proves it usable.

Cache entries must be split logically into:

- metadata
- payload

Example conceptual keys:

- `m:{root}`
- `r:{root}`

---

### 3. Cache Result States

A cached result may be used in exactly three cases:

#### Fresh
- metadata exists
- TTL valid
- table write timestamps do not invalidate it

#### Interval-Protected
- policy says do not recheck DB until TTL expires
- cached result may be served even if table mtime changed

#### Stale-on-Error
- DB query attempted
- DB failed with transient error
- policy allows stale
- cached age within `maxStaleSeconds`

No other stale cases are allowed.

---

### 4. Table Write Tracking

Driver must inform the tracker when successful DML occurs and `affectedRows > 0`.

Tracked operations:

- INSERT
- UPDATE
- DELETE
- UPSERT

Tracking is per connection and per table.

---

### 5. Policy Hierarchy

Cache policy resolution order:

1. per-request override (internal only if supported by architecture)
2. per-table rule
3. per-connection default
4. global default

If your current docs ban per-request public overrides, preserve that ban in public API while still supporting internal policy resolution architecture as needed.

---

### 6. Store Backends

Support:

- Redis
- Memcached
- ArrayStore

All must follow metadata-first behavior.

---

### 7. Serializer

Serializer id must be part of key format to avoid collisions.

Support igbinary if available, otherwise PHP serialize.

---

## Tests Required

Create or update tests covering:

### Metadata-first
- metadata read occurs before payload read
- unusable entries do not deserialize payload

### Fresh hits
- valid metadata + valid payload returns cached result

### Interval-protected hits
- cached result returned without DB recheck until TTL expiry

### Stale-on-error
- DB failure returns stale result only when policy allows

### Invalidation
- touched tables make old cache entries stale

---

## Acceptance Criteria

All of the following must pass:

- cache tests
- no public cache API added
- cache reads are metadata-first
- stale behavior matches doctrine

---

## Evidence Required

Provide:

- PHPUnit output for `tests/Cache/*`
- key format example
- example metadata structure
- proof that payload is not deserialized before metadata decision
