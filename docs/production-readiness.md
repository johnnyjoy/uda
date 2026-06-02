# Production Readiness

**Audience:** Teams evaluating UDA for HTTP APIs, workers, and multi-connection
services. This document is the honest posture after a hostile practitioner review
— not marketing copy.

**Status:** Pre–v1 tag. Use the matrices below before standardizing an org-wide DAL.

**This doc is the execution plan** — implement P0 → P1 → P2 in code and tests.
Repo housekeeping (untracking `docs/plans/`, history rewrite) is **not** part of
this plan; handle that separately if you care about remote hygiene.

**Related:** `docs/getting-started.md` (sharp edges), `docs/certification/`,
`docs/caching.md`, `CHANGELOG.md` `[Unreleased]`.

---

## Execution order (do this, not git ceremony)

| Order | ID | Work | Status |
| ----- | -- | ---- | ------ |
| 1 | P0-1 | Align license headers with `composer.json` (MIT) + CI guardrail | **Done** |
| 2 | P0-2 | Structured `QueryException` | **Done** |
| 3 | P0-3 | `cache.require_table_hints` config flag | **Done** |
| 4 | P0-4 | Cert honesty in docs — **done** (this doc + README) | **Done** |
| 5+ | P1-* | Observer hook, connect overloads, dev warnings, worker docs, SQL Server cert, Driver split | Pending |

---

## Executive summary

UDA's **design contract is coherent**: one handle (`Database`), one execution path,
named parameters only, transparent cache when configured. That fits disciplined
repository-style PHP services.

**Gaps before “default DAL” recommendation:**

| Area | Posture |
| ---- | ------- |
| CI-certified engines | SQLite, PostgreSQL only |
| License in source vs package | MIT in `composer.json` / `LICENSE.md`; GPL-2.0-only in file headers |
| API-layer errors | `QueryException` is unstructured |
| Observability | `lastSql()` / `lastParams()` only; no hooks |
| Read cache + raw SQL | Table hints required; easy to misconfigure |
| Long-running workers | Reconnect documented; concurrency on pooled handles not |

---

## Engine certification matrix

Legend: **Cert** = enforced in GitHub Actions · **Unit** = PHPUnit without live DB ·
**Code** = implemented, not CI-certified · **N/A** = not connectable or disabled

| Engine | Connect | Builders | Upsert | Read cache | CI cert | Notes |
| ------ | ------- | -------- | ------ | ---------- | ------- | ----- |
| **SQLite** | Cert | Cert | Cert | Cert | `sqlite-cert.yml` | Primary dev/cert path |
| **PostgreSQL** | Cert | Cert | Cert | Cert | `postgres-cert.yml` | PG 16 + Redis/Memcached in CI |
| **MariaDB / MySQL** | Code | Unit | Code | Unit | — | DSN + dialect present; no live cert job |
| **SQL Server** | Code | Unit | Code | Unit | — | `sqlsrv` and `dblib` transports |
| **Sybase (ASE)** | Code | Unit | **Off** | Unit | — | MERGE/UPSERT disabled in dialect |
| **Oracle** | Code | Unit | Code | Unit | — | Special `returning()` path |
| **DB2** | **N/A** | Code | Code | — | — | Dialect file only; not in connect factory |

Do not treat “Code” as production-certified. Run your own cert suite before
Friday deploys on uncertified engines.

---

## Deployment scenario matrix

