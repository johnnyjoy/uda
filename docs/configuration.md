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

# Top-Level Structure

| Key             | Required | Description                                                                                                                             |
| --------------- | -------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| **defaults**    | no       | String. Name of the default connection. Must exist in `connections`. Used when `Database::connect()` is called with no connection name. |
| **connections** | yes      | Object mapping connection name → connection definition. Must contain at least one entry.                                                |
| **templates**   | no       | Object reserved for future mass-database generation patterns. Stored but not interpreted by Config.                                     |
| **cache**       | no       | Object defining the process-wide cache store (Redis/Memcached/Array).                                                                   |

### Validation Rules

* `connections` **must exist and be non-empty**
* connection names **must be non-empty strings**
* if `defaults` is defined, it **must reference an existing connection**

---

# Connection Definition

Each entry in `connections` describes how a database connection is created.

| Key          | Required | Description                                                                                             |
| ------------ | -------- | ------------------------------------------------------------------------------------------------------- |
| **driver**   | yes      | Database driver. One of: `sqlite`, `pgsql`, `postgresql`, `mysql`, `mariadb`, `sqlsrv`, `dblib`, `oci`, `oracle`. Case-insensitive. |
| **params**   | yes      | Object of driver-specific connection parameters used to build the PDO DSN.                              |
| **user**     | no       | Username. May reference environment variable `{env:VAR_NAME}`.                                          |
| **pass**     | no       | Password. May reference environment variable `{env:VAR_NAME}`.                                          |
| **options**  | no       | Object of PDO options. Keys must be integers (`PDO::*`).                                                |
| **dialect**  | no       | SQL dialect override.                                                                                   |
| **init_sql** | no       | Array of SQL statements executed after connection.                                                      |
| **cache**    | no       | Connection-specific cache policy configuration.                                                         |

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

This controls behavior of:

```php
$driver->cache()
```

## Structure

| Key               | Required | Description                              |
| ----------------- | -------- | ---------------------------------------- |
| **defaultPolicy** | yes      | Default cache policy for this connection |
| **namespace**     | no       | Cache namespace prefix                   |
| **tables**        | no       | Per-table cache rules                    |

---

## Default Policy

| Key                    | Required | Description                     |
| ---------------------- | -------- | ------------------------------- |
| **ttlSeconds**         | yes      | Cache lifetime. Must be > 0     |
| **minIntervalSeconds** | no       | Minimum refresh interval        |
| **allowStaleOnError**  | no       | Serve stale data if query fails |
| **maxStaleSeconds**    | no       | Maximum stale window            |

---

## Per-Table Rules

Tables override the default policy.

Example:

```json
"tables": {
  "users": { "ttlSeconds": 30 },
  "audit_log": { "disable": true }
}
```

### Rule Fields

| Key                    | Description                                    |
| ---------------------- | ---------------------------------------------- |
| **disable**            | Disables caching if query references the table |
| **ttlSeconds**         | Overrides TTL                                  |
| **minIntervalSeconds** | Overrides minimum interval                     |
| **allowStaleOnError**  | Overrides stale policy                         |
| **maxStaleSeconds**    | Overrides stale window                         |

### Merge Semantics

When a query references multiple tables:

| Field              | Merge Rule      |
| ------------------ | --------------- |
| ttlSeconds         | **minimum**     |
| minIntervalSeconds | **maximum**     |
| allowStaleOnError  | **logical AND** |
| maxStaleSeconds    | **minimum**     |

---

# Top-Level Cache Store

The root `cache` key defines the **process-wide cache backend**.

If omitted or `driver = off`, caching is disabled.

## Structure

| Key           | Required | Description                          |
| ------------- | -------- | ------------------------------------ |
| **driver**    | yes      | `redis`, `memcached`, `array`, `off` |
| **redis**     | no       | Redis connection options             |
| **memcached** | no       | Memcached servers and options        |
| **array**     | no       | In-memory cache settings             |

---

## Redis Example

```json
"cache": {
  "driver": "redis",
  "redis": {
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
  "driver": "memcached",
  "memcached": {
    "servers": [
      { "host": "localhost", "port": 11211, "weight": 1 }
    ]
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
  "defaults": "sqlite_mem",
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
  "defaults": "app",
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
        "defaultPolicy": {
          "ttlSeconds": 60,
          "minIntervalSeconds": 5
        },
        "tables": {
          "users": { "ttlSeconds": 30 },
          "audit_log": { "disable": true }
        }
      }
    }
  },
  "cache": {
    "driver": "redis",
    "redis": {
      "host": "localhost",
      "port": 6379
    }
  }
}
```

---

# Using Configuration

```php
$driver = Database::connect();                  // default connection (UDA_CONFIG)
$driver = Database::connect('pgsql_test');      // named connection
$driver = Database::connect('/config/app.json'); // explicit config file
$driver = Database::connect('pgsql_test', '/config/app.json');
```

Argument order is **position-independent**.
