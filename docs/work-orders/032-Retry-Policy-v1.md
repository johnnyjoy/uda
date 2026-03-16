# Work Order 032 — Retry Policy for Transient Failures

## Authority

Documentation precedence:

1. `constitution.md` + `style-guide.md`
2. `contract.md`
3. `spec.md`
4. `design.md`

The Query Cookbook remains the canonical developer-facing guide.

This work order must **not change query grammar**.  
Retry is an **execution-layer concern**, not a query-building feature.

---

# Goal

Implement an **optional retry policy** for transient database failures.

The retry system must allow UDA to retry **eligible operations** when failures are likely temporary, such as:

- deadlocks
- lock wait timeouts
- transient connection drops
- temporary failover interruptions
- backend-unavailable conditions that may resolve quickly

Retry support must:

- be **disabled by default**
- be **explicitly configurable**
- apply only to **eligible operations**
- preserve UDA’s deterministic execution model
- never silently change query semantics
- never retry statements that are unsafe to replay unless explicitly allowed

---

# Problem This Work Order Solves

Transient database failures are common in real systems, especially under:

- concurrency
- clustered/failover deployments
- cloud databases
- network instability
- high lock contention

Today, a transient deadlock or connection blip causes the query to fail immediately.

A bounded, explicit retry mechanism improves resilience without requiring each caller to hand-roll retry loops.

---

# Core Design Principles

## 1. Retry Is Operational, Not Core Grammar

Retry belongs in the execution pipeline:

