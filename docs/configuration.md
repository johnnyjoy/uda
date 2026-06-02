# UDA Configuration

**Purpose:**
Defines the configuration model for UDA. Configuration is loaded from a single JSON file and validated once at startup. The configuration describes database connections, secrets, cache store configuration, and per-connection cache policy.

Configuration is **sanitized during ingestion**, producing an immutable internal snapshot used by all UDA domains.

---

# Source

Configuration is loaded from exactly **one JSON file**.

Two loading routes exist:

1. **Environment route (production default)**
   The environment variable `UDA_CONFIG` contains the path to the JSON configuration file.

2. **Explicit file override**

   ```php
   Database::connect('connectionName', '/path/to/config.json');
   ```

If no config file argument is supplied, the system loads from `UDA_CONFIG`.

### Rules

* Configuration format **must be JSON**
* File **must have `.json` extension**
* Root **must be a JSON object**
* Configuration is **validated once during ingestion**
* After loading, configuration becomes **immutable**

Any failure during loading or validation throws **`ConfigException`**.

---

# Engine certification

Accepting a `"driver"` value in JSON does **not** mean that engine is CI-certified.
Only **SQLite** and **PostgreSQL** are enforced in GitHub Actions today.

See **`docs/certification/README.md`** for the full matrix (connect, builders, upsert,
cache, CI workflow names). `config/example-config.json` lists several engines for
config-shape illustration — treat uncertified engines as integrator-validated.

---

# Top-Level Structure

| Key             | Required | Description                                                                                                                             |
| --------------- | -------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| **defaults**    | no       | Object. `defaults.connection` names the default connection. It must exist in `connections`. |
| **connections** | yes      | Object mapping connection name → connection definition. Must contain at least one entry.                                                |
| **templates**   | no       | Object reserved for future mass-database generation patterns. Stored but not interpreted by Config.                                     |
| **cache**       | no       | Reserved for future process-wide cache defaults. V1 cache behavior is read from each connection's `cache` block.                        |

### Validation Rules

* `connections` **must exist and be non-empty**
* connection names **must be non-empty strings**
* if `defaults.connection` is defined, it **must reference an existing connection**

---

# Connection Definition

Each entry in `connections` describes how a database connection is created.

| Key            | Required | Description                                                                                             |
| -------------- | -------- | ------------------------------------------------------------------------------------------------------- |
| **driver**     | yes      | **Engine** / SQL family: `sqlite`, `pgsql`, `mysql`, `mariadb`, `sqlserver`, `sybase`, `oracle`, … Shorthand aliases (`sqlsrv`, `dblib`, …) normalize to canonical engine + transport. Case-insensitive. **CI-certified today:** `sqlite`, `pgsql`, `sqlserver` (`sqlsrv`) — see `docs/certification/README.md`. |
| **transport**  | no       | PDO DSN prefix when the engine supports more than one (`sqlsrv`, `dblib`, …). Defaults from engine when omitted. |
| **params**     | yes      | Object of driver-specific connection parameters used to build the PDO DSN.                              |
| **user**       | no       | Username. May reference environment variable `{env:VAR_NAME}`.                                          |
| **pass**       | no       | Password. May reference environment variable `{env:VAR_NAME}`.                                          |
| **options**    | no       | Object of PDO options. Keys must be integers (`PDO::*`).                                                |
| **init_sql**   | no       | Array of SQL statements executed after connection.                                                      |
| **cache**      | no       | Connection-specific cache policy configuration.                                                         |
| **trace**      | no       | When `true`, emit **`E_USER_NOTICE`** during ingestion if this connection uses a driver **alias** that normalizes to canonical engine + transport (e.g. `"driver": "dblib"` → sybase). Omit or `false` in production. |

When **`trace`** is enabled on a connection, prefer explicit shapes after you see a notice:

```json
"driver": "sybase",
"transport": "dblib"
```

SQL Server over DBLib:

```json
"driver": "sqlserver",
"transport": "dblib"
```

Example while authoring a connection:

```json
"ase": {
  "driver": "dblib",
  "trace": true,
  "params": { "host": "ase.internal", "dbname": "app" }
}
```

After ingestion, each connection stores normalized **`engine`** and **`transport`** fields. `Config::engine()` returns the engine; `Config::driver()` is a deprecated alias. `Config::transport()` returns the PDO prefix used for DSN construction.

### Engine vs transport

| Concept     | Config key    | Role |
| ----------- | ------------- | ---- |
| **Engine**  | `driver`      | SQL semantics — dialect selection, identifier quoting, pagination fragments. Routes to `UDA\Driver\{Engine}::dsn()` for most engines. |
| **Transport** | `transport` | PDO DSN prefix only when the engine has more than one (today: SQL Server `sqlsrv` vs `dblib`). `UDA\Driver` still performs `new PDO()`; per-engine classes only build the DSN string. |

Microsoft SQL Server over DBLib must set `"driver": "sqlserver", "transport": "dblib"`. `"driver": "dblib"` alone resolves to engine **sybase** + transport **dblib** (not SQL Server).

### Important Rule

The configuration **never contains a DSN string**.

