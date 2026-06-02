# Engine certification

UDA supports multiple SQL engines in code. **CI production certification** applies
to a subset only. Accepting a config `"driver"` value does not mean that engine is
CI-certified for your deployment.

**Normative matrix:** this document. Per-engine detail: linked pages below.

---

## Matrix

Legend:

| Symbol | Meaning |
| ------ | ------- |
| **Cert** | Enforced in GitHub Actions on every push and PR |
| **Code** | Implemented in `src/`; not certified in CI |
| **N/A** | Not connectable via `Database::connect()` today |

| Engine | Connect | Builders | Upsert | Read cache | CI | Workflow / doc |
| ------ | ------- | -------- | ------ | ---------- | -- | -------------- |
| **SQLite** | Cert | Cert | Cert | Cert | Yes | [`sqlite-cert.yml`](../.github/workflows/sqlite-cert.yml) · [sqlite.md](sqlite.md) |
| **PostgreSQL** | Cert | Cert | Cert | Cert | Yes | [`postgres-cert.yml`](../.github/workflows/postgres-cert.yml) · [postgresql.md](postgresql.md) |
| **MariaDB / MySQL** | Code | Code | Code | Code | No | [driver.md](../driver.md) |
| **SQL Server** | Cert | Cert | Cert | Code | Yes | [`sqlserver-cert.yml`](../.github/workflows/sqlserver-cert.yml) · [sqlserver.md](sqlserver.md) |
| **Sybase (ASE)** | Code | Code | Off¹ | Code | No | [driver.md](../driver.md) |
| **Oracle** | Code | Code | Code | Code | No | [oracle-testing.md](../oracle-testing.md) (manual smoke only) |
| **DB2** | N/A² | Code | Code | — | No | Dialect file only |

¹ Sybase dialect disables MERGE/UPSERT until ASE is certified (`supportsUpsert()` false).

² `Query/Dialect/Db2.php` exists for future compilation; no `Driver/Db2.php` connect path.

---

## What “Cert” means

A **Cert** row means GitHub Actions runs a dedicated job against a live or in-process
database service and fails the build on regression. It does **not** guarantee every
engine feature or every config combination — see each engine’s certification page for
suite scope.

Today’s certified engines:

| Engine | Local command | CI job |
| ------ | ------------- | ------ |
| SQLite | `vendor/bin/phpunit tests/SQLite tests/Cache tests/Runtime` | `sqlite-cert` |
| PostgreSQL | See [postgresql.md](postgresql.md) | `postgres-cert` |
| SQL Server | See [sqlserver.md](sqlserver.md) | `sqlserver-cert` |

Also run `composer check` before merge (architecture guardrails).

---

## Config examples vs certification

`config/example-config.json` shows **multiple engines** for illustration (PostgreSQL,
SQLite, SQL Server, Sybase). That file demonstrates config shape — **not** a statement
that every listed engine is CI-certified. See the matrix above before production use.

---

## Using an uncertified engine

You may configure MariaDB, Oracle, or Sybase if your environment has the
right PDO extensions and servers. Treat that as **integrator-owned validation**:

1. Confirm connect + CRUD + transactions on your target.
2. Run builder and upsert paths your app uses (Sybase: avoid upsert until certified).
3. Do not infer production readiness from config acceptance alone.

When CI certification lands for an engine, this matrix and the engine’s doc page will
name the workflow explicitly.

---

## Related

- [configuration.md](../configuration.md) — `"driver"` (engine) and `transport`
- [driver.md](../driver.md) — per-engine DSN and SQL rules
- [support/sqlite.md](../support/sqlite.md) — SQLite capability notes (support, not cert scope)
