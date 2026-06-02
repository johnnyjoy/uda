# Contributing to UDA

**CI:** GitHub Actions only (`.github/workflows/`). There is no `.gitlab-ci.yml`
in this tree—run the same Composer commands locally before merge, or add CI on
your fork.

## Requirements

- PHP ≥ 8.2, `ext-pdo`
- Composer 2.x
- Read `docs/style-guide.md` and `docs/contract.md` before non-trivial changes

## Local workflow (before MR)

```bash
composer install --no-interaction --prefer-dist
composer check    # Purpose, PDO path, imports, …
composer stan
composer test
```

For cache or PostgreSQL-heavy changes, also match `.github/workflows/sqlite-cert.yml`
and `postgres-cert.yml` (see `docs/certification/`).

## Merge requests (GitLab)

1. Branch from default (or fork + branch).
2. Use the Default MR template.
3. Do not edit `docs/query-cookbook.md` without explicit per-section approval.
4. User-visible change → bullet under `CHANGELOG.md` `[Unreleased]`.

**Protected default branch:** require MRs; require passing checks **or** documented
equivalent (e.g. GitHub Actions on a mirror); squash policy is team choice.

## Merge requests (GitHub)

Same commands as above; use the repo’s PR template if any.

## Code style

- `src/UDA/**/*.php` must contain `Purpose:` (`tools/check-purpose.php`).
- PHPDoc: `docs/style-guide.md`; alignment may be review-only—see enforcement map there.

## Security

`SECURITY.md`. Prefer **confidential** GitLab issues for undisclosed problems.

## Releases

`docs/releases.md`.

## Maintenance

`composer outdated`; keep host dependency / SAST scanning on; fix false positives,
do not blanket-disable rules.
