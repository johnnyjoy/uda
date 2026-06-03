# Engine integration (CI)

UDA supports multiple SQL engines in code. **CI integration** runs merge-blocking
PHPUnit against live databases for a subset of engines. Accepting a config `"driver"`
value does not mean that engine is integration-gated in CI.

**Normative matrix:** this document. Per-engine detail: linked pages below.

Integration jobs are **live-database smoke tests**, not TLS certificate configuration
and not vendor certification programs.

---

## Matrix

Legend:

| Symbol | Meaning |
| ------ | ------- |
| **Gated** | Enforced in GitHub Actions on every push and PR |
| **Code** | Implemented in `src/`; not gated in CI |
| **N/A** | Not connectable via `Database::connect()` today |

| Engine | Connect | Builders | Upsert | Read cache | CI | Workflow / doc |
| ------ | ------- | -------- | ------ | ---------- | -- | -------------- |
| **SQLite** | Gated | Gated | Gated | Gated | Yes | [`sqlite-integration.yml`](../.github/workflows/sqlite-integration.yml) · [sqlite.md](sqlite.md) |
| **PostgreSQL** | Gated | Gated | Gated | Gated | Yes | [`postgres-integration.yml`](../.github/workflows/postgres-integration.yml) · [postgresql.md](postgresql.md) |
| **MariaDB / MySQL** | Gated | Code | Code | Code | Yes | [`mariadb-integration.yml`](../.github/workflows/mariadb-integration.yml) · [mariadb.md](mariadb.md) |
| **SQL Server** | Gated | Gated | Gated | Code | Yes | [`sqlserver-integration.yml`](../.github/workflows/sqlserver-integration.yml) · [sqlserver.md](sqlserver.md) |
| **Sybase (ASE)** | Code | Code | Off¹ | Code | No | [driver.md](../driver.md) |
| **Oracle** | Gated | Code | Code | Code | Yes | [`oracle-integration.yml`](../.github/workflows/oracle-integration.yml) · [oracle.md](oracle.md) |
| **DB2** | N/A² | Code | Code | — | No | Dialect file only |

¹ Sybase dialect disables MERGE/UPSERT until ASE is integration-gated (`supportsUpsert()` false).

² `Query/Dialect/Db2.php` exists for future compilation; no `Driver/Db2.php` connect path.

---

## What “Gated” means

A **Gated** row means GitHub Actions runs a dedicated job against a live or in-process
database service and fails the build on regression. It does **not** guarantee every
engine feature or every config combination — see each engine’s integration page for
suite scope.

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
- [oracle-testing.md](../oracle-testing.md) — deeper manual Oracle suites (RETURNING, MERGE, pagination)
- [support/sqlite.md](../support/sqlite.md) — SQLite capability notes
