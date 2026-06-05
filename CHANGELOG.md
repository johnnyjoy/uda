# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Audience:** integrators adopting UDA and reviewing API changes at release time.
CI job names and maintainer workflow detail belong in `docs/integration/` or
`CONTRIBUTING.md`, not here. See `docs/releases.md`.

## [Unreleased]

### Added

- **CUBRID engine support**: `driver: cubrid` is now a first-class, CI-gated engine. `UDA\Driver\Cubrid` supplies the `cubrid:` DSN and backtick quoting; `UDA\Query\Dialect\Cubrid` extends `MariaDb` and inherits `LIMIT/OFFSET` pagination and `ON DUPLICATE KEY UPDATE` upsert. No `RETURNING` clause — the dialect rejects it before PDO. Integration suite: `tests/Cubrid/`; CI job: `cubrid-integration.yml` (`cubrid/cubrid:11.4`, `pdo_cubrid`, `--privileged`).

- **Persistent connections (always on)**: UDA now keeps the PDO handle in PHP's per-process pool and reuses it across requests, eliminating the connect/auth handshake on every request in php-fpm/mod_php/container deployments. This is intrinsic to the runtime, not a setting — `PDO::ATTR_PERSISTENT` is forced exactly like `PDO::ATTR_ERRMODE` and cannot be overridden through connection `options`. For safety, the Driver rolls back any transaction a prior request left open on a pooled handle when it is checked out, so every request starts from a clean session.

### Fixed

- `WhereBuilder::toSql()` proxies through `end()` like read terminators, so `->where(...)->toSql()` no longer requires a manual `end()` on SELECT builders.
- Added architecture guard to keep `Driver::row()` on single-row fetch path (`fetch()`), preventing regression to full-result materialization.
- Restored the architecture-boundary guard (`tools/check-imports.php`), which previously matched nothing (path-prefix bug) and passed vacuously. It now uses PHP's tokenizer to inspect real code references only (comments/strings ignored) and enforces the domain-takeover invariants: `Query` must not execute via PDO or reach into `Cache`; `Cache` must not execute via PDO or drive via `Driver`; `Driver` must not own a cache backend (`Redis`/`Memcached`/`Predis`) directly.

### Changed

- Cache freshness gate now reads all tracked table mtimes in a single batched backend round-trip (`Redis::mGet` / `Memcached::getMulti`) instead of one round-trip per table, so a cached read's staleness check costs one round-trip regardless of how many tables a query touches. The (potentially large) payload remains deferred — it is only fetched after freshness is confirmed, preserving the metadata-first guard against needlessly deserializing large datasets. Removed the now-unused single-key `tableMtime()` helper and dead `tableWriteTimes()` method.
- Closed the lone cross-domain crossing in `Query\Dialect\Firebird`: pagination SQL is now emitted inline by the dialect (matching the Db2 dialect) instead of calling `Driver\Firebird::limitOffset()`. `Driver\Firebird` is unchanged and still serves the `Driver`'s own `limitOffset()` ingress.
- Moved the dialect-compilation state DTOs from `UDA\Query\Dialect` to a new `UDA\Query\State` namespace (`src/UDA/Query/State/`). They are builder-state inputs consumed by dialects, not dialects, so the `Dialect` directory now contains only engine dialects and their base classes. The redundant `State` suffix was dropped from the class names (`UDA\Query\State\Select`/`Insert`/`Update`/`Delete`/`Upsert`) per the namespace-repetition naming rule; consumers import them aliased as `…State` to disambiguate from the same-named query builders in `UDA\Query`.
- Query-compilation layer made closure-free for shallower, more predictable build paths: dialect state objects (`SelectState`/`InsertState`/`UpdateState`/`DeleteState`/`UpsertState`) bind values via their `ParamBag` and quote identifiers through the shared `Identifier::quoteFor()` helper instead of storing `parameterize`/`quote` closures; `WhereBuilder` quotes through its injected parent builder rather than a stored quoter closure; dialect compilers reuse `quoteColumns()`/`rowPlaceholders()` helpers in place of per-call `array_map(fn …)`; and `Database`/`Link` transactions pass the subject to `Driver::transaction()` directly rather than wrapping the callback. The transaction callback contract is unchanged (`Database` callbacks receive the `Database`; `Link` callbacks need no argument). Internal visibility widened: `Query::param()` and `Query::quote()` are now public.
- `Driver` read/execute paths are now closure-free and shallow for performance: `row()`, `rows()`, `value()`, `values()`, and `list()` fetch directly (no per-call executor closure), with caching handled by flat `cacheHit()`/`cacheStore()` helpers. `list()` fetches numeric rows via `PDO::FETCH_NUM` directly instead of converting an associative row. The prepared-statement reuse cache (`Driver\Prepared`) exposes `get()`/`put()` so the hot path no longer allocates a `prepare` closure per query, and `Driver\Oracle\Returning` takes the `Driver` directly instead of two wrapper closures.
- README and integration matrix: **Informix** and **CUBRID** listed as not in UDA yet / coming soon.
- Hostile docs pass: removed stale/ambiguous wording (`DB2 (stub)`), reduced second-person phrasing in reader-facing docs, and tightened integration/getting-started language for neutral, project-focused tone.
- Clarified singular-read behavior in docs: `row()` uses single-row fetch path and does not materialize full result sets.
- Agent skill `skills/uda-dal-layer` renamed to **`skills/uda-data-access`** (clearer name; “DAL layer” was redundant).
- Composer package name **`johnnyjoy/uda`** (install: `composer require johnnyjoy/uda`).
- Documentation reorganized for **application developers**: README as full
  storefront (lead, capabilities, engines, Configuration / Raw SQL / Fluent queries /
  reuse & bulk insert, Caching sections, `Link` quick start), plus [docs/building-your-dal.md](docs/building-your-dal.md),
  [docs/engines.md](docs/engines.md), [docs/README.md](docs/README.md) user vs
  contributor paths; agent skills described as tool-agnostic under `skills/`.

