# UDA agent skills (repository)

Skills for building and operating a **database abstraction layer** on UDA. No ORM.
No second execution path. **Normative docs** remain in `docs/`; skills are
executable checklists for agents and humans.

## Use with your agent

**Canonical source:** this `skills/` tree in git. Skills are **agent-agnostic** — load
from here; nothing in this repo should assume a specific IDE or vendor.

| How to load | |
|-------------|--|
| **Direct path** | Open `skills/<name>/SKILL.md` (see index below). |
| **Chat attach / @** | Reference or attach the skill file path your agent supports. |
| **Project instructions** | Add to your agent bootstrap (e.g. `CLAUDE.md`, `AGENTS.md`): “For application DAL on UDA, read `skills/README.md` and apply the matching skill.” |
| **Local copy** | Optional symlink or copy into your agent's skill directory — never required in git. |

## Skill index

| Skill | When to load |
|-------|----------------|
| [uda-dal-layer](uda-dal-layer/SKILL.md) | New repository class, module layout, `Database` vs `Link` |
| [uda-sql-and-cache](uda-sql-and-cache/SKILL.md) | Queries, writes, table hints, cache flush/clear |
| [uda-config-deploy](uda-config-deploy/SKILL.md) | JSON config, env, Redis/Memcached, FPM vs workers |
| [uda-change-gates](uda-change-gates/SKILL.md) | Before merge: guardrails, tests, blast radius |

## Maintainer-only (`.opencode/skills/`)

UDA **library** internals (domain bleed, naming for `src/UDA`) live under
`.opencode/skills/` (`uda-domain-model`, `uda-naming`, `understand-*`). Do not
mix those into application DAL work unless you are changing UDA itself.

## Doc authority (read order)

1. `docs/getting-started.md`
2. `docs/public-api.md`
3. `docs/architecture.md`
4. `docs/configuration.md`
5. `docs/caching.md`
6. `docs/metrics.md` (query observer — ops)
7. `docs/contract.md`

Do not edit `docs/query-cookbook.md` without explicit approval.
