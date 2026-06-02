# Docs Index (Source of Truth Map)

**Normative (read before changing code or public behaviour):** constitution,
product contract, spec, contract, architecture, style guide, public API,
getting started, configuration, caching doctrine, security.

**Operational / patterns:** driver reference, caching operations, repository
patterns cookbook, certification docs, **`skills/`** (agent checklists for application DAL on UDA).

**Historical / design archive:** `docs/plans/**`, `docs/production-readiness.md` —
local drafts (gitignored until public-suitable); context only when present; do not
treat as current behaviour unless cross-checked against code.

---

## Doctrine / Constitution (normative)

- docs/constitution.md
- docs/product-contract-v1.md

## Spec (normative)

- docs/spec.md

## Architecture and style (normative)

- docs/contract.md
- docs/architecture.md
- docs/style-guide.md

## Public API (normative)

- docs/getting-started.md
- docs/public-api.md

## Releases (normative)

- docs/releases.md
- CHANGELOG.md (repository root)

## Caching (normative + operational)

- docs/cache-doctrine.md
- docs/caching.md

## Query usage

- docs/query-cookbook.md (guide; edits require explicit approval)
- docs/patterns.md — repository patterns cookbook

## Drivers / Config / Security

- docs/driver.md
- docs/configuration.md
- docs/security.md

## Engine certification

- docs/certification/README.md — matrix (CI vs code-only engines)
- docs/certification/sqlite.md
- docs/certification/postgresql.md

## Required invariants to verify in code
1) Database is primary ingress for app code.
2) Driver is internal execution engine; app code should not depend on it.
3) Exactly one prepare/bind/execute hot path.
4) Cache is transparent when enabled by config; no opt-in “scope”.
5) Table mtime touched only after successful DML with affectedRows > 0.
6) Named params only; `?` rejected pre-PDO.
7) No namespace token repetition.
8) Query domain builds SQL only; never touches PDO.
