# Work Order 004 — Database::connect Architecture

## Authority

Documentation precedence:

1. constitution.md + style-guide.md
2. contract.md
3. spec.md
4. design.md

If code conflicts with docs, code is wrong.

---

## Goal

Finalize `Database::connect(string ...$args): self` as the only public entrypoint and align Database with the constitutional model:

- `Database` is the public database handle
- `Driver` is internal
- connection name and config file are positional-independent
- Config initializes from env or explicit JSON file
- Database stores the selected connection name and binds the internal Driver lazily or at construction, consistent with current design

---

## Scope (Allowed Changes)

Only modify:

- `src/UDA/Database.php`
- `src/UDA/Config.php` (only if needed to support this flow)
- `tests/Database/*`
- `public-api.md`
- `architecture.md`
- `spec.md` (only if wording requires correction)

No other files may be changed.

---

## Requirements

### 1. Public API

`Database::connect(string ...$args): self`

Accepted call shapes:

```php
Database::connect()
Database::connect('analytics')
Database::connect('/tmp/uda.generated.json')
Database::connect('gen_001', '/tmp/uda.generated.json')
Database::connect('/tmp/uda.generated.json', 'gen_001')
````

---

### 2. Argument Parsing

Arguments are position-independent.

Rules:

* if an arg is a JSON file path (`.json` or `is_file($arg)`), it is the config file
* otherwise it is the connection name

If no connection name is supplied, Config resolves the default internally

If no config file is supplied, Config initializes from `UDA_CONFIG`

---

### 3. Database Handle Model

`Database::connect()` must return `Database`, not `Driver`.

Application code must call:

```php
$db = Database::connect();
$db->row(...)
$db->rows(...)
$db->select()
```

Application code must not receive or depend on `Driver`.

---

### 4. Database Construction

Database constructor may accept a nullable connection name internally if needed, but default resolution must occur inside Config, not in Database.

Database stores:

* selected connection name
* bound internal Driver instance

---

### 5. Documentation Alignment

Correct any documentation still claiming:

* Database returns Driver
* Config arrays are accepted
* connectFrom/fromFile/bootArray APIs exist

---

## Tests Required

Create or update tests covering:

### Connect Argument Parsing

* no args → env config + default connection
* named connection only
* config file only
* named connection + config file
* config file + named connection

### Return Type

* `Database::connect()` returns `Database`

### Default Resolution

* default connection comes from Config internally
* missing default throws `ConfigException`

---

## Acceptance Criteria

All of the following must pass:

* Database connect tests
* no docs claim Database returns Driver
* no docs mention config arrays as a supported loading route

---

## Evidence Required

Provide:

* PHPUnit output for `tests/Database/*`
* diff summary by file
* examples of the five supported connect call forms
