# PostgreSQL Integration

## Status

**Enforced in CI** on every push and pull request (`.github/workflows/postgres-integration.yml`).

Full engine matrix: [README.md](README.md).

## Suite

`tests/Postgres/PostgresIntegrationTest.php` with `tests/postgres-bootstrap.php`.

| Test | Proves |
| ---- | ------ |
| `test_postgres_read_write_and_named_parameter_execution` | Connect + named CRUD |
| `test_postgres_transaction_commits` | `Database::transaction()` |
| `test_postgres_insert_returning_row` | `INSERT … RETURNING` via builder |
| `test_postgres_upsert_on_conflict` | `ON CONFLICT` upsert |
| `test_postgres_pagination_limit_offset` | `limit()` / `offset()` on live rows |

## Command

```bash
PGHOST=127.0.0.1 \
PGPORT=5432 \
PGDATABASE=testdb \
PGUSER=postgres \
PGPASSWORD=postgres \
vendor/bin/phpunit --bootstrap tests/postgres-bootstrap.php tests/Postgres
```

## CI Enforcement

The `postgres-integration` job starts PostgreSQL 16 (plus Redis/Memcached for broader
suites when extended), PHP 8.2 + `pdo_pgsql`, `composer check`, then PHPUnit as above.
