# Driver runtime decomposition (P1-6)

**Status:** In progress. **Public API unchanged** — `UDA\Driver` remains the sole PDO owner and the only file that may call `prepare()` / `execute()` (enforced by `tools/check-execution-path.php`).

## Goal

Shrink `src/UDA/Driver.php` by moving **internal** units into `src/UDA/Driver/` without new application-facing types.

## Constraints (non-negotiable)

| Rule | Reason |
| ---- | ------ |
| `prepare()` / `execute()` only in `UDA/Driver.php` | Single execution pipeline |
| PDO types only under `UDA/Driver*` | `tools/check-pdo-usage.php` |
| Per-engine classes (`Driver/PostgreSQL.php`, …) stay **DSN + SQL fragments only** | No second PDO owner |
| Move-only phases; `composer check` + full PHPUnit after each slice | No behaviour drift |

## Target modules

| Module | Responsibility | PDO? | Phase |
| ------ | -------------- | ---- | ----- |
| **Transport** | `engineKey`, `transportKey`, `defaultTransport`, `resolve` | No | **1 — done** |
| **Oracle\Returning** | `RETURNING INTO` output binds via callback into `executeInternal` | Via callback only | **1 — done** |
| **Reconnect** | `isReconnectableConnectionLost`, `reconnect`, LRU clear | Yes — stays in `Driver.php` until guardrail extended | 2 |
| **Execute** | `executeInternal`, prepared-statement LRU, `normalizeSql` | Yes — stays in `Driver.php` for now | 3 |
| **ReadCache** | `executeRead` bridge to `UDA\Cache` | No PDO in cache itself | 3 |
| **Transaction** | `transaction()`, savepoint SQL resolution | Uses `$pdo->beginTransaction` / `exec` — stays in `Driver.php` for now | 4 |

Phases 2–4 are planned; phase 1 landed the two slices with **zero** PDO call-site changes.

## Delegation pattern

`UDA\Driver` keeps existing public/static methods where tests and docs reference them. Implementations live in `UDA\Driver\*` helpers; `Driver` forwards.

Oracle `RETURNING INTO` uses a **closure** into `executeInternal()` so `Oracle/Returning.php` never calls `prepare()` / `execute()` directly. Engine DSN/SQL lives in `Driver/Oracle.php` (`UDA\Driver\Oracle`); `Returning.php` uses leading `\` FQCNs to avoid resolving `Oracle` inside `namespace UDA\Driver\Oracle`.

## Related

- [architecture.md](architecture.md) — canonical pipeline
- [driver.md](driver.md) — engine vs transport vs runtime
