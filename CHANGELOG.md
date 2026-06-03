# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Audience:** integrators adopting UDA and reviewing API changes at release time.
CI job names and maintainer workflow detail belong in `docs/integration/` or
`CONTRIBUTING.md`, not here. See `docs/releases.md`.

## [Unreleased]

### Added

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
- GitHub Actions **integration** jobs for PostgreSQL, MariaDB, SQL Server, Oracle, and DB2
  (SQLite already covered); matrix in `docs/integration/README.md`.
- Composer package **`universal-data-abstractor/universal-data-abstractor`** (Universal Data Abstractor).
- `tools/check-license.php` — MIT-only `@license` headers in `src/`.
- Contributor docs: `CONTRIBUTING.md`, `SECURITY.md`, `docs/releases.md`; GitLab MR
  templates for mirror workflows.

### Removed

- **`UDA\Query` static facade** — use `Database::connect()` or `UDA\Link` instead.
  See `memory-bank/creative/creative-query-ingress.md` (design note).

### Changed

- Renamed abstract builder base to `UDA\Query` (`src/UDA/Query.php`); removed
  `UDA\Query\Builder`. Concrete builders use `extends \UDA\Query`.
- GitHub required check names use `*-integration` (not `*-cert`).
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
- Sybase ASE live integration opt-in only: excluded from default `composer test`;
  `UDA_SYBASE_LIVE=1` for local runs; manual `sybase-integration` workflow (not on
  push/PR) supports forks with `SYBASE_LICENSE_B64`. Upstream has no SAP license.

### Fixed

- SQL Server and Oracle `SELECT` pagination: `OFFSET` / `FETCH` use integer literals
  (some drivers reject bound row-limit parameters).
- Oracle pagination with no offset: emit `FETCH FIRST n ROWS ONLY` only (avoids
  `OFFSET 0` issues on some `pdo_oci` builds).
- Oracle: reconnect when the driver reports a lost connection (e.g. ORA-03114).
- `SQL\Identifier` invalid-character check (broken character-class range in regex).
