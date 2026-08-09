# ECOS ERP — Enterprise Certification
## Final Go-Live Assessment

**Date:** 2026-08-08
**Framework:** ECF v4 (Rules 1–18) + Enterprise Certification Principle
**Basis:** Evidence from Campaigns 1–11. No new testing performed.
**Status:** **This document supersedes every previous campaign report and is the official
Enterprise Certification for ECOS ERP.**

---

# FINAL VERDICT

# NO-GO

**ECOS ERP cannot be certified for enterprise go-live.**

Not because it is unstable — it is the most technically stable unfinished product I have audited.
**Zero console errors across eleven campaigns, ~60 screens, two languages and both text
directions.** No crash, no 5xx, no broken asset.

It is NO-GO because in three domains the platform **states things that are not true**, and in two
domains the capability **does not exist at all**.

---

# PHASE 1 — Finding consolidation

| | Count |
| --- | --- |
| Findings raised across 11 campaigns | **~60** |
| After removing duplicates and merging symptoms | **52 distinct** |
| Root causes at end of Campaign 11 | 11 |
| **Root causes surviving verification** | **10** |

### Merges applied

**RC-2 + RC-7 → RC-2 (No multi-tenant governance model).** Both were "nobody decided who may do
what across a tenant boundary", expressed as four symptoms: global reference data editable by
tenants (C2), cross-company filters offered as a feature (C3, C4), `Allow Negative` toggles
ungoverned (C5), and ungoverned CSV export (C11). One missing decision, four faces. Merging is
honest; splitting them produced two backlog items with the same owner and the same question.

### Root causes verified as still supported

All ten retain direct screenshot, network or arithmetic evidence. **RC-6 is retained but flagged
as the weakest** — a single finding with an `Unknown` cause. It was deliberately *not* folded into
RC-1 because RC-1 predicts an unscoped list returning *both* warehouses, while the observed list
returned *one of two*. That inconsistency remains unexplained, and merging it would have
manufactured a false pattern.

---

# PHASE 2 — Root Cause Matrix (rebuilt)

## RC-1 — Tenant scope not applied in the query layer

| | |
| --- | --- |
| **Classification** | **SECURITY** / ARCHITECTURE |
| **Modules** | Administration · Master Data · Procurement · Manufacturing (+ every unaudited module) |
| **Campaigns** | 1, 2, 3, 4 — **confirmed in 4 of 4 modules where data existed to leak** |
| **Severity** | **P0** |
| **Business impact** | Product cost, markup and gross margin; supplier identities and balances; and complete bills of materials are served to companies that own none of them. A BOM reveals product composition, proportions and input cost — a competitor learns how to make the product and what it costs. In a hosted deployment this is a reportable disclosure event and a probable contractual breach. |
| **Effort** | **M** — S per endpoint, but must be enforced server-side, failing closed |
| **Findings explained** | **9** |
| **Confidence** | **High** — request-level evidence. `/api/brands?…&company_id=…` carries the tenant; `/api/products`, `/api/categories`, `/api/suppliers` do not. `/api/warehouses` is called **twice on one page** — once scoped, once not. |

## RC-2 — No multi-tenant governance model

| | |
| --- | --- |
| **Classification** | **GOVERNANCE** |
| **Modules** | Master Data · Procurement · Manufacturing · Inventory · Reporting |
| **Campaigns** | 2, 3, 4, 5, 11 |
| **Severity** | P1 |
| **Business impact** | Global reference data (Units) is tenant-editable and deletable; Categories has no decided ownership model; Purchases and Recipes offer `All companies` browsing with no permission; `Allow Negative` is a one-click row toggle defaulted ON; CSV export exists on ~11 grids with no permission or audit. **RC-1's fix depends on this**: enforcing strict isolation would break a deliberate group-buyer capability. |
| **Effort** | **XS** to enforce once decided · **L** if Categories must be split and migrated |
| **Findings explained** | **7** |
| **Confidence** | High |

## RC-3 — Absent surfaces

