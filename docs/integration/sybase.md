# Sybase ASE (optional live tests)

## Status

**Upstream CI does not run Sybase** on push or pull request — this project has no SAP ASE
license. There is **no Sybase GitHub Actions workflow** in this repo; a CI job without a
license would fail or mislead. Live tests remain for **local** runs when you have ASE.

**Still supported in code:** `driver: sybase` with `transport: dblib` (FreeTDS /
`pdo_dblib`), `UDA\Driver\Sybase`, and `Query\Dialect/Sybase`.

Unit tests (no ASE): `tests/Query/SybaseCapabilitiesTest.php` — included in `composer test`.

## What is disabled vs available

| Context | Behavior |
| ------- | -------- |
| **Upstream push/PR** | No Sybase job — not merge-blocking |
| **GitHub Actions** | **No Sybase workflow** — no license to run ASE on CI |
| **Default `composer test`** | Excludes `tests/Sybase/`; live tests skipped without `UDA_SYBASE_LIVE=1` |
| **You have ASE + license (local)** | `UDA_SYBASE_LIVE=1` + `composer test:sybase-live` |

## Run live tests locally

```bash
TDSVER=7.4 \
UDA_SYBASE_LIVE=1 \
SYBASE_HOST=127.0.0.1 \
SYBASE_PORT=5000 \
SYBASE_DATABASE=master \
SYBASE_USER=sa \
SYBASE_PASSWORD='your_password' \
composer test:sybase-live
```

Do not commit license files to the repository.

## When upstream might add Sybase CI

Only if ASE can run on public GHA **without** a maintainer license (today it cannot).
Until then, local opt-in is the supported path for licensees.

## Dialect notes

- MERGE / upsert: **off** in dialect until verified on live ASE (`supportsMerge` / `supportsUpsert` false)
- OUTPUT-style `returning()` on insert: same shape as SQL Server; validate on your server

## Related

- [README.md](README.md) — integration matrix
- [deferred.md](deferred.md) — DB2 and other follow-ups
- [driver.md](../driver.md) — engine vs transport
