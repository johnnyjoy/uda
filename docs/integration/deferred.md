# Deferred integration work (Phase C)

Phases A and B of the integration expansion are **complete**. Five engines are
merge-blocking in CI (SQLite, PostgreSQL, MariaDB, SQL Server, Oracle). Sybase ASE
was spiked and **does not belong in required public CI** without a mounted SAP
Developer license — see [sybase.md](sybase.md).

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
| **DB2** | **Yes** (IBM community image + `LICENSE: accept`) | **No** — no `Driver/Db2.php` / connect | Service container after driver + `pdo_ibm` |
| **Sybase ASE** | **No** — SAP ASE_CORE license required | **Yes** — tests + `pdo_dblib` | `workflow_dispatch` + `SYBASE_LICENSE_B64` (already in repo) |

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
| Repo secret `SYBASE_LICENSE_B64` | **Yes** | **Implemented** in `.github/workflows/sybase-integration.yml` (`workflow_dispatch` + `docker run -v`) |
| Build-from-SAP-tarball images ([cboudereau/docker-sybase](https://github.com/cboudereau/docker-sybase), [blieusong/ase-server](https://hub.docker.com/r/blieusong/ase-server)) | Same — mount license | Image build does not remove SySAM requirement |
| SAP ASE Express in custom Dockerfile ([annagapuz/docker-sap-ase-express](https://github.com/annagapuz/docker-sap-ase-express)) | Express license from SAP download | Heavier build; still not “zero-config” on GHA |
| [datagrip/sybase:16.0](https://github.com/DataGrip/docker-env) | Bundled dev image | Mac/Docker 20.x may need `-T11889` on `dataserver` (snap validation) |

**UDA already supports Sybase in code:** `driver: sybase`, `transport: dblib`, `pdo_dblib`, `tests/Sybase/`.

**No known method** for **anonymous** push/PR CI on `github.com` runners without either:

- A **repository/org secret** holding a base64 license (current workflow), or
- A **self-hosted** runner with ASE + license preinstalled.

**Verdict:** Sybase on GHA is **possible only with licensed, manual (or secret-gated) runs** — not comparable to Postgres/Oracle/SQL Server service containers.

---

### Recommendation for UDA

1. **Sybase:** Keep current design — manual workflow + optional `SYBASE_LICENSE_B64`; do not add to branch protection on public repo.
2. **DB2:** Treat as a **separate build task**: driver/connect first, then copy IBM community service pattern into `db2-integration.yml`; expect slow/flaky startup and budget CI time accordingly.
3. **Neither** should block the five-engine integration milestone.

---

## Sybase ASE (Phase B outcome)

| Item | Status |
| ---- | ------ |
| Required GHA on push/PR | **No-go** — ASE_CORE / SySAM license |
| Live tests in repo | `tests/Sybase/` for local or licensed runners |
| CI workflow | `workflow_dispatch` only; optional `SYBASE_LICENSE_B64` secret |
| MERGE / upsert in dialect | Disabled until proven on licensed ASE (Phase B4) |

---

## DB2

| Blocker | Notes |
| ------- | ----- |
| No connect path | `Query/Dialect/Db2.php` exists for compilation; **`Driver/Db2.php` is missing** |
| No CI image | No stable public DB2 service container in this repo’s matrix |

**Before any DB2 integration job:**

1. Implement `UDA\Driver\Db2` + factory wiring in `Database::connect()`.
2. Add unit/runtime tests for DSN and dialect selection.
3. Choose integrator-owned DB2 instance or licensed container; document in `docs/integration/db2.md`.

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
- `integration:sybase-license` — licensed ASE runner path
- `integration:writable-cte` — future dialect + tests

---

## Related

- [README.md](README.md) — current gated matrix
- [sybase.md](sybase.md) — Sybase spike and licensing
- [../production-readiness.md](../production-readiness.md) — broader engine backlog