| | |
| --- | --- |
| **Classification** | **BUSINESS** |
| **Modules** | **All eleven** |
| **Campaigns** | 1, 2, 3, 4, 5, 6, 7, 8, 9, 11 |
| **Severity** | **P0** (cumulative) |
| **Business impact** | ~55 absent capabilities including **two entire modules** (Manufacturing, Reporting). No user administration, no price lists, no stock entry path, no quotations, no order approval, no tax, no returns, no GL browser, no cash flow, no bank reconciliation, no CRM activities, no reporting of any kind. |
| **Effort** | **XL** |
| **Findings explained** | **21** |
| **Confidence** | **High** — every absence independently confirmed by navigation enumeration |

## RC-4 — Entity creation does not enforce business completeness

| | |
| --- | --- |
| **Classification** | **BUSINESS** |
| **Modules** | Administration |
| **Campaigns** | 1 |
| **Severity** | P1 |
| **Business impact** | A company was created with **no currency** (`POST 201`). A Business Account is **Active** with no company and no brand yet drives a live sales channel. Create forms ignore the active header context. Currency is the root of every monetary value; without it no amount has defined meaning. |
| **Effort** | **M** |
| **Findings explained** | **3** |
| **Confidence** | High |

## RC-5 — No shared list-workspace contract

| | |
| --- | --- |
| **Classification** | **UX** |
| **Modules** | Administration · Master Data · Procurement · Sales · Executive |
| **Campaigns** | 1, 2, 6, 10 |
| **Severity** | P2 |
| **Business impact** | Companies search issues **no request at all** and silently returns wrong results; Orders search requires Enter; Categories lacks the toolbar five sibling grids have; Branches lacks a KPI band; counts render as `0.00`; "no data" is shown three different ways on one screen. Individually cosmetic; collectively they mean no screen can be trusted to behave like its neighbour. |
| **Effort** | **L** |
| **Findings explained** | **7** |
| **Confidence** | High |

## RC-6 — Created record invisible after successful write

| | |
| --- | --- |
| **Classification** | **DATA** |
| **Modules** | Administration |
| **Campaigns** | 1 |
| **Severity** | **P0** |
| **Business impact** | `POST /api/warehouses` → **201**; the warehouse never appears in the grid, KPIs or header selector, across refresh, reload and re-navigation. A customer cannot create their first warehouse — or rather creates it and the system denies its existence, with no error. This blocks onboarding entirely and invites duplicate records. |
| **Effort** | **Unknown** — diagnosis required before estimation |
| **Findings explained** | **1** |
| **Confidence** | **Medium** — the behaviour is certain; the cause is **Unknown**. Weakest root cause in the set. |

## RC-8 — Navigation registry not validated against router

| | |
| --- | --- |
| **Classification** | **BUG** |
| **Modules** | Administration · Manufacturing · Finance · Reporting |
| **Campaigns** | 1, 4, 9, 11 |
| **Severity** | P1 |
| **Business impact** | The command palette advertises **Manufacturing** (hard 404) and **Reports** (Coming Soon). `Settings` and `Configuration OS` are one page under two nav entries with both highlighted. `/app/accounting/financial-statements` 404s while the page lives at `/statements`. A buyer searching for a capability is told it exists, then told it does not. |
| **Effort** | **XS** |
| **Findings explained** | **5** |
| **Confidence** | High |

## RC-9 — State computed independently of the system of record

| | |
| --- | --- |
| **Classification** | **DATA** / REPORTING |
| **Modules** | Inventory · CRM · Executive |
| **Campaigns** | 5, 8, 10 |
| **Severity** | **P0** |
| **Business impact** | **The platform asserts facts that are false.** Item level: `Stock Status: In Stock` beside `On Hand 0`, `Available 0`, with the Stock Ledger showing no movements ever. Aggregate level: `All Materials 0` above a table of 2; CRM `Total customers 1` while Orders holds 2. Executive level: `ORDERS —` while the Dashboard shows `2`. Staff act on these: sales commits stock that does not exist, buyers do not reorder, pickers are dispatched for nothing. |
| **Effort** | **S** (Inventory + CRM) · **Unknown** (Executive) |
| **Findings explained** | **6** |
| **Confidence** | **High** — each contradiction visible within a single screenshot |

## RC-10 — Orchestration without enforcement

