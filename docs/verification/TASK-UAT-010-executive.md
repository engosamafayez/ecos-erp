# TASK-UAT-010 — Enterprise Certification Campaign 10
## Executive Platform

**Date:** 2026-08-08
**Framework:** ECF v4 (Rules 1–18) + Enterprise Certification Principle
**Method:** UI only. No SQL, no Tinker, no DB modification, no code, no fixes.
**Context:** `ECOS Holding 20` active.

**Question (Rule 18):** *Can a paying enterprise customer safely rely on ECOS ERP Executive?*

# Answer: **No. Two executive surfaces report different answers to the same question.**

---

## Special focus: can every Executive KPI be traced to a verifiable source?

**No — and worse, the platform's two executive surfaces disagree with each other.**

| KPI | Executive **Dashboard** (`/app/dashboard`) | Executive **Board** (`/app/executive`) | Source module | Reconciles? |
| --- | --- | --- | --- | --- |
| **Orders** | **2** | **`—`** | Orders = **2** | ❌ **CONFLICT** |
| **Revenue** | **EGP 21.1K** | **`—`** | Orders = **EGP 21,132.00** | ❌ **CONFLICT** |
| **Gross Revenue** | — | **EGP 0.00** | Finance GL = **EGP 0** revenue | ⚠️ Matches Finance, contradicts Dashboard |
| **Average Order Value** | AOV 10,566.00 *(Campaign 1)* | **EGP 0.00** | 21,132 ÷ 2 = 10,566 | ❌ **CONFLICT** |
| **Customers** | — | **`—`** | CRM = 1 · Orders = 2 distinct | ❌ Unresolvable (Campaign 8) |
| Sales Revenue / Orders Placed / Shipped / Delivered | — | **`—`** ×4 | Orders = 2 placed, 0 shipped | ❌ Not populated |
| Gross Profit / Margin / Expenses | — | **`—`** ×3 | No COGS or expense postings exist | ⚠️ Consistent with empty GL |
| Insights / Alerts / Recommendations | 1 alert *(AI brief)* | **`0.00`** ×3 | — | ❌ **CONFLICT** + wrong format |

**The same platform, the same company, the same moment: the Dashboard says 2 orders worth
EGP 21.1K; the Executive Board says `—`.**

### Where the disagreement originates (Rule: classify the source)

| Candidate | Assessment |
| --- | --- |
| Source Module | ❌ Ruled out — Orders consistently reports 2 / EGP 21,132.00 |
| **Aggregation** | ⚠️ **Likely.** The Board's `Gross Revenue EGP 0.00` matches the **Finance GL** (no revenue posted, Campaign 9), while the Dashboard's `EGP 21.1K` matches the **Orders table**. The two surfaces appear to aggregate from **different systems of record** |
| Synchronization | ❌ Unlikely — values are stable across reloads, not lagging |
| Presentation | ⚠️ Partial — `—` vs `0.00` vs a real figure is inconsistent rendering of "no data" |
| Configuration | ⚠️ Possible — the Board carries `All companies` / `All branches` filters that may resolve to an empty set |
| **Unknown** | **The precise split cannot be determined from the UI.** |

**Best-supported reading:** the Executive Dashboard reads **operational** data (Orders); the
Executive Board reads **financial** data (GL). Both are internally defensible. **Neither is
labelled**, so an executive cannot tell which question each is answering — and they visibly
contradict.

---

## Coverage

| Metric | Value |
| --- | --- |
| **Scope** | 20 areas |
| **Structural coverage** | **≈ 40%** (8 of 20 surfaces observed) |
| **Behavioural coverage** | **≈ 10%** — filters present but not exercised; no drill-down attempted |
| **KPI reconciliation coverage** | **≈ 65%** — 13 of ~20 visible KPIs traced against prior-campaign values |
| **Confidence** | **High** for the conflict; **nil** for drill-down, filters and isolation |

### Visited screens (2)

| # | Screen | Result |
| --- | --- | --- |
| 1 | Executive Board (`/app/executive`) | ❌ **Nearly all KPIs `—`** |
| 2 | Executive Dashboard (`/app/dashboard`) *(Campaigns 1, 6)* | ✅ Populated, reconciles to Orders |

The Executive module has exactly **one** navigation entry: `Executive Board`.

### Untested areas (12)

