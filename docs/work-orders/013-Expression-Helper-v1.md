````markdown
# Work Order 013 — Expression Helpers

## Execution Context

Target coding agent: **OpenAI GPT-5.1-codex** operating in an Opencode workflow.

This work order introduces **lightweight expression helpers** to the Query domain so UDA can express serious SQL structure without falling back to raw SQL everywhere.

This is **not** an AST project.

This is **not** an ORM feature.

This is **not** a rewrite of the builder system.

Expression helpers must remain:

- small
- optional
- composable
- deterministic
- subordinate to the existing builder + dialect architecture

---

# Authority

Documentation precedence:

1. constitution.md + style-guide.md
2. contract.md
3. spec.md
4. design.md

If code conflicts with docs, code is wrong unless docs are clearly outdated.

The **Query Cookbook remains the north star**.

---

# Goal

Introduce a small expression helper system under the Query domain:

```php
UDA\Query\Expr
````

so developers can express structural SQL such as:

* aggregates
* function calls
* coalesce
* aliased computed columns
* safe raw expressions where necessary

without turning UDA into an AST framework.

Target usage examples:

```php
$rows = $db->select(
        'department_id',
        Expr::count('id')->as('employee_count')
    )
    ->from('employees')
    ->groupBy('department_id')
    ->having(Expr::count('id'))->gt(10)
    ->rows();
```

```php
$rows = $db->select(
        'id',
        Expr::coalesce('title', 'Unknown')->as('title_display')
    )
    ->from('employees')
    ->rows();
