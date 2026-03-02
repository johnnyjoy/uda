# UDA Constitution

Universal Data Abstractor exists for two reasons:

1. Uniform database access.
2. Performance improvement through transparent caching.

Nothing else.

---

# Article I — Uniformity

Database access must:

- Look the same everywhere.
- Be centralized in repositories.
- Prevent SQL from being scattered across a project.
- Use named parameters only.

UDA is not an ORM.
UDA does not map entities.
UDA does not introspect schema.
UDA does not generate magic.

It provides a uniform execution surface.

## Database Doctrine (Non-Negotiable)

**UDA\Database is the database** from the perspective of application code.

- Application code MUST treat `UDA\Database` as the sole ingress and primary handle.
- Application code MUST NOT use `UDA\Driver` directly.
- `UDA\Driver` and all `UDA\Driver\*` classes are **internal execution engines** and are not part of the public mental model.

### The One Handle Rule
- The only handle that user code should hold for data access is a `Database` instance (or query objects created by it).
- There SHALL NOT be a “two ways to do it” split such as “direct Driver usage” vs “Database usage.”

### What Database Is Allowed To Do
`Database` is a **Domain Master**: short pathways, minimal hops.

- Select the connection (default or named)
- Lazily bind the internal execution engine (Driver)
- Expose uniform execution methods (`row/rows/value/values/list/each/exec/transaction`)
- Create fluent query objects (`select/insert/update/delete/upsert`) that terminate back into the Database/Driver execution path

### What Database Must Never Do
`Database` MUST remain thin: **no business logic**, only coordination.

`Database` MUST NOT:
- build SQL strings (Query does that)
- touch PDO / PDOStatement (Driver does that)
- implement caching logic (Driver does that)
- implement serialization policy (cache backend handles do that)
- compute DSNs (Driver/Config does that)
- perform table write tracking logic (Driver informs Cache/Tracker)

---

# Article II — Driver Doctrine

Driver is the delivery man.

- Driver retrieves data.
- Driver checks cache first.
- Driver queries database if necessary.
- Driver informs Cache when writes occur.

Only Driver orchestrates runtime behavior.

---

# Article III — Cache Doctrine

Cache exists to:

1. Reduce database load.
2. Increase read performance.
3. Provide resilience when DB is unavailable.

Cache is never explicitly requested.
Cache is enabled by configuration.

There is exactly one read path.

---

# Article IV — Stale Rules

Stale cache may be served only when:

1. TTL-as-interval mode is active (ignore table mtime within TTL).
2. Database failure occurs and policy allows stale-on-error.

Staleness must be policy-driven.

---

# Article V — Metadata First

Cache must never:

- Deserialize results to determine usability.
- Perform unnecessary memory copies.

Metadata is checked first.
Payload is retrieved only if selected.

---

# Article VI — State Minimalism

The number of stateful locations must be minimized.

State exists only where required:

- Driver
- Cache backend
- Configuration snapshot

Everything else must be immutable.

---

# Article VII — No Parallel Universes

There shall not be:

- Multiple execution paths.
- Duplicate abstractions.
- Parallel caching systems.
- Competing builder models.

One path.
One grammar.
One runtime.

---

# Article VIII — Explicit Over Implicit

UDA does not guess.

It does not:

- Parse SQL to infer tables.
- Infer connection names.
- Auto-discover schema.

It requires explicit configuration.

---

# Article IX — Deletion Rule

If a feature adds:

- Abstraction without uniformity gain
- Overhead without performance gain

It must be removed.

---

# Article X — Performance Mandate

If cache hit rate approaches 90%, UDA must outperform direct database access.

If UDA makes things slower without benefit, it has failed its mission.

---

# Final Clause

UDA must remain:

- Small
- Sharp
- Deterministic
- Predictable
- Fast

Anything that contradicts this constitution is unconstitutional.
