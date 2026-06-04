# UDA agent skills (repository)

Executable checklists for building and operating a **database abstraction layer**
on UDA. Skills complement the docs; they do not replace
[docs/building-your-dal.md](../docs/building-your-dal.md).

**UDA is not an ORM.** One execution path; import only `Database` or `Link` in
application code.

---

## Use with any AI agent

Skills in this tree are **agent-agnostic**. They are plain Markdown with YAML
front matter (`name`, `description`). Any tool that supports “skills” (or manual
@‑attachment) can load them:

| How to load | |
| ----------- | -- |
| **Path** | `skills/uda-dal-layer/SKILL.md` (and others below) |
| **Chat** | Attach or reference the file path your agent supports |
| **Project bootstrap** | e.g. `AGENTS.md` / `CLAUDE.md`: “For DAL work on UDA, follow `skills/uda-dal-layer/SKILL.md`.” |
| **Local copy** | Optional symlink into your agent’s skills folder — never required in git |

Canonical copy lives in **this repo** under `skills/`. Do not depend on IDE-only
config directories for discovery.

---

## Skill index

| Skill | When to load |
| ----- | ------------ |
| [uda-dal-layer](uda-dal-layer/SKILL.md) | New repository class, `Database` vs `Link`, layering, anti-patterns |
| [uda-sql-and-cache](uda-sql-and-cache/SKILL.md) | Queries, terminators, table hints, cache flush/clear |
| [uda-config-deploy](uda-config-deploy/SKILL.md) | JSON config, env, Redis/Memcached, FPM vs workers |
| [uda-change-gates](uda-change-gates/SKILL.md) | Before merge to **UDA itself**: guardrails, tests |

---

## Doc map (humans and agents)

**Application developers:**

1. [docs/building-your-dal.md](../docs/building-your-dal.md)
2. [docs/getting-started.md](../docs/getting-started.md)
3. [docs/engines.md](../docs/engines.md)
4. [docs/patterns.md](../docs/patterns.md)
5. [docs/public-api.md](../docs/public-api.md)
6. [docs/configuration.md](../docs/configuration.md)
7. [docs/caching.md](../docs/caching.md)

**Contributors** (changing `src/UDA/`): [docs/README.md#for-contributors](../docs/README.md#for-contributors).

Do not edit `docs/query-cookbook.md` without explicit approval.

---

## Maintainer-only (`.opencode/skills/`)

UDA **library** internals (domain bleed, naming for `src/UDA/`) may live under
`.opencode/skills/`. Use those only when modifying UDA, not when building an
application DAL.
