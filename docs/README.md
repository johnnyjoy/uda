# UDA Docs Index (Normative Order)

UDA is **Universal Data Abstractor**: a small, fast, cross-DB SQL execution and query composition system.
**The goal is fewer hops, fewer layers, fewer moving parts.** Tight code. High performance. High leverage.

## Directive Precedence (hard)
1. Project prompt / constitution / style-guide (non-negotiables)
2. contract.md (hard rules, compact)
3. spec.md (full normative spec)
4. design.md (how we implement spec)
5. Everything else (informative)

If two docs conflict: **higher wins**. If code conflicts with docs: **code is wrong**.

---

## Start Here (required reading for any change)
- **constitution.md** — values, tone, “why”
- **style-guide.md** — formatting, docblocks, naming
- **contract.md** — short hard rules that must remain true
- **spec.md** — detailed contract + invariants
- **design.md** — implementation plan + directory/domain map

---

## Operational references (read when touching those areas)
- architecture.md — high-level diagram + domain boundaries
- cache-doctrine.md — delivery-man model; cache is transparent + metadata-first
- caching.md — key scheme, TTL policies, invalidation, stores
- security.md — binding rules, fragments, identifier validation
- configuration.md — config structure + validation rules
- drivers.md / driver.md — engine-specific behavior

---

## Usage docs (how apps should use UDA)
- public-api.md — public surface
- repositories.md — where SQL lives; how to centralize DB access
- query-cookbook.md — patterns and examples (IN, EXISTS, HAVING, GROUP BY, BETWEEN, DISTINCT, UPSERT, etc.)

---

## Policy: “doc-first”
Any code change that affects behavior MUST update:
- spec.md (if it changes rules/invariants)
- design.md (if it changes structure/how)
- public-api.md / query-cookbook.md (if it changes developer usage)
- caching.md (if it changes cache behavior)
