# UDA Security

**Purpose:** Security model: parameterized only, identifier allowlist, no raw interpolation, executor single path.

See [spec.md](spec.md) Security Model.

## Binding and SQL injection avoidance

### 1. Values are always bound (never concatenated)

All runtime values go through **parameters**, not into the SQL string.

- **Sql** holds `sql` (string with `?` or `:name` placeholders) and `params` (array).
- **Executor** is the only place that runs queries. It does:
  - `$stmt = $this->pdo->prepare($sql->sql);`
  - `foreach ($sql->params as $key => $value) { $stmt->bindValue(...); }`
  - `$stmt->execute();`

So user input and any dynamic values must be in `$params`; the SQL string must only contain placeholders. Example:

```php
// Safe: value is bound
Sql::of('SELECT * FROM users WHERE id = ?', [$userId]);

// Unsafe: value is concatenated (do not do this)
Sql::of("SELECT * FROM users WHERE id = $userId", []);  // BAD
```

Builders (Where, SelectBuilder, SelectQuery) never concatenate values: they emit `?` and append to the params array (see `Where::append()`).

### 2. Identifiers are validated and quoted (not user input as SQL)

Table and column names never come from raw user input into the SQL string. They go through **Identifier**:

- **Constructor:** Each segment must match `[a-zA-Z_][a-zA-Z0-9_]*`. Otherwise `QueryException` is thrown (e.g. `"; DROP TABLE users--"` is rejected).
- **quoted():** Segments are passed to the driver’s `quoteIdentifier()` (e.g. double-quote or brackets), then joined with `.`. So even valid-looking names are never interpolated as raw SQL.

So: identifiers are constrained and quoted; only **values** are bound. There is no way in the builder path to turn arbitrary user input into identifier or SQL text.

### 3. Raw SQL you pass to Sql::of()

If you build the SQL string yourself (e.g. for a CTE or complex query), **you** must use placeholders and pass values in the second argument. UDA will only bind that param array; it does not sanitize or parse the string. Rule: **any user or dynamic value must be in the params array, not in the SQL string.**

### 4. Exceptions

QueryException can include `sqlState` and a sanitized SQL snippet (whitespace normalized). Passwords and secrets are never included.

### 5. Tests

SecurityTest verifies that a value that looks like SQL in a parameter is treated as a literal (no execution of that value as SQL).
