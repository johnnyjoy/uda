# UDA Contract (Hard Rules)

**UDA exists to reduce SQL chaos and increase speed.**
We optimize for:
1) **Uniformity** (one way to do common DB ops)
2) **Performance** (no avoidable overhead)

If it doesn’t improve uniformity or speed, it doesn’t belong.

---

## 1) Domain Master Pattern (no facade soup)

**Each domain has exactly one Master class** (the entrypoint and policy owner).
Subclasses/files in the domain are *tools* used by the Master.

- No “Facade” abstractions whose only job is forwarding.
- No extra hops. If a method can call the real thing, it does.

**Master owns state** when state is required.
Stateless helpers are functions/classes with no saved state.

---

## 2) Execution: one hot path

- **Only Driver owns PDO/PDOStatement.**
- There is **exactly one** prepare/bind/execute implementation in the entire codebase.
- No second execution path (not in Cache, not in helpers, not in “executors”).

---

## 3) Public entrypoint(s)

- **Database** is the external entrypoint for getting a Driver.
- App code should not touch Config directly in normal usage.
- Driver is the execution domain for all reads/writes.

---

## 4) Named parameters only

- Public raw SQL APIs accept **named parameters only** (`:id`).
- Any SQL containing `?` is rejected **before touching PDO**.

---

## 5) Cache doctrine (transparent + metadata-first)

- Cache is **not something the caller asks for**. Cache **happens** when configured.
- Driver is the only runtime owner of cache behavior.
- Cache read is **metadata-first**:
  - read metadata (ctime, ttl, tables, version, serializer id) **without deserializing payload**
  - decide if payload is used
  - only then fetch/deserialize payload

**Stale usage rules (only two):**
1) Request/config says “serve cached result for TTL interval even if table mtime changed” (TTL-as-interval mode)
2) DB error and policy allows **stale-on-error** (and not forbidden)

---

## 6) Table write timestamps (mtime) are real

- On successful DML with **affectedRows > 0**, Driver informs Cache:
  - `(connection, table) -> touch(mtime=now)`
- Invalidations are based on comparing cached ctime with table mtime.

---

## 7) Purpose docblocks are mandatory (all files)

Every PHP file has a file-level docblock with:
- `Purpose:` one sentence

Policy guard tests must fail if missing.

---

## 8) Naming + structure

- No namespace token repetition (`Driver\SqlServerDriver` is allowed only if “Driver” is the domain root and not repeated as a directory token again).
- Avoid “XManager”, “XService”, “XEngine”, “XFacade” garbage.
- Prefer simple nouns: `Cache`, `Key`, `Policy`, `StoreRedis`, etc.

---

## 9) Fewer objects, fewer copies

- Objects exist only when they carry **state** or provide tight fluent scoping.
- Prefer arrays + functions where state isn’t needed.
- Avoid needless copying; use references only when proven necessary.

---

## 10) Tests enforce the contract

At minimum:
- PDO usage outside Driver forbidden
- duplicate execute path forbidden
- Scope/cache() API forbidden
- Purpose docblock required in all src files
- cache metadata-first behavior proven (meta read without payload read)
