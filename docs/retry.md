# UDA Retry Policy

UDA ships with an opt-in retry layer that lives between `Database` and the driver. When enabled it detects transient errors (deadlocks, lock wait timeouts, transport glitches), backs off using deterministic strategies, and surfaces detailed trace/replay metadata without touching the fast path when disabled.

> Retry is off by default. You **must** configure it manually per `Database` instance when you want it.

---

## Components

| Class | Purpose |
| --- | --- |
| `RetryConfig` | Immutable settings: enable flag, attempt count, backoff strategy (`fixed`, `linear`, `exponential`), base/max delays (milliseconds), jitter toggle, write/transaction policies |
| `RetryPolicy` | Execution wrapper that enforces eligibility, computes backoff, emits trace events, and records metadata |
| `TransientErrorClassifier` | Detects retryable exceptions using driver hints, SQLSTATE tables, and message heuristics |

`Database::setRetryPolicy(?RetryPolicy $policy)` installs the policy. Passing `null` removes it and returns the hot path to zero overhead.

---

## Enabling Retries

```php
use UDA\Retry\RetryConfig;
use UDA\Retry\RetryPolicy;
use UDA\Retry\TransientErrorClassifier;

$db = Database::connect('analytics');

$policy = new RetryPolicy(
    new RetryConfig(
        enabled: true,
        maxAttempts: 3,
        baseDelayMs: 10,
        maxDelayMs: 500,
        retryWrites: false,
        retryInTransactions: false,
        jitter: true,
    ),
    new TransientErrorClassifier(),
    sleeper: static function (int $milliseconds): void {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
);

$db->setRetryPolicy($policy);
```

* `maxAttempts` counts the first try. With `3` the policy runs at most three executions.
* `baseDelayMs` and `strategy` control the backoff curve; delays are capped by `maxDelayMs` after jitter.
* Provide a custom sleeper in tests to avoid real delays (e.g., `static function (): void {}`).

---

## Eligibility Rules

Retries run only when **all** conditions below are satisfied:

1. Retry policy enabled and attached to `Database`.
2. Operation is considered safe or explicitly opted-in.
3. Query is **not** executing inside an open transaction unless `retryInTransactions=true`.
4. The last attempt threw a transient exception (classifier and optional driver hook must agree).
5. Attempt count remains below `maxAttempts`.

### Safe-by-default operations

| Operation | Default |
| --- | --- |
| `rows`, `row`, `value`, `values`, `list` | ✅ |
| `each` | ✅ (only if no rows yielded yet) |
| `explain`, `explainAnalyze` | ✅ |
| `exec`, `returning`, `transaction` bodies | ❌ (require explicit opt-in) |

`each()` carries a progress flag. Once the callback has processed at least one row the next failure surfaces immediately to avoid re-invoking user code.

### Writes and transactions

* `retryWrites=false` blocks all write retries even if a builder opts in.
* Set `retryWrites=true` **and** opt-in the specific statement (builder `allowRetry()` or custom `SqlMessage` metadata) to retry inserts/updates/deletes.
* `retryInTransactions=false` skips retries when inside `Database::transaction()`. Enabling it is discouraged because transaction semantics depend on backend guarantees.

---

## Builder Overrides

Every immutable builder exposes opt-in/out helpers:

```php
$select = $db->select()->from('orders')->allowRetry();
$insert = $db->insert()->into('orders')->values($row)->noRetry();

$raw = Sql::of('CALL export_reports()')->allowRetry();
```

* `allowRetry()` sets `SqlMessage->retryAllowed = true`.
* `noRetry()` hard-disables retries for that query.
* Leaving the flag `null` uses the operation defaults listed earlier.

This metadata flows through plan caching and Database execution so the policy receives the exact intent for each statement.

---

## Tracing, Replay, and Metrics

Every retry attempt emits a `QueryTrace` with `traceType = retry_attempt`. Final traces include:

* `retryCount` – total attempts
* `retried` – boolean
* `finalFailure` – true only if the final execution threw
* `retryReasons` – ordered list of classifier decisions (e.g., `transient_error`, `max_attempts`, `transaction_blocked`)

Replay snapshots store the same metadata so downstream analysis (replayers, metrics pipelines) can correlate retries with captured queries.

Include these fields in your observability dashboards so teams can spot hot loops, excessive max-attempt failures, and accidental write retries.

---

## Operational Guidance

* Start with reads only. Opt-in writes gradually and only for idempotent operations.
* Keep `maxAttempts` low—most transient failures resolve within two attempts.
* Monitor `retryReasons` for `max_attempts` spikes; they usually indicate systemic issues (overloaded replica, sustained deadlock patterns).
* Leave `retryInTransactions` off unless your backend guarantees fully idempotent transaction blocks.
* Combine retries with existing guardrails and tracing to maintain full auditability.

With these controls in place, UDA retries maintain the “single execution path” rule while offering targeted resilience for flaky connections.
