# Work Order 010 — Cookbook Alignment and Fluent Query Gap Closure

## Execution Context

Target coding agent: **OpenAI GPT-5.1-codex** operating in an Opencode workflow.

This work order is primarily **audit + alignment**, not large new feature development.  
The objective is to ensure the **UDA Query Cookbook (the north star)** accurately reflects the real API and that any missing fluent surface methods promised by the cookbook are implemented.

---

# Authority

Documentation precedence:

1. constitution.md + style-guide.md  
2. contract.md  
3. spec.md  
4. design.md  

If code conflicts with documentation, **code is wrong** unless the documentation itself is clearly outdated or incorrect.

The **Query Cookbook is the developer contract** and must reflect the real behavior of the system.

---

# Goal

Bring the **Query Cookbook**, **query builders**, and **dialect system** into full alignment.

This work order must:

1. Audit the cookbook against the codebase.
2. Identify any fluent query features documented but not implemented.
3. Implement any missing fluent methods that are clearly part of the intended query language.
4. Correct documentation where behavior is intentionally different.
5. Clarify backend support differences where needed.
6. Ensure the cookbook is a **truthful, executable specification** of the query API.

This work order **does not introduce major new query capabilities**.  
It ensures the system and its documentation match perfectly.

---

# Scope (Allowed Changes)

Only modify:

- `docs/query-cookbook.md`
- `docs/spec.md`
- `docs/design.md`
- `docs/architecture.md`
- `src/UDA/Query/*`
- `src/UDA/Query/Dialect/*` (only if needed for minor query-surface completion)
- `src/UDA/Database.php` (only if required for terminator alignment)
- `tests/Query/*`
- `tests/Database/*` (only if needed for execution tests)

Do **not modify**:

- cache architecture
- configuration system
- driver connection logic
- execution model

This work order must **not introduce ORM features**.

---

# Architectural Intent

UDA’s query layer is defined by three principles:

1. **Fluent query grammar**
2. **Backend-neutral API**
3. **Deterministic SQL compilation**

The cookbook describes the intended grammar.

This work order ensures:

```

Cookbook examples
==
Real builder API
==
Dialect output

```

No divergence.

---

# Requirements

## 1. Cookbook Audit

Audit every section of `query-cookbook.md` against the actual implementation.

For each example determine:

- Fully implemented
- Partially implemented
- Not implemented
- Incorrect documentation

Produce a short audit summary in the pull request.

---

## 2. Fluent Query Surface Completion

If the cookbook references fluent methods that do not exist, implement them **only if they are consistent with the intended query grammar**.

Examples to verify include:

Possible methods referenced or implied:

- `whereRaw`
- `isNull`
- `isNotNull`
- `notIn`
- `notLike`
- `exists`
- `notExists`
- `havingRaw`

If missing but clearly part of the intended query language:

- implement them in the appropriate builder
- ensure dialect compilation handles them correctly
- add tests

If intentionally unsupported, update the cookbook.

---

## 3. WHERE Grammar Consistency

Verify boolean expression grammar:

```

where(...)
and(...)
or(...)
not(...)

```

Ensure the expression builder supports:

- nested closure blocks
- deterministic parameter generation
- predictable SQL ordering

Invalid boolean chains must fail early.

---

## 4. Terminator Semantics

Confirm all cookbook terminators exist and behave correctly:

```

row()
rows()
value()
values()
exec()
each()

```

Rules:

- terminators forward execution to `Database`
- builders must never execute SQL themselves
- `Database` remains the execution surface

---

## 5. Streaming Section Accuracy

The cookbook currently claims:

```

each() uses constant memory

```

Verify whether this is **true in the current implementation**.

If not implemented yet:

- adjust wording to avoid misleading claims

Example correction:

```

each() iterates rows using the underlying PDO cursor.

```

Do **not implement new streaming infrastructure in this work order.**

---

## 6. RETURNING Documentation Clarification

Returning is implemented but **not supported on all engines**.

Update cookbook documentation to explicitly state:

Supported engines:

- PostgreSQL
- SQLite
- SQL Server
- Sybase
- Oracle

Unsupported:

- MariaDB
- DB2

Calling `returning()` on unsupported engines must throw a clear exception.

---

## 7. Dialect Neutrality

Ensure cookbook examples remain **backend neutral**.

Examples must never contain vendor-specific SQL.

Vendor-specific clauses must remain the responsibility of the dialect layer.

---

## 8. Deterministic Output

Verify builder compilation still guarantees:

- named parameters only
- deterministic SQL
- stable clause ordering
- explicit table lists

Tests must confirm identical builder state produces identical SQL.

---

# Tests Required

## Cookbook Coverage

Add tests verifying cookbook examples compile and execute.

Examples to include:

- basic select
- joins
- nested where closures
- group by / having
- insert
- bulk insert
- update
- delete
- upsert
- returning
- transactions

---

## Boolean Grammar Tests

Confirm correct compilation for:

```

where()
and()
or()
nested closures

```

Edge cases:

- empty IN lists
- nested boolean blocks
- multiple chained conditions

---

## Determinism Tests

Ensure identical builder state always produces identical:

- SQL text
- parameter names
- table attribution

---

## Unsupported Feature Tests

Verify that:

- `returning()` throws on MariaDB
- `returning()` throws on DB2

Tests must assert the correct exception.

---

# Acceptance Criteria

All must be true:

- Every cookbook example compiles and runs
- No cookbook example lies about behavior
- Fluent query grammar matches documentation
- Builder terminators behave consistently
- Unsupported backend behavior is documented
- Tests verify cookbook examples
- SQL output remains deterministic

---

# Non-Goals

Do not introduce:

- CTE support
- window functions
- ORM/entity systems
- query AST layers
- raw SQL parsing
- MariaDB returning emulation
- DB2 returning support

Those belong in future work orders.

---

# Evidence Required

Provide:

1. Cookbook audit summary
2. List of modified builder methods
3. PHPUnit output for all query tests
4. Example SQL output for:
   - select
   - insert returning
   - upsert
   - nested where queries

---

# Philosophy

The Query Cookbook is the **north star of UDA**.

Developers should be able to:

- read the cookbook
- write queries exactly like the examples
- trust that the system behaves exactly as described

No surprises. No hidden dialect hacks.  
Just predictable SQL with clean fluent grammar.