The DSN is constructed by the driver using the `params` object.
Drivers own the transport-specific knowledge for PostgreSQL, Oracle, SQLite, MariaDB/MySQL, SQL Server, and Dblib transports.
Configuration only provides normalized parameters (host, port, path, service, etc.).

Example parameters:

```json
{
  "driver": "pgsql",
  "params": {
    "host": "localhost",
    "port": 5432,
    "dbname": "test"
  }
}
```

---

# Secrets

Connection credentials support environment variable resolution.

Example:

```json
"user": "{env:DB_USER}",
"pass": "{env:DB_PASS}"
```

Resolution occurs **during validation**.

Rules:

* `{env:VAR}` is replaced by `getenv('VAR')`
* Missing environment variables throw `ConfigException`

Secrets are **resolved during ingestion** so downstream code never handles placeholders.

---

# Connection Cache Configuration

When present, `connections.<name>.cache` defines default caching policy and per-table overrides.

This controls transparent read caching behind `Database`. Application code does
not call cache APIs on the read path; it continues to call `rows()`, `row()`, `value()`, and
builder terminators the same way whether cache is enabled or disabled. For ops-only
purge after deploy or incidents, use `Database::flushCache()` (see `docs/caching.md`).

## Structure

| Key           | Required | Description                              |
| ------------- | -------- | ---------------------------------------- |
| **store**     | yes      | Cache backend config for this connection |
| **namespace** | no       | Cache namespace prefix                   |
| **require_table_hints** | no | When `true` and cache store is enabled, raw SQL reads (`rows()`, `row()`, …) must pass table hints or throw `QueryException` (category `guardrail`). Default `false`. |
| **tables**    | no       | Per-table cache rules                    |

---

## Per-Table Rules

Tables can disable caching for statements that reference that table.

Example:

```json
"tables": {
  "users": {},
  "audit_log": { "disable": true }
}
```

### Rule Fields

| Key         | Description                                    |
| ----------- | ---------------------------------------------- |
| **disable** | Disables caching if query references the table |

---

# Connection Cache Store

`connections.<name>.cache.store` defines the cache backend for that connection.

If omitted or `type = off`, caching is disabled.

## Structure

| Key          | Required | Description                          |
| ------------ | -------- | ------------------------------------ |
| **type**     | yes      | `redis`, `memcached`, `array`, `off` |
| **host**     | no       | Redis/Memcached host                 |
| **port**     | no       | Redis/Memcached port                 |
| **timeout**  | no       | Redis connection timeout             |
| **database** | no       | Redis database number                |

### PHP extensions (deployment)

Composer requires only `ext-pdo`. Cache store types need **additional extensions**
in the runtime image when that store is configured:

| `store.type` | PHP extension   | Notes                          |
| ------------ | --------------- | ------------------------------ |
| `off`        | —               | No cache                       |
| `array`      | —               | In-process only                |
| `redis`      | `ext-redis`     | Required when store is `redis` |
| `memcached`  | `ext-memcached` | Required when store is `memcached` |

Missing extensions throw at first cache client creation, not at `composer install`.

---

## Redis Example

```json
"cache": {
  "store": {
    "type": "redis",
    "host": "localhost",
    "port": 6379,
    "timeout": 1
  }
}
```

---

## Memcached Example

```json
"cache": {
  "store": {
    "type": "memcached",
    "host": "localhost",
    "port": 11211
  }
}
```

---

# Loading Process

Configuration loading occurs exactly once per process.

### Steps

1. **Resolve config file path**

   * explicit path from `Database::connect()`
   * otherwise `UDA_CONFIG` environment variable

2. **Load JSON file**

3. **Validate structure**

   * top-level keys
   * connections
   * driver values
   * secrets resolution
   * cache configuration

4. **Build immutable Snapshot**

The resulting Snapshot is used by:

* `Database`
* `Driver`
* `Cache`

No further configuration processing occurs after this stage.

---

# Example Configuration

## Minimal Example

```json
{
  "defaults": {
    "connection": "sqlite_mem"
  },
  "connections": {
    "sqlite_mem": {
      "driver": "sqlite",
      "params": { "path": ":memory:" }
    }
  }
}
```

---

## Example with Cache

```json
{
  "defaults": {
    "connection": "app"
  },
  "connections": {
    "app": {
      "driver": "pgsql",
      "params": {
        "host": "localhost",
        "dbname": "appdb"
      },
      "user": "{env:DB_USER}",
      "pass": "{env:DB_PASS}",
      "cache": {
        "namespace": "APP",
        "store": {
          "type": "redis",
          "host": "localhost",
          "port": 6379
        },
        "tables": {
          "users": {},
          "audit_log": { "disable": true }
        }
      }
    }
  }
}
```

---

# Using Configuration

```php
$db = Database::connectDefault();
$db = Database::connectNamed('pgsql_test');
$db = Database::connectWithConfig('/config/app.json');
$db = Database::connectWithConfig('/config/app.json', 'pgsql_test');
```

`Database::connect(string ...$args)` supports the same four shapes; argument order is **position-independent** when using varargs.
