# Engine integration (CI)

UDA supports multiple SQL engines in code. **CI integration** runs merge-blocking
PHPUnit against live databases for a subset of engines. Accepting a config `"driver"`
value does not mean that engine is integration-gated in CI.

**Normative matrix:** this document. Per-engine detail: linked pages below.

Integration jobs exercise **connect, transactions, pagination, RETURNING/OUTPUT, and
upsert** where the dialect advertises support — not TLS certificates and not vendor
certification programs.

---

## Matrix

Legend:

| Symbol | Meaning |
| ------ | ------- |
| **Gated** | Enforced in GitHub Actions on every push and PR |
| **Code** | Implemented in `src/`; not gated in CI |
| **N/A** | Not connectable via `Database::connect()` today |

| Engine | Connect | Builders | Upsert | RETURNING | CI | Workflow / doc |
| ------ | ------- | -------- | ------ | --------- | -- | -------------- |
| **SQLite** | Gated | Gated | Gated | Gated | Yes | [`sqlite-integration.yml`](../.github/workflows/sqlite-integration.yml) · [sqlite.md](sqlite.md) |
| **PostgreSQL** | Gated | Gated | Gated | Gated | Yes | [`postgres-integration.yml`](../.github/workflows/postgres-integration.yml) · [postgresql.md](postgresql.md) |
| **MariaDB / MySQL** | Gated | Gated | Gated | N/A¹ | Yes | [`mariadb-integration.yml`](../.github/workflows/mariadb-integration.yml) · [mariadb.md](mariadb.md) |
| **SQL Server** | Gated | Gated | Gated | Gated | Yes | [`sqlserver-integration.yml`](../.github/workflows/sqlserver-integration.yml) · [sqlserver.md](sqlserver.md) |
| **Sybase (ASE)** | Code | Code | Off² | Code | Manual³ | [sybase.md](sybase.md) · [`sybase-integration.yml`](../.github/workflows/sybase-integration.yml) |
| **Oracle** | Gated | Gated | Gated | Gated | Yes | [`oracle-integration.yml`](../.github/workflows/oracle-integration.yml) · [oracle.md](oracle.md) |
| **DB2** | Code | Code | Code | N/A⁴ | Spike⁵ | [db2.md](db2.md) · [`db2-integration.yml`](../.github/workflows/db2-integration.yml) |

¹ MariaDB dialect rejects `returning()` before PDO (no `RETURNING` in emitted SQL).  
² Sybase dialect disables MERGE/UPSERT.  
³ Not on push/PR CI (no upstream license); manual workflow + `UDA_SYBASE_LIVE` for licensees.  
⁴ Db2 dialect rejects `returning()` before PDO; requires `pdo_ibm` at connect time.  
⁵ CI job runs on push/PR but **`continue-on-error`** until stable; not branch-protection required yet.

Read cache behaviour is covered in the **SQLite** job (`tests/Cache`).

---

## What “Gated” means

A **Gated** row means GitHub Actions runs a dedicated job against a live or in-process
database service and fails the build on regression. See each engine’s integration page
for the exact test classes and methods.

| Engine | Local command | CI job |
| ------ | ------------- | ------ |
| SQLite | `vendor/bin/phpunit tests/SQLite tests/Cache tests/Runtime` | `sqlite-integration` |
| PostgreSQL | See [postgresql.md](postgresql.md) | `postgres-integration` |
| MariaDB | See [mariadb.md](mariadb.md) | `mariadb-integration` |
| SQL Server | See [sqlserver.md](sqlserver.md) | `sqlserver-integration` |
| Oracle | See [oracle.md](oracle.md) | `oracle-integration` |
| Sybase (live, optional) | `UDA_SYBASE_LIVE=1 composer test:sybase-live` | `sybase-integration` (manual only; not on PR) |
| DB2 | See [db2.md](db2.md) | `db2-integration` (optional; may fail while stabilizing) |

Also run `composer check` before merge (architecture guardrails).

---

## Config examples vs integration

`config/example-config.json` shows **multiple engines** for illustration. That file
demonstrates config shape — **not** a statement that every listed engine is
CI-gated. See the matrix above before production use.

---

## Using a non-gated engine

You may configure Sybase if your environment has ASE and `pdo_dblib`. Live tests in this
repo are **disabled** unless you set `UDA_SYBASE_LIVE=1` (see [sybase.md](sybase.md)).
Avoid upsert until MERGE is verified on your server.

---

## Deferred work (Phase C)

Integration Phases A–B are complete. Follow-ups (DB2 connect, `sqlsrv` CI job,
writable CTE tests, etc.) are documented in
[deferred.md](deferred.md) — not merge-blocking.

---

## Related

- [deferred.md](deferred.md) — Phase C backlog
- [configuration.md](../configuration.md) — `"driver"` (engine) and `transport`
- [driver.md](../driver.md) — per-engine DSN and SQL rules
- [oracle-testing.md](../oracle-testing.md) — Oracle troubleshooting and evidence notes
- [support/sqlite.md](../support/sqlite.md) — SQLite capability notes
