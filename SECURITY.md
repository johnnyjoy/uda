# Security policy

## Supported versions

Security fixes target the **latest minor** on the current **major** line on the
default branch. Older lines only if maintainers agree.

## Reporting

Do **not** open a public issue for an undisclosed vulnerability.

1. **GitHub private security advisory** on the canonical repo, or  
2. Email authors from `composer.json` — subject: `[SECURITY] UDA`.

(GitLab confidential issues on a mirror are acceptable if you cannot use GitHub.)

Include: versions, repro steps, impact if known.

## Scope

UDA **library** code, documented public API, and `tools/`. Misconfigured hosts,
app code, or third-party runners are out of scope unless UDA’s documented
behaviour is the direct cause.

## Disclosure

Acknowledge valid reports within a few business days; coordinate release timing
with the reporter when practical.
