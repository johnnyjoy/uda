# Driver runtime decomposition (P1-6)

**Status:** **Done** (phase 4 skipped). **Public API unchanged** — `UDA\Driver` remains the sole PDO owner and the only file that may call `prepare()` / `execute()` (enforced by `tools/check-execution-path.php`).

## Goal

Shrink `src/UDA/Driver.php` by moving **internal** units into `src/UDA/Driver/` without new application-facing types.

## Outcome

| Extracted to `UDA\Driver\*` | Left on `UDA\Driver` |
| --------------------------- | --------------------- |
| `Transport` — engine/transport resolution | `executeInternal`, `normalizeSql`, `executeRead` |
| `Prepared` — per-connection prepared-statement reuse | `isConnectionLost`, `reconnect` |
| `Oracle` + `Oracle\Returning` — DSN/SQL + RETURNING INTO | `transaction()`, savepoint orchestration |

**Skipped extractions** (symbol clutter, no meaningful state): standalone helpers for read-cache bridge, reconnect orchestration, SQL input normalization, and transaction/savepoint loop. Engine savepoint SQL remains on `Driver/SQLServer.php`, `Driver/Sybase.php`, etc.

## Constraints (non-negotiable)

| Rule | Reason |
| ---- | ------ |
| `prepare()` / `execute()` only in `UDA/Driver.php` | Single execution pipeline |
| PDO types only under `UDA/Driver*` | `tools/check-pdo-usage.php` |
| Per-engine classes (`Driver/PostgreSQL.php`, …) stay **DSN + SQL fragments only** | No second PDO owner |
| Move-only phases; `composer check` + full PHPUnit after each slice | No behaviour drift |

## Phases

| Phase | Module | Status |
| ----- | ------ | ------ |
| 1 | `Transport`, `Oracle\Returning` | **Done** |
| 2 | Reconnect-on-failure (`isConnectionLost`, `reconnect`) | **Done** (on `Driver.php`, not extracted) |
| 3 | `Prepared` | **Done** |
| 4 | Transaction / savepoint extraction | **Skipped** — savepoint SQL already on engine classes; `transaction()` stays on `Driver.php` |

## Delegation pattern

`UDA\Driver` keeps existing public/static methods where tests and docs reference them. Extracted units live under `UDA\Driver\*`; `Driver` forwards or owns PDO-bound paths.

Oracle `RETURNING INTO` uses a **closure** into `executeInternal()` so `Oracle/Returning.php` never calls `prepare()` / `execute()` directly. Engine DSN/SQL lives in `Driver/Oracle.php` (`UDA\Driver\Oracle`); `Returning.php` uses leading `\` FQCNs to avoid resolving `Oracle` inside `namespace UDA\Driver\Oracle`.

## Related

- [architecture.md](architecture.md) — canonical pipeline
- [driver.md](driver.md) — engine vs transport vs runtime
