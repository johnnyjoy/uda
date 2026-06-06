---
name: uda-repository
description: >-
  Write, structure, and test repository classes that use UDA for data access.
  Covers Link vs Database, the repository class template, hard boundaries (no
  Driver, no PDO), naming conventions, testing with SQLite in-memory, and
  anti-patterns to reject in code review. Use when creating or reviewing any
  data access code built on UDA.
---

# UDA: repository classes

## Choose an entry shape

| Shape | When to use |
|---|---|
| `final class X { use Link; }` | SQL methods live on the class; one connection per class. Recommended for most apps. |
| `Database::connectDefault()` injected via constructor | Few SQL entry points; service container or bootstrap hands out `$db`. |

`Link` memoizes one `Database` instance per class per process. `Database::connectNamed($name)` pools per process; both share the same underlying connection pool.

## Repository class template (Link)

```php
use UDA\Link;

final class Users
{
    use Link;

    // Matches connections.<name> in uda.json. Static = property of the type, not the instance.
    protected static string $connection = 'app';

    public function findById(int $id): ?array
    {
        return $this->row(
            'SELECT id, name, email FROM users WHERE id = :id',
            ['id' => $id],
            ['users']   // table hints — required when cache is enabled
        );
    }

    public function activeEmails(): array
    {
        return $this->values(
            'SELECT email FROM users WHERE active = 1',
            [],
            ['users']
        );
    }

    public function create(string $name, string $email): int
    {
        return $this->exec(
            'INSERT INTO users (name, email) VALUES (:name, :email)',
            ['name' => $name, 'email' => $email],
            ['users']
        );
    }
}
```

Methods are **domain-named** (`findById`, `create`), not generic (`query`, `run`).
SQL lives **in the class**, not in callers.

## Entry shape template (injected Database)

```php
use UDA\Database;

final class OrderService
{
    public function __construct(private Database $db) {}

    public function pendingCount(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM orders WHERE status = :s',
            ['s' => 'pending'],
            ['orders']
        );
    }
}

// Bootstrap / service container:
$db  = Database::connectDefault();
$svc = new OrderService($db);
```

## Hard rules

- Import **only** `UDA\Database` or `UDA\Link` from the `UDA\` namespace.
- **Never** import `UDA\Driver`, `UDA\Cache`, `UDA\Config`, dialect classes, or `PDO`.
- **Never** wrap UDA in a custom "Connection", "DB", or "Executor" that calls `prepare()`/`execute()`.
- **Named parameters only** — `:name` not `?`. See `uda-queries`.
- **No lazy relation loading** — write an explicit JOIN. An extra query per row is an N+1 bug.
- `$connection` must be `static` on `Link` classes — it is a property of the class, not per instance.

## Testing with SQLite in-memory

No daemon required. Use `array` cache store so cache logic runs without Redis.

```json
// tests/uda-test.json
{
  "defaults": { "connection": "test" },
  "connections": {
    "test": {
      "driver": "sqlite",
      "params": { "path": ":memory:" },
      "cache": { "namespace": "TEST", "store": { "type": "array" } }
    }
  }
}
```

```php
// tests/bootstrap.php
putenv('UDA_CONFIG=' . __DIR__ . '/uda-test.json');
UDA\Database::setQueryObserver(null); // silence observer in tests
```

```php
// UsersTest.php
use PHPUnit\Framework\TestCase;
use UDA\Database;

final class UsersTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::connectDefault();
        $this->db->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1
        )');
        $this->db->exec('DELETE FROM users');
    }

    public function test_findById_returns_null_when_not_found(): void
    {
        self::assertNull((new Users())->findById(999));
    }

    public function test_findById_returns_row_when_exists(): void
    {
        $this->db->exec(
            'INSERT INTO users (id, name, email) VALUES (:id, :name, :email)',
            ['id' => 1, 'name' => 'Ada', 'email' => 'ada@test.com']
        );
        $row = (new Users())->findById(1);
        self::assertSame('Ada', $row['name']);
    }
}
```

For real-database integration tests, see `docs/integration/README.md`.

## Anti-patterns (reject in code review)

| Pattern | What to do instead |
|---|---|
| `use UDA\Driver` or `use UDA\Cache` in app code | Only `Database` or `Link` |
| `new PDO(...)` anywhere in application | `Database::connectDefault()` |
| `abstract class BaseRepo { protected $db; }` | Use the `Link` trait |
| `if ($this->cache->has($key)) { ... }` | Table hints drive cache automatically — remove the branch |
| `$repo->getConnection()->prepare(...)` | No access to `Driver` or PDO from application code |
| Extra queries inside a loop | Rewrite as a JOIN or a single `WHERE id IN (...)` |
| `Database::setQueryObserver()` inside a method | Observer belongs in bootstrap, once, not per repository |

## Authority

`docs/building-your-dal.md`, `docs/public-api.md` (§ Link, § Database, § transactions).
