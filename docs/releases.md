# Versioning and releases

## Semantic versioning (SemVer 2.0)

| Bump | When |
|------|------|
| **MAJOR** | Breaking public API, documented guarantees, or PHP / extension baseline. |
| **MINOR** | Backwards-compatible features or optional APIs. |
| **PATCH** | Bug fixes, internal refactors with no caller-visible change, doc fixes. |

Public contract: `docs/public-api.md`, `docs/contract.md`. Caller-visible
behaviour change → at least **MINOR**; breaks → **MAJOR**.

## Changelog

Root **`CHANGELOG.md`**. Update `[Unreleased]` per MR; on release rename to
`x.y.z` + date.

## CI (source of truth)

GitHub Actions: `.github/workflows/` — `ci.yml` (guardrails, PHPStan, PHPUnit);
certification workflows for SQLite / PostgreSQL.

GitLab-only mirrors: run the same Composer targets locally or in **your** CI
before merge:

```bash
composer install --no-interaction --prefer-dist
composer check
composer stan
composer test
```

## Tagging

1. `CHANGELOG.md`: move `[Unreleased]` → version.
2. Tag `vMAJOR.MINOR.PATCH`.
3. Publish via Packagist (or org registry).

GitLab Releases can mirror tags; they do not replace Packagist unless you
document a Package Registry consumer flow.
