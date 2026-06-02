# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Structured `QueryException`: `category()`, `sqlState()`, `driverCode()`; factories `guardrail()`, `fromPdo()`, `unsupported()` (`docs/public-api.md` §7).
- Connection cache option `require_table_hints` — fail loud on hintless raw SQL reads when cache is enabled (`docs/configuration.md`, `docs/caching.md`).
- Connection option `trace: true` emits `E_USER_NOTICE` on driver alias normalization during ingestion (`docs/configuration.md`).
- `Database::setQueryObserver()` / `UDA\Query\Observer` for ops instrumentation after each execute or read-cache hit (`docs/metrics.md`).
- `Database::connectDefault()`, `connectNamed()`, `connectWithConfig()` — explicit connect entry points (`docs/public-api.md` §1); varargs `connect()` unchanged.
- SQL Server CI certification (`sqlserver-cert.yml`, `tests/SqlServer`, `docs/certification/sqlserver.md`); `sqlsrv` transport only.
- SQL Server DSN: optional `params.trust_server_certificate` for `sqlsrv` (CI/local containers).
- `tools/check-license.php` guardrail (MIT-only `@license` in `src/`).
- `skills/` agent skill pack for application DAL on UDA (`uda-dal-layer`, `uda-sql-and-cache`, `uda-config-deploy`, `uda-change-gates`); see `skills/README.md`.
- `UDA\Cache::flush($connectionName)` and `Database::flushCache()` for ops-only cache purge (per-connection namespace scope).
- `.gitlab/` MR + issue templates; `CONTRIBUTING.md`; `SECURITY.md`; `docs/releases.md`.

### Removed

- **`UDA\Query` static facade** — redundant second ingress; all paths use `Database::connect()` or `Link`. See `memory-bank/creative/creative-query-ingress.md`.

### Changed

- Docs: engine certification matrix (`docs/certification/README.md`); config and README state CI certifies SQLite/PostgreSQL only.
- Docs: Octane/RoadRunner/Swoole concurrency — shared pooled handles, transaction rules, `lastSql()`/`lastParams()` hazards (`docs/architecture.md`, `docs/getting-started.md`, `docs/public-api.md`).
- All `src/` PHPDoc `@license` headers aligned to MIT (matches `composer.json` / `LICENSE.md`).
- PDO failures map through `QueryException::fromPdo()` with SQLSTATE extraction.
- `UDA\Driver::engineKey()` centralizes engine alias normalization; `Database::queryDialect()` selects dialects using canonical keys only (Driver cannot import Query).
- Config ingestion normalizes each connection to **`engine`** + **`transport`**; per-engine classes under `UDA\Driver\` build DSN strings; **`UDA\Driver` always owns `new PDO()`**. DSN routing is by engine (transport only selects `sqlsrv` vs `dblib` for SQL Server).
- `UDA\Driver::transportName()` and `Config::transport()` expose the normalized PDO transport prefix.
- `UDA\Driver\Sybase` engine class; Sybase dialect disables MERGE/UPSERT until ASE certified (`supportsMerge`/`supportsUpsert` false).
- Removed `db2` from `Database::queryDialect()` until `Driver/Db2.php` exists; `Query/Dialect/Db2.php` retained for future compilation.
- Removed in-process `UDA_DRIVER_DISABLE_PREPARED_LRU` benchmark bypass from `Driver::getOrPrepareStatement()`; `tools/benchmark-prepared-lru.php` times production LRU path only.
- Documented engine vs driver vs transport vocabulary (`docs/driver.md`, `docs/public-api.md` §12).
- Removed `Query\Dialect\Registry` (redundant indirection).
- `UDA\Driver::engineName()` replaces internal `$dbtype`; `limitOffsetForBackend` renamed to `limitOffsetFor`.
- Removed unused `Driver::resolveCredentials`, stub `isTransient`, and stub `consumeResultCacheHit`.
- `Query\Expr` expression aliases now quote via `SQL\Identifier` / `Driver::quoteIdentifier()` (same rules as column identifiers), not a separate ANSI-only path.
- Docs: certification status synced with CI; cache ops (`flush`/`clear`), optional cache extensions, and read-path vs ops-only cache API (`docs/caching.md`, `docs/configuration.md`, `docs/public-api.md`, certification/support docs).
- **Breaking:** `UDA\Cache::clearForTests()` renamed to `UDA\Cache::clear()`; docblock now states scope (process-local statics only, not remote store purge).
- Docs: where CI runs; contributor expectations before merge.
- PHPDoc: shorter summaries on `Sql`, `Abs::param()`, `WhereBuilder::in()`/`build()`, and `toSql()` on concrete builders.
