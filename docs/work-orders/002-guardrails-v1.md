Work Order 002 — Architecture guard pack

Once the purpose checker is solid, have OpenCode implement the rest of the guardrails:

tools/check-pdo-usage.php

fail if PDO/PDOStatement/prepare/execute appear outside allowed Driver files

tools/check-imports.php

fail on forbidden imports:

Query → Cache

Cache → Driver

Query → PDO

tools/check-forbidden-names.php

fail on class names ending in:

Manager

Service

Engine

Facade

Handler

Controller

tools/check-execution-path.php

verify only one prepare/execute hot path exists

Acceptance:

all checks wired into composer check

CI runs them

failures are human-readable
