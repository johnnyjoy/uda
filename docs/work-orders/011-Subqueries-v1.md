# Engineering Assessment of WO011

## 1. Subqueries are now first-class

You now support:

```
FROM (subquery) alias
JOIN (subquery) alias
WHERE IN (subquery)
EXISTS (subquery)
NOT EXISTS (subquery)
```

via:

```
fromSub()
joinSub()
leftJoinSub()
rightJoinSub()
```

and `WhereBuilder` upgrades.

That is the **correct minimal API**. Nothing unnecessary was introduced.

This moves UDA from **CRUD builder** territory into **real relational composition**.

---

# 2. The critical part: parameter merging

This line matters more than it may appear:

> renaming the child placeholders deterministically while merging cache-table hints

That means the system solved the hardest part of nested queries:

```
parent query params
+ child query params
= one deterministic param bag
```

Example:

```sql
WHERE salary > :p1
AND department_id IN (
    SELECT department_id
    FROM payroll
    WHERE bonus > :p2
)
```

Without collisions.

That confirms the system still guarantees:

```
same builder state → same SQL → same params
```

which protects **query cache keys**.

Excellent.

---

# 3. Table attribution propagation

This line is extremely important:

> merging cache-table hints

That means:

```
SELECT ...
FROM (SELECT ... FROM payroll) p
```

still records:

```
tables = ['employees', 'payroll']
```

This is necessary for:

* cache invalidation
* metadata tracking
* write invalidation safety

Many libraries get this wrong.

UDA did not.

---

# 4. Join storage refactor was the right move

You mentioned:

> join storage to keep metadata instead of raw strings

That’s a **big structural improvement**.

Previously many builders do this:

```
joins = ["JOIN departments d ON d.id = e.department_id"]
```

Which becomes impossible to manipulate safely.

Moving to structured join metadata allows:

* table joins
* subquery joins
* dialect-aware compilation
* deterministic rendering

without introducing an AST.

That is the correct balance.

---

# 5. WhereBuilder now has a full predicate grammar

You now have:

```
isNull()
isNotNull()
notLike()
in(subquery)
notIn(subquery)
exists()
notExists()
whereRaw()
```

This means developers rarely need to drop to raw SQL.

That is exactly what the cookbook promised.

---

# 6. Alias enforcement is correct

Rejecting:

```
->fromSub($query)
```

and requiring:

```
->fromSub($query, 'alias')
```

prevents illegal SQL generation and ambiguous columns.

That enforcement should remain **non-optional**.

---

# 7. Tests are targeting the correct failure points

The test suite now covers:

* derived tables
* join subqueries
* exists/not exists
* parameter determinism
* alias enforcement
* table attribution
* multi-dialect compilation

Those are precisely the fragile areas.

This indicates the design is **stable enough for extension**.

---

# 8. Cookbook expansion is exactly right

The cookbook gained:

```
Derived Tables
JOIN (subquery)
WHERE IN (subquery)
NOT EXISTS
```

Those are the features developers expect once a builder becomes serious.

The cookbook is now closer to:

> executable documentation

which is what you wanted from the beginning.

---

# What this means architecturally

UDA now supports three essential relational building blocks:

### 1. base queries

```
SELECT ...
FROM ...
WHERE ...
```

### 2. write operations

```
INSERT
UPDATE
DELETE
UPSERT
RETURNING
```

### 3. relational composition

```
subqueries
derived tables
EXISTS
IN (subquery)
```

That is a **very complete SQL builder foundation**.

---

# The system now has a clear architecture

```
Query Builder
    ↓
Dialect Compiler
    ↓
Sql object
    ↓
Database
    ↓
Driver
    ↓
PDO
```

And subqueries integrate **without breaking that pipeline**.

That’s exactly what we wanted.

---

# The next logical frontier

Now that relational composition exists, the next serious feature is:

## Work Order 012 — Expression Objects

Why?

Because developers will soon write things like:

```
SUM(amount)
COUNT(*)
COALESCE(title,'Unknown')
CASE WHEN ...
```

Right now that requires:

```
havingRaw()
whereRaw()
```

Expression objects would allow:

```
Expr::sum('amount')
Expr::count('*')
Expr::coalesce('title','Unknown')
```

which:

* improves safety
* improves composability
* reduces raw SQL
* fits perfectly into your builder

But importantly:

**this should remain lightweight**, not an AST explosion.
