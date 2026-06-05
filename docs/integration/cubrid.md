# CUBRID Integration

## Status

**Enforced in CI** on every push and pull request (`.github/workflows/cubrid-integration.yml`).

Full engine matrix: [README.md](README.md).

## Suite

`tests/Cubrid/CubridIntegrationTest.php` with `tests/cubrid-bootstrap.php`.

| Test | Proves |
| ---- | ------ |
| `test_cubrid_read_write_and_named_parameters` | Connect + named CRUD |
| `test_cubrid_transaction_commits` | `Database::transaction()` |
| `test_cubrid_upsert_on_duplicate_key` | `ON DUPLICATE KEY UPDATE` upsert |
| `test_cubrid_pagination_limit_offset` | `limit()` / `offset()` |
| `test_cubrid_insert_returning_throws` | Dialect guardrail (no RETURNING) |

## Command

```bash
CUBRID_HOST=127.0.0.1 \
CUBRID_PORT=33000 \
CUBRID_DB=testdb \
CUBRID_USER=dba \
CUBRID_PASS="" \
vendor/bin/phpunit --bootstrap tests/cubrid-bootstrap.php tests/Cubrid
```

## CI Enforcement

The `cubrid-integration` job starts `cubrid/cubrid:11.4` (requires `--privileged`), PHP 8.2
with `pdo_cubrid`, runs `composer check`, then PHPUnit as above. Default port: 33000.
Default admin credential: user `dba`, empty password.

## Dialect notes

CUBRID uses MySQL-compatible SQL. The `Cubrid` dialect extends `MariaDb` and inherits:

- `LIMIT n OFFSET m` pagination
- `INSERT ... ON DUPLICATE KEY UPDATE` upsert
- Backtick identifier quoting

CUBRID has no `RETURNING` clause. Calling `.returning()` on any builder throws
`QueryException` before reaching PDO.
