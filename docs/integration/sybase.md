# Sybase ASE (optional live tests)

## Status

**Upstream CI does not run Sybase** on push or pull request — this project has no SAP ASE
license. Live tests and a **manual** workflow stay in the repo so **you** can run them if you
have ASE and (for GHA) mount your own license.

**Still supported in code:** `driver: sybase` with `transport: dblib` (FreeTDS /
`pdo_dblib`), `UDA\Driver\Sybase`, and `Query\Dialect/Sybase`.

Unit tests (no ASE): `tests/Query/SybaseCapabilitiesTest.php` — included in `composer test`.

## What is disabled vs available

| Context | Behavior |
| ------- | -------- |
| **Upstream push/PR** | No Sybase job — not merge-blocking |
| **Default `composer test`** | Excludes `tests/Sybase/`; live tests skipped without `UDA_SYBASE_LIVE=1` |
| **You have ASE + license (local)** | `UDA_SYBASE_LIVE=1` + `composer test:sybase-live` |
| **You have license (GitHub fork)** | `workflow_dispatch` on **Sybase Integration (manual)** + repo secret `SYBASE_LICENSE_B64` |

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

## Run live tests on GitHub Actions (your fork / your secrets)

1. Obtain SAP ASE Developer `license.dat` (your responsibility).
2. Base64-encode it and add repository secret **`SYBASE_LICENSE_B64`**.
3. Actions → **Sybase Integration (manual)** → Run workflow.
4. With the secret set, the job starts ASE, sets `UDA_SYBASE_LIVE=1`, and runs `tests/Sybase`.
5. Without the secret, the same workflow runs only `SybaseCapabilitiesTest`.

Do not commit license files to the repository.

## When upstream might add push/PR Sybase CI

Only if ASE can run on public GHA **without** a maintainer license (today it cannot).
Until then, optional manual workflow + local opt-in remain the supported paths.

## Dialect notes

- MERGE / upsert: **off** in dialect until verified on live ASE (`supportsMerge` / `supportsUpsert` false)
- OUTPUT-style `returning()` on insert: same shape as SQL Server; validate on your server

## Related

- [README.md](README.md) — integration matrix
- [deferred.md](deferred.md) — DB2 and other follow-ups
- [driver.md](../driver.md) — engine vs transport