| | |
| --- | --- |
| **Classification** | **BUSINESS** |
| **Modules** | Sales & Orders (predicted: Preparation, Shipping, Dispatch, Finance posting) |
| **Campaigns** | 6 |
| **Severity** | **P0** |
| **Business impact** | `Mark Ready` is offered as the **primary** action on an order whose own Inventory tab reads `Not Reserved` with `Assigned Warehouse —`. The state machine exists and the inventory read exists; **neither consults the other.** This is the mechanism by which RC-9's false stock becomes a delivery promise to a paying customer. |
| **Effort** | **M** |
| **Findings explained** | **1 confirmed** — reach across other guarded transitions **unverified** |
| **Confidence** | High for the finding; medium for predicted reach |

## RC-11 — Declared-but-unexercised integrations

| | |
| --- | --- |
| **Classification** | **INTEGRATION** |
| **Modules** | Procurement · Manufacturing · Inventory · Sales · Logistics · CRM · Finance · Executive |
| **Campaigns** | 3, 4, 5, 6, 7, 8, 9, 10 |
| **Severity** | **P0** |
| **Business impact** | **In eleven campaigns, not one cross-module transaction was observed end to end.** Orders runs a live distribution probe against a Logistics module with zero records. EGP 21,132.00 of orders produced **zero** ledger postings. CRM holds one customer while Orders holds two — **actively divergent**, not merely unexercised. |
| **Effort** | **Unknown** — diagnosis first |
| **Findings explained** | **8** |
| **Confidence** | **High** — CRM/Orders divergence is proven, not inferred |

### Consolidation summary

| | Value |
| --- | --- |
| Distinct findings | **52** |
| Root causes | **10** |
| **Findings explained by root causes** | **68 attributions across 52 findings — 100% coverage** |
| Root causes that are **not** engineering problems | **3** — RC-2 (governance), RC-3 (product scope), part of RC-10 (business rule) |
| Top 3 root causes (RC-3, RC-1, RC-11) explain | **38 of 52 findings — 73%** |

---

# PHASE 3 — Enterprise Readiness Matrix

| # | Module | Score | Verdict | Decisive evidence |
| --- | --- | --- | --- | --- |
| 1 | **Platform Initialization** | **2/10** | NO-GO | Cannot create a usable warehouse or a single user; foreign warehouse in active context |
| 2 | **Master Data** | **2/10** | NO-GO | Cost/margin/BOM visible cross-tenant; 8 of 18 entities have no screen |
| 3 | **Procurement** | **4/10** | NO-GO | Best-structured module of the first three; supplier balances leak; no import |
| 4 | **Manufacturing** | **2/10** | NO-GO | **Module does not exist.** BOM is excellent — the best artefact in the platform |
| 5 | **Inventory** | **2/10** | NO-GO | Reports `In Stock` at zero; **no route exists to enter stock at all** |
| 6 | **Sales & Orders** | **5/10** | NO-GO | **Highest score.** Real orchestration, honest inventory reporting, ungated transitions, no tax |
| 7 | **Logistics** | **3/10** | NO-GO | Largest, most sophisticated module; **0% behavioural coverage** — never used |
| 8 | **CRM** | **3/10** | NO-GO | **Best-proven CRUD in the platform**; does not contain the customers who buy |
| 9 | **Finance** | **6/10** | NO-GO | **Highest score. Ledger arithmetically correct.** Has never recorded a sale |
| 10 | **Executive** | **2/10** | NO-GO | Two executive surfaces contradict each other on revenue and order count |
| 11 | **Reporting** | **0/10** | NO-GO | **"Coming Soon" placeholder** |
| | **Platform average** | **2.8/10** | **NO-GO** | |

**No module reaches a passing score. Finance (6) and Sales (5) are the strongest and both are
structurally sound — their failures are starvation and missing guards, not defective design.**

---

# PHASE 4 — Go-Live Blocker Matrix

