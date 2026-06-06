# UDA agent skills

Four skills that cover everything an application developer needs to use UDA.
They are plain Markdown with YAML front matter and work with any AI agent that
supports skills or file attachment.

---

## Skill index

| Skill | When to load |
|---|---|
| [uda-start](uda-start/SKILL.md) | **Start here.** Install, configure, first query, verify a working connection. |
| [uda-repository](uda-repository/SKILL.md) | Writing repository classes, `Link` vs `Database`, naming rules, testing with SQLite in-memory, anti-patterns to reject in review. |
| [uda-queries](uda-queries/SKILL.md) | Named params, terminators, table hints, transactions, `QueryException`, streaming with `each()`, cache flush. |
| [uda-deploy](uda-deploy/SKILL.md) | Production config, Redis/Memcached, query observer, multiple connections, deployment checklist, failure runbooks. |

---

## How to load

| Method | Example |
|---|---|
| **Manual attach** | Attach `skills/uda-start/SKILL.md` in chat |
| **Project bootstrap** | Add to `AGENTS.md` or `CLAUDE.md`: `"For UDA setup, follow skills/uda-start/SKILL.md"` |
| **Auto-invocation** | Skills have descriptive front matter — agents that support auto-invocation will load the right skill from the `description` field |

Canonical copies live in **this repo** under `skills/`. Do not depend on IDE-specific
config directories for the authoritative version.

---

## Doc map (humans and agents)

**Application developers:**

1. [docs/getting-started.md](../docs/getting-started.md)
2. [docs/configuration.md](../docs/configuration.md)
3. [docs/building-your-dal.md](../docs/building-your-dal.md)
4. [docs/public-api.md](../docs/public-api.md)
5. [docs/caching.md](../docs/caching.md)
6. [docs/engines.md](../docs/engines.md)

**Contributors** (changing `src/UDA/`): [docs/README.md#for-contributors](../docs/README.md#for-contributors).

---

## Maintainer skills (`.opencode/skills/`)

UDA library internals (naming, domain model, config authority) live under
`.opencode/skills/`. Use those only when modifying UDA itself, not when
building an application.
