# Database engines (configuration)

Copy-paste **`connections`** entries for `uda.json`. JSON key `"driver"` is the
**engine** name (historical key name). Optional `"transport"` selects the PDO
adapter when an engine has more than one (e.g. SQL Server `sqlsrv` vs `dblib`).

Set `UDA_CONFIG` to your config file, then `Database::connectDefault()` or
`Link` with `protected static string $connection = '...'` matching the key below.

**Build your layer:** [building-your-dal.md](building-your-dal.md).  
**CI / maintainer matrix:** [integration/README.md](integration/README.md).

---

## Summary

| Engine | `driver` value | PHP extension | Notes |
| ------ | -------------- | ------------- | ----- |
| SQLite | `sqlite` | `pdo_sqlite` | File or `:memory:` |
| PostgreSQL | `pgsql` | `pdo_pgsql` | |
| MariaDB / MySQL | `mysql` | `pdo_mysql` | |
| SQL Server | `sqlserver` | `pdo_sqlsrv` or `pdo_dblib` | Set `transport` |
| Oracle | `oracle` | `pdo_oci` | |
| DB2 | `db2` | `pdo_ibm` | |
| Firebird | `firebird` | `pdo_firebird` | Alias `interbase` |
| Sybase ASE | `sybase` | `pdo_dblib` | Not in upstream CI |
| Informix | — | — | **Coming soon** — not connectable in UDA today; planned (`pdo_informix`, CI TBD) |
| CUBRID | — | — | **Coming soon** — not connectable in UDA today; planned (`pdo_cubrid`, CI TBD) |

---

## SQLite

```json
"app": {
  "driver": "sqlite",
  "params": {
    "path": "/var/app/data.sqlite"
  }
}
```

In-memory:

```json
"test": {
  "driver": "sqlite",
  "params": { "path": ":memory:" }
}
```

---

## PostgreSQL

```json
"app": {
  "driver": "pgsql",
  "params": {
    "host": "127.0.0.1",
    "port": 5432,
    "dbname": "myapp"
  },
  "user": "app_user",
  "pass": "secret"
}
```

Env indirection:

```json
"user": { "env": "PG_USER" },
"pass": { "env": "PG_PASS" }
```

---

## MariaDB / MySQL

```json
"app": {
  "driver": "mysql",
  "params": {
    "host": "127.0.0.1",
    "port": 3306,
    "dbname": "myapp"
  },
  "user": "app_user",
  "pass": "secret"
}
```

---

## SQL Server

**`sqlsrv`** (typical on Windows; Linux CI uses **`dblib`**):

```json
"app": {
  "driver": "sqlserver",
  "transport": "sqlsrv",
  "params": {
    "host": "sql01.internal",
    "port": 1433,
    "database": "MyApp"
  },
  "user": "app_user",
  "pass": "secret"
}
```

**`dblib`** on Linux:

```json
"app": {
  "driver": "sqlserver",
  "transport": "dblib",
  "params": {
    "host": "mssql.internal",
    "dbname": "MyApp"
  },
  "user": { "env": "SQL_USER" },
  "pass": { "env": "SQL_PASS" }
}
```

Optional `params.trust_server_certificate` for dev containers — see
[configuration.md](configuration.md).

---

## Oracle

```json
"app": {
  "driver": "oracle",
  "params": {
    "host": "127.0.0.1",
    "port": 1521,
    "dbname": "ORCLPDB1"
  },
  "user": "app_user",
  "pass": "secret"
}
```

---

## DB2

```json
"app": {
  "driver": "db2",
  "params": {
    "host": "db2host",
    "port": 50000,
    "dbname": "SAMPLE"
  },
  "user": "db2inst1",
  "pass": "secret"
}
```

Requires `pdo_ibm`. Dialect notes: [integration/db2.md](integration/db2.md).

---

## Firebird

```json
"app": {
  "driver": "firebird",
  "params": {
    "host": "127.0.0.1",
    "port": 3050,
    "database": "/var/lib/firebird/data/app.fdb"
  },
  "user": "app_user",
  "pass": "secret"
}
```

Over TCP, `database` must be the **path on the Firebird server**, not a bare
filename on the PHP host. More detail: [integration/firebird.md](integration/firebird.md).

---

## Sybase ASE (optional)

```json
"ase": {
  "driver": "sybase",
  "params": {
    "host": "ase.internal",
    "dbname": "app"
  },
  "user": { "env": "ASE_USER" },
  "pass": { "env": "ASE_PASS" }
}
```

Legacy config `driver: dblib` maps to engine `sybase`. Local live tests:
[integration/sybase.md](integration/sybase.md).

---

## Multiple connections

```json
{
  "defaults": { "connection": "app" },
  "connections": {
    "app": { "driver": "pgsql", "params": { "host": "localhost", "dbname": "app" }, "user": "u", "pass": "p" },
    "reporting": { "driver": "pgsql", "params": { "host": "replica.internal", "dbname": "app" }, "user": "ro", "pass": "p" }
  }
}
```

```php
$appDb = Database::connectNamed('app');
$reportDb = Database::connectNamed('reporting');
```

Example multi-engine file (illustration only): `config/example-config.json` in the repo.