| Scenario | Recommendation |
| -------- | -------------- |
| New API, PostgreSQL, PHP-FPM, cache off or hints disciplined | **Ready** |
| New API, SQLite for edge/embedded | **Ready** |
| Redis/Memcached read cache on raw SQL | **Ready only with table hints on every cached read** |
| Laravel Octane / RoadRunner / Swoole | **Caution** — see [Long-running workers](#long-running-workers) |
| Multi-engine product (PG + SQL Server + Oracle) | **Not yet** — cert matrix incomplete |
| Enterprise compliance review | **Blocked** until license headers match package license |
| Platform “default DAL” for all teams | **Wait** — P0 items in [Backlog](#backlog-p0--p1--p2) |

---

## Long-running workers

`Database::connect()` pools one `Database` (and one PDO) per connection name per
process. Transparent reconnect covers dropped TCP connections on the next
statement — documented in `docs/architecture.md`.

**Not documented enough today:**

- Do not share one connection name across **concurrent coroutines** without
  external serialization; transaction state and `lastSql()` are per-handle.
- Mid-transaction connection loss still fails the transaction (expected).
- Debug accessors (`lastSql()`, `lastParams()`) reflect the **last operation on
  that pooled handle**, not “the current request” under concurrency.

**Operational rule:** One in-flight transaction per pooled handle; isolate
connection names per worker context when using Octane/RoadRunner.

---

## v1 migration checklist (upgrading)

If you used pre-consolidation UDA, verify before tagging/deploying:

- [ ] Replace `UDA\Query::…` with `Database::connect()` or `Link` trait.
- [ ] Rename test helper `Cache::clearForTests()` → `Cache::clear()` (ops/test scope only).
- [ ] Config: understand **engine** (`driver` key) vs **transport** (`sqlsrv` vs `dblib`).
- [ ] Legacy `"driver": "dblib"` → engine **sybase**; MSSQL over DBLib needs
      `"driver": "sqlserver", "transport": "dblib"`.
- [ ] DB2 connections will not connect until `Driver/Db2.php` ships; remove from config or accept failure at connect.
- [ ] Sybase: do not call `$db->upsert()` until ASE is certified (`supportsUpsert()` false).

See `CHANGELOG.md` `[Unreleased]` for the full list.

---

## Backlog (P0 / P1 / P2)

Copy any section below into a GitHub issue. IDs are stable for cross-reference.

---

### P0-1 — Unify license across package and source headers

**Problem:** `composer.json` and `LICENSE.md` declare MIT; PHPDoc in `src/`
declares `GPL-2.0-only`. Compliance review cannot proceed.

**Acceptance criteria:**

- [ ] Single license declared in `composer.json`, `LICENSE.md`, and all `src/` file headers.
- [ ] `tools/check-purpose.php` or new guardrail fails CI on license mismatch.
- [ ] `README.md` and this doc reference the resolved license only.

**Notes:** Legal choice is maintainer decision; consistency is non-negotiable.

---

### P0-2 — Structured `QueryException` for API mapping

**Problem:** `QueryException` extends `RuntimeException` with no fields. API
layers cannot reliably map DB errors to HTTP status codes or structured logs.

**Acceptance criteria:**

- [ ] `QueryException` exposes at minimum: `sqlState(): ?string`, `driverCode(): ?string`, `category(): string` (e.g. `connection`, `constraint`, `syntax`, `guardrail`, `unsupported`).
- [ ] PDO failures wrap with `$previous` preserved; SQLSTATE read from `PDOException` when present.
- [ ] Guardrail failures (positional `?`, unbounded DELETE) use distinct categories.
- [ ] `docs/public-api.md` §7 updated with mapping guidance (409, 503, 400 examples).
- [ ] PHPUnit covers at least: guardrail throw, simulated PDO constraint, connection error category.

**Out of scope:** Full SQLSTATE catalogue per engine.

---

### P0-3 — Cache safety policy for hintless raw SQL

**Problem:** With cache enabled, raw SQL reads without `tableHints` may not
invalidate correctly — silent stale reads in production.

**Acceptance criteria:**

- [ ] New connection-level config flag, e.g. `cache.require_table_hints` (default `false` for BC).
- [ ] When `true`: `rows()` / `row()` / `value()` / etc. on string SQL without hints throw `ConfigException` or `QueryException` with clear message.
- [ ] When `false`: document current behaviour unchanged.
- [ ] `docs/caching.md` and `docs/getting-started.md` reference the flag.
- [ ] Test: cache on + flag true + hintless raw SQL → fails loud.

**Alternative considered:** Auto-disable cache for hintless raw reads (document behaviour if chosen instead).

---

### P0-4 — Certification honesty in README and docs index

**Problem:** Config examples list engines that CI does not certify; implies universal production readiness.

**Acceptance criteria:**

- [ ] Engine matrix (this doc) linked from `README.md`.
- [ ] `docs/configuration.md` cross-links matrix; no engine implied as certified without CI job name.
- [ ] Each uncertified engine doc page (when added) states “not CI-certified” explicitly.

**Partially done:** This document satisfies the matrix; README link is the remaining step.

---

### P1-1 — Query observer hook (observability)

**Problem:** No supported way to record duration, cache hit/miss, reconnect retry,
or correlation IDs. Operators rely on wrapping or parsing exceptions.

**Acceptance criteria:**

- [ ] Optional static or config-registered callable invoked after each execute:
      `(connectionName, sql, params, durationMs, cacheHit, retried, ?Throwable)`.
- [ ] Zero overhead when unset (no-op default).
- [ ] Documented as ops/instrumentation — not required for application code.
- [ ] Does not replace PSR-3 logging mandate; no mandatory framework coupling.

---

### P1-2 — Explicit `Database::connect` overloads (ergonomics)

**Problem:** Varargs `connect(string ...$args)` with file/connection heuristics is hard to discover and IDE-assist.

**Acceptance criteria:**

- [ ] Add named entrypoints, e.g. `connectDefault()`, `connectNamed(string $name)`, `connectWithConfig(string $file, ?string $name = null)`.
- [ ] Existing varargs signature unchanged (BC).
- [ ] `docs/public-api.md` §1 lists both styles; examples prefer named methods for new code.

---

### P1-3 — Config normalization warnings (dev mode)

**Problem:** `driver` config key means engine; legacy aliases confuse SQL Server vs Sybase setups.

**Acceptance criteria:**

- [ ] When `UDA_DEV=1` or config `dev.warnings: true`, log or trigger_user_error (E_USER_NOTICE) on legacy normalization (e.g. `driver: dblib` → sybase).
- [ ] Message includes recommended explicit `driver` + `transport` for target engine.
- [ ] Silent in production default path.

---

### P1-4 — Octane / RoadRunner concurrency documentation

**Problem:** Pooling + reconnect documented; coroutine/concurrency hazards under-documented.

**Acceptance criteria:**

- [ ] `docs/architecture.md` section: one transaction per handle, no shared handle across concurrent coroutines without locking.
- [ ] `lastSql()` / `lastParams()` called out as unsafe for concurrent use on same connection name.
- [ ] Linked from `docs/getting-started.md` sharp edges.

**Partially done:** Long-running workers section in this doc; normative architecture doc update remains.

---

### P1-5 — SQL Server certification CI job

**Problem:** Common production engine with zero CI cert.

**Acceptance criteria:**

- [ ] `.github/workflows/sqlserver-cert.yml` (or documented manual gate) running CRUD + transaction + upsert smoke against SQL Server container/service.
- [ ] `docs/certification/sqlserver.md` with status, env vars, command.
- [ ] Matrix row updated to **Cert**.

---

### P1-6 — `Driver.php` decomposition plan

**Problem:** ~1,400+ line runtime monolith; high outage-debug surface.

**Acceptance criteria:**

- [ ] ADR or `docs/architecture.md` appendix: target modules (execute, cache bridge, oracle returning, transaction, reconnect) without new public surface.
- [ ] Phased extraction with `composer check` + full test suite green after each phase.
- [ ] No behaviour change in phase 1 (move-only refactor).

---

### P2-1 — Fix placeholder PHPDoc `@link` URLs

**Problem:** `docs.uda.example.com` in source headers is not a live site.

**Acceptance criteria:**

- [ ] Replace with GitHub repo docs path or remove `@link`.
- [ ] Guardrail optional: fail on `uda.example.com` in `src/`.

---

### P2-2 — Align CONTRIBUTING with GitHub-primary workflow

**Problem:** CONTRIBUTING leads with GitLab MR flow; CI is GitHub Actions.

**Acceptance criteria:**

- [ ] GitHub PR flow first; GitLab mirror noted as secondary.
- [ ] README Contributing line matches.

---

### P2-3 — Rename `Abs` → `QueryBuilder` (semver major)

**Problem:** Cryptic internal name; docs already use `QueryBuilder` as conceptual alias.

**Acceptance criteria:**

- [ ] Semver-major release only.
- [ ] Deprecation alias `Abs extends QueryBuilder` for one major if needed.
- [ ] `docs/public-api.md` §3.2 disposition updated.

---

## What works today (use with confidence)

- **One-handle rule** — `Database::connect()` + optional `Link`; no static `Query`.
- **Named parameters enforced** before PDO — positional `?` rejected.
- **Write guardrails** — unbounded UPDATE/DELETE require `unsafe()`.
- **Architecture guardrails** — `composer check` (PDO boundary, imports, execution path).
- **SQLite + PostgreSQL CI cert** — reproducible on GitHub Actions.
- **Transparent cache** — when hints and config are correct; see `docs/caching.md`.
- **Reconnect once** on connection-lost errors — FPM and long-running workers.

---

## Pre-deploy checklist (operator)

Before production on any engine:

1. Confirm engine row in [certification matrix](#engine-certification-matrix).
2. Set `UDA_CONFIG`; validate JSON at boot (fail fast on `ConfigException`).
3. If read cache enabled: pass **table hints** on every raw SQL read, or enable P0-3 when implemented.
4. Wrap `QueryException` at API boundary until P0-2 ships; log `$e->getPrevious()` for PDO detail.
5. Run `composer check && composer test` (+ engine cert command if applicable).
6. For workers: one transaction per handle; do not rely on `lastSql()` under concurrency.
7. Review `CHANGELOG.md` `[Unreleased]` before upgrading tagged releases.
