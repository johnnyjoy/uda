# Work Order 000 — Guardrails v1

## Authority
Follow docs in this order:
1) constitution.md + style-guide.md
2) contract.md
3) spec.md
4) design.md

## Scope
Allowed to change/add ONLY:
- tools/check-purpose.php
- tools/check-imports.php
- tools/check-pdo-usage.php
- tools/check-execution-path.php
- tools/check-forbidden-names.php
- composer.json
- .github/workflows/* (or your CI config)
- docs/ci.md

## Goal
Add CI-enforced architecture guardrails:
- every src file must have Purpose docblock
- PDO usage only in Driver domain
- exactly one prepare/execute path
- forbid Query->Cache and Cache->Driver imports
- forbid class suffixes: Manager/Service/Engine/Facade/Handler/Controller

## Non-goals
- Do not modify runtime code in src/
- Do not refactor any classes

## Acceptance criteria (must all pass)
- composer check passes
- composer test passes (even if empty suite)
- CI runs composer check

## Evidence required
- show output of: composer check
- list any failing files with clear messages
