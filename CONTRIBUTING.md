# Contributing to UDA

**Canonical repo:** [github.com/johnnyjoy/uda](https://github.com/johnnyjoy/uda)

**CI:** GitHub Actions only (`.github/workflows/`). There is no `.gitlab-ci.yml`
in this tree. Run the same Composer commands locally before opening a PR.

## Requirements

- PHP ≥ 8.2, `ext-pdo`
- Composer 2.x
- Read `docs/style-guide.md` and `docs/contract.md` before non-trivial changes

## Local validation (before a PR)

```bash
composer install --no-interaction --prefer-dist
composer check    # Purpose, PDO path, imports, …
composer stan
composer test
```

For cache or engine-heavy changes, also match the relevant
`.github/workflows/*-integration.yml` jobs (see `docs/integration/`). Sybase is manual-only; future engines are listed in `docs/integration/deferred.md`.

## Pull requests (GitHub)

1. Fork or branch from `master`.
2. Make changes; keep diffs focused.
3. Run the local validation commands above.
4. Open a PR against `master`. CI must pass on GitHub Actions.
5. Do not edit `docs/query-cookbook.md` without explicit per-section approval.
6. Caller-visible change → bullet under `CHANGELOG.md` `[Unreleased]` in plain language
   (what integrators gain or must change). Do not log CI job names, internal phase labels,
   SAP/GHA spike outcomes, or driver-refactor milestones — those go in `docs/integration/`
   or the PR body.

Squash vs merge is maintainer choice; one logical change per PR is preferred.

## GitLab mirror (optional)

If you work from a GitLab mirror, use the same local validation commands. Open the
PR on GitHub (canonical) or follow your mirror’s merge policy. Templates under
`.gitlab/` are for mirror convenience only — they do not drive CI.

## Code style

- `src/UDA/**/*.php` must contain `Purpose:` (`tools/check-purpose.php`).
- PHPDoc: `docs/style-guide.md`; alignment may be review-only — see enforcement map there.

## Security

See `SECURITY.md`. Do not open a public issue for an undisclosed vulnerability.
Prefer GitHub **private security advisories** on the canonical repo, or email
authors from `composer.json`.

## Releases

`docs/releases.md`.

## Maintenance

`composer outdated`; keep host dependency / SAST scanning on; fix false positives,
do not blanket-disable rules.
