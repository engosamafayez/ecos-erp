# ECF v4 — Consolidated Enterprise Certification Analysis
## Campaigns 1 + 2 · Root Cause Consolidation

**Date:** 2026-08-08
**Framework:** Enterprise Certification Framework v4 (Rules 12–18) + Enterprise Certification Principle
**Inputs:** [Campaign 1 — Platform Initialization](TASK-UAT-001-platform-initialization.md) ·
[Campaign 2 — Master Data](TASK-UAT-002-master-data.md) ·
[Campaign 2 Addendum v3](TASK-UAT-002-master-data-ADDENDUM-v3.md)

**Question (Rule 18):** *Can a paying enterprise customer safely rely on ECOS ERP?*

---

## Headline

**22 observed defects collapse into 5 root causes, 1 unexplained anomaly and 3 genuinely isolated
issues.**

Two of the five root causes are **not engineering problems at all** — they are undecided product
governance. Engineering cannot resolve them and must not invent them (Rule 12).

**The single highest-value action is not a bug fix.** It is deciding the tenant-scope contract,
because that one decision governs 8 of the 22 observed defects and every module not yet audited.

---

# SECTION 1 — Individual Findings

Consolidated inventory. Full reproduction steps, screenshots, console and network evidence live in
the two campaign reports; not duplicated here.

| ID | Finding | Class | Sev | Root cause |
| --- | --- | --- | --- | --- |
| UAT1-001 | Active company operates against another company's warehouse | SECURITY | P0 | **RC-1** |
| UAT1-002 | Created warehouse invisible in list, KPIs and selector (POST 201) | DATA | P0 | **RC-6 (Unknown)** |
| UAT1-003 | No Users / Roles / Permissions UI | BUSINESS | P1 | **RC-3** |
| UAT1-004 | Company creatable with no currency | BUSINESS | P1 | **RC-4** |
| UAT1-005 | Companies search issues no request, does not filter | UX | P2 | **RC-5** |
| UAT1-006 | No guided onboarding; new company dead-ends | UX | P2 | **RC-3** |
| UAT1-007 | Duplicate codes across companies (`BRD-000001`, `BA-000001`) | DATA | P2 | **RC-2** |
| UAT1-008 | Active Business Account with no company or brand | DATA | P2 | **RC-4** |
| UAT1-009 | Channels show `Disconnected` + `Active` simultaneously | UX | P3 | *isolated* |
| UAT1-010 | `Settings` duplicates Configuration OS; two sidebar items highlight | UX | P3 | **RC-5** |
| UAT1-011 | Create Warehouse ignores active company context | UX | P3 | **RC-4** |
| UAT1-012 | ~15 s spinner before 404 | PERFORMANCE | P3 | *isolated* |
| UAT1-013 | First click after load frequently swallowed *(observation)* | UX | P3 | *isolated* |
| UAT1-014 | Inconsistent KPI bands across sibling screens | UX | P3 | **RC-5** |
| UAT2-001a | Units of Measure GLOBAL but tenant-editable/deletable | GOVERNANCE | P1 | **RC-2** |
| UAT2-001b | Categories ownership model undecided | ARCHITECTURE | P1 | **RC-2** |
| UAT2-002 | Product cost / markup / margin visible cross-company | SECURITY | P0 | **RC-1** |
| UAT2-003 | Material costs + `Allow Negative` toggles exposed cross-company | SECURITY | P0 | **RC-1** |
| UAT2-004 | 8 master-data entities have no screen (Price Lists, Storage Locations, …) | BUSINESS | P1 | **RC-3** |
| UAT2-005 | No ownership column or company filter on any master-data grid | UX | P2 | **RC-1** |
| UAT2-006 | Categories lacks the standard toolbar | UX | P3 | **RC-5** |
| UAT2-007 | `/api/warehouses` called twice — one scoped, one not | DATA | P2 | **RC-1** |

---

# SECTION 2 — Root Cause Matrix

## RC-1 — Tenant scope is not applied in the query layer

