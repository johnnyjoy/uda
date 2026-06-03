# Deferred integration work (Phase C)

Phases A and B of the integration expansion are **complete**. Five engines are
merge-blocking in CI (SQLite, PostgreSQL, MariaDB, SQL Server, Oracle). **Sybase ASE** live tests and CI are **disabled** (retained in repo); config remains in
code — see [sybase.md](sybase.md).

This page records **explicitly deferred** follow-ups. None are required for the
current integration milestone.

---

## GitHub Actions investigation (2026-06-03)

Known community patterns for running **DB2** and **Sybase ASE** on GitHub-hosted
`ubuntu-latest` runners, and what UDA would need before either could join the
integration matrix.

### Summary

| Engine | Public GHA without secrets | UDA code ready? | Practical CI path |
| ------ | -------------------------- | --------------- | ----------------- |
| **DB2** | **Yes** (IBM community image + `LICENSE: accept`) | **Yes** | **`db2-integration`** merge-blocking on push/PR |
| **Sybase ASE** | **No** on upstream push/PR | **Yes** — code + unit tests | **Manual** — `SYBASE_LICENSE_B64` or `UDA_SYBASE_LIVE=1` |

---

### DB2 on GitHub Actions

**Known method:** IBM Db2 Community Edition as a **privileged** service container.

