---
name: uda-start
description: >-
  Get started with UDA: install the package, write uda.json config, connect to
  a database, and execute the first query. Use for new UDA projects, setup
  questions, or verifying a working connection. Read this before the other UDA
  skills.
---

# UDA: getting started

## Install

```bash
composer require johnnyjoy/uda
```

PHP ≥ 8.2. Install the PDO extension for your database engine:

| Engine | Extension |
|---|---|
| PostgreSQL | `ext-pdo_pgsql` |
| MariaDB / MySQL | `ext-pdo_mysql` |
| SQL Server | `ext-pdo_sqlsrv` or `ext-pdo_dblib` |
| SQLite | `ext-pdo_sqlite` |
| Oracle | `ext-pdo_oci` |
| DB2 | `ext-pdo_ibm` |
| Firebird | `ext-pdo_firebird` |
| CUBRID | `ext-pdo_cubrid` |

## Config file

Create `uda.json` anywhere on disk:

```json
{
  "defaults": { "connection": "app" },
  "connections": {
    "app": {
      "driver": "pgsql",
      "params": { "host": "db", "port": 5432, "dbname": "myapp" },
      "user": "{env:DB_USER}",
      "pass": "{env:DB_PASS}"
    }
  }
}
```

`{env:VAR}` values are resolved from environment variables at load time.
The running application never sees or re-parses raw secret strings.

Tell UDA where the config lives:

```bash
export UDA_CONFIG=/path/to/uda.json
```

## Connect and query

```php
use UDA\Database;

// Reads UDA_CONFIG env var:
$db = Database::connectDefault();

// Or explicit path + connection name:
$db = Database::connectWithConfig('/path/to/uda.json', 'app');

// First real query:
$user = $db->row(
    'SELECT id, name FROM users WHERE id = :id',
    ['id' => 1],
    ['users']
);
```

## Verify the connection works

```bash
php -r "
require 'vendor/autoload.php';
\$db = UDA\Database::connectDefault();
var_dump(\$db->value('SELECT 1'));
"
```

Expected output: `int(1)` (or `string(1) "1"` on some engines).

## What to read next

- **Writing repository classes:** `uda-repository` skill
- **Query rules — params, terminators, hints, transactions:** `uda-queries` skill
- **Production config, cache, deployment:** `uda-deploy` skill
- **Full config reference:** `docs/configuration.md`
- **Public API:** `docs/public-api.md`