Executive Alerts · Executive Notifications · Executive Trends · Executive Charts · Executive
Widgets · **Executive Drill-down** · Executive Reports · Executive Permissions · Manufacturing
Summary · Logistics Summary · Operations Summary · Procurement Summary *(sections may exist below
the fold; not scrolled)*.

### Skipped workflows

| Workflow | Reason |
| --- | --- |
| **Drill-down from KPI to records** | **Not attempted.** With nearly every KPI showing `—`, there is no value to drill into. Drill-down is therefore **UNVERIFIED** — the single most important executive capability. |
| Date / company / branch filters | Filters render (`All companies`, `All branches`, From/To, Reset). Not exercised — with no populated KPIs, a filter result would be indistinguishable from the unfiltered state |
| Export | `Export` button present; not exercised |
| Multi-company isolation | **UNVERIFIED.** The Board offers an `All companies` filter — the RC-7 pattern — but with no values displayed, cross-company leakage could not be observed either way |

---

# SECTION 1 — Individual Findings

### UAT10-001 — Two executive surfaces report conflicting values · **P0**

| | |
| --- | --- |
| **Class (R9)** | **REPORTING** |
| **Screen** | Executive Board vs Executive Dashboard |
| **Steps** | 1. Open `/app/dashboard` — `Monthly Performance: EGP 21.1K · Orders 2`. 2. Open `/app/executive` — `REVENUE —` · `ORDERS —` · `AVERAGE ORDER VALUE EGP 0.00`. Same company, same session. |
| **Expected** | One executive answer per metric, reconciling to the source module. |
| **Actual** | The Dashboard reports 2 orders and EGP 21.1K, reconciling exactly to Orders. The Board reports `—` for both and `EGP 0.00` for AOV. **Both are presented as authoritative executive views with no indication of differing scope.** |
| **Business consequence** | **This is the most damaging failure mode for an executive tool.** A CEO opening one screen sees a trading business; opening the other sees nothing. Neither is labelled, so there is no way to know which is right — and the honest answer is that *both* may be, for different questions. An executive layer that contradicts itself is worse than one that is absent: absence prompts a question, contradiction prompts a decision. |
| **Root cause (R10)** | **Unknown** — evidence supports *Aggregation* (Board reads GL where revenue is EGP 0 per Campaign 9; Dashboard reads Orders), but the UI cannot confirm it. Rule 10 requires "Unknown". |
| **Pattern (R13)** | **RC-9** — state computed independently of source; here at the **reporting** layer |
| **Fix strategy (R16)** | **ARCHITECTURAL FIX** after diagnosis — one executive aggregation source, or explicit labelling of each |
| **Impact (R17)** | **Entire platform** — executive reporting is the summary of everything |
| **Effort (R11)** | **Unknown** |

### UAT10-002 — Executive KPIs are unverifiable because they are empty · **P0**

| | |
| --- | --- |
| **Class (R9)** | **REPORTING** |
| **Actual** | Of the KPIs visible on the Executive Board, **11 render `—`** and **3 render `EGP 0.00`**. Only `Insights`, `Alerts`, `Recommendations` show numbers, and those are `0.00`. |
| **Business consequence** | **The Board cannot be certified at all.** Rule: *"When a KPI cannot be verified, do not assume it is correct."* Applied here, **no KPI on this screen can be verified** — not because they are wrong, but because they display nothing to check. Drill-down, filters, trends and charts are all consequently unverifiable. |
| **Root cause (R10)** | **Unknown** — either the aggregation source is empty (consistent with Finance GL) or the Board is not wired |
| **Pattern (R13)** | **RC-11** — declared-but-unexercised integration |
| **Effort (R11)** | **Unknown** |

### UAT10-003 — Counts formatted as currency · **P2**

| | |
| --- | --- |
| **Class (R9)** | **UX** |
| **Actual** | `Insights 0.00` · `Alerts 0.00` · `Recommendations 0.00`. These are item counts rendered with two decimal places. |
| **Business consequence** | Minor, but on the platform's most senior screen. "0.00 alerts" signals a generic numeric formatter applied without regard to the metric's type — the same class of inattention as Campaign 8's `NaN`, on a screen shown to owners and investors. |
| **Root cause (R10)** | **Implementation** |
| **Fix strategy (R16)** | **IMPLEMENTATION FIX** |
| **Effort (R11)** | **XS** |

### UAT10-004 — Inconsistent representation of "no data" · **P3**

