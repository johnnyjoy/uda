# Oracle Testing Guide

> **CI:** `docs/integration/oracle.md` and `oracle-integration.yml` run the full
> `tests/Oracle` suite (smoke, pagination, RETURNING/MERGE). This guide adds troubleshooting
> and evidence notes. Engine matrix: `docs/integration/README.md`.

The Oracle smoke tests exercise UDA end-to-end through PDO OCI. Use this guide to spin up the database, configure connections, and run the test suite.

## Requirements

- Docker (for running `gvenzl/oracle-free`) or an equivalent Oracle instance.
- PHP extensions: `oci8` and `pdo_oci` (verify via `php -m | grep oci`).
- Access to the repository (tests live under `tests/Oracle`).

## Starting Oracle Locally

Use the official `gvenzl/oracle-free` container. The command below exposes the listener on localhost and sets the SYS/SYSTEM password:

```bash
docker run --rm -d -p 1521:1521 \
    -e ORACLE_PASSWORD='@@@oracle@@@' \
    gvenzl/oracle-free
```

Default connection properties:

| Property     | Value        |
|--------------|--------------|
| Host         | `127.0.0.1`  |
| Port         | `1521`       |
| Service name | `FREEPDB1`   |
| User         | `system`     |
| Password     | `@@@oracle@@@` |

You can override these via environment variables:

| Variable              | Description                          |
|-----------------------|--------------------------------------|
| `UDA_ORACLE_HOST`     | Hostname (default `127.0.0.1`)       |
| `UDA_ORACLE_PORT`     | Listener port (default `1521`)       |
| `UDA_ORACLE_SERVICE`  | Service / PDB name (default `FREEPDB1`) |
| `UDA_ORACLE_DBNAME`   | Full DSN string (optional)           |
| `UDA_ORACLE_USER`     | Database user (default `system`)     |
| `UDA_ORACLE_PASSWORD` | User password (default `@@@oracle@@@`) |

## Running the Smoke Tests

Install dependencies, ensure PDO OCI is available, then run:

```bash
composer install
php composer.phar test tests/Oracle
```

The harness (`Tests\Oracle\OracleTestCase`) builds a temporary UDA config pointing at the Oracle instance, seeds `UDA_TEST_USERS`, and suppresses the known identifier-regex warning so that PHPUnit can focus on connectivity.

## Pagination Tests

Work Order 020 adds `tests/Oracle/PaginationTest.php`, which seeds `UDA_TEST_NUMBERS` (10 deterministic rows) and verifies:

- `limit()` emits `FETCH NEXT …` and actually limits rows
- `offset()` emits `OFFSET …` and skips rows deterministically
- combined limit + offset honors ordering
- WHERE clauses and parameterized predicates work with pagination
- repeated executions reuse the cached SQL (`Select` memoization)
- streaming iteration via `each()` respects pagination
- invalid limits throw `QueryException`

Run them with the same command:

```bash
php composer.phar test tests/Oracle
```

Both the smoke and pagination suites require the live Oracle instance.

## What the Tests Cover

1. **Raw SQL** – `SELECT 1 FROM SYS.DUAL` to verify basic execution.
2. **Bound Parameters** – `SELECT :x` with named placeholders.
3. **Builder Execution** – `Select` builder hitting `SYS.DUAL`.
4. **Builder Parameters** – `Expr::raw()` value merging.
5. **Table CRUD** – Create/seed `UDA_TEST_USERS` and fetch rows.
6. **WHERE clauses** – Builder-generated predicates.
7. **Streaming** – `each()` iteration over the seeded table.
8. **Error surfacing** – Querying a missing table to ensure exceptions bubble up.
9. **Pagination** – Enforced limits/offsets, ordering, streaming, and validation on `UDA_TEST_NUMBERS`.

## RETURNING + MERGE Verification (WO021)

Work Order 021 adds `tests/Oracle/ReturningAndMergeTest.php`, which proves that PDO OCI exercises `INSERT/UPDATE/DELETE ... RETURNING` as well as Oracle’s `MERGE`-based UPSERT path end to end.

- **Command**

  ```bash
  vendor/bin/phpunit tests/Oracle/ReturningAndMergeTest.php
  ```

  Sample evidence (23 AI Free container):

  ```
  PHPUnit 10.5.63 by Sebastian Bergmann and contributors.

  .............                                                     13 / 13 (100%)

  Time: 00:05.013, Memory: 10.00 MB
  ```

- **Oracle version**

  ```sql
  SELECT banner FROM v$version;
  ```

  → `Oracle AI Database 26ai Free Release 23.26.1.0.0 - Develop, Learn, and Run for Free`

- **Sample compiled SQL**

  ```sql
  INSERT INTO "UDA_TEST_EMPLOYEES" ("EMPLOYEE_NO", "FIRST_NAME", "LAST_NAME", "SALARY")
  VALUES (:q1, :q2, :q3, :q4)
  RETURNING "ID", "EMPLOYEE_NO"
  ```

  ```sql
  MERGE INTO "UDA_TEST_EMPLOYEES" target
  USING (SELECT :q1 AS "EMPLOYEE_NO", :q2 AS "FIRST_NAME", :q3 AS "LAST_NAME", :q4 AS "SALARY" FROM dual) src
    ON (target."EMPLOYEE_NO" = src."EMPLOYEE_NO")
  WHEN MATCHED THEN UPDATE
    SET target."FIRST_NAME" = src."FIRST_NAME",
        target."LAST_NAME" = src."LAST_NAME",
        target."SALARY" = src."SALARY"
  WHEN NOT MATCHED THEN INSERT ("EMPLOYEE_NO", "FIRST_NAME", "LAST_NAME", "SALARY")
    VALUES (src."EMPLOYEE_NO", src."FIRST_NAME", src."LAST_NAME", src."SALARY")
  ```

- **Observed rows**

  - Insert returning emits `['id' => 3, 'employee_no' => 'E003']`
  - Update returning emits `['employee_no' => 'E001', 'salary' => 150000]`
  - Multi-row insert returning yields `[['id' => 6], ['id' => 7]]`

- **Driver behavior tips**

  - PDO OCI requires binding RETURNING placeholders as input/output strings *before* execution. The driver now seeds empty strings and trims Oracle’s output to avoid ORA-03131.
  - Oracle raises `ORA-63809` when asked to return columns from a multi-row `VALUES` clause; the driver automatically breaks those into one-row statements and stitches the result set together.
  - Oracle MERGE cannot touch identity columns. The UPSERT builder now omits `ID` entirely so Oracle assigns it server-side while still returning the optimistic rows via `select()` helpers.

## Inspecting Oracle Version

Run the following inside the container or via PDO to record the version for evidence:

```sql
SELECT * FROM v$version;
```

Example output (23c Free):

```
Oracle Database 23c Free Release 23.3.0.23.6 - Developer-Release
Oracle Database 23c Free Release 23.3.0.0.0 - Production
"Oracle Database 23c Free Release 23.3.0.0.0 - Dev"
```

## Troubleshooting

- **Connection refused** – ensure the container is running (`docker ps`) and port 1521 is free.
- **ORA-01017** – verify `UDA_ORACLE_USER` / `UDA_ORACLE_PASSWORD`.
- **Missing extensions** – confirm `pdo_oci` and `oci8` are compiled with PHP.
- **Identifier regex warning** – known issue in `Identifier.php`; the Oracle test harness suppresses it. Do not disable warnings globally.

## Cleanup

Stop the container when finished:

```bash
docker ps -q --filter ancestor=gvenzl/oracle-free | xargs docker stop
```

The test harness deletes its temporary config automatically during teardown.
