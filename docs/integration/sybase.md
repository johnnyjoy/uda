# Sybase ASE Integration (spike)

## Status

**Optional CI spike** on every push and pull request (`.github/workflows/sybase-integration.yml`).

The job uses `continue-on-error: true` until the community Docker image proves stable
across several runs. It is **not** merge-blocking yet.

Full engine matrix: [README.md](README.md).

## Licensing and image disclaimer

CI uses the community image [superbeeeeeee/docker-sybase](https://hub.docker.com/r/superbeeeeeee/docker-sybase).

- Intended for **local development and CI spikes only**, not production.
- SAP ASE Developer Edition licensing applies; image validity may be time-limited.
- No official SAP Docker image is used.

Do not treat a green spike run as production readiness for Sybase.

## Suite

`tests/sybase-bootstrap.php` loads config; PHPUnit runs `tests/Sybase/SybaseIntegrationTest.php`.

| Test | Proves |
| ---- | ------ |
| `test_sybase_read_write_and_named_parameters` | Connect + named CRUD via `pdo_dblib` |
| `test_sybase_transaction_commits` | `Database::transaction()` |
| `test_sybase_insert_returning_output` | `INSERT … OUTPUT` via builder |
| `test_sybase_pagination_limit_offset` | `OFFSET … FETCH NEXT` (T-SQL shape) |
| `test_sybase_upsert_builder_throws` | MERGE upsert disabled until ASE is gated (`SybaseCapabilitiesTest` alignment) |

Config uses `"driver": "sybase", "transport": "dblib"` (not `"driver": "dblib"` alone — that
normalizes to sybase + dblib but is easy to confuse with SQL Server).

## Command

```bash
TDSVER=7.4 \
SYBASE_HOST=127.0.0.1 \
SYBASE_PORT=5000 \
SYBASE_DATABASE=master \
SYBASE_USER=sa \
SYBASE_PASSWORD='Sybase_UDA_CI1' \
vendor/bin/phpunit --bootstrap tests/sybase-bootstrap.php tests/Sybase
```

## CI enforcement

1. Start `superbeeeeeee/docker-sybase` on port **5000** (`SA_PASSWORD`, `healthcheck` script).
2. Install FreeTDS + PHP 8.2 `pdo_dblib`.
3. Run `composer check`, then the Sybase PHPUnit suite with `TDSVER=7.4`.

## Promotion (Phase B3)

After **three consecutive green** spike runs on `main`, consider:

- Remove `continue-on-error` from the workflow.
- Add `sybase-integration` to branch protection alongside other `*-integration` jobs.

If the job stays flaky, restrict to `workflow_dispatch` or a nightly cron and document that
in CONTRIBUTING.

## MERGE / upsert (Phase B4)

`Query/Dialect/Sybase` sets `supportsMerge()` and `supportsUpsert()` to **false** until ASE
MERGE is verified in this container. A future spike can attempt live MERGE and update
`tests/Query/SybaseCapabilitiesTest.php` if ASE accepts UDA’s emitted SQL.
