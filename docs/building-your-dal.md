# Build your database abstraction on UDA

UDA is the **engine under your layer**. You write **repository-style classes**
(or thin modules) that own SQL. UDA runs it through one pipeline (`Database` →
internal `Driver` → PDO). You do **not** import `Driver`, `PDO`, or dialect classes.

Your methods are domain-named; SQL stays visible. No generated entity graph, lazy
relations, or magic `find()` in PHP.

---

## What you are building

```text
Your application
    → (optional) service / controller
    → your repository or data class   ← you write this
    → UDA\Database                      ← only UDA type you import
    → (internal) Driver → PDO
```

Your job: **one clear place per table or domain** for SQL. UDA’s job: connect,
bind named parameters, dialect-aware builders, cache, transactions.

---

## Where PHP runs (web service, container, worker)

Most integrators use UDA in a **web service**, not in isolation:

| Runtime | Typical setup | UDA notes |
| ------- | ------------- | --------- |
| **php-fpm** | nginx/Apache → FPM pool; often in Docker/K8s | Default path. Set `UDA_CONFIG` in the image. DB `host` = service name (`postgres`, not `127.0.0.1`). Pool resets each request. |
| **CLI / cron** | Same image or sidecar | Same `uda.json`; connect, run, exit. |
| **Octane / RoadRunner / Swoole** | Long-lived worker | Pooled `Database` per worker — read [getting-started.md](getting-started.md) and [architecture.md](architecture.md) § concurrency. |

Wire `Database::setQueryObserver()` **once** in front-controller bootstrap (`public/index.php`)
or worker boot — not inside each repository. Deploy patterns:
[skills/uda-config-deploy/SKILL.md](../skills/uda-config-deploy/SKILL.md).

---

## Pick a shape: `Database` or `Link`

| Shape | Import | Use when |
| ----- | ------ | -------- |
| **Inject `Database`** | `UDA\Database` | Bootstrap creates `$db`; pass into constructors; few entrypoints |
| **`Link` trait** | `UDA\Link` | Many methods on a dedicated class; SQL stays on that class |

Both call the **same** execution path. Do not mix `Driver` or a custom wrapper.

### `Link` — one class, one connection name

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use UDA\Link;

final class OrderRepository
{
    use Link;

    /** Must match a key under connections in uda.json */
    protected static string $connection = 'app';

    public function findStatus(int $orderId): ?string
    {
        $status = $this->value(
            'SELECT status FROM orders WHERE id = :id',
            ['id' => $orderId],
            ['orders']
        );

        return is_string($status) ? $status : null;
    }

    public function markShipped(int $orderId): void
    {
        $this->transaction(function (): void {
            $this->exec(
                'UPDATE orders SET status = :status WHERE id = :id',
                ['id' => $orderId, 'status' => 'shipped'],
                ['orders']
            );
            $this->exec(
                'INSERT INTO order_events (order_id, event) VALUES (:oid, :ev)',
                ['oid' => $orderId, 'ev' => 'shipped'],
                ['order_events']
            );
        });
    }
}
```

`$connection` is **`static`**: the connection is a fact about the **class**, not
each instance. `Link` memoizes one `Database` per class in the process.

Reference implementation in the repo: `tests/Fixtures/TraitUserRepository.php`.

### Inject `Database` — constructor wiring

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use UDA\Database;

final class InvoiceRepository
{
    public function __construct(
        private Database $db,
    ) {}

    public function totalForCustomer(int $customerId): ?float
    {
        $total = $this->db->value(
            'SELECT SUM(amount) FROM invoices WHERE customer_id = :cid',
            ['cid' => $customerId],
            ['invoices']
        );

        return is_numeric($total) ? (float) $total : null;
    }
}
```

Bootstrap (conceptual):

```php
$db = Database::connectDefault();
$invoices = new InvoiceRepository($db);
```