| | |
| --- | --- |
| **Classification** | **SECURITY** / ARCHITECTURE |
| **Rule 12 category** | Not applicable — this one *is* engineering |
| **Root cause (R10)** | **Implementation.** Not architecture: the context plumbing exists and works. |
| **Evidence** | With `Nile Foods Trading` active: `/api/brands?…&company_id=019fe003…` **carries** the tenant; `/api/products`, `/api/categories`, `/api/suppliers` and `/api/warehouses?per_page=200` **do not**. `/api/warehouses` is called **twice on one page** — once scoped, once not. |
| **Affected modules** | Commerce (Products) · Inventory (Materials, Categories) · Purchasing (Suppliers) · Administration (Warehouses) — **and every module not yet audited** |
| **Affected screens** | ≥ 6 confirmed; realistically **platform-wide** |
| **Findings explained** | **5** — UAT1-001, UAT2-002, UAT2-003, UAT2-005, UAT2-007 |
| **Severity** | **P0** |
| **Effort (R11)** | **M** — S per endpoint, but must include **server-side enforcement**, not just query params |
| **Enterprise impact (R17)** | **Entire platform** |
| **Fix strategy (R16)** | **ARCHITECTURAL FIX** — a single enforced tenant-scope layer, not per-screen patches |
| **Priority** | **1** |

> **Why architectural, not implementation, despite the root cause being implementation:** adding
> `company_id` to five queries fixes five screens and leaves the next fifty unprotected. A
> paying customer cannot rely on a rule enforced by convention. The scope must be enforced where
> it cannot be forgotten — server-side, by default, failing closed.

## RC-2 — No data ownership policy for reference data

| | |
| --- | --- |
| **Classification** | **GOVERNANCE** |
| **Rule 12 category** | **Missing Data Ownership Policy** |
| **Root cause (R10)** | **Governance Decision** — never made |
| **Evidence** | Units (PCS/KG/BOX/LTR/MTR) are shared *and* tenant-editable/deletable. Categories mix system taxonomy (`Raw Materials`, `Packaging Materials`) with merchandising taxonomy (`Electronics`, `Groceries`) under one model. Codes duplicate across companies (`BRD-000001` ×2). |
| **Affected modules** | Inventory master data · Administration (Brands, Business Accounts) |
| **Findings explained** | **3** — UAT2-001a, UAT2-001b, UAT1-007 |
| **Severity** | **P1** |
| **Effort (R11)** | **XS** to enforce once decided · **L** if Categories must be split and migrated |
| **Enterprise impact (R17)** | Cross-module |
| **Fix strategy (R16)** | **PRODUCT DECISION** |
| **Priority** | **2** — blocks RC-1's completion, because scope cannot be enforced on entities whose ownership model is undefined |

> **Engineering must not guess this.** Three questions need answers: (1) Are Units GLOBAL,
> platform-editable only? (2) Is Categories one entity or two — system taxonomy vs merchandising?
> (3) Are codes unique per company or globally? Until answered, any scoping work on these entities
> is speculation.

## RC-3 — Administrative and master-data surfaces are absent

| | |
| --- | --- |
| **Classification** | **BUSINESS** |
| **Rule 12 category** | **Missing Product Decision** (v1.0 scope) |
| **Root cause (R10)** | **Missing Feature** |
| **Evidence** | No UI for Users, Roles, Permissions. No screen for Price Lists, Storage Locations, Customer Groups, Product Types, Inventory Classes, Attributes, Tags, Variants. No Departments, Currencies, Notification Settings or Branding screen in Administration. |
| **Findings explained** | **3** — UAT1-003, UAT2-004, UAT1-006 (11 entities in total) |
| **Severity** | **P1** |
| **Effort (R11)** | **XL** |
| **Enterprise impact (R17)** | Entire platform — blocks self-service onboarding outright |
| **Fix strategy (R16)** | **PRODUCT DECISION** first (what is in v1.0), then implementation |
| **Priority** | **3** |

## RC-4 — Entity creation does not enforce business completeness

| | |
| --- | --- |
| **Classification** | **BUSINESS** |
| **Rule 12 category** | **Missing Business Policy** — what constitutes a valid, operable entity |
| **Root cause (R10)** | **Business Rule** — undefined |
| **Evidence** | A company was created with **no currency**. A Business Account is **Active** with no company and no brand, yet drives a live sales channel. Create Warehouse ignores the active company context. |
| **Findings explained** | **3** — UAT1-004, UAT1-008, UAT1-011 |
| **Severity** | **P1** |
| **Effort (R11)** | **M** |
| **Enterprise impact (R17)** | Cross-module — every create path |
| **Fix strategy (R16)** | **BUSINESS DECISION** then **IMPLEMENTATION FIX** |
| **Priority** | **4** |

## RC-5 — No shared contract for list workspaces

