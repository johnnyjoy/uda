# UDA Security

**Purpose:** Define the security model of UDA.

UDA’s security posture is based on four core rules:

1. **All runtime values are parameterized.**
2. **Identifiers are validated before inclusion in SQL.**
3. **SQL execution occurs through exactly one execution path.**
4. **Configuration and infrastructure are validated at ingestion time.**

UDA is designed so that **SQL injection, parameter confusion, and driver misuse are structurally difficult to introduce.**

See: `spec.md` → Security Model.

---

# 1. Parameterized Queries (Mandatory)

All runtime values must be passed as **named parameters**.

SQL strings must never contain interpolated values.

### Correct

```php
$row = $db->row(
    'SELECT * FROM users WHERE id = :id',
    ['id' => $userId]
);
```

### Incorrect

```php
// BAD — value interpolated into SQL
$db->row("SELECT * FROM users WHERE id = $userId");
```

UDA only binds values that appear in the **params array**.

SQL strings must contain only:

* SQL syntax
* named parameter placeholders

---

## Named Parameters Only

The public API supports **named parameters only**.

Positional placeholders (`?`) are not part of the public API.

This rule prevents subtle parameter ordering bugs and improves readability.
`Driver::executeInternal()` enforces this rule and throws a `QueryException`
if a positional placeholder is detected before the statement is prepared.

---

# 2. Single Execution Path

All SQL execution occurs through one internal pipeline:

```
Repository
   ↓
Database
   ↓
Driver
   ↓
PDO
```

The **Driver** is the only place where SQL reaches PDO.

Internally it performs:

```php
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
```

No other component may:

* call `prepare`
* call `execute`
* bind parameters
* access PDO

This guarantees that parameter binding and execution are always performed safely.

---

# 3. Identifier Safety

Values and identifiers have different security rules.

| Type                             | Handling           |
| -------------------------------- | ------------------ |
| Values                           | bound parameters   |
| Identifiers (table/column names) | validated + quoted |

Identifiers must never come directly from user input.

When dynamic identifiers are required (e.g., sorting), they must be validated against an allowlist.

Example:

```php
$allowed = ['name','created_at'];

$sort = in_array($sort, $allowed, true)
    ? $sort
    : 'name';

$rows = $db->select()
    ->from('users')
    ->orderBy($sort)
    ->rows();
```

UDA provides helpers to make this safe.

---

# 4. Safe SQL Fragment Helpers

When raw SQL is necessary, UDA provides helpers that safely construct dynamic SQL fragments.

| Helper             | Purpose                             |
| ------------------ | ----------------------------------- |
| `q()`              | validate + quote identifier         |
| `orderByAllowed()` | allowlisted ORDER BY                |
| `limitOffset()`    | safe pagination fragments           |
| `inList()`         | safe IN-list placeholder generation |

Example:

```php
$order = $db->orderByAllowed($col, ['name','created_at']);
$page  = $db->limitOffset(20,0);

$sql = "SELECT * FROM users WHERE active = :a $order " . $page->sql;

$rows = $db->rows($sql, ['a' => 1] + $page->params);
```

These helpers allow safe dynamic SQL **without requiring developers to build SQL strings manually**.

---

# 5. Raw SQL Responsibility

UDA allows raw SQL through:

```php
Sql::of($sql, $params, $tables)
```

UDA does **not** parse or sanitize SQL strings.

Therefore:

* all dynamic values must appear in `$params`
* SQL strings must not contain interpolated values

Example:

```php
$q = Sql::of(
    'SELECT * FROM users WHERE created_at > :d',
    ['d' => $date],
    ['users']
);

$rows = $db->rows($q);
```

The optional table list enables correct cache invalidation without SQL parsing.

---

# 6. Exception Safety

When SQL errors occur, UDA throws `QueryException`.

A QueryException may include:

* SQLSTATE
* sanitized SQL snippet
* driver error message

It must **never include**:

* passwords
* connection secrets
* full raw queries with sensitive data

---

# 7. Inspection Hygiene

V1 exposes only small debugging helpers: `lastSql()`, `lastParams()`, and
builder `toSql()`. These helpers are useful during development, but application
code must still treat SQL text and parameter values as sensitive operational
data.

Do not write raw parameter values, credentials, or full request-derived SQL to
untrusted logs.

---

# 7. Configuration Security

Configuration is validated at ingestion time.

Requirements:

* configuration must come from JSON
* DSN strings are never supplied by application code
* environment variables may supply credentials
* connections must be validated before runtime

This prevents configuration-driven injection or runtime misconfiguration.

---

# 8. Cache Safety

The cache subsystem does not introduce new SQL execution paths.

All cached queries still originate from the same execution pipeline.

Cache stores:

* result payload
* metadata (TTL, table timestamps)

Cache never executes SQL and never modifies query text.

---

# 9. Test Enforcement

Security invariants are enforced through automated tests.

Examples include:

| Test                           | Purpose                                                 |
| ------------------------------ | ------------------------------------------------------- |
| Parameter injection test       | ensure injected SQL inside params is treated as literal |
| Duplicate execution path test  | ensure only one prepare/execute implementation exists   |
| Raw SQL table attribution test | ensure invalidation rules remain correct                |
| Forbidden placeholder test     | ensure positional `?` placeholders are rejected         |

Security failures must be detectable by automated tests.

---

# Security Philosophy

UDA security is based on **structural guarantees rather than developer discipline**.

Developers should not need to remember security rules.

Instead:

* values are always bound
* identifiers are validated
* execution path is centralized
* unsafe patterns are difficult to express

If an application can accidentally create SQL injection through the normal API, the system has failed its design goals.
