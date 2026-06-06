---
name: uda-deploy
description: >-
  Configure and deploy UDA for staging and production: environment secrets,
  cache backends (Redis, Memcached), process model (FPM vs long-running
  workers), query observer for ops logging, and failure runbooks for cache
  outages and stale reads. Use when writing Dockerfiles, Kubernetes manifests,
  supervisor configs, or on-call runbooks for UDA-backed services.
---

# UDA: production config and deployment

## Config file

```json
{
  "defaults": { "connection": "app" },
  "connections": {
    "app": {
      "driver": "pgsql",
      "params": { "host": "db", "port": 5432, "dbname": "myapp" },
      "user": "{env:DB_USER}",
      "pass": "{env:DB_PASS}",
      "cache": {
        "namespace": "MYAPP",
        "store": { "type": "redis", "host": "redis", "port": 6379 }
      }
    }
  }
}
```

`{env:VAR}` is resolved from environment variables at load time. Runtime code never
sees or re-parses raw secret strings. `namespace` prefixes all cache keys — keep it
short and unique per application.

Config is a **static snapshot after load**. A config change requires a worker restart.

## Point UDA at the config

```bash
# In your manifest, .env, or Dockerfile:
ENV UDA_CONFIG=/app/config/uda.json
```

UDA reads `UDA_CONFIG` once at first connection. Missing or malformed config throws at
connect time, not silently at query time.

## Cache stores

| `store.type` | Extension | Notes |
|---|---|---|
| `off` | — | No cache (default) |
| `array` | — | In-process; dev and PHPUnit only |
| `redis` | `ext-redis` | Recommended for production |
| `memcached` | `ext-memcached` | Supported; requires `getAllKeys()` for prefix flush |

`composer.json` only requires `ext-pdo`. **Your Docker image** must install `ext-redis`
or `ext-memcached` when using those store types. Example:

```dockerfile
RUN docker-php-ext-enable pdo_pgsql \
 && pecl install redis \
 && docker-php-ext-enable redis
```

## Process model

| Runtime | Notes |
|---|---|
| PHP-FPM | One connection pool per worker process; pool resets naturally on restart |
| Octane / RoadRunner / Swoole | Persistent workers; UDA reconnects on dropped connection; no special UDA config needed; use PgBouncer or ProxySQL for high connection counts |

## Query observer (ops logging)

Register **once** at bootstrap — `index.php`, worker boot, or FPM startup script.
Never inside repositories or per-request code. Registering it per request re-registers
it thousands of times.

```php
use UDA\Database;
use UDA\Observer;

// At bootstrap:
Database::setQueryObserver(function (Observer $o): void {
    if ($o->error !== null) {
        error_log(sprintf('[db-error] conn=%s sql=%s err=%s',
            $o->connection, $o->sql, $o->error));
        return;
    }
    if ($o->durationMs >= 500) {
        error_log(sprintf('[slow-sql] %.1fms conn=%s sql=%s',
            $o->durationMs, $o->connection, $o->sql));
    }
    if ($o->retried) {
        error_log('[db-retry] ' . $o->connection);
    }
});
```

Observer fields: `error`, `cacheHit`, `retried`, `durationMs`, `sql`, `connection`.

```php
// In PHPUnit bootstrap — silence the observer so test output is clean:
Database::setQueryObserver(null);
```

See `docs/metrics.md` for the full `Observer` field reference and Prometheus / StatsD examples.

## Multiple connections

```json
{
  "defaults": { "connection": "primary" },
  "connections": {
    "primary": { "driver": "pgsql", "params": { "host": "primary-db", ... }, ... },
    "replica": { "driver": "pgsql", "params": { "host": "replica-db", ... }, ... }
  }
}
```

```php
$write = Database::connectNamed('primary');
$read  = Database::connectNamed('replica');
```

UDA holds one `Database` instance per named connection per process — `connectNamed('primary')`
called twice returns the same instance.

## Deployment checklist

- [ ] `UDA_CONFIG` set in manifest / environment
- [ ] PDO extension installed and matches `driver` (e.g. `ext-pdo_pgsql`)
- [ ] Cache extension installed when `store.type` is `redis` or `memcached`
- [ ] Query observer registered at bootstrap; `null` in PHPUnit bootstrap
- [ ] Config change documented as requiring worker restart in runbook
- [ ] `flushCache()` procedure documented for on-call team

## Failure runbook

### Cache server (Redis / Memcached) unavailable

UDA throws on first cache use — it does not silently fall back to uncached reads.

1. Confirm the cache store is unreachable: `redis-cli ping` or `telnet <host> 6379`.
2. To restore service without a code deploy, set `store.type: off` in `uda.json` and
   restart workers. All queries execute uncached.
3. When the cache store recovers, restore `store.type: redis` and restart workers.

### Stale reads after a migration or bad write

1. Run `$db->flushCache()` for the affected connection. This purges all cached data for
   that connection from the remote store.
2. Alternatively, set `cache.tables.<name>.disable: true` in config and restart — permanent
   until removed.
3. **Do not use `Cache::clear()`** for production purges — it only clears in-process PHP
   handles, not the Redis or Memcached data.

### Dropped connections under load

UDA retries once on a lost connection, **outside open transactions only**. The retry
is logged via the observer (`$o->retried === true`). If retries appear repeatedly:
- Check database max_connections and connection pool sizing.
- Consider PgBouncer (PostgreSQL) or ProxySQL (MySQL/MariaDB) in front of the database.

## Authority

`docs/configuration.md`, `docs/caching.md`, `docs/metrics.md`, `docs/public-api.md` (§ Database).
