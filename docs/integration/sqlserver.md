# SQL Server Integration

## Status

**Enforced in CI** on every push and pull request (`.github/workflows/sqlserver-integration.yml`).

Full engine matrix: [README.md](README.md).

Gates **`driver: sqlserver`** with transport **`dblib`** (FreeTDS / `pdo_dblib`) on Linux CI.

## Suite

`tests/SqlServer/SqlServerIntegrationTest.php` with `tests/sqlserver-bootstrap.php`.

| Test | Proves |
| ---- | ------ |
| `test_sqlserver_read_write_and_named_parameters` | Connect + named CRUD |
| `test_sqlserver_transaction_commits` | `Database::transaction()` |
| `test_sqlserver_insert_returning_output` | `INSERT … OUTPUT` via builder |
| `test_sqlserver_upsert_builder_executes` | MERGE upsert |
| `test_sqlserver_pagination_limit_offset` | `OFFSET … FETCH NEXT` pagination |

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

Bootstrap uses `"driver": "sqlserver", "transport": "dblib"`.

## CI Enforcement

Starts SQL Server 2022, installs FreeTDS + `pdo_dblib`, runs `composer check`, then PHPUnit
with `TDSVER=7.4`.

## sqlsrv vs dblib

| Transport | PDO | CI |
| --------- | --- | -- |
| **dblib** | `pdo_dblib` | **Yes** |
| **sqlsrv** | `pdo_sqlsrv` | No (integrator-owned) |