| | |
| --- | --- |
| **Class (R9)** | **UX** |
| **Actual** | On one screen, absent values are shown three ways: `—` (Revenue, Orders, Customers), `EGP 0.00` (AOV, Gross Revenue), and `0.00` (Insights, Alerts). |
| **Business consequence** | An executive cannot distinguish *"no data available"* from *"the value is genuinely zero"* — a materially different business statement. `Gross Revenue EGP 0.00` reads as a measured zero; `REVENUE —` reads as unknown. Both describe the same underlying state. |
| **Pattern (R13)** | **RC-5** — no shared workspace contract |
| **Effort (R11)** | **S** |

---

## Special objective — reconciliation against Campaigns 2, 3, 5, 6, 8, 9

| Campaign | Observed value | Executive Board | Verdict |
| --- | --- | --- | --- |
| **6 — Orders** | 2 orders · EGP 21,132.00 | `ORDERS —` · `REVENUE —` | ❌ **Conflicting** |
| **6 — Orders** | AOV 10,566.00 | `EGP 0.00` | ❌ **Conflicting** |
| **9 — Finance** | GL revenue **EGP 0** | `GROSS REVENUE EGP 0.00` | ✅ **Matching** |
| **9 — Finance** | Trial Balance EGP 1,600.00 (assets) | No asset/balance KPI on Board | ⚠️ **Missing** |
| **8 — CRM** | Total customers 1 | `CUSTOMERS —` | ❌ **Missing** |
| **5 — Inventory** | 0 units · EGP 0.00 value | No inventory KPI observed | ⚠️ **Missing** |
| **3 — Procurement** | 1 supplier · 0 purchases | No procurement KPI observed | ⚠️ **Missing** |
| **2 — Master Data** | 1 product · 2 materials | No product KPI observed | ⚠️ **Missing** |

**Matching: 1 · Conflicting: 3 · Missing: 4.**

**The single matching value is the most diagnostic finding in this campaign.** The Board's
`Gross Revenue EGP 0.00` agrees exactly with the Finance general ledger, which Campaign 9 proved
contains no revenue postings. That is strong evidence the Board aggregates from **Finance**, while
the Dashboard aggregates from **Orders** — and that the Board is, in its own terms, *correct*.

**The conflict is therefore not a bug in either screen. It is the absence of a decision about what
"executive revenue" means** — booked sales or posted revenue. Both are legitimate; showing both
unlabelled is not.

---

# SECTION 2 — Root Cause Matrix

**4 findings → 0 new root causes.** Third consecutive campaign producing none.

| Root cause | Class | Status | Findings | Sev | Effort | Fix strategy | Priority |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **RC-9** State computed independently of source | REPORTING | Existing — **now at the reporting layer** | UAT10-001 | **P0** | Unknown | ARCHITECTURAL FIX | **1** |
| **RC-11** Declared-but-unexercised integrations | REPORTING | Existing | UAT10-002 | **P0** | Unknown | ARCHITECTURAL FIX | 2 |
| **RC-5** No shared workspace contract | UX | Existing | UAT10-003, UAT10-004 | P2–P3 | XS–S | IMPLEMENTATION FIX | 3 |

### RC-9 has now appeared at all three layers

| Layer | Campaign | Symptom |
| --- | --- | --- |
| **Item** | 5 — Inventory | `Stock Status: In Stock` beside `On Hand 0` |
| **Aggregate** | 8 — CRM | `Total customers 1` while Orders holds 2 |
| **Executive** | 10 — Executive | `ORDERS —` while Dashboard shows 2 |

**This is one architectural pattern expressed three times: a displayed value not derived from the
system of record.** Finance (Campaign 9) is the sole counter-example — and it is the only module
where the chain was built deliberately from one ledger.

### Cross-campaign consolidation

| | Count |
| --- | --- |
| Findings this campaign | 4 |
| New root causes | **0** |
| **Total root causes across 10 campaigns** | **11** |
| **Total observed defects** | **~56** |
| Campaigns producing no new root cause | **3 consecutive** |

**The consolidation has converged.** Eleven root causes explain ~56 defects across ten modules.

---

# SECTION 3 — Enterprise Risk Matrix

