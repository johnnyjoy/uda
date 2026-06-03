# Sybase ASE Integration (spike)

## Spike outcome (2026-06-03)

**Required CI on public GitHub Actions: no-go.**

The community image [superbeeeeeee/docker-sybase](https://hub.docker.com/r/superbeeeeeee/docker-sybase)
does not include a valid SAP ASE license. On GHA the service container never becomes healthy:

```text
SySAM: Failed to obtain license(s) for ASE_CORE feature
SySAM: Cannot find license file.
There is no valid license for ASE server product. Installation date is not found
or installation grace period has expired. Server will not boot.
```

The image author documents that a **SAP ASE Developer Edition license must be mounted**
for dev use; the image grace period may also expire. This is an **SAP licensing constraint**,
not a UDA defect.

UDA keeps:

- Dialect and capability tests in `composer test` (no live ASE).
- Live integration tests under `tests/Sybase/` for local or licensed runners.
- A **manual** workflow (`.github/workflows/sybase-integration.yml`) — not on push/PR.

## CI workflow

| Trigger | What runs |
| ------- | --------- |
| `workflow_dispatch` (no secret) | `composer check` + `SybaseCapabilitiesTest` |
| `workflow_dispatch` + `SYBASE_LICENSE_B64` | Boots ASE with mounted license, runs `tests/Sybase` |

To enable live CI in your fork:

1. Obtain SAP ASE Developer Edition license file (`license.dat` or `.lic`).
2. Base64-encode it and add repo secret **`SYBASE_LICENSE_B64`**.
3. Run **Sybase Integration (manual)** from the Actions tab.

Do not commit SAP license files to the repository.

## Status vs merge-blocking engines

Sybase is **not** merge-blocking. Five engines are gated: SQLite, PostgreSQL, MariaDB,
SQL Server, Oracle. See [README.md](README.md).

## Licensing and image disclaimer

- Intended for **local development and licensed CI only**, not production.
- SAP ASE Developer Edition licensing applies.
- No official SAP Docker image is used.

## Suite

`tests/sybase-bootstrap.php` loads config; PHPUnit runs `tests/Sybase/SybaseIntegrationTest.php`.

| Test | Proves |
| ---- | ------ |
| `test_sybase_read_write_and_named_parameters` | Connect + named CRUD via `pdo_dblib` |
| `test_sybase_transaction_commits` | `Database::transaction()` |
| `test_sybase_insert_returning_output` | `INSERT … OUTPUT` via builder |
| `test_sybase_pagination_limit_offset` | `OFFSET … FETCH NEXT` (T-SQL shape) |
| `test_sybase_upsert_builder_throws` | MERGE upsert disabled until ASE is gated |

Config uses `"driver": "sybase", "transport": "dblib"`.

## Local command

```bash
# Mount your SAP license (path varies by image; superbeeeeeee expects flexlm):
docker run -d -p 5000:5000 \
  -v /path/to/licenses:/usr/local/flexlm/licenses:ro \
  -e SA_PASSWORD=Sybase_UDA_CI1 \
  -e DATABASE=master \
  superbeeeeeee/docker-sybase:latest

TDSVER=7.4 \
SYBASE_HOST=127.0.0.1 \
SYBASE_PORT=5000 \
SYBASE_DATABASE=master \
SYBASE_USER=sa \
SYBASE_PASSWORD='Sybase_UDA_CI1' \
vendor/bin/phpunit --bootstrap tests/sybase-bootstrap.php tests/Sybase
```

## MERGE / upsert (Phase B4)

`Query/Dialect/Sybase` sets `supportsMerge()` and `supportsUpsert()` to **false** until ASE
MERGE is verified against a licensed container. See `tests/Query/SybaseCapabilitiesTest.php`.