| Root cause | Classification |
| --- | --- |
| **RC-9** State computed independently of source | **BLOCKER** |
| **RC-1** Tenant scope not applied | **BLOCKER** |
| **RC-6** Created record invisible | **BLOCKER** |
| **RC-10** Orchestration without enforcement | **BLOCKER** |
| **RC-11** Declared-but-unexercised integrations | **HIGH PRIORITY** |
| **RC-4** Creation lacks business completeness | **HIGH PRIORITY** |
| **RC-8** Nav registry vs router | **NORMAL** |
| **RC-5** No list-workspace contract | **POST GO-LIVE** |
| **RC-3** Absent surfaces | **PRODUCT DECISION** |
| **RC-2** No multi-tenant governance model | **GOVERNANCE DECISION** |

---

# PHASE 5 — Must be fixed before production

Classified by **business impact**, not severity.

### 1. RC-9 — The platform must stop asserting falsehoods · **BLOCKER**

Not because it is P0, but because **staff act on it.** `In Stock` at zero stock causes committed
sales, missed reorders and wasted picks. Every other defect either exposes data or blocks work;
this one produces **wrong decisions by people who trusted the screen.** Inventory + CRM portions
are **S**. Highest value-per-hour in the entire assessment.

### 2. RC-10 — Transitions must be gated · **BLOCKER**

Must ship **with** RC-9, not after. RC-9 alone makes inventory truthful but still permits marking
an unreserved order ready; RC-10 alone gates transitions while inventory still lies. Either alone
leaves the failure reachable from the other end. Together they close the chain from false stock to
broken customer promise. **M.**

### 3. RC-1 — Tenant scope must be enforced server-side · **BLOCKER**

Business impact is legal, not operational: cost, margin, supplier balances and BOMs crossing
tenant boundaries is a **disclosure event** under standard SaaS terms. A single-company customer
would never see it; a group customer would breach immediately. Must be enforced where it cannot be
forgotten. **M**, gated on RC-2.

### 4. RC-6 — Silent write-then-vanish must be diagnosed · **BLOCKER**

Onboarding cannot complete. Beyond that, **a system that accepts a record and denies its existence
teaches users the UI cannot be believed** — unrecoverable in a way a visible error never is. Cause
**Unknown**; diagnosis is the deliverable. **Unknown effort.**

### 5. RC-4 (currency only) — **HIGH PRIORITY**

A company with no currency makes every downstream amount undefined. The other RC-4 symptoms can
wait; this one cannot. **S** for the currency guard alone.

### Minimum legal set

Trading legally additionally requires **order tax lines and VAT** (RC-3 subset, **L**). Without
them no compliant invoice can be issued in this tenant's jurisdiction.

---

# PHASE 6 — Can safely wait until after production

### RC-5 — List-workspace inconsistencies · **POST GO-LIVE**
Companies search is the worst symptom and is genuinely misleading, but it affects a screen used
during setup, not daily operations. No data is corrupted and no decision is wrongly informed.
**Fix after go-live, prioritising the search.**

### RC-8 — Navigation vs router · **NORMAL, but do it first anyway**
Zero operational impact — yet it is **XS**, and it removes false capability claims from the first
screen a buyer searches. **Cheapest reputational fix available.** Deferred by impact, promoted by cost.

### RC-3 — Absent surfaces · **PRODUCT DECISION, mostly deferrable**
~55 capabilities cannot be built before go-live, nor should they be. Most are genuinely
post-go-live: CRM activities, price lists, route planning, ABC classification, cash flow.

**Three subsets are not deferrable**, and are carried into Phase 5 above:
- **Tax/VAT** — legal requirement
- **A stock entry path** — without it inventory can never be loaded, so the system cannot start
- **User/role administration** — otherwise the vendor administers every customer's staff

### RC-11 — Integration verification · **HIGH PRIORITY, not a blocker**
Cannot be *fixed* — it must be *measured*. Deferrable only in the sense that a seeded reference
dataset is a verification activity, not a code change. **But note Campaign 8's correction: the
CRM/Orders divergence must be diagnosed before seeding, or seeding enlarges it.**

### RC-2 — Governance model · **GOVERNANCE DECISION, blocks RC-1**
The decision cannot wait even though the enforcement can. Hours of discussion; gates the most
severe engineering work.

---

# PHASE 7 — Enterprise Engineering Backlog

**Ordered by root cause and engineering value — not by finding.**

### Stage 0 — Decisions (no engineering; gate everything below)

