---
name: uda-dal-layer
description: >-
  Build application database abstraction on UDA using Database or Link only.
  Use when creating repository classes, DAL modules, or wiring SQL behind a
  class boundary. Rejects Driver, PDO, ORM patterns, and second execution paths.
---

# UDA: database abstraction layer (application)

UDA is **not** an ORM. Your layer is **SQL + thin classes** that call UDA.

## Hard rules

- Import **`UDA\Database`** for scripts/services that own the handle directly.
- Import **`UDA\Link`** only when SQL lives inside dedicated classes (repositories).
- **Never** import `UDA\Driver`, `UDA\Cache`, `UDA\Config`, dialect classes, or `PDO`.
- **Never** wrap UDA in a custom “Connection” or “Executor” that calls `prepare()`/`execute()`.
- One **configured connection name** per repository class (`protected static string $connection` with `Link`).

## Choose an entry shape

| Shape | Use when |
|-------|----------|
| `Database::connect(...)` in a factory/bootstrap | Few entrypoints; procedural or service container hands out `$db` |
| `final class X { use Link; }` | Many SQL methods; SQL stays on the class; one connection per class |

`Link` memoizes one `Database` per class. `Database::connect($name)` pools per process.

## Repository class template (`Link`)

```php
use UDA\Link;

final class Users
{
    use Link;

    protected static string $connection = 'app';

    public function findName(int $id): ?string
    {
        $v = $this->value(
            'SELECT name FROM users WHERE id = :id',
            ['id' => $id],
            ['users']
        );
        return is_string($v) ? $v : null;
    }
}
```

- Methods are **domain-named** (`findName`, `create`), not `getDb()->query`.
- SQL strings live **in the class**, not scattered via string builders in callers.
- `$connection` is **`static`** — connection is a property of the type, not the instance.

Reference fixture: `tests/Fixtures/TraitUserRepository.php`.

## Anti-patterns (reject in review)

| Pattern | Why wrong |
|---------|-----------|
| `extends` a UDA type | No public base classes |
| Lazy-load relations in PHP | N+1 + hidden SQL; write explicit queries |
| Generic `query($sql)` on a base repository | Bypasses table hints and safety conventions |
| Passing `Driver` or `PDO` into constructors | Breaks single pipeline |
| Caching in application (`if ($cached)`) | UDA cache is config-driven and transparent on reads |

## Layering (allowed)

```text
HTTP / CLI / job
    → application service (optional)
    → your repository (Link or injected Database)
    → UDA\Database
    → (internal) Driver → PDO
```

Your layer may **not** insert another SQL execution tier below `Database`.

## Checklist (new repository)

- [ ] Only `Database` or `Link` in `use` statements from `UDA\`
- [ ] Connection name matches `connections.<name>` in JSON config
- [ ] Every read/write that participates in cache lists **table hints** when caching is on (see `uda-sql-and-cache`)
- [ ] Named parameters only (`:id`), never `?`
- [ ] Transactions use `$db->transaction(fn (Database $db) => ...)` or `$this->transaction(...)` — callback gets `Database`, not `Driver`

## Authority

`docs/getting-started.md`, `docs/public-api.md`, `docs/architecture.md`.