| | |
| --- | --- |
| **Classification** | **UX** |
| **Rule 12 category** | **Missing Enterprise Standard** |
| **Root cause (R10)** | **Implementation** — no shared component contract |
| **Evidence** | Companies search renders a Clear button and issues **no request**; Orders search works correctly. Categories lacks Refresh/Export/Columns/pagination that five sibling grids have. Branches has no KPI band; six siblings do. `Settings` and `Configuration OS` are one page under two nav entries, and the sidebar highlights both. |
| **Findings explained** | **4** — UAT1-005, UAT1-010, UAT1-014, UAT2-006 |
| **Severity** | **P2** |
| **Effort (R11)** | **L** |
| **Enterprise impact (R17)** | Entire platform (every list screen) |
| **Fix strategy (R16)** | **ARCHITECTURAL FIX** — one list-workspace contract |
| **Priority** | **5** |

## RC-6 — Created record invisible after successful write · **UNKNOWN**

| | |
| --- | --- |
| **Classification** | **DATA** |
| **Root cause (R10)** | **Unknown — insufficient evidence** |
| **Evidence** | `POST /api/warehouses` → **201**. The record never appears in the grid, KPIs or selector, across refresh, reload and re-navigation. |
| **Why not folded into RC-1** | RC-1 would predict an **unscoped** list showing **both** warehouses. The list returned **one of two**. That is inconsistent with a simple missing filter, so attributing it to RC-1 would be a guess. **Rule 10 requires "Unknown" here.** |
| **Findings explained** | **1** — UAT1-002 |
| **Severity** | **P0** |
| **Effort (R11)** | **Unknown** — diagnosis required before estimation |
| **Fix strategy (R16)** | **IMPLEMENTATION FIX** after diagnosis |
| **Priority** | **1 (joint)** — a silent write-then-vanish is the most corrosive class of defect to customer trust |

### Isolated findings — no shared pattern

UAT1-009 (channel status contradiction, UX, P3, **XS**) · UAT1-012 (15 s pre-404, PERFORMANCE, P3,
**S**) · UAT1-013 (first-click swallowed, UX, P3, **Unknown** — partly automation artifact).

### Consolidation summary

| | Count |
| --- | --- |
| Observed defects | **22** |
| Root causes | **5** + 1 Unknown |
| Isolated findings | **3** |
| **Defects eliminated by the top 2 root causes** | **8 of 22 (36%)** |
| **Defects eliminated by all root causes** | **19 of 22 (86%)** |
| Root causes that are **not** engineering | **2 of 5** (RC-2, RC-3) |

---

# SECTION 3 — Enterprise Risk Matrix

Rule 15 — *not every P0 has equal business impact.*

| Risk | RC-1 Tenant scope | RC-6 Invisible record | RC-3 Missing surfaces | RC-2 Ownership policy | RC-4 Completeness | RC-5 List contract |
| --- | --- | --- | --- | --- | --- | --- |
| **Customer** | **Critical** | **Critical** | High | Medium | Medium | Low |
| **Operational** | High | **Critical** | **Critical** | Medium | Medium | Low |
| **Financial** | **Critical** | Medium | Medium | Low | High | None |
| **Security** | **Critical** | None | High | Medium | Low | None |
| **Compliance** | **Critical** | Low | High | Medium | Medium | None |
| **Data integrity** | High | **Critical** | Low | **Critical** | High | None |
| **Reputation** | **Critical** | High | Medium | Low | Low | Low |
| **Engineering** | Medium | **High** *(unknown cause)* | **Critical** *(XL)* | Low | Low | Medium |

### The two P0s are not equivalent

**RC-1 is the certification blocker.** Cost price, markup and gross margin crossing tenant
boundaries is a **disclosure event**, not a display bug. Under GDPR-style regimes and any standard
SaaS contract it is reportable, and in a hosted deployment it would likely be a contractual
breach. Financial, Security, Compliance and Reputation risk are all Critical simultaneously —
the only root cause for which that is true.

**RC-6 is the trust blocker.** No data escapes, so security and compliance risk are near zero. But
a system that accepts a record and then denies its existence teaches users they cannot believe the
UI. That is unrecoverable in a way a visible error never is — a customer who sees an error retries;
a customer who sees silence stops trusting every screen.

**RC-3 carries the highest engineering risk (XL) but the lowest urgency**, because it is bounded
and visible: everyone can see the module is absent. Absent capability is honest; wrong data is not.

---

# SECTION 4 — Engineering Backlog Recommendation

**Root causes before individual bugs, as Rule 14 requires.**

### Stage 0 — Decisions (no engineering; blocks Stage 1)