| # | Decision | Owner | Gates |
| --- | --- | --- | --- |
| **D-A** | **The tenant-scope contract** — which entities are GLOBAL / SHARED / COMPANY SCOPED, and is cross-company visibility ever intended (and behind which permission)? | Product + Architecture | RC-1, RC-2 |
| **D-B** | **RC-3 portfolio scope** — decide all ~55 absent capabilities **once**, not module by module | Product | RC-3 |
| **D-C** | **Transition preconditions** — what must be true before an order is marked ready, and who may override? | Business + Operations | RC-10 |
| **D-D** | **Customer identity owner** — CRM or Orders? Two populations already exist | Product + Architecture | RC-11 |
| **D-E** | **Executive revenue definition** — booked sales or posted revenue? Both surfaces are internally correct | Product + Finance | RC-9 (executive) |
| **D-F** | **Sales posting trigger** — order, delivery or invoice? No invoice action exists | Finance + Product | RC-11 |

> **Six decisions gate roughly 60% of the backlog. None requires an engineer.** This is the single
> most important observation in this certification.

### Stage 1 — Blockers

| # | Work | RC | Effort | Findings closed |
| --- | --- | --- | --- | --- |
| 1 | Derive `Stock Status` and item-count KPIs from the ledger | RC-9 | **S** | 3 |
| 2 | Gate `Mark Ready` on reservation + warehouse (after D-C) | RC-10 | M | 1 |
| 3 | Diagnose RC-6 — 201 write, invisible record | RC-6 | Unknown | 1 |
| 4 | Enforce tenant scope server-side, failing closed (after D-A) | RC-1 | M | 9 |
| 5 | Require currency on company creation | RC-4 | S | 1 |

**Stage 1 closes 15 of 52 findings and every BLOCKER.**

### Stage 2 — Legal minimum

| # | Work | RC | Effort |
| --- | --- | --- | --- |
| 6 | Tax configuration → order tax lines → VAT return | RC-3 | L |
| 7 | Stock entry path (adjustment / opening balance) | RC-3 | M |
| 8 | IAM administration UI | RC-3 | XL |

### Stage 3 — Cheap, high-visibility

| # | Work | RC | Effort |
| --- | --- | --- | --- |
| 9 | Remove/mark dead palette entries; fix route mismatches | RC-8 | **XS** |
| 10 | Fix `NaN` and count formatting on executive screens | RC-9 | **XS** |
| 11 | Make GLOBAL reference data read-only to tenants (after D-A) | RC-2 | XS |

### Stage 4 — Verification

| # | Work | RC | Effort |
| --- | --- | --- | --- |
| 12 | Diagnose CRM/Orders customer divergence (after D-D) | RC-11 | Unknown |
| 13 | Seed a reference dataset — one full lifecycle | RC-11 | M |
| 14 | Re-run Campaigns 3–11 against it | — | — |

### Stage 5 — Post go-live

RC-5 workspace contract (L) · remaining RC-3 capabilities (XL) · RC-2 remaining enforcement.

---

# PHASE 8 — Executive Summary

### Is the platform technically stable?
**Yes — emphatically.** Zero console errors across eleven campaigns, ~60 screens, both languages
and both text directions. No crash, no 5xx, no broken asset, no hang beyond a slow 404. Validation
consistently fires without issuing a request. **Technical stability is not this platform's problem.**

### Is the architecture stable?
**Partially.** Finance proves the platform *can* build a correctly-derived chain from a single
source of record — ledger → trial balance → balance sheet, arithmetically exact. That capability
exists. But tenant scoping is systematically absent from the query layer, and three modules
compute displayed state independently of their source. **The architecture is sound where it was
applied deliberately and absent where it was assumed.**

### Is the accounting engine trustworthy?
**Yes — for what it contains.** Four journals posted through the real event→rule→journal pipeline
aggregate exactly (`1,000 + 100 = 1,100` on account 1420), balance at `1,600.00 = 1,600.00`, and
derive into a balance sheet where `Assets = Liabilities + Equity`. Fiscal period enforcement
genuinely blocks posting. **The double-entry engine is correct. It has simply never recorded a
sale.**

