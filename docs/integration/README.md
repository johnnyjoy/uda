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
| **Sybase (ASE)** | Code | Code | Off² | Code | No | [driver.md](../driver.md) |
| **Oracle** | Gated | Gated | Gated | Gated | Yes | [`oracle-integration.yml`](../.github/workflows/oracle-integration.yml) · [oracle.md](oracle.md) |
| **DB2** | N/A³ | Code | Code | Code | No | Dialect file only |

¹ MariaDB dialect rejects `returning()` before PDO (no `RETURNING` in emitted SQL).  
² Sybase dialect disables MERGE/UPSERT until ASE is integration-gated.  
³ `Query/Dialect/Db2.php` exists for future compilation; no `Driver/Db2.php` connect path.

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

Also run `composer check` before merge (architecture guardrails).

---

## Config examples vs integration

`config/example-config.json` shows **multiple engines** for illustration. That file
demonstrates config shape — **not** a statement that every listed engine is
CI-gated. See the matrix above before production use.

---

## Using a non-gated engine

You may configure Sybase if your environment has the right PDO extensions and servers.
Treat that as **integrator-owned validation**:

1. Confirm connect + CRUD + transactions on your target.
2. Run builder and upsert paths your app uses (Sybase: avoid upsert until gated).
3. Do not infer production readiness from config acceptance alone.

---

## Related

- [configuration.md](../configuration.md) — `"driver"` (engine) and `transport`
- [driver.md](../driver.md) — per-engine DSN and SQL rules
- [oracle-testing.md](../oracle-testing.md) — Oracle troubleshooting and evidence notes
- [support/sqlite.md](../support/sqlite.md) — SQLite capability notes
