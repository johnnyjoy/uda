# UDA Drivers

**Purpose:** Driver selection, DSN building, quoting, pagination, savepoints; engine behavior only in Driver/*.

See [spec.md](spec.md) Architecture. Base class: **src/UDA/Driver.php** (namespace `UDA`). Engine implementations: **src/UDA/Driver/** (SqliteDriver, PostgresDriver, SqlServerDriver, MySQLDriver, Identifier).

**The driver is the only place for engine-specific behavior.** DSN building, quoting, and pagination SQL all live in the driver. Execution is PDO; the driver owns everything that differs per database.

- **Driver:** Interface: `buildDsn(array $params): string`, `quoteIdentifier(string $segment): string`, `limitOffsetSql(int $limit, int $offset): string`. Implementations: MySQLDriver, PostgresDriver, SqliteDriver, SqlServerDriver. Chosen by config `"driver"` (pgsql, sqlite, sqlsrv, mysql). When config has no explicit `"dsn"`, Connection uses `getDriver()->buildDsn(definition.params)`.
- **Connection::getDriver():** Returns the Driver for this connection's backend. Repositories and builders get the driver from the connection, not by injection.
- **Identifier:** Value object; segments validated; `quoted()` uses the driver. Identifiers are never raw strings in SQL.
- **Pagination:** One API: `Pagination(limit, offset)` and `toSql(Driver)`. Each driver produces the correct clause (LIMIT/OFFSET or OFFSET-FETCH). SQL Server requires ORDER BY when paginating; SelectBuilder enforces it.
