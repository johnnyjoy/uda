# Docs Index (Source of Truth Map)

## Doctrine / Constitution
- docs/constitution.md

## Spec
- docs/spec.md

## Public API
- docs/public-api.md

## Caching
- docs/cache-doctrine.md
- docs/caching.md

## Query usage
- docs/query-cookbook.md
- docs/repositories.md

## Drivers / Config / Security
- docs/driver.md
- docs/configuration.md
- docs/security.md

## Required invariants to verify in code
1) Database is primary ingress for app code.
2) Driver is internal execution engine; app code should not depend on it.
3) Exactly one prepare/bind/execute hot path.
4) Cache is transparent when enabled by config; no opt-in “scope”.
5) Table mtime touched only after successful DML with affectedRows > 0.
6) Named params only; `?` rejected pre-PDO.
7) No namespace token repetition.
8) Query domain builds SQL only; never touches PDO.
