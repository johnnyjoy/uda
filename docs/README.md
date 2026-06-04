# UDA documentation

**Universal Data Abstractor** — slim SQL execution for PHP repositories; one API across
major databases, optional read cache. Storefront:
[../README.md](../README.md).

---

## For application developers (start here)

This path targets repository and data classes with explicit SQL.

| Order | Document | Purpose |
| ----- | -------- | ------- |
| 1 | [**building-your-dal.md**](building-your-dal.md) | Layer shape, `Link` vs inject `Database`, rules, examples |
| 2 | [**getting-started.md**](getting-started.md) | Install, connect, builders, transactions, worker sharp edges |
| 3 | [**engines.md**](engines.md) | `uda.json` snippets per database |
| 4 | [**patterns.md**](patterns.md) | Repository recipes (pagination, filters, joins) |
| 5 | [**public-api.md**](public-api.md) | Method reference when you need exact semantics |
| 6 | [**configuration.md**](configuration.md) | Full config schema, cache stores, env |
| 7 | [**caching.md**](caching.md) | Table hints, mtime vs policy vs backend TTL, flush vs clear |

**Also useful:** [product-contract-v1.md](product-contract-v1.md) (v1 promise),
[security.md](security.md) (binding, identifiers), [metrics.md](metrics.md) (query observer).

**Agent skills:** [skills/README.md](../skills/README.md) — checklists for any AI
tool that loads skills; start with `skills/uda-data-access/SKILL.md`.

**Query cookbook:** [query-cookbook.md](query-cookbook.md) — large SQL pattern
reference (maintainer-guarded edits).

---

## For contributors

Changes to UDA itself (`src/UDA/`). Read before editing:

| Document | Purpose |
| -------- | ------- |
| [contract.md](contract.md) | Architectural invariants |
| [spec.md](spec.md) | Full normative specification |
| [constitution.md](constitution.md) | Design philosophy |
| [architecture.md](architecture.md) | Domains, pipeline, pooling |
| [driver.md](driver.md) | Engine vs `UDA\Driver` vocabulary |
| [style-guide.md](style-guide.md) | PHPDoc, naming |
| [integration/README.md](integration/README.md) | CI engine matrix |
| [releases.md](releases.md) | Versioning, tags |

Process: [CONTRIBUTING.md](../CONTRIBUTING.md) at repo root. Local plans under
`docs/plans/` are gitignored drafts when present.

**Do not** point application developers at constitution/spec as “getting started.”

---

## Index by topic

| Topic | Doc |
| ----- | --- |
| DAL / repositories | [building-your-dal.md](building-your-dal.md), [patterns.md](patterns.md) |
| Config / engines | [engines.md](engines.md), [configuration.md](configuration.md) |
| Cache | [caching.md](caching.md) (current runtime), [cache-doctrine.md](cache-doctrine.md) (future design doctrine) |
| API | [public-api.md](public-api.md) |
| Drivers (internals) | [driver.md](driver.md) |
| CI per engine | [integration/](integration/) |
| Changelog | [../CHANGELOG.md](../CHANGELOG.md) |

Legacy maintainer map: [docs-index.md](docs-index.md) (points here).