### Is inventory trustworthy?
**No.** It reports `In Stock` beside `On Hand 0` and `Available 0`, with a Stock Ledger showing no
movement ever. It also reports `All Materials 0` above a table listing two. And **no route exists
to enter stock at all** — procurement is tenant-blocked, manufacturing absent, and there is no
adjustment or transfer screen.

### Is CRM trustworthy?
**No.** It contains one customer who has never ordered, and not the two customers who placed every
order in the system. Every retention and churn metric is computed over a population that excludes
all revenue. Its CRUD engine, by contrast, is the best-proven in the platform.

### Is Executive trustworthy?
**No.** The Dashboard reports 2 orders and EGP 21.1K; the Executive Board reports `—` and
`EGP 0.00`. Both are internally defensible — the Board matches the GL, the Dashboard matches
Orders — but **neither is labelled**, so an executive cannot know which question each answers.

### Can customers safely operate it?
**No.** They cannot create a user, cannot load opening stock, cannot see their own data without
seeing another company's, and will be shown stock that does not exist.

### Can it legally operate?
**No.** No VAT on any order, so no compliant invoice can be issued. No reporting module, no
browsable general ledger, no cash flow statement, no bank reconciliation — a statutory close
cannot be performed. Cross-tenant disclosure of cost, margin and BOM data is reportable under
standard SaaS terms.

### Would you recommend Go-Live today?
**No.**

---

# FINAL VERDICT

# NO-GO

### The shortest honest statement of why

ECOS ERP is a **well-built, technically stable, architecturally capable platform that has never
been run.** In eleven campaigns, **not one cross-module transaction was observed end to end.**
Every integration is declared and inferred; none is demonstrated. Where the platform does hold
data, it is correct — Finance proves that. Where it does not, it invents state rather than
admitting absence.

### What is genuinely good, and should not be lost in the verdict

- **Finance** — the only special-focus consistency test to pass in eleven campaigns, and it passed arithmetically
- **The BOM** — separate costed raw/packaging sections, per-line waste with effective-quantity rollup, live cost with history. The best artefact in the platform
- **Orders** — a real guarded state machine, an Inventory tab that reports inconvenient truths, KPIs reconciling exactly to source
- **Logistics** — the most sophisticated module architecture: claim-based dispatch, blocking conflicts, held resources, fleet compliance tracking
- **CRM CRUD** — the only complete create-and-update lifecycle proven with request evidence
- **Validation discipline** — required-field errors fire inline and **issue no request**, consistently, everywhere
- **Arabic and RTL** — full layout mirror, Arabic-Indic numerals, correct currency, verified programmatically
- **Zero console errors, everywhere, throughout**

### The distance to GO is shorter than 52 findings implies

**Stage 1 is five items — four of them S or M — and it closes every BLOCKER and 15 findings.**
Roughly half the remaining work is not engineering at all: **six product and governance decisions
gate ~60% of the backlog, and not one requires an engineer.**

The failures cluster tightly and are of three kinds only:
1. **Scope never enforced** (RC-1, RC-2) — decide, then enforce once
2. **State not derived from source** (RC-9, RC-10, RC-6) — small fixes, large consequences
3. **Capability never built** (RC-3, RC-11) — a scope decision, not a defect

**None requires redesign.** That is the most important sentence in this certification.

### Certification conditions

ECOS ERP may be re-submitted for certification when:

1. Stage 0 decisions **D-A** through **D-F** are recorded
2. Stage 1 blockers are closed and independently re-verified
3. Tax/VAT, a stock entry path and IAM administration exist
4. A reference dataset exists and **one complete business lifecycle** has been executed end to end
5. Campaigns 3–11 are re-run against real data — **behavioural coverage across the platform is currently under 10%**

---

**Evidence base:** 11 campaigns · ~60 screens · ~52 distinct findings · 10 root causes · request-level
network evidence · arithmetic reconciliation where data existed.
**Method throughout:** UI only. No SQL, no Tinker, no database modification, no UI bypass, no code,
no fixes. Two records were created during eleven campaigns; both are documented for cleanup.
**Confidence:** High for structure and for every finding above. **Low for behaviour** — the platform
has not been operated, and this certification says so plainly rather than inferring success from
rendering.