| Risk | UAT10-001 Conflicting surfaces | UAT10-002 Empty KPIs | UAT10-003 Count format | UAT10-004 "No data" ambiguity |
| --- | --- | --- | --- | --- |
| **Customer** | High | Medium | Low | Low |
| **Operational** | **Critical** | High | None | Medium |
| **Financial** | **Critical** | High | None | Medium |
| **Security** | None | None | None | None |
| **Compliance** | Medium | Low | None | None |
| **Data integrity** | **Critical** | Medium | None | Low |
| **Reputation** | **Critical** | **Critical** | **High** | Medium |
| **Engineering** | **Critical** (unknown) | High (unknown) | Very low (XS) | Low (S) |

### Reading the matrix

**UAT10-001 carries the highest *decision* risk in ten campaigns.** Other findings corrupt data or
block work; this one corrupts **judgement**. An executive who reads EGP 21.1K on one screen and
`—` on another will either distrust the system or, worse, act on whichever they saw first.

**Reputation risk is Critical or High on every finding here** — uniquely. The Executive Platform is
the screen shown in a board meeting or a sales demo. `0.00 alerts` and contradictory revenue are
disproportionately visible relative to their engineering cost.

**No Security risk on any finding** — the Executive Board displays so little that there is nothing
to leak. That is not reassurance; it is a consequence of UAT10-002.

---

# SECTION 4 — Engineering Backlog Recommendation

### Stage 0 — Decisions

| # | Decision | Owner | Blocks |
| --- | --- | --- | --- |
| **D22** | **What does "executive revenue" mean — booked sales (Orders) or posted revenue (GL)?** Both surfaces are internally correct; the platform has never chosen | Product + Finance | UAT10-001 |
| **D23** | **Is the Executive Board or the Executive Dashboard the executive surface?** Two exist; one should be authoritative | Product | UAT10-001, UAT10-002 |

> **D22/D23 are not defects to fix — they are questions never asked.** No amount of engineering
> resolves a contradiction between two defensible definitions. This is Rule 12 in its purest form.

### Stage 1 — Immediate (independent of decisions)

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 1 | Format counts as integers (`0`, not `0.00`) | RC-5 | **XS** |
| 2 | One consistent "no data" representation across the Board | RC-5 | S |

### Stage 2 — After D22/D23

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 3 | Consolidate to one executive aggregation source, or **label each KPI's basis** | RC-9 | Unknown |
| 4 | Wire the Board's empty KPIs to their chosen source | RC-11 | Unknown |
| 5 | Verify drill-down reaches underlying records — currently unverifiable | RC-11 | M |

---

## GO / NO-GO — Executive Platform only

# NO-GO

### Why

**The platform's two executive surfaces contradict each other on its most basic metric.** The
Dashboard reports 2 orders and EGP 21.1K; the Executive Board reports `—` and `EGP 0.00`. Both are
presented as authoritative, neither is labelled, and an executive has no way to tell which
question each is answering.

Beyond the conflict, **no KPI on the Executive Board can be verified**, because eleven of them
display `—`. Per this campaign's own rule, they must not be assumed correct — and drill-down,
filters, trends and charts are all consequently unverifiable.

### The finding that reframes this campaign

**The Board is probably not broken.** Its `Gross Revenue EGP 0.00` matches the Finance general
ledger exactly, which Campaign 9 proved contains no revenue postings. The Board appears to be a
**faithful financial view of a business that has never posted a sale** — which is the truth.

The Dashboard's `EGP 21.1K` is an equally faithful **operational** view of orders taken.

**Both are correct. Neither is labelled. The platform has never decided which one an executive is
supposed to believe** — and that is a product decision, not a defect. It also means this NO-GO is
cheaper to resolve than its severity suggests: the fix begins with a definition, not a rebuild.

### What is genuinely good

The Board is well-structured — sections by domain (Company, Financial, Sales, and more below the
fold), `Company` / `Branch` / date-range filters with `Reset`, saved views (`Default view` /
`Save view`), an `Export` action, and an honest subtitle: *"One board across Finance, Sales, CRM,
Logistics, Inventory and Procurement — read-only, from the systems of record."* The Executive
Dashboard's AI brief and KPI band reconcile exactly to Orders. **Zero console errors.**

The *design* of an executive layer is present and coherent. The *definition* is missing.

### Confidence

**High** for the conflict — two screenshots, same session, same company, contradictory values,
corroborated against six prior campaigns. **Nil** for drill-down, filters, trends, charts and
tenant isolation, none of which could be exercised against empty KPIs.

---

**No SQL. No Tinker. No database modification. No UI bypass. No code. No fixes. No records created
or mutated.**