Widely copied pattern (e.g. [flowable/flowable-engine `db2.yml`](https://github.com/flowable/flowable-engine/blob/master/.github/workflows/db2.yml), [php/pecl-database-pdo_ibm `ci.yml`](https://github.com/php/pecl-database-pdo_ibm/blob/master/.github/workflows/ci.yml)):

```yaml
services:
  db2:
    image: icr.io/db2_community/db2:11.5.9.0   # pin tag; 11.5.8.0 also used
    env:
      LICENSE: accept
      DB2INST1_PASSWORD: <password>
      DBNAME: <database>
      ARCHIVE_LOGS: "false"
      AUTOCONFIG: "false"
    ports:
      - 50000:50000
    options: >-
      --privileged=true
      --health-cmd="su - db2inst1 -c \"~/sqllib/bin/db2gcf -s\""
      --health-interval 30s
      --health-timeout 40s
      --health-retries 10
      --health-start-period 120s
```

**Connect from job steps:** `127.0.0.1` and mapped port (or `job.services.db2.ports[50000]`).

**PHP:** extension **`pdo_ibm`** (PECL `pdo_ibm`), DSN shape `ibm:DSN=...` with `db2cli.ini` or JDBC-style URL — see PECL CI workflow above. UDA would need `transport: db2` wired to PDO IBM and a new `UDA\Driver\Db2`.

**Caveats (documented by Doctrine DBAL maintainers):**

- Startup is **slow** (~2–5+ minutes); health checks are **brittle** (~30% flake reported when creating tablespaces post-connect).
- Mitigations: longer `health-start-period`, log-grep wait (`Setup has completed`), or init scripts under `/var/custom/` in the image.
- Image is **large** and requires **`--privileged=true`** (acceptable on GHA but heavy for every push).

**Alternatives:**

- [achrinza/setup-db2](https://github.com/achrinza/setup-db2) — early-stage action wrapping a dev install (not widely adopted).
- Self-hosted runner with a persistent Db2 instance — common for enterprises; out of scope for public matrix.

**UDA blockers before a `db2-integration.yml` job:**

1. Implement `src/UDA/Driver/Db2.php` and `Database::connect()` factory entry (dialect exists; connect path does not).
2. Confirm DSN + identifier/pagination fragments for Db2 LUW.
3. Add `tests/Db2/` + bootstrap; install `pdo_ibm` in workflow (`shivammathur/setup-php` may need custom extension or compile step).
4. Spike one green run, then consider merge-blocking (expect **long** job time).

**Verdict:** DB2 on GHA is **feasible** with IBM’s community image; UDA is **not** ready until the driver/connect path exists.

---

### Sybase ASE on GitHub Actions

**SAP position:** ASE in Docker is **not officially supported** ([SAP KBA 2753980](https://userapps.support.sap.com/sap/support/knowledge/en/2753980)). Community images are dev/spike only.

**Known methods:**

| Approach | License on public GHA? | Notes |
| -------- | ------------------------ | ----- |
| [superbeeeeeee/docker-sybase](https://hub.docker.com/r/superbeeeeeee/docker-sybase) | **No** (unless volume mount) | UDA spike: ASE_CORE failure, grace expired — [sybase.md](sybase.md) |
| Mount `license.dat` via volume | **Yes, maintainer-owned** | Paths: `/usr/local/flexlm/licenses` (this image) or `/opt/sybase/SYSAM-2_0/licenses` (older images) |
| Repo secret `SYBASE_LICENSE_B64` | **Yes** | **Manual workflow** — not on upstream PR; fork/maintainer opt-in |
| Build-from-SAP-tarball images ([cboudereau/docker-sybase](https://github.com/cboudereau/docker-sybase), [blieusong/ase-server](https://hub.docker.com/r/blieusong/ase-server)) | Same — mount license | Image build does not remove SySAM requirement |
| SAP ASE Express in custom Dockerfile ([annagapuz/docker-sap-ase-express](https://github.com/annagapuz/docker-sap-ase-express)) | Express license from SAP download | Heavier build; still not “zero-config” on GHA |
| [datagrip/sybase:16.0](https://github.com/DataGrip/docker-env) | Bundled dev image | Mac/Docker 20.x may need `-T11889` on `dataserver` (snap validation) |

**UDA supports Sybase in code only:** `driver: sybase`, `transport: dblib`, `pdo_dblib`;
`tests/Query/SybaseCapabilitiesTest.php` (no ASE).

**No known method** for **anonymous** push/PR CI on `github.com` runners without either:

- A **repository/org secret** holding a base64 license (current workflow), or
- A **self-hosted** runner with ASE + license preinstalled.

**Verdict:** Sybase on GHA is **possible only with licensed, manual (or secret-gated) runs** — not comparable to Postgres/Oracle/SQL Server service containers.

---

### Recommendation for UDA

1. **Sybase:** **Not on upstream PR CI** — licensees use manual workflow or `UDA_SYBASE_LIVE=1`.
2. **DB2:** **Done** — `db2-integration` gated on push/PR; see [db2.md](db2.md).

---

## Sybase ASE — disabled (2026-06-03)

| Item | Status |
| ---- | ------ |
| Required GHA on push/PR | **No** — upstream has no license |
| Live tests in repo | Opt-in — `UDA_SYBASE_LIVE=1`; excluded from default `composer test` |
| CI workflow | **Manual only** — `sybase-integration`; live steps if `SYBASE_LICENSE_B64` set |
| MERGE / upsert in dialect | Stays **off** until live ASE is re-enabled and verified |

---

## DB2

| Item | Status |
| ---- | ------ |
| Connect path | **Done** — `UDA\Driver\Db2`, `pdo_ibm`, see [db2.md](db2.md) |
| GHA integration job | **Gated** — `db2-integration.yml` on push/PR (merge-blocking) |

---

## SQL Server `sqlsrv` transport (second job)

Today CI uses **`pdo_dblib`** against `mcr.microsoft.com/mssql/server` — sufficient to
prove T-SQL builders, OUTPUT, pagination, and MERGE.

A separate job for **`pdo_sqlsrv`** / ODBC would add:

- TLS / certificate trust configuration on Linux runners
- Duplicate test maintenance for the same engine semantics

**Defer** until a product requirement needs `sqlsrv:` DSN validation in CI, not `dblib:`.

---

## Writable CTE integration

Writable CTEs vary widely by engine and are easy to make flaky in container CI.
The v1 integration contract stays on **connect, transactions, pagination, RETURNING/OUTPUT,
and upsert** per `docs/spec.md`.

Add cross-engine writable CTE tests only after dialect capability flags and stable
fixtures exist.

---

## Cache backends in every engine job

PostgreSQL integration already runs Memcached/Redis **services** where cache tests need them.
Other engine jobs intentionally avoid extra services to keep job time and flake low.

Expand cache-in-integration only when adding **engine-specific cache regression** tests,
not by default.

---

## Repository hygiene (separate from integration)

| Item | Owner |
| ---- | ----- |
| Commit `composer.lock` | Separate hygiene / release task |
| Pin third-party image digests | Per-engine when stability requires it |

---

## Suggested issue labels (if tracking in GitHub)

- `integration:db2` — driver + connect + optional CI
- `integration:sqlsrv` — second SQL Server transport job
- `integration:writable-cte` — future dialect + tests

---

## Related

- [README.md](README.md) — current gated matrix
- [sybase.md](sybase.md) — Sybase spike and licensing
- [../production-readiness.md](../production-readiness.md) — broader engine backlog