| # | Decision | Owner | Blocks |
| --- | --- | --- | --- |
| D1 | Define the **tenant-scope contract**: which entities are GLOBAL / SHARED / COMPANY SCOPED | Product + Architecture | RC-1, RC-2 |
| D2 | **Units of Measure** — GLOBAL and platform-editable only? | Product | RC-2 |
| D3 | **Categories** — one entity or two (system vs merchandising taxonomy)? | Product | RC-2 |
| D4 | **Code uniqueness** — per company or global? | Product | RC-2 |
| D5 | **v1.0 scope** for the 11 absent entities (IAM UI, Price Lists, Storage Locations, …) | Product | RC-3 |
| D6 | **Entity completeness policy** — what makes a company/account/warehouse operable? | Business | RC-4 |

> D1–D4 are hours of discussion, not days of engineering. **They gate roughly 40% of the backlog.**
> Rule 12: engineering must not invent them.

### Stage 1 — Certification blockers

| # | Work | Root cause | Effort | Eliminates |
| --- | --- | --- | --- | --- |
| 1 | **Diagnose RC-6** — why a 201-created warehouse is invisible. Timebox; do not guess | RC-6 | Unknown | 1 P0 |
| 2 | **Enforce tenant scope server-side, failing closed** — not per-query params | RC-1 | M | 5 findings, 3 of them P0 |
| 3 | Add ownership column + company filter to every master-data grid | RC-1 | S | Makes scope auditable by the customer |

**Exit criterion:** switch company, reload every audited screen, and confirm at **request level**
(Rule 7) that no foreign record is returned.

### Stage 2 — Governance enforcement (after D1–D4)

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 4 | Make GLOBAL reference data read-only to tenants | RC-2 | XS |
| 5 | Split/migrate Categories if D3 requires it | RC-2 | L |
| 6 | Enforce code-uniqueness policy per D4 | RC-2 | S |

### Stage 3 — Business completeness (after D6)

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 7 | Required-field policy on create paths (currency, company) | RC-4 | M |
| 8 | Default create forms from active header context | RC-4 | XS |

### Stage 4 — Platform consistency

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 9 | One list-workspace contract — search, toolbar, KPI band, pagination | RC-5 | L |
| 10 | Remove duplicate `Settings` nav entry; fix active-state matching | RC-5 | XS |

### Stage 5 — Capability build (after D5)

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 11 | IAM administration UI | RC-3 | XL |
| 12 | Price Lists · Storage Locations · Customer Groups · remaining entities | RC-3 | XL |

### Deferred — isolated, low value

Channel status contradiction (XS) · 404 delay (S) · first-click observation (diagnose only).

---

## Certification verdict (Rule 18)

# NOT CERTIFIABLE — but the distance is smaller than the defect count suggests

**Can a paying enterprise customer safely rely on ECOS ERP today? No.** A customer who buys this
and configures two companies will be shown one company's cost prices and margins inside the
other's workspace, and will create records that vanish without error.

**Is the problem engineering?** Only partly — and this is the finding that matters most.

| Category | Root causes | Share of observed defects |
| --- | --- | --- |
| **Engineering** | RC-1, RC-5, RC-6 | 10 of 22 |
| **Governance** | RC-2 | 3 of 22 |
| **Product** | RC-3 | 3 of 22 |
| **Business** | RC-4 | 3 of 22 |

**Two of five root causes cannot be fixed by writing code**, and one of them (RC-2) *gates* the
most severe engineering work. Assigning RC-1 to a developer before D1–D4 are answered would
produce scoping rules for entities whose ownership model is undefined — work that would be
re-done.

### What is genuinely encouraging

The audited surface is **well built**: honest validation that fires no request, commercial-quality
grids and switchers, a strong category hierarchy, thoughtful empty states, correct Arabic
rendering, and **zero console errors across every screen in both campaigns**. There is no evidence
of a fragile or failing application — the failures cluster tightly in tenant scoping and in
decisions that were never made.

**The certification gap is narrow and specific.** Six product decisions and one architectural
scope layer address 8 of 22 observed defects and, more importantly, protect every module not yet
audited. That is a matter of weeks, not a rebuild.

### Confidence

**High** for the two audited campaigns and the request-level evidence. **Nil** for the ~13 modules
never audited — and note that RC-1 predicts the same class of leak throughout them. Coverage to
date: Campaign 1 ≈53%, Campaign 2 ≈33%. **The root causes above should be treated as lower bounds.**
