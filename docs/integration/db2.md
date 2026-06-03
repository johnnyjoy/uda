# DB2 integration

## Status

**Enforced in CI** on every push and pull request (`.github/workflows/db2-integration.yml`).

**Connect path:** `driver: db2` with transport `db2` and the PHP **`pdo_ibm`** extension.

The job uses IBM Db2 Community Edition (`LICENSE: accept`), downloads the IBM CLI
driver tarball, and **compiles `pdo_ibm` from**
[php/pecl-database-pdo_ibm](https://github.com/php/pecl-database-pdo_ibm) (`RELEASE_1_7_0`)
— PECL does not publish a downloadable release for this extension.

Expect **~5–15 minutes** per run (container cold start + `pdo_ibm` compile). Flake
mitigations: long `health-start-period`, log wait for `Setup has completed`, pinned
image `icr.io/db2_community/db2:11.5.9.0`.

**Branch protection:** add required check `db2-integration` with the other `*-integration` jobs.

Full engine matrix: [README.md](README.md).

## Requirements

- PHP 8.2+
- [PECL `pdo_ibm`](https://pecl.php.net/package/pdo_ibm) (IBM CLI driver / `db2cli.ini`)
- Db2 LUW instance (local Docker, enterprise host, or community image)

## Configuration

Inline DSN (typical):

```json
{
  "connections": {
    "db2": {
      "driver": "db2",
      "params": {
        "host": "127.0.0.1",
        "port": 50000,
        "dbname": "testdb"
      },
      "user": "db2inst1",
      "pass": "your-password"
    }
  }
}
```

`db2cli.ini` section (PECL-style):

```json
{
  "params": {
    "dsn": "DSN=SAMPLE"
  }
}
```

UDA emits `ibm:DSN=…` or `ibm:DATABASE=…;HOSTNAME=…;PORT=…;PROTOCOL=TCPIP`.

## Dialect capabilities

| Feature | Supported |
| ------- | --------- |
| MERGE / upsert builders | Yes |
| Pagination (`OFFSET` / `FETCH NEXT`) | Yes |
| `returning()` on builders | No — rejected before PDO |
| Writable CTE | No |

See `docs/spec.md` capability matrix.

## Suite

`tests/db2-bootstrap.php` loads config; PHPUnit runs `tests/Db2/`:

| Class | Proves |
| ----- | ------ |
| `Db2IntegrationTest` | Connect + CRUD, transactions, MERGE upsert, pagination; RETURNING rejected before PDO |

## CI command

Same env vars as the workflow:

```bash
DB2_HOST=127.0.0.1 DB2_PORT=50000 DB2_DATABASE=testdb \
DB2_USER=db2inst1 DB2_PASSWORD='…' \
vendor/bin/phpunit --bootstrap tests/db2-bootstrap.php tests/Db2
```

Requires `pdo_ibm` and `LD_LIBRARY_PATH` pointing at the IBM CLI `lib` directory when
using a local CLI install.

## Local verification (no CI container)

Runtime and dialect tests do not require a live Db2:

```bash
composer check
vendor/bin/phpunit tests/Runtime/Db2ConnectTest.php tests/Query/Db2CapabilitiesTest.php
```

With `pdo_ibm` and a running database, point `UDA_CONFIG` at your JSON config and exercise
builders the same way as other engines.

## Related

- [deferred.md](deferred.md) — GHA community image pattern and Phase 2 CI spike
- `src/UDA/Driver/Db2.php` — DSN and identifier rules
- `src/UDA/Query/Dialect/Db2.php` — SQL compilation
