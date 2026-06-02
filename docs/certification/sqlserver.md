# SQL Server Certification

## Status

**Enforced in CI** on every push and pull request (`.github/workflows/sqlserver-cert.yml`).

Full engine matrix: [README.md](README.md).

Certifies **`driver: sqlserver`** with default transport **`sqlsrv`** (Microsoft PDO).
**Not** Sybase (`driver: dblib` alone) and **not** MSSQL-over-FreeTDS (`transport: dblib`) in this job.

The workflow starts SQL Server 2022 in a service container. Local runs need PHP 8.2+,
`pdo_sqlsrv`, a reachable server, and the env vars below. Tests skip when the extension
or bootstrap is missing.

## Suite

`tests/SqlServer/SqlServerCertTest.php` runs with `tests/sqlserver-bootstrap.php`.

It proves:

* `Database::connectNamed()` / `connectWithConfig()` against a live SQL Server
* named-parameter INSERT/SELECT
* `Database::transaction()` commit path
* MERGE-style `$db->upsert()` builder execution

## Command

```bash
MSSQL_HOST=127.0.0.1 \
MSSQL_PORT=1433 \
MSSQL_DATABASE=master \
MSSQL_USER=sa \
MSSQL_PASSWORD='Your_Str0ng!Passw0rd123' \
vendor/bin/phpunit --bootstrap tests/sqlserver-bootstrap.php tests/SqlServer
```

Bootstrap sets `trust_server_certificate: true` in connection params (appended to the
`sqlsrv` DSN) for dev/CI TLS to local containers.

## CI Enforcement

GitHub Actions: `.github/workflows/sqlserver-cert.yml`

The `sqlserver-cert` job:

1. Starts `mcr.microsoft.com/mssql/server:2022-latest`.
2. Installs PHP 8.2 with `pdo_sqlsrv` / `sqlsrv`.
3. Runs `composer check`.
4. Runs `vendor/bin/phpunit --bootstrap tests/sqlserver-bootstrap.php tests/SqlServer`.
