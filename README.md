# Universal Data Abstractor (UDA)

PHP 8.2+ library for **your** database abstraction layer in **web apps and
services** — explicit SQL, named parameters, optional builders, transparent read
cache. **Not an ORM.**

Typical deploy: **php-fpm** (or equivalent request-bound PHP) behind nginx or Apache,
often in a **Docker/Kubernetes** image. CLI and cron use the same `uda.json`.
Long-running workers (Octane, RoadRunner, Swoole) are supported with extra rules —
see [getting-started.md](docs/getting-started.md).

**GitHub:** [github.com/johnnyjoy/uda](https://github.com/johnnyjoy/uda)

```bash
composer require johnnyjoy/uda
```

## Quick start (web service + `Link`)

**1. Config** — mount `uda.json` in the image or VM; set `UDA_CONFIG`. Use your DB
service hostname (Compose/Kubernetes), not `localhost`, inside the app container:

```json
{
  "defaults": { "connection": "app" },
  "connections": {
    "app": {
      "driver": "pgsql",
      "params": {
        "host": "postgres",
        "port": 5432,
        "dbname": "myapp"
      },
      "user": { "env": "DB_USER" },
      "pass": { "env": "DB_PASS" }
    }
  }
}
```

```dockerfile
# Dockerfile (excerpt)
ENV UDA_CONFIG=/etc/app/uda.json
COPY uda.json /etc/app/uda.json
```

**2. Bootstrap** — once per request in php-fpm (e.g. `public/index.php` before
routing). Optional ops logging via `setQueryObserver()` — see
[metrics.md](docs/metrics.md).

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// UDA reads UDA_CONFIG; repositories use Link or injected Database below.
```

**3. Repository** — SQL stays in small classes; HTTP controllers call these, not
`Database` directly everywhere:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use UDA\Link;

final class UserRepository
{
    use Link;

    protected static string $connection = 'app';

    public function findName(int $id): ?string
    {
        $name = $this->value(
            'SELECT name FROM users WHERE id = :id',
            ['id' => $id],
            ['users']
        );

        return is_string($name) ? $name : null;
    }

    public function create(int $id, string $name): void
    {
        $this->exec(
            'INSERT INTO users (id, name) VALUES (:id, :name)',
            ['id' => $id, 'name' => $name],
            ['users']
        );
    }
}
```

```php
// Controller (conceptual)
$user = (new UserRepository())->findName($id);
```

- **`Link`** — one connection name per repository class; fits php-fpm request flow.
- **Named params only** (`:id`) — positional `?` is rejected.
- **Table hints** `['users']` — required for correct cache behaviour when caching is on.

Local dev with SQLite only: change `driver` / `params` — see [engines.md](docs/engines.md).

**Next:** [Build your DAL on UDA](docs/building-your-dal.md) · deploy skill:
[skills/uda-config-deploy/SKILL.md](skills/uda-config-deploy/SKILL.md)

## Inject `Database` instead of `Link`

Use when a service container or front controller already owns the handle:

```php
use UDA\Database;

$db = Database::connectDefault(); // UDA_CONFIG + default connection
$user = $db->row(
    'SELECT id, name FROM users WHERE id = :id',
    ['id' => 42],
    ['users']
);
```

Same pipeline as `Link`. See [building-your-dal.md](docs/building-your-dal.md#database-or-link).

## Documentation (users)

| Doc | You need it when… |
| --- | ----------------- |
| [**building-your-dal.md**](docs/building-your-dal.md) | Layer shape, FPM vs workers, repositories, rules |
| [**getting-started.md**](docs/getting-started.md) | Builders, transactions, reconnect, Octane caveats |
| [**engines.md**](docs/engines.md) | `uda.json` per database (Postgres, SQL Server, Firebird, …) |
| [**patterns.md**](docs/patterns.md) | Repository recipes — pagination, filters, joins |
| [**configuration.md**](docs/configuration.md) | Cache stores, env secrets, full schema |
| [**public-api.md**](docs/public-api.md) | Method reference |

Full map: [**docs/README.md**](docs/README.md).

## Agent skills (any AI tool that loads skills)

[`skills/`](skills/README.md) — agent-agnostic checklists (DAL layout, SQL/cache,
**config & deploy** for containers/FPM, PR gates). Example:
`skills/uda-dal-layer/SKILL.md`, `skills/uda-config-deploy/SKILL.md`.

## Supported engines

SQLite, PostgreSQL, MariaDB/MySQL, SQL Server, Oracle, DB2, Firebird —
[engines.md](docs/engines.md). Sybase ASE in code; not in upstream CI (license).
Maintainer CI matrix: [docs/integration/README.md](docs/integration/README.md).

## Contributing & license

- **Contributing:** [CONTRIBUTING.md](CONTRIBUTING.md) — changes to UDA itself; see [docs/README.md#for-contributors](docs/README.md#for-contributors)
- **License:** [LICENSE.md](LICENSE.md) (MIT)
- **Security:** [SECURITY.md](SECURITY.md)
- **Changelog:** [CHANGELOG.md](CHANGELOG.md)