```

Strings must remain first-class. Expression helpers are **additive**, not mandatory.

---

# Scope (Allowed Changes)

Only modify:

* `src/UDA/Query/*`
* `src/UDA/Query/Dialect/*`
* `src/UDA/SQL/*` (only if needed for immutable Sql support)
* `tests/Query/*`
* `docs/query-cookbook.md`
* `docs/spec.md`
* `docs/design.md`
* `docs/architecture.md`

Do **not modify**:

* cache subsystem
* config subsystem
* driver connection logic
* execution model

Expression helpers must fit the existing pipeline:

```text
Builder
→ Dialect compiler
→ Sql value object
→ Database
→ Driver
→ PDO
```

---

# Architectural Intent

## 1. Domain Placement

Expression helpers belong to the **Query** domain.

Suggested structure:

```text
src/UDA/Query/
    Expr.php
    Select.php
    Insert.php
    Update.php
    Delete.php
    Upsert.php
    Sql.php
    Dialect/
        Dialect.php
        PostgreSQL.php
        Oracle.php
        SQLite.php
        MariaDb.php
        SqlServer.php
        Sybase.php
        Db2.php
```

Public class name:

```php
UDA\Query\Expr
```

Not:

* `Expression`
* `ExprNode`
* `QueryExpr`
* `RawExpression`

---

## 2. Responsibility Split

### Expr owns:

* representing trusted SQL structure
* rendering expression fragments through the dialect
* carrying expression-local params if needed
* aliasing via `as(...)`

### Builders own:

* query state
* clause ordering
* grammar validation
* accepting `string|Expr` where appropriate

### Dialect owns:

* final SQL rendering of expressions
* backend differences where expression syntax differs

### Database / Driver own:

* execution only

Expr must never execute SQL.

---

## 3. Non-AST Rule

This work order must **not** turn UDA into a node-based compiler framework.

Do not introduce:

* ExpressionNode trees everywhere
* visitor patterns
* compiler passes
* dozens of tiny node classes
* mandatory object wrapping for columns/tables

Strings remain the default and simplest representation.

Expression helpers are used **only when they materially improve expressiveness**.

---

# Requirements

## 1. Base Expr API

Create `UDA\Query\Expr` as a lightweight immutable structural helper.

Minimum required factories:

```php
Expr::raw(string $sql, array $params = [])
Expr::count(string $target = '*')
Expr::sum(string $target)
Expr::avg(string $target)
Expr::min(string $target)
Expr::max(string $target)
Expr::coalesce(string|Expr ...$values)
```

Minimum required instance methods:

```php
->as(string $alias)
```

All Expr instances must be immutable.

---

## 2. Safe Raw Expressions

`Expr::raw()` is allowed, but must remain a **trusted structural escape hatch**.

Example:

```php
Expr::raw('COUNT(id)')
Expr::raw('COALESCE(title, :fallback)', ['fallback' => 'Unknown'])
```

Rules:

* params must be named params only
* positional placeholders are forbidden
* placeholder validation must occur
* aliasing through `->as(...)` must remain supported

This is structural SQL, not user-input concatenation.

---

## 3. Builder Acceptance

Builders must accept `string|Expr` in the following locations where appropriate:

### Select list

```php
->select('id', Expr::count('id')->as('total'))
```

### Having left-hand side

```php
->having(Expr::count('id'))->gt(5)
```

### Order by target

If feasible in current grammar:

```php
->orderBy(Expr::count('id'), 'DESC')
```

If not feasible without destabilizing grammar, defer this exact shape and document the supported form.

### Grouping / computed expressions

Only allow where it is structurally sound.

Do not force expression support into every builder method unless it clearly belongs there.

---

## 4. Aliasing

Expressions must support:

```php
Expr::sum('amount')->as('total')
```

This must compile correctly in SELECT lists.

Alias validation rules must match existing identifier/alias rules.

---

## 5. Dialect Rendering

Dialect must be able to render expression fragments as part of larger query compilation.

Examples:

* `COUNT(id)`
* `SUM(amount)`
* `COALESCE(title, :p1)`
* raw expressions with params
* aliased expressions

Dialect must not require a separate expression compiler subsystem.

Expression rendering should remain small and direct.

---

## 6. Parameter Merging

Expressions may carry params:

```php
Expr::raw('COALESCE(title, :fallback)', ['fallback' => 'Unknown'])
```

These params must merge into the parent query deterministically.

No collisions.

Same builder state must always produce the same final param ordering.

---

## 7. Deterministic Compilation

Given identical builder state, expression use must produce identical:

* SQL string
* params
* table attribution where relevant
* aliases

No hidden reordering.

No runtime mutation.

---

## 8. Table Attribution

Expressions normally do not add table attribution by themselves unless they explicitly reference subqueries or structural SQL carrying tables.

Do not invent table inference from raw expression strings.

If expressions later need explicit table hints, that belongs in a later work order.

For this work order:

* expressions do not alter table attribution unless already backed by structured objects carrying that metadata

---

## 9. Grammar Preservation

Expression helpers must improve expressiveness without damaging readability.

This must remain valid and common:

```php
$db->select('id', 'name')
   ->from('users')
   ->where('id', $id)
   ->row();
```

Expression support must not make normal builder usage noisier.

---

# Tests Required

## Expr Construction Tests

Add tests for:

* `Expr::count('*')`
* `Expr::count('id')`
* `Expr::sum('amount')`
* `Expr::avg('salary')`
* `Expr::coalesce('title', 'Unknown')`
* `Expr::raw('COUNT(id)')`
* aliasing via `->as('alias')`

---

## Builder Integration Tests

Verify expressions work in:

### Select list

```php
->select('department_id', Expr::count('id')->as('total'))
```

### Having

```php
->groupBy('department_id')
->having(Expr::count('id'))->gt(5)
```

### Raw expression with params

```php
->select(Expr::raw('COALESCE(title, :fallback)', ['fallback' => 'Unknown'])->as('title_display'))
```

---

## Determinism Tests

Ensure identical builder + expression state always produces identical:

* SQL
* param names
* param order

---

## Validation Tests

Ensure:

* `Expr::raw()` rejects positional placeholders
* invalid alias names fail
* invalid raw placeholder bindings fail

---

## Dialect Tests

Verify expression compilation works across supported dialects for at least:

* aggregate select
* coalesce expression
* aliased raw expression

Unless a dialect truly differs, expression rendering should remain consistent.

---

# Acceptance Criteria

All must be true:

* `UDA\Query\Expr` exists
* builders accept expressions where defined
* expressions are immutable
* strings remain first-class
* no AST subsystem is introduced
* expression params merge deterministically
* aliasing works
* dialect compilation remains stable
* cookbook examples are updated and accurate
* tests pass

---

# Non-Goals

Do not implement in this work order:

* full CASE builder
* window functions
* FILTER clauses
* JSON expression helpers
* subquery expressions beyond already-supported builder composition
* AST node frameworks
* mandatory Identifier objects everywhere
* mandatory Expr wrapping for columns/tables

Those belong in later work orders if needed.

---

# Evidence Required

Provide:

1. Changed files
2. PHPUnit output for `tests/Query/*`
3. Example compiled SQL for:

   * aggregate select
   * aliased sum expression
   * coalesce expression with params
4. One cookbook example showing expression helpers in a real grouped query

---

# Cookbook Updates

Add sections for:

## Aggregate Expressions

```php
$rows = $db->select(
        'department_id',
        Expr::count('id')->as('employee_count')
    )
    ->from('employees')
    ->groupBy('department_id')
    ->rows();
```

## HAVING with Expr

```php
$rows = $db->select(
        'department_id',
        Expr::avg('salary')->as('avg_salary')
    )
    ->from('employees')
    ->groupBy('department_id')
    ->having(Expr::avg('salary'))->gt(120000)
    ->rows();
```

## COALESCE

```php
$rows = $db->select(
        'id',
        Expr::coalesce('title', 'Unknown')->as('title_display')
    )
    ->from('employees')
    ->rows();
```

## Raw Expression Escape Hatch

```php
$rows = $db->select(
        Expr::raw('COUNT(id)')->as('employee_count')
    )
    ->from('employees')
    ->rows();
```

Documentation must make clear:

* strings remain normal
* Expr is optional
* Expr is for structural SQL, not careless string interpolation

---

# Philosophy

Expression helpers are the smallest useful step beyond plain strings.

They let UDA express serious SQL structure without:

* collapsing into raw SQL everywhere
* infecting the API with AST complexity
* making ordinary queries harder to read

The standard is simple:

Use strings when strings are enough.
Use `Expr` when structure matters.
