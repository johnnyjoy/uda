# SQL Server Integration

## Status

**Enforced in CI** on every push and pull request (`.github/workflows/sqlserver-integration.yml`).

Full engine matrix: [README.md](README.md).

Gates **`driver: sqlserver`** with transport **`dblib`** (FreeTDS / `pdo_dblib`) on Linux CI —
the usual production path on Linux. The library also supports **`transport: sqlsrv`**
(Microsoft ODBC + `pdo_sqlsrv`); that stack is **not** exercised in this job.

**Not** Sybase (`driver: sybase` or shorthand `"driver": "dblib"` alone).

The workflow starts SQL Server 2022 in a service container. Local runs need PHP 8.2+,
`pdo_dblib`, FreeTDS, a reachable server, and the env vars below. Tests skip when the
extension or bootstrap is missing.

## Suite

`tests/SqlServer/SqlServerIntegrationTest.php` runs with `tests/sqlserver-bootstrap.php`.

It proves:

* `Database::connectNamed()` / `connectWithConfig()` against a live SQL Server
* named-parameter INSERT/SELECT
* `Database::transaction()` commit path
* MERGE-style `$db->upsert()` builder execution

## Command

```bash
TDSVER=7.4 \
MSSQL_HOST=127.0.0.1 \
MSSQL_PORT=1433 \
MSSQL_DATABASE=master \
MSSQL_USER=sa \
MSSQL_PASSWORD='Your_Str0ng!Passw0rd123' \
vendor/bin/phpunit --bootstrap tests/sqlserver-bootstrap.php tests/SqlServer
```

Bootstrap uses `"driver": "sqlserver", "transport": "dblib"`. For local **sqlsrv**
instead, set `"transport": "sqlsrv"` and add `trust_server_certificate: true` under
`params` when connecting to dev/CI containers with self-signed TLS.

## CI Enforcement

GitHub Actions: `.github/workflows/sqlserver-integration.yml`

The `sqlserver-integration` job:

1. Starts `mcr.microsoft.com/mssql/server:2022-latest`.
2. Installs FreeTDS (`freetds-dev`) on the runner.
3. Installs PHP 8.2 with `pdo_dblib`.
4. Runs `composer check`.
5. Runs PHPUnit with `TDSVER=7.4` for SQL Server 2022 compatibility.

## sqlsrv vs dblib

| Transport | PDO | Typical host | CI |
| --------- | --- | ------------ | -- |
| **dblib** | `pdo_dblib` | Linux + FreeTDS | **Yes** |
| **sqlsrv** | `pdo_sqlsrv` | Windows, or Linux + Microsoft ODBC Driver 18 | No (integrator-owned) |

Same **sqlserver** engine rules and dialect either way; only the PDO wire adapter differs.