## [1.0.0] - 2026-06-03

### Added

- **`firebird` engine connectable** via `UDA\Driver\Firebird` and `pdo_firebird` (MERGE upsert,
  RETURNING, pagination; writable CTE off). See `docs/integration/firebird.md`.
- Structured `QueryException`: `category()`, `sqlState()`, `driverCode()`; factories
  `guardrail()`, `fromPdo()`, `unsupported()` (`docs/public-api.md` §7).
- Connection cache option `require_table_hints` — fail loud on hintless raw SQL reads
  when cache is enabled (`docs/configuration.md`, `docs/caching.md`).
- Connection option `dev.warnings` / env `UDA_DEV=1` — `E_USER_NOTICE` when legacy
  driver aliases are normalized during config ingestion (`docs/configuration.md`).
- `Database::setQueryObserver()` and `UDA\Query\Observer` for instrumentation after
  each execute or read-cache hit (`docs/metrics.md`).
- `Database::connectDefault()`, `connectNamed()`, `connectWithConfig()` — explicit
  connect entry points; varargs `connect()` unchanged (`docs/public-api.md` §1).
- `UDA\Cache::flush($connectionName)` and `Database::flushCache()` — ops-only purge
  scoped to a connection namespace (not a substitute for app cache invalidation).
- SQL Server: optional `params.trust_server_certificate` for the `sqlsrv` transport
  (local/CI containers without full TLS trust chains).
- GitHub Actions **integration** jobs for PostgreSQL, MariaDB, SQL Server, Oracle, DB2, and
  Firebird (SQLite already covered); matrix in `docs/integration/README.md`.
- Composer package **`johnnyjoy/uda`** (Universal Data Abstractor).
- `tools/check-license.php` — MIT-only `@license` headers in `src/`.
- Contributor docs: `CONTRIBUTING.md`, `SECURITY.md`, `docs/releases.md`; GitLab MR
  templates for mirror workflows.

### Removed

- **`UDA\Query` static facade** — use `Database::connect()` or `UDA\Link` instead
  (`docs/public-api.md` §1).

### Changed

- Renamed abstract builder base to `UDA\Query` (`src/UDA/Query.php`); removed
  `UDA\Query\Builder`. Concrete builders use `extends \UDA\Query`.
- `UDA\Cache::clearForTests()` renamed to `UDA\Cache::clear()` — same
  scope (process-local statics only; does not purge Memcached/Redis).
- Config ingestion normalizes each connection to **`engine`** + **`transport`**; per-engine
  classes under `UDA\Driver\` build DSN strings; `UDA\Driver` owns `new PDO()`.
  Legacy `driver: dblib` maps to engine `sybase` + transport `dblib`; MSSQL over DBLib
  needs `driver: sqlserver` + `transport: dblib` explicitly (`docs/driver.md`,
  `docs/configuration.md`).
- `UDA\Driver::engineKey()` centralizes engine aliases; `Database::queryDialect()` uses
  canonical keys only.
- `UDA\Driver\Sybase` engine class; Sybase dialect disables MERGE/upsert until verified
  on ASE (`supportsMerge` / `supportsUpsert` false).
- **`db2` engine connectable** via `UDA\Driver\Db2` and `pdo_ibm` (MERGE upsert and
  pagination builders; `returning()` still unsupported per dialect).
- PDO failures map through `QueryException::fromPdo()` with SQLSTATE extraction.
- `Query\Expr` aliases quote via `SQL\Identifier` / `Driver::quoteIdentifier()` (same
  rules as column identifiers).
- Docs: engine integration matrix, Octane/RoadRunner/Swoole concurrency notes, cache
  ops (`flush`/`clear`) vs read-path caching (`docs/caching.md`, `docs/integration/`).
- All `src/` PHPDoc `@license` headers aligned to MIT; `@link` targets point at GitHub docs.

### Fixed

- SQL Server and Oracle `SELECT` pagination: `OFFSET` / `FETCH` use integer literals
  (some drivers reject bound row-limit parameters).
- Oracle pagination with no offset: emit `FETCH FIRST n ROWS ONLY` only (avoids
  `OFFSET 0` issues on some `pdo_oci` builds).
- Oracle: reconnect when the driver reports a lost connection (e.g. ORA-03114).
- `SQL\Identifier` invalid-character check (broken character-class range in regex).
