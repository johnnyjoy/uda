---
name: uda-config-deploy
description: >-
  Configure UDA for production: JSON config, UDA_CONFIG, secrets, cache backends,
  PHP extensions, FPM vs long-running workers. Use when writing uda.json, Docker
  images, or runbooks for cache/DB failures.
---

# UDA: config and deployment

## Config file

- Single JSON file; path via **`UDA_CONFIG`** or `Database::connect($name, '/path/to.json')`.
- Root must be an object; `connections` non-empty.
- **No DSN strings in JSON** — `driver` + `params` only; UDA builds DSN.

```json
{
  "defaults": { "connection": "app" },
  "connections": {
    "app": {
      "driver": "pgsql",
      "params": { "host": "db", "port": 5432, "dbname": "app" },
      "user": "{env:DB_USER}",
      "pass": "{env:DB_PASS}",
      "cache": {
        "namespace": "APP",
        "store": { "type": "redis", "host": "redis", "port": 6379 }
      }
    }
  }
}
```

Secrets resolve at **ingestion** — runtime code does not re-parse `{env:...}`.

## Cache store types

| `store.type` | Requires | Notes |
|--------------|----------|--------|
| `off` | — | No cache |
| `array` | — | In-process; dev/tests |
| `redis` | `ext-redis` | Fail at first cache use if missing |
| `memcached` | `ext-memcached` | Prefix flush needs `getAllKeys()` |

`composer.json` only requires `ext-pdo`. **Your image** must install redis/memcached extensions when those stores are enabled.

## Process model

| Runtime | Pool / reconnect |
|---------|------------------|
| PHP-FPM (request per process) | New process each request; pool resets |
| Octane / RoadRunner / Swoole | Pool persists; reconnect on failure; consider PgBouncer/ProxySQL at scale |

Config is a **static snapshot** after load — config change = **restart workers**.

## Failure runbook (cache enabled)

1. Redis/Memcached down → connection errors at cache client creation; no silent “cache off” unless you set `store.type: off` in config.
2. Stale reads after migration → `flushCache()` for affected connection or fix table hints on writes.
3. Do not use `Cache::clear()` expecting remote purge — wrong tool.

## CI parity (mirror before merge)

```bash
composer install --no-interaction --prefer-dist
composer check
composer stan
composer test
```

Cert workflows: `.github/workflows/sqlite-cert.yml`, `postgres-cert.yml` — see `docs/certification/`.

## Checklist (new environment)

- [ ] `UDA_CONFIG` set in unit/supervisor/k8s manifest
- [ ] PDO driver extension matches `connections.*.driver`
- [ ] Cache extensions match `store.type` when not `off`/`array`
- [ ] Table-level `cache.tables.*.disable` for audit/high-churn tables if needed
- [ ] Runbook documents `flushCache()` vs disabling cache in config

## Authority

`docs/configuration.md`, `docs/caching.md`, `docs/certification/sqlite.md`, `docs/certification/postgresql.md`.
