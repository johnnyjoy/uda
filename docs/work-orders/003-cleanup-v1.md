After that: Work Order 003 — Config cleanup

Only after guardrails are in place.

Have OpenCode refactor Config to the model you want:

Config::init(?string $file = null)

file-only config

no bootArray

no DSN in config

default connection resolved inside Config

canonical sanitized snapshot returned by Config::connection()

tests for:

env route

explicit file route

same-path reinit no-op

conflicting reinit fails

missing default fails

named/default connection resolution

That’s the first “real code” task I’d trust it with.

Priority order

Fix purpose checker

Add architecture checks

Wire CI/composer scripts

Refactor Config

Then Database::connect ergonomics

Then Driver hot path / Postgres / Cache / Builders

If you want the exact text, I can draft Work Order 001 in the same format as 000
