# UDA V1 Product Contract

**Status:** authoritative product target for finishing UDA v1.

UDA is a PHP 8.2+ Composer library for application and repository classes
that need deterministic, explicit SQL execution across relational database engines.

## Product Promise

Application code imports one handle:

```php
use UDA\Database;
```

UDA is the **abstractor**: engine routing, builders, cache, and one execution
pipeline. Domain shape — tables, queries, methods — lives in integrator classes,
often via `UDA\Link`.

From that point forward, `Database` is the database. Application classes use
`Database` to connect, run named-parameter SQL, create fluent builders, execute
transactions, and inspect the last executed SQL for debugging. Application
classes do not import `Driver`, backend driver rule classes, PDO, cache stores,
dialect classes, or connection wrapper objects.

## Who UDA Serves

UDA serves PHP classes that own database work directly — repositories and
domain-specific data access classes built on `Database` or `Link`.

## What UDA Provides

* one uniform execution model across engines
* explicit SQL via raw strings and fluent builders
* named parameter discipline before SQL reaches PDO
* deterministic query-builder output for common CRUD and reads
* transparent read acceleration without caller-visible cache calls
* multiple named connections, including multiple connections of the same backend
* class-scoped delegation via `Link` for domain data layers

UDA exists only where a feature improves uniformity, performance, safety,
determinism, or developer clarity. Traditional PHP patterns are acceptable only
when they improve those outcomes.

## Runtime Contract

All SQL execution flows through one hot path:

```text
Application class
    -> Database
    -> Driver
    -> PDO
    -> database engine
```

The `Driver` domain is the only PDO owner. Cache may participate only as a
Driver-owned transparent decision before or after the same execution path. Query
builders construct SQL and parameters, but never execute SQL, own PDO, or
consult cache.

## Static-First Rule

UDA uses static behavior where no per-instance state exists. Backend-specific
Driver-domain classes provide static rules for DSN construction, identifier
quoting, pagination fragments, savepoint SQL, and capability metadata. Objects
exist only when they own runtime state, such as a PDO handle, transaction depth,
last SQL and parameters, or mutable builder state.

## Cache Contract

Cache is configuration-driven and transparent. Callers always execute queries
the same way whether cache is enabled or disabled.

Cache reads must decide from metadata before payload:

1. read cache metadata
2. evaluate TTL
3. evaluate table write timestamps
4. decide whether the cached payload is usable
5. read payload only after the decision selects it

Writes update table write timestamps only after successful DML with affected
rows.

## Public Surface

The v1 public surface is:

* `Database::connect(string ...$args): Database`
* raw SQL methods on `Database`
* fluent builders created by `Database`
* safe SQL fragment helpers created by `Database`
* `ConfigException`, `ConnectionException`, and `QueryException`
* optional `UDA\Link` trait for class-scoped delegation to the same `Database` handle

The static `UDA\Query` facade is **not** part of the v1 public surface (removed — use `Database::connect()` only).

## Finish Criteria

UDA v1 is finished only when:

* docs consistently describe `Database` as the single application handle
* positional placeholders are rejected before PDO
* only `Driver` touches PDO and PDOStatement
* one prepare/bind/execute implementation exists
* cache is transparent and metadata-first
* external class usage is proven with `use UDA\Database;`
* Composer autoload, PHPStan, PHPUnit, architecture checks, and CI engine integration
  are reproducible under PHP 8.2+

