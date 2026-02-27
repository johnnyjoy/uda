# UDA Configuration

**Purpose:** Config model: one file, JSON, env UDA_CONFIG, structure, connections, secrets, cache, validation.

## Source

- **Path:** From environment variable `UDA_CONFIG` (file path to JSON), or override via `Database::connect(null, $configFile)`.
- **Format:** JSON only. File must have `.json` extension. Root must be a JSON object. PHP config is not supported.
- **Loading:** `UDA\Config\Snapshot::fromEnv()` reads `UDA_CONFIG` and throws `ConfigException` if unset/empty. `Snapshot::fromFile($path)` loads from a given path. The `UDA\Config` facade wraps a Snapshot and is used by `Database::connect()`.

## Top-level structure

| Key | Required | Description |
|-----|----------|-------------|
| **defaults** | no | String. Name of the default connection. Must equal a key in `connections`. When set, `Database::connect()` with no name uses this connection. |
| **connections** | yes | Object. Map of connection name (string) → connection object. Must be non-empty. Each key must be a non-empty string. |
| **templates** | no | Array. Reserved for mass database patterns. Passed through unchanged in `Snapshot`. |

Validation: missing or empty `connections` throws `ConfigException`. If `defaults` is set, it must exist in `connections`.

## Connection entry

Each value in `connections` must be an object with:

