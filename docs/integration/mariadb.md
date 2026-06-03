# MariaDB Integration

## Status

**Enforced in CI** on every push and pull request (`.github/workflows/mariadb-integration.yml`).

Full engine matrix: [README.md](README.md).

## Suite

`tests/MariaDb/MariaDbIntegrationTest.php` with `tests/mariadb-bootstrap.php`.

| Test | Proves |
| ---- | ------ |
| `test_mariadb_read_write_and_named_parameters` | Connect + named CRUD |
| `test_mariadb_transaction_commits` | `Database::transaction()` |
| `test_mariadb_upsert_on_duplicate_key` | `ON DUPLICATE KEY UPDATE` upsert |
| `test_mariadb_pagination_limit_offset` | `limit()` / `offset()` |
| `test_mariadb_insert_returning_throws_before_pdo` | Dialect guardrail (no RETURNING) |

## Command

```bash
MARIADB_HOST=127.0.0.1 \
MARIADB_PORT=3306 \
MARIADB_DATABASE=testdb \
MARIADB_USER=root \
MARIADB_PASSWORD=root \
vendor/bin/phpunit --bootstrap tests/mariadb-bootstrap.php tests/MariaDb
```

## CI Enforcement

The `mariadb-integration` job starts `mariadb:11`, PHP 8.2 + `pdo_mysql`, `composer check`,
then PHPUnit as above.