```text
Builder
→ SqlMessage
→ Database
→ Driver
→ PDO
````

It must **not** alter:

* builder grammar
* dialect compilation
* SqlMessage shape beyond retry metadata
* public SQL semantics

---

## 2. Disabled by Default

When retry is disabled:

* no retry classification occurs
* no delay logic occurs
* the hot path remains effectively unchanged

The only allowed disabled-path behavior is a very cheap early branch.

---

## 3. Safe by Default

By default, retry is allowed only for operations considered safe to replay.

Default retry eligibility:

| Statement Type               | Default Retry |
| ---------------------------- | ------------- |
| `select`                     | yes           |
| `insert`                     | no            |
| `update`                     | no            |
| `delete`                     | no            |
| `upsert`                     | no            |
| `merge`                      | no            |
| `explain`                    | yes           |
| `returning` write statements | no            |

Write retries may be enabled explicitly, but only through configuration or per-query override.

---

## 4. No Silent Write Duplication

The retry system must not create accidental duplicate writes.

Therefore:

* writes are not retried by default
* transaction-bound statements are more restrictive
* statements marked `noRetry()` must never retry
* retry only happens on a fresh execution attempt, never mid-stream

---

## 5. Retry Is Bounded

Retry must always have:

* maximum attempts
* bounded delays
* explicit stop conditions

No infinite retry loops.

---

# Scope

Allowed modifications:

```text
src/UDA/Database.php
src/UDA/Driver.php
src/UDA/Driver/*
src/UDA/Query/*
src/UDA/SQL/*
src/UDA/Retry/*
tests/Retry/*
tests/Query/*
docs/retry.md
docs/spec.md
docs/architecture.md
docs/query-cookbook.md
```

Do not modify:

```text
src/UDA/Cache/*
src/UDA/Config/*
```

unless a minimal config DTO hook is absolutely required.

Prefer explicit runtime configuration objects over expanding core config unnecessarily.

---

# Architectural Placement

Retry wraps the **driver execution call** inside the Database execution surface.

Correct placement:

```text
Builder / Sql / raw SQL
→ Database normalizes to SqlMessage
→ Guardrails validate
→ RetryPolicy executes closure
→ Driver executes
→ Result returned
```

Incorrect placements:

* inside builders
* inside dialect compilation
* inside cache layer
* inside trace listeners

Retry must be **one layer above Driver execution**, not scattered.

---

# New Components

## 1. `RetryConfig`

Namespace:

```php
UDA\Retry\RetryConfig
```

Represents retry configuration.

Required fields:

```php
class RetryConfig
{
    public bool $enabled;
    public int $maxAttempts;
    public string $strategy;
    public int $baseDelayMs;
    public int $maxDelayMs;
    public bool $retryWrites;
    public bool $retryInTransactions;
    public bool $jitter;
}
```

Defaults:

```text
enabled = false
maxAttempts = 3
strategy = exponential
baseDelayMs = 10
maxDelayMs = 500
retryWrites = false
retryInTransactions = false
jitter = true
```

Validation rules:

* `maxAttempts >= 1`
* `baseDelayMs >= 0`
* `maxDelayMs >= baseDelayMs`
* `strategy ∈ {fixed, linear, exponential}`

---

## 2. `RetryPolicy`

Namespace:

```php
UDA\Retry\RetryPolicy
```

Responsibilities:

* decide whether retry is enabled
* classify exceptions as retryable or not
* determine whether current statement is eligible
* compute backoff
* emit retry trace metadata
* execute retry loop

Primary entrypoint:

```php
public function execute(
    SqlMessage $sql,
    string $operation,
    bool $inTransaction,
    callable $attempt
): mixed
```

Where:

* `$sql` is normalized query metadata
* `$operation` is execution mode (`row`, `rows`, `exec`, `returning`, `explain`, etc.)
* `$inTransaction` is whether execution is currently inside an open transaction/savepoint scope
* `$attempt` is the closure that calls Driver

---

## 3. `RetryDecision`

Optional helper value object.

Namespace:

```php
UDA\Retry\RetryDecision
```

Represents:

* whether to retry
* reason
* computed delay
* attempt number

This is optional but useful for clean trace integration and testing.

---

## 4. `TransientErrorClassifier`

Namespace:

```php
UDA\Retry\TransientErrorClassifier
```

Responsibilities:

* inspect thrown exceptions
* classify transient vs non-transient
* prefer driver-specific classification first
* fall back to SQLSTATE and known message patterns

Primary method:

```php
public function isTransient(Throwable $e, ?Driver $driver = null): bool
```

---

# Database Integration

`Database` becomes the place that applies retry.

All execution entrypoints must route through retry-aware execution:

* `row()`
* `rows()`
* `value()`
* `values()`
* `list()`
* `exec()`
* `returning()`
* `each()`
* `explain()`
* `explainAnalyze()`

Pattern:

```php
$result = $this->retryPolicy?->execute(
    $sqlMessage,
    $operation,
    $this->inTransaction(),
    fn() => $this->driver->rows($sqlMessage)
) ?? $this->driver->rows($sqlMessage);
```

Guardrails must run **before** retry.

That ordering is mandatory:

```text
normalize SqlMessage
→ guardrails
→ retry wrapper
→ driver
```

Guardrail failures must never retry.

---

# Builder / SqlMessage Metadata

Add optional retry metadata to `SqlMessage`.

Required fields:

```php
SqlMessage {
    statementType: string
    retryAllowed: ?bool
}
```

Meaning:

* `null` = use policy defaults
* `true` = explicitly allow retry even if not default-safe
* `false` = never retry

Builders may expose:

```php
->noRetry()
->allowRetry()
```

on statement types where it is meaningful.

These must be immutable clone-on-write methods.

Raw `Sql::of(...)` may optionally expose:

```php
->withRetryAllowed(true|false)
```

for expert callers.

---

# Eligibility Rules

Retry eligibility must be determined by combining:

1. global retry enabled
2. statement type
3. per-query override
4. transaction state
5. transient error classification
6. attempt count

## Default matrix

| Statement Type  |            Default Retry | Notes                        |
| --------------- | -----------------------: | ---------------------------- |
| select          |                      yes | safe by default              |
| explain         |                      yes | safe by default              |
| insert          |                       no | must be explicit             |
| update          |                       no | must be explicit             |
| delete          |                       no | must be explicit             |
| upsert          |                       no | must be explicit             |
| merge           |                       no | must be explicit             |
| returning write |                       no | must be explicit             |
| each            |           yes for select | same as underlying statement |
| exec            | depends on statementType | use statement type           |

## Transaction rule

If `inTransaction == true` and `retryInTransactions == false`, then **no retry**.

Reason:

* retry inside transactions can break correctness assumptions
* especially dangerous for writes and lock-sensitive flows

---

# Error Classification

Retry must happen only for exceptions classified as transient.

Classification order:

## 1. Driver-specific override

`Driver` may implement:

```php
public function isTransientError(Throwable $e): ?bool
```

Return values:

* `true` = retryable
* `false` = not retryable
* `null` = unknown, fall back to generic classifier

This allows backend-specific precision.

## 2. Generic SQLSTATE classification

Known transient SQLSTATE classes/examples include:

| SQLSTATE | Meaning                       | Retry                                    |
| -------- | ----------------------------- | ---------------------------------------- |
| `40001`  | serialization/deadlock        | yes                                      |
| `40P01`  | PostgreSQL deadlock           | yes                                      |
| `HYT00`  | timeout                       | maybe yes                                |
| `HY000`  | generic; driver-specific only | no generic retry                         |
| `08006`  | connection failure            | yes                                      |
| `08003`  | connection does not exist     | yes                                      |
| `57P01`  | admin shutdown (PG)           | yes                                      |
| `57014`  | cancel/timeout                | usually no unless driver marks transient |

The generic classifier must stay conservative.

## 3. Known message patterns (last resort)

Only when SQLSTATE is unavailable and driver does not classify.

Examples:

* "deadlock"
* "lock wait timeout"
* "connection reset"
* "server has gone away"

This fallback must be minimal and conservative.

---

# Backoff Strategies

Supported strategies:

## Fixed

```text
delay = baseDelayMs
```

## Linear

```text
delay = min(maxDelayMs, baseDelayMs * attemptNumber)
```

## Exponential

```text
delay = min(maxDelayMs, baseDelayMs * 2^(attemptNumber - 1))
```

## Jitter

If `jitter == true`, apply bounded randomization to delay:

```text
actualDelay = random between 50% and 100% of computed delay
```

This reduces retry thundering.

---

# Trace Integration

Retry must integrate with the tracing system from WO027.

## Retry attempt trace

Each retry attempt after the first must emit a trace event with:

```text
traceType = retry_attempt
operation
statementType
attempt
delayMs
reason
connection
dialect
fingerprint
```

## Final success trace

If a later retry succeeds, the normal success trace must include:

```text
retryCount
retried = true
```

## Final failure trace

If retries exhaust, final failure trace must include:

```text
retryCount
retried = true
finalFailure = true
```

Retry events must not be counted as independent successful queries in metrics unless that is explicitly desired by WO031 aggregation rules.

---

# Replay Integration

Replay snapshots from WO030 may include retry metadata:

```text
retryCount
retryReasons
```

Replay itself does not need to mimic original retry timing by default, but must preserve the metadata for debugging.

---

# Explain / ExplainAnalyze Rules

`explain()` is safe to retry by default.

`explainAnalyze()` may or may not be safe depending on backend semantics. Treat it according to statement type and backend behavior:

* if explainAnalyze truly executes the statement, it must not be retried by default for writes
* if it is read-only plan analysis, it may retry

Driver/dialect behavior must determine this conservatively.

---

# Prepared Statement Reuse Interaction

Retry must work correctly with prepared statement reuse (WO025).

Requirements:

* each retry attempt must rebind params fresh
* if statement/cursor state is dirty after exception, the driver must recover or prepare anew
* retry must not assume the previous PDOStatement is reusable after failure

Conservative rule:

* after a driver execution exception, the driver may discard that cached prepared statement entry if necessary

This behavior may be driver-specific.

---

# Cache Interaction

Retry does not interact with result caching policy directly.

However:

* a cache hit that avoids DB execution should not enter retry path
* only actual driver execution attempts are retryable

So retry wraps only the DB-bound execution branch, not cache lookups.

---

# Public API

## Runtime setup

Preferred shape:

```php
$retry = new RetryPolicy(new RetryConfig(
    enabled: true,
    maxAttempts: 4,
    strategy: 'exponential',
    baseDelayMs: 20,
    maxDelayMs: 250,
    retryWrites: false,
    retryInTransactions: false,
    jitter: true,
));

$db->setRetryPolicy($retry);
```

If policy is connection/global to Database instances, document clearly. Keep it explicit and non-magical.

## Builder overrides

Optional builder methods:

```php
->noRetry()
->allowRetry()
```

Examples:

```php
$db->select()
   ->from('employees')
   ->noRetry()
   ->rows();
```

```php
$db->update()
   ->table('jobs')
   ->set('status', 'pending')
   ->where('id', 5)
   ->allowRetry()
   ->exec();
```

This second example only retries if:

* retry policy enabled
* retryWrites enabled **or** explicit allowRetry honored by policy
* not in a forbidden transaction context
* failure is transient

---

# Tests Required

Create:

```text
tests/Retry/RetryPolicyTest.php
tests/Retry/TransientErrorClassifierTest.php
tests/Retry/RetryIntegrationTest.php
```

## Unit tests

### 1. Disabled policy

Verify no retries occur when disabled.

### 2. Select retry on transient failure

Simulate transient failure on first attempt, success on second.

Verify:

* two attempts
* success result returned
* retry trace emitted

### 3. Max attempts enforced

Simulate repeated transient failure.

Verify:

* attempt count equals maxAttempts
* final exception thrown

### 4. Non-transient error not retried

Simulate syntax error / constraint violation.

Verify:

* one attempt only

### 5. Exponential backoff calculation

Verify computed delays are correct and capped.

### 6. Jitter stays within bounds

Verify jittered delays remain between expected ranges.

### 7. Write retry disabled by default

`insert/update/delete/upsert/merge` should not retry.

### 8. Write retry explicit enable

When allowed by policy and statement override, retry occurs.

### 9. Transaction retry blocked by default

If in transaction and `retryInTransactions == false`, verify no retry.

### 10. Builder `noRetry()`

Verify even retryable reads do not retry.

### 11. Builder `allowRetry()`

Verify it marks SqlMessage appropriately.

## Integration tests

### 12. Retry with prepared statement reuse

Ensure repeated attempt after transient failure still works.

### 13. Retry trace metadata

Verify `retry_attempt` and final trace metadata.

### 14. Replay metadata presence

If replay enabled, verify snapshot includes retry fields.

---

# Documentation

Create/update:

```text
docs/retry.md
docs/spec.md
docs/architecture.md
docs/query-cookbook.md
```

## `docs/retry.md` must include

* what retry is for
* default-safe statement types
* why writes are not retried by default
* transaction caveats
* config options
* builder overrides
* examples

## Cookbook addition

Add a small section like:

### Retry Policy

```php
$retry = new RetryPolicy(new RetryConfig(
    enabled: true,
    maxAttempts: 3,
    strategy: 'exponential',
    baseDelayMs: 10,
));

$db->setRetryPolicy($retry);

$rows = $db->select()
    ->from('employees')
    ->rows();
```

And one example of disabling retry per query:

```php
$db->select()
   ->from('jobs')
   ->noRetry()
   ->rows();
```

---

# Acceptance Criteria

WO032 is complete when all of the following are true:

* `RetryConfig` exists and validates correctly
* `RetryPolicy` wraps driver execution in Database
* transient error classification exists and is conservative
* retries are disabled by default
* selects retry correctly when enabled
* writes do not retry by default
* explicit write retry can be enabled
* transaction retry is blocked by default
* builder `noRetry()` / `allowRetry()` work
* retry trace events are emitted
* tests pass
* documentation is complete and accurate

---

# Evidence Required

Provide:

1. changed files
2. PHPUnit output for `tests/Retry/*`
3. example retry trace output
4. one example of successful retry on transient read failure
5. one example showing write retry blocked by default
6. one example showing retry disabled in transaction context

---

# Non-Goals

This work order does **not** implement:

* distributed transaction retry
* transparent duplicate-write prevention
* circuit breakers
* connection pool failover orchestration
* workload-level retry policies
* query rewriting for idempotency
* automatic replay of entire transactions

This is a **bounded execution retry mechanism**, not a resilience platform.

---

# Philosophy

Retry support must make UDA more resilient without making it surprising.

The rules are simple:

* disabled unless enabled
* safe operations first
* bounded attempts
* explicit write retry
* no hidden semantics changes

This keeps retry useful, understandable, and operationally safe.
