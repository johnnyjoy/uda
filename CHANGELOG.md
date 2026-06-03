# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Engine **integration** CI (`docs/integration/`): workflows `*-integration.yml` (replaces `*-cert.yml`).
- MariaDB integration (`mariadb-integration.yml`, `tests/MariaDb`, `docs/integration/mariadb.md`).
- Oracle integration (`oracle-integration.yml`, `tests/Oracle`, `docs/integration/oracle.md`); `gvenzl/oracle-free` (Oracle Database Free).
- Integration depth (Phase A): Postgres/MariaDB/SQL Server expanded tests; Oracle `PaginationTest` + `ReturningAndMergeTest` restored in CI.
- Sybase ASE integration spike (Phase B): `tests/Sybase`, `docs/integration/sybase.md`; manual `sybase-integration.yml` (`workflow_dispatch` + optional `SYBASE_LICENSE_B64`). Public GHA cannot boot ASE without SAP license mount (ASE_CORE).
- Fix SQL Server and Oracle `Select` pagination: emit integer literals for `OFFSET`/`FETCH` (drivers reject bound row-count parameters).
- Fix Oracle CI pagination: use `FETCH FIRST` when offset is unset (avoid `OFFSET 0` + `pdo_oci` ORA-03137); lighter fixture resets; reconnect on ORA-03114.
- Fix Oracle CI exit 139: run `tests/Oracle` with `--process-isolation` and disable GC in `oracle-bootstrap.php` (pdo_oci statement-GC segfault, php#18494).
- Fix `SQL/Identifier` invalid-character check (broken `[\\/*]` regex range); remove redundant Oracle pagination test that re-executed the same builder twice.
- Structured `QueryException`: `category()`, `sqlState()`, `driverCode()`; factories `guardrail()`, `fromPdo()`, `unsupported()` (`docs/public-api.md` §7).
- Connection cache option `require_table_hints` — fail loud on hintless raw SQL reads when cache is enabled (`docs/configuration.md`, `docs/caching.md`).
- Connection option `trace: true` emits `E_USER_NOTICE` on driver alias normalization during ingestion (`docs/configuration.md`).
- `Database::setQueryObserver()` / `UDA\Query\Observer` for ops instrumentation after each execute or read-cache hit (`docs/metrics.md`).
- `Database::connectDefault()`, `connectNamed()`, `connectWithConfig()` — explicit connect entry points (`docs/public-api.md` §1); varargs `connect()` unchanged.
- SQL Server CI integration (`sqlserver-integration.yml`, `tests/SqlServer`); Linux CI uses `sqlserver` + `dblib` (FreeTDS); `sqlsrv` remains supported for Windows/ODBC.
- SQL Server DSN: optional `params.trust_server_certificate` for `sqlsrv` (CI/local containers).
- Driver runtime phase 1: `UDA\Driver\Transport`, `UDA\Driver\Oracle`, `UDA\Driver\Oracle\Returning` (`docs/architecture-driver-runtime.md`); no public API change.
- Driver runtime phase 2: reconnect-on-failure (`isConnectionLost`, `reconnect`) stays on `UDA\Driver` (`docs/architecture-driver-runtime.md`); no public API change.
- Driver runtime phase 3: `UDA\Driver\Prepared` (`docs/architecture-driver-runtime.md`); no public API change.
- P1-6 driver decomposition **complete** (phase 4 transactions skipped); see `docs/architecture-driver-runtime.md`.
- CONTRIBUTING and README aligned to GitHub-primary PR workflow; GitLab mirror secondary.
- `tools/check-license.php` guardrail (MIT-only `@license` in `src/`).
- `skills/` agent skill pack for application DAL on UDA (`uda-dal-layer`, `uda-sql-and-cache`, `uda-config-deploy`, `uda-change-gates`); see `skills/README.md`.
- `UDA\Cache::flush($connectionName)` and `Database::flushCache()` for ops-only cache purge (per-connection namespace scope).
- `.gitlab/` MR + issue templates; `CONTRIBUTING.md`; `SECURITY.md`; `docs/releases.md`.

### Removed

- **`UDA\Query` static facade** — redundant second ingress; all paths use `Database::connect()` or `Link`. See `memory-bank/creative/creative-query-ingress.md`.

### Changed

- Renamed abstract builder base to `UDA\Query` (`src/UDA/Query.php`); removed `UDA\Query\Builder`. Concrete builders use `extends \UDA\Query`.
- Docs: engine integration matrix (`docs/integration/README.md`); config and README list CI-gated engines.
- Renamed certification tests/docs/workflows to **integration** (avoids TLS “cert” confusion). **Breaking:** GitHub required check names `*-cert` → `*-integration`.
- Docs: Octane/RoadRunner/Swoole concurrency — shared pooled handles, transaction rules, `lastSql()`/`lastParams()` hazards (`docs/architecture.md`, `docs/getting-started.md`, `docs/public-api.md`).
- All `src/` PHPDoc `@license` headers aligned to MIT (matches `composer.json` / `LICENSE.md`).
- PHPDoc `@link` headers point at GitHub repo docs.
- PDO failures map through `QueryException::fromPdo()` with SQLSTATE extraction.
- `UDA\Driver::engineKey()` centralizes engine alias normalization; `Database::queryDialect()` selects dialects using canonical keys only (Driver cannot import Query).
- Config ingestion normalizes each connection to **`engine`** + **`transport`**; per-engine classes under `UDA\Driver\` build DSN strings; **`UDA\Driver` always owns `new PDO()`**. DSN routing is by engine (transport only selects `sqlsrv` vs `dblib` for SQL Server).
- `UDA\Driver::transportName()` and `Config::transport()` expose the normalized PDO transport prefix.
- `UDA\Driver\Sybase` engine class; Sybase dialect disables MERGE/UPSERT until ASE is integration-gated (`supportsMerge`/`supportsUpsert` false).
- Removed `db2` from `Database::queryDialect()` until `Driver/Db2.php` exists; `Query/Dialect/Db2.php` retained for future compilation.
- Removed in-process `UDA_DRIVER_DISABLE_PREPARED_LRU` benchmark bypass from `Driver::getOrPrepareStatement()`; `tools/benchmark-prepared-lru.php` times production LRU path only.
- Documented engine vs driver vs transport vocabulary (`docs/driver.md`, `docs/public-api.md` §12).
- Removed `Query\Dialect\Registry` (redundant indirection).
- `UDA\Driver::engineName()` replaces internal `$dbtype`; `limitOffsetForBackend` renamed to `limitOffsetFor`.
- Removed unused `Driver::resolveCredentials`, stub `isTransient`, and stub `consumeResultCacheHit`.
- `Query\Expr` expression aliases now quote via `SQL\Identifier` / `Driver::quoteIdentifier()` (same rules as column identifiers), not a separate ANSI-only path.
- Docs: integration status synced with CI; cache ops (`flush`/`clear`), optional cache extensions, and read-path vs ops-only cache API (`docs/caching.md`, `docs/configuration.md`, `docs/public-api.md`, integration/support docs).
- **Breaking:** `UDA\Cache::clearForTests()` renamed to `UDA\Cache::clear()`; docblock now states scope (process-local statics only, not remote store purge).
- Docs: where CI runs; contributor expectations before merge.
- PHPDoc: shorter summaries on `Sql`, `UDA\Query::param()`, `WhereBuilder::in()`/`build()`, and `toSql()` on concrete builders.