Use **`Database::connectNamed('reporting')`** for a second config connection when
you have a read replica or separate database (see [Multi-connection](#multi-connection)).

---

## Configure once: `uda.json`

```json
{
  "defaults": {
    "connection": "app"
  },
  "connections": {
    "app": {
      "driver": "sqlite",
      "params": {
        "path": "/var/app/storage/app.sqlite"
      }
    }
  }
}
```

```bash
export UDA_CONFIG=/etc/app/uda.json
```

Per-engine `params` examples: [**engines.md**](engines.md). Full schema:
[**configuration.md**](configuration.md).

---

## Rules (non-negotiable)

### 1. Named parameters only

```php
// OK
$this->row('SELECT * FROM users WHERE id = :id', ['id' => $id], ['users']);

// Rejected before PDO
$this->rows('SELECT * FROM users WHERE id = ?', [$id], ['users']);
```

### 2. Table hints on raw SQL when cache is enabled

Third argument: `['table_name', ...]`. Builders pick up tables from `from()` / `into()`.

```php
$this->rows('SELECT id, name FROM users WHERE active = :a', ['a' => 1], ['users']);
```

If caching is off, hints still help invalidation when you turn cache on later.
Details: [**caching.md**](caching.md).

### 3. Pick the right terminator

| Need | Method |
| ---- | ------ |
| Zero or one row as assoc | `row()` |
| One scalar | `value()` |
| Many rows | `rows()` |
| Insert/update/delete count | `exec()` |
| Stream large result sets | `each()` |

Do not use `rows()` when you only need one row.

### 4. Never import infrastructure below `Database`

| Allowed | Forbidden in application code |
| ------- | ----------------------------- |
| `UDA\Database` | `UDA\Driver`, `UDA\Driver\*` |
| `UDA\Link` | `UDA\Cache`, `UDA\Config`, `PDO` |

### 5. Transactions use `Database`, not PDO

```php
$this->transaction(function (): void {
    $this->exec('...', [...], ['users']);
});
```

The callback receives the same handle semantics as `Link` documents in
[**getting-started.md**](getting-started.md).

---

## Fluent builders (optional)

Same class, builder entrypoints:

```php
public function listActive(): array
{
    return $this->select('id', 'name')
        ->from('users')
        ->where('active', 1)
        ->orderBy('name')
        ->rows();
}
```

`toSql()` is for debugging only — it does not execute.

More recipes: [**patterns.md**](patterns.md) (pagination, filters, joins).

---

## Multi-connection

**Option A — different repository classes, different `Link` connection names:**

```php
final class AppUsers
{
    use Link;
    protected static string $connection = 'app';
}

final class ReportingMetrics
{
    use Link;
    protected static string $connection = 'reporting';
}
```

**Option B — inject the right `Database`:**

```php
$app = Database::connectNamed('app');
$reporting = Database::connectNamed('reporting');
```

`Database::connect()` pools **one instance per connection name** per PHP process.

---

## Bootstrap checklist

- [ ] `composer require johnnyjoy/uda`
- [ ] `uda.json` mounted or copied into the app image; `UDA_CONFIG` set in the environment
- [ ] DB `params.host` points at the **database service** when the app runs in a container
- [ ] php-fpm (or worker) bootstrap loads autoload; repositories called from HTTP layer
- [ ] Repositories use only `Database` or `Link`
- [ ] Connection names in code match JSON keys
- [ ] Query observer wired **once** at app bootstrap if you need metrics — not per repository ([**metrics.md**](metrics.md))

---

## Anti-patterns

| Do not | Why |
| ------ | --- |
| Base `Repository` with `query($sql)` | Hides hints and review; write explicit methods |
| Lazy-load relations in PHP | N+1; write explicit SQL |
| App-level `if ($cached)` around reads | UDA cache is config-driven on the read path |
| Second executor that calls `prepare()` | Breaks single pipeline |
| `extends` anything from `UDA\` | No public base classes for apps |
| Log every query inside each method | Use `Database::setQueryObserver()` once in bootstrap |

---

## Where to go next

| Topic | Doc |
| ----- | --- |
| More repository examples | [patterns.md](patterns.md) |
| Connect variants, sharp edges | [getting-started.md](getting-started.md) |
| Engine config snippets | [engines.md](engines.md) |
| API reference | [public-api.md](public-api.md) |
| Agent checklists | [skills/README.md](../skills/README.md) |

**Contributors** changing UDA itself: [docs/README.md#for-contributors](README.md#for-contributors) — not this page.