| Key | Required | Description |
|-----|----------|-------------|
| **driver** | yes | String. One of: `sqlite`, `pgsql`, `postgresql`, `mysql`, `sqlsrv`, `dblib`. Case-insensitive. |
| **dsn** | no* | String. Full PDO DSN. If set, used as-is; otherwise `Driver::buildDsn(params)` is used. |
| **params** | no* | Object. Driver-specific params for DSN when `dsn` is not set (e.g. `path`, `host`, `dbname`). See [drivers.md](drivers.md). At least one of `dsn` or `params` required unless the driver provides default params (e.g. SQLite `:memory:`). |
| **user** | no | String. Username. May be `{env:VAR_NAME}` to resolve from environment. |
| **pass** | no | String. Password. May be `{env:VAR_NAME}`. |
| **options** | no | Object. PDO options; keys must be integers (PDO::* constants). |
| **dialect** | no | String. Dialect override name. |
| **init_sql** | no | Array of strings. Safe SQL statements run after connection. Empty strings are ignored. |
| **cache** | no | Object. Optional per-connection cache defaults and per-table rules. See [Connection cache](#connection-cache) below. |

Driver name is validated only when UDA must construct a DSN (dsn absent/null and params absent/empty); if dsn is provided, validation is deferred to bind/connect time.

Secrets: `user` and `pass` support `{env:VAR}`; unresolved env reference throws `ConfigException`.

## Connection cache

When present, `connections.<name>.cache` configures default cache policy and per-table overrides for `$driver->cache(null)` and policy merge when tables are specified.

| Key | Required | Description |
|-----|----------|-------------|
| **defaultPolicy** | yes (if cache present) | Object. Default policy when using `cache(null)` or when no per-call policy is given. |
| **defaultPolicy.ttlSeconds** | yes | Integer. Must be > 0. |
| **defaultPolicy.minIntervalSeconds** | no | Integer. Default 0. Must be >= 0. |
| **defaultPolicy.allowStaleOnError** | no | Boolean. Default false. |
| **defaultPolicy.maxStaleSeconds** | no | Integer. Default 0. Must be >= 0. |
| **namespace** | no | String. Default key namespace for this connection. |
| **tables** | no | Object. Map of table name (string) → table rule object. Table names are normalized (lowercase). |

**Per-table rule** (each value under `tables`):

| Key | Required | Description |
|-----|----------|-------------|
| **disable** | no | Boolean. Default false. If true, any query involving this table has caching disabled (ttl ≤ 0). |
| **ttlSeconds** | no | Integer. Override; merged as MIN across involved tables. |
| **minIntervalSeconds** | no | Integer. Override; merged as MAX. |
| **allowStaleOnError** | no | Boolean. Override; merged with AND. |
| **maxStaleSeconds** | no | Integer. Override; merged as MIN. |

Validation: if `cache` is present, `cache.defaultPolicy` must be an array with `ttlSeconds` integer > 0. Other policy fields validated as above. Invalid shape throws `ConfigException`. See [caching.md](caching.md) for merge semantics and usage.

**Note:** The cache *store* (Redis, Memcached, Array) is configured at the **top level** (see [Top-level cache store](#top-level-cache-store)). This section only sets per-connection policy defaults and table rules.

## Top-level cache store

Optional root key `cache` configures the single process-wide cache store. If missing or `cache.driver` is `off`, no cache store is used (pass-through).

| Key | Required | Description |
|-----|----------|-------------|
| **cache** | no | Object. Cache store and driver options. |
| **cache.driver** | yes (if cache present) | String. One of: `redis`, `memcached`, `array`, `off`. Case-insensitive. |
| **cache.redis** | no | Object. Redis connection: `host`, `port`, `timeout`, `persistent`, `auth`, `db`. |
| **cache.memcached** | no | Object. `servers` (array of `{host, port, weight}`), `options` (int-keyed). |
| **cache.array** | no | Object. `maxItems` (int, default 5000). |

Validation: `cache.driver` = `redis` requires ext-redis; `memcached` requires ext-memcached. Serializer is chosen automatically (igbinary if available, else php serialize).

Override at connect time: `Database::connect(null, null, ['cache' => [...same shape...]])` uses that cache config for that connection only.

## Loading and validation

- **UDA\Config\Loader::load(string $path):** Reads file, decodes JSON, returns array. Throws if path empty, file missing/unreadable, extension not `.json`, invalid JSON, or root not an array.
- **UDA\Config\Validator::validate(array $config):** Validates structure and connections, resolves secrets, returns **Snapshot**. Throws **ConfigException** on any validation failure. No raw config escapes; only validated connection config arrays and value objects (ConnectionCacheConfig, etc.).
- **Snapshot::fromEnv():** Uses `getenv('UDA_CONFIG')` as path; throws if unset or empty, then loads and validates.
- **Snapshot::fromFile(string $path):** Loads and validates from the given path.
- **Database::connect(?string $connectionName, ?string $configFile, ?array $options = null):** Boots **Config** (via `Config::boot($configFile)` or `Config::boot()`) which uses Snapshot::fromFile/fromEnv internally. Optional `$options['cache']` overrides top-level cache config for this connect. Returns a bound **Driver**.
- **UDA\Core\ConfigLoader::load(string $path):** Loads and validates the JSON once per canonical path and caches it. Call `ConfigLoader::clearCache($path)` to force a reload during deployment or testing.

## Example (minimal)

```json
{
  "defaults": "sqlite_mem",
  "connections": {
    "sqlite_mem": {
      "driver": "sqlite",
      "params": { "path": ":memory:" }
    },
    "pgsql_test": {
      "driver": "pgsql",
      "params": { "host": "localhost", "dbname": "test" },
      "user": "u",
      "pass": "p"
    }
  }
}
```

## Example (with connection cache)

```json
{
  "defaults": "mem",
  "connections": {
    "mem": {
      "driver": "sqlite",
      "params": { "path": ":memory:" },
      "cache": {
        "defaultPolicy": {
          "ttlSeconds": 60,
          "minIntervalSeconds": 5,
          "allowStaleOnError": false,
          "maxStaleSeconds": 0
        },
        "namespace": "app1",
        "tables": {
          "users": { "disable": false, "ttlSeconds": 30 },
          "audit_log": { "disable": true }
        }
      }
    }
  }
}
```

## Using config

```php
$driver = Database::connect();                        // default connection (uses UDA_CONFIG)
$driver = Database::connect('pgsql_test');            // named connection
$driver = Database::connect(null, '/path/to/config.json');  // config file override
```

See [spec.md](spec.md) for canonical rules and [caching.md](caching.md) for cache behavior.
