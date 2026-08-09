# TASK-UAT-008 — Enterprise Certification Campaign 8
## CRM Platform

**Date:** 2026-08-08
**Framework:** ECF v4 (Rules 1–18) + Enterprise Certification Principle
**Method:** UI only. No SQL, no Tinker, no DB modification, no code, no fixes.
**Context:** `ECOS Holding 20` active.

**Question (Rule 18):** *Can a paying enterprise customer safely rely on ECOS ERP CRM?*

# Answer: **No. CRM does not know about the customers who buy.**

---

## Special focus: is CRM the single customer knowledge platform?

**No. Customer state is fragmented into two disconnected populations.**

| Source | Customers |
| --- | --- |
| **Orders** (Campaign 6) | **2** — `Test` (`01012345678`) and `أحمد محمد` (`01099999999`), EGP 21,132.00 combined |
| **CRM → Customers** | **1** — `RENAMED TestRecord` (`CUST-28B1A8E6`), `No phone`, `No email` |
| **CRM → Executive** | `Total customers 1` · `Active 1` · `New this period 1` |
| **Executive Dashboard** (Campaign 6) | `2 orders · EGP 21.1K` |

**The two customers who placed every order in the system do not exist in CRM.** The single
customer CRM knows about has never ordered — confirmed by its own 360 view, where the **`Orders`
and `Loyalty` tabs are disabled**.

This is not a filtering artefact. CRM's own executive KPI independently reports `Total customers 1`.
Orders and CRM maintain **separate customer identities**, and neither is aware of the other.

**Consequence:** every CRM metric is computed over a population that excludes all buying activity.
Retention `0%`, Churn `0%`, Repeat Customers `0`, At Risk `0` are not measurements — they are
artefacts of an empty denominator. Meanwhile the Executive Dashboard reports EGP 21.1K of revenue
from customers CRM cannot see.

---

## Coverage

| Metric | Value |
| --- | --- |
| **Scope** | 21 areas |
| **Structural coverage** | **≈ 38%** (8 of 21 surfaces observed) |
| **Behavioural coverage** | **≈ 15%** — the **highest of any campaign**: full CRUD was genuinely executed on this module |
| **Confidence** | High for what was observed; nil for the 13 unobserved areas |

### Visited screens (4)

| # | Screen | Result |
| --- | --- | --- |
| 1 | CRM → Customers | ⚠️ Population fragmented |
| 2 | Customer 360 drawer (11 tabs) | ✅ **Excellent structure** |
| 3 | CRM → Executive Overview | ❌ **`NaN` rendered to user** |
| 4 | Customer create / edit drawers | ✅ **Behaviourally verified** |

### Behavioural evidence (carried from prior verified execution on this module)

| Operation | Evidence | Result |
| --- | --- | --- |
| **Create** | `POST /api/crm/customers` → **201**; code auto-generated `CUST-28B1A8E6`; list 0 → 1 | ✅ **PASS** |
| **Read (list)** | `GET /api/crm/customers?page=1&per_page=25` → 200, paginated | ✅ PASS |
| **Read (detail)** | `GET /api/crm/customers/{id}/profile` → 200 | ✅ PASS |
| **Update** | `PATCH /api/crm/customers/{id}` → **200**, persisted across full reload | ✅ **PASS** |
| **Validation** | Required field empty → blocked, **no request issued** | ✅ PASS |
| **Business rule surfaced** | Edit drawer states *"Type and status are set at creation and cannot be changed here."* | ✅ PASS |

**CRM is the only module in eight campaigns where a complete create-and-update cycle has been
proven end to end.**

### Blocked screens (0)

### Untested areas (13)

**No screen found (9):** Activities · Tasks · Meetings · Calls · Opportunities · Campaigns ·
Segments · Customer Groups · CRM Reports.
**Tab exists, not opened (4):** Timeline · Activity · Analytics · Documents.

**Loyalty** appears as a **disabled tab**, implying capability gated on order history rather than
absent.

### Skipped workflows

| Workflow | Reason |
| --- | --- |
| Archive / delete customer | No delete affordance in the list or drawer — consistent with the SSOT design noted in Campaign 1 |
| Timeline / Activity / Analytics tabs | Deprioritised in favour of the fragmentation and `NaN` findings; would show empty states for a customer with no history |
| Multi-company isolation | **Not re-tested.** The only CRM customer belongs to the active company, so nothing could leak or be proven. **UNVERIFIED.** |
| Merge / dedupe | No affordance observed |

---

# SECTION 1 — Individual Findings

### UAT8-001 — Order customers do not exist in CRM · **P0**

| | |
| --- | --- |
| **Class (R9)** | **INTEGRATION** |
| **Screen** | CRM → Customers; CRM → Executive; Orders |
| **Steps** | 1. Open **Orders** — two orders, customers `Test` (`01012345678`) and `أحمد محمد` (`01099999999`). 2. Open **CRM → Customers** — `1 customers`, only `RENAMED TestRecord`. 3. Open **CRM → Executive** — `Total customers 1`. |
| **Expected** | CRM is the customer system of record. Every ordering customer appears, with order history on the profile. |
| **Actual** | Neither ordering customer appears in CRM. The one CRM customer has never ordered — its `Orders` and `Loyalty` tabs are **disabled**. Two independent CRM surfaces (list and executive KPI) agree the population is 1. |
| **Business consequence** | **CRM is blind to the business.** No customer service agent can look up either buyer. No account manager sees purchase history. No segmentation, campaign or loyalty programme can reach anyone who has actually bought. Every retention and churn figure is computed over a population that excludes all revenue. In an ERP the customer record is the join between sales, service and finance — here that join does not exist. |
| **Root cause (R10)** | **Unknown.** Two candidates the UI cannot distinguish: (a) Orders stores customer identity inline rather than referencing CRM; (b) both reference a shared table but CRM filters by a type or origin flag that order-created customers lack. **Rule 10 requires "Unknown"** — I will not guess between them. |
| **Pattern (R13)** | **RC-11** — declared-but-unexercised integration, now with **evidence of active divergence** |
| **Fix strategy (R16)** | **ARCHITECTURAL FIX** after diagnosis |
| **Impact (R17)** | Cross-module — CRM, Orders, Marketing, Executive |
| **Effort (R11)** | **Unknown** — diagnosis first |

### UAT8-002 — `NaN` rendered in an executive KPI · **P1**

| | |
| --- | --- |
| **Class (R9)** | **DATA** |
| **Screen** | CRM → Executive Overview → Customer growth |
| **Steps** | Open CRM Executive with `Monthly / 2026`. |
| **Actual** | The **`ACQUIRED`** card displays literally **`NaN`**, beside `OPENING 0`, `CLOSING 1`, `GROWTH RATE 100%`. |
| **Business consequence** | A raw JavaScript `NaN` on an executive dashboard is the most visible possible signal of an unfinished product — and this screen is one an owner or investor would be shown. It also undermines the three neighbouring figures: if one is wrong, none can be trusted. |
| **Root cause (R10)** | **Implementation** — near-certainly a division with `OPENING = 0` as the denominator, unguarded. Consistent with `GROWTH RATE 100%` being computed on the same inputs by a different path. |
| **Fix strategy (R16)** | **IMPLEMENTATION FIX** — guard the zero-denominator case; render `—` as the platform does elsewhere |
| **Impact (R17)** | 1 screen |
| **Effort (R11)** | **XS** |

### UAT8-003 — Nine CRM capabilities have no screen · **P1**

| | |
| --- | --- |
| **Class (R9)** | **BUSINESS** |
| **Actual** | No screen for **Activities · Tasks · Meetings · Calls · Opportunities · Campaigns · Segments · Customer Groups · CRM Reports**. The CRM module contains exactly **two** navigation entries: `Customers` and `Executive Overview`. |
| **Business consequence** | Without activities, tasks, calls or meetings there is **no way to record an interaction with a customer** — which is the primary purpose of CRM. Without opportunities there is no pipeline. Without segments or groups there is no targeting. What exists is a customer *directory* with analytics, not a customer *relationship management* system. |
| **Root cause (R10)** | **Missing Feature** |
| **Pattern (R13)** | **RC-3** |
| **Effort (R11)** | **XL** |
| **Note** | The 360 drawer's tab strip (`Timeline`, `Activity`, `Notes`, `Documents`) implies these were designed. They may exist per-customer while lacking module-level workspaces — which would still leave no way to see "all my tasks today". |

### UAT8-004 — CRM retention metrics are structurally meaningless · **P2**

| | |
| --- | --- |
| **Class (R9)** | **REPORTING** |
| **Actual** | `RETENTION RATE 0%` · `CHURN RATE 0%` · `REPEAT CUSTOMERS 0` · `AT RISK 0`, computed over one customer who has never transacted. Weekly acquisition shows `W1: 1`, `W2–W5: 0`. |
| **Business consequence** | These are not measurements; they are artefacts of UAT8-001. A user cannot distinguish *"0% churn because retention is perfect"* from *"0% churn because no customer has ever bought"*. The screen presents the second as though it were the first. |
| **Root cause (R10)** | Consequence of **UAT8-001** |
| **Fix strategy (R16)** | **IMPLEMENTATION FIX** — suppress or annotate metrics below a minimum population |
| **Effort (R11)** | **S** |

---

# SECTION 2 — Root Cause Matrix

**4 findings → 0 new root causes.** All map to established patterns.

| Root cause | Class | Status | Findings | Sev | Effort | Fix strategy | Priority |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **RC-11** Declared-but-unexercised integrations | INTEGRATION | **Existing — now with hard evidence** | UAT8-001, UAT8-004 | **P0** | Unknown | ARCHITECTURAL FIX | **1** |
| **RC-3** Absent surfaces | BUSINESS | Existing | UAT8-003 | P1 | XL | PRODUCT DECISION | 2 |
| **RC-9** State computed independently of source data | DATA | Existing | UAT8-002 | P1 | XS | IMPLEMENTATION FIX | 3 |

### RC-11 is upgraded by this campaign

Campaign 7 identified RC-11 as *"integrations declared but never exercised"* — an **absence** of
evidence. **Campaign 8 supplies presence of evidence: the integration is not merely unexercised,
it is actively divergent.** Two modules maintain two customer populations, and both report their
own as complete.

That changes the recommendation. A reference dataset (Campaign 7's proposal) would **not** fix
this — it would populate both sides and make the divergence larger. **RC-11 now requires
diagnosis before seeding**, not seeding to enable diagnosis.

### Cross-campaign consolidation

| | Count |
| --- | --- |
| Findings this campaign | 4 |
| New root causes | **0** |
| Explained by existing causes | **4 of 4 (100%)** |
| **Total root causes across 8 campaigns** | **11** |
| **Total observed defects** | **~48** |

**First campaign to produce no new root cause.** Eleven causes now explain roughly 48 defects
across eight modules — the consolidation is converging.

---

# SECTION 3 — Enterprise Risk Matrix

| Risk | UAT8-001 Fragmented customers | UAT8-002 `NaN` KPI | UAT8-003 No CRM capabilities | UAT8-004 Meaningless metrics |
| --- | --- | --- | --- | --- |
| **Customer** | **Critical** | Low | High | Medium |
| **Operational** | **Critical** | Low | **Critical** | Medium |
| **Financial** | High | Low | Medium | Medium |
| **Security** | None | None | None | None |
| **Compliance** | Medium | None | Low | Low |
| **Data integrity** | **Critical** | Medium | None | High |
| **Reputation** | High | **Critical** | Medium | Medium |
| **Engineering** | **Critical** (unknown) | Very low (XS) | **Critical** (XL) | Low (S) |

### Reading the matrix

**UAT8-001 is the highest data-integrity risk found in eight campaigns.** Previous integrity
findings were *wrong values* (Campaign 5's false `In Stock`). This is **two authoritative records
of the same real-world entity**, each complete in its own view. That is harder to detect, harder to
reconcile, and it worsens with every order placed.

**UAT8-002 scores Critical on Reputation alone** — near-zero engineering risk (**XS**), no
operational impact, but a raw `NaN` on an executive screen shapes a buyer's judgement of the whole
product in seconds. **Best reputation-per-hour fix identified in eight campaigns.**

**UAT8-003 is Critical Operationally** — a CRM that cannot log a call or a task cannot be used by a
service team at all.

---

# SECTION 4 — Engineering Backlog Recommendation

### Stage 0 — Decisions

| # | Decision | Owner | Blocks |
| --- | --- | --- | --- |
| **D18** | **Which system owns customer identity — CRM or Orders?** Every downstream fix depends on this | Product + Architecture | RC-11 / UAT8-001 |
| D19 | Is CRM in v1.0 as a relationship platform, or shipping as a customer directory? | Product | UAT8-003 |

> **D18 is not a preference — it is a data-model decision with migration consequences.** Two
> populations already exist; choosing an owner determines what happens to the other.

### Stage 1 — Immediate

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| **1** | **Fix the `NaN`** — guard the zero-denominator, render `—` | RC-9 | **XS** |
| 2 | **Diagnose UAT8-001** — do Orders and CRM share a customer table? | RC-11 | Unknown |

> Item 1 is minutes of work on the screen most likely to be demonstrated to a buyer.
> Item 2 must precede any reference-dataset work.

### Stage 2 — After D18

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 3 | Unify customer identity; backfill order customers into CRM | RC-11 | L–XL |
| 4 | Suppress/annotate retention metrics below minimum population | RC-9 | S |

### Stage 3 — After D19

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 5 | Activities · tasks · calls · meetings | RC-3 | XL |
| 6 | Opportunities · segments · customer groups · CRM reports | RC-3 | XL |

---

## GO / NO-GO — CRM only

# NO-GO

### Why

**CRM does not contain the customers who buy.** Orders records two customers with EGP 21,132.00
of business; CRM shows one customer who has never transacted and reports `Total customers 1` from
its own executive API. A customer service agent cannot look up either buyer. Every retention,
churn and acquisition figure is computed over a population that excludes all revenue.

And the executive screen an owner would be shown displays a literal **`NaN`**.

### What is genuinely strong — and it is real

CRM has the **best-proven behaviour of any module in eight campaigns.** It is the only one where a
complete lifecycle has been executed end to end with request evidence: create (`201`), read,
update (`PATCH 200`, persisted across reload), and validation that blocks submission **without
issuing a request**. The Customer 360 drawer is well-conceived — 11 tabs with live count badges,
tabs correctly **disabled** when the underlying capability has no data (`Orders`, `Loyalty`), and
an edit form that states its own business rule: *"Type and status are set at creation and cannot
be changed here."*

The CRM Executive screen also **discloses its own scope honestly**: *"These figures cover the whole
company. The executive API takes no branch filter."* That is unusually candid instrumentation.

**The CRUD engine works. The customer model is disconnected.**

### The honest limit

Multi-company isolation is **UNVERIFIED** — the only CRM customer belongs to the active company,
so nothing could leak or be proven. Nine of the scoped capabilities have no screen, so most of
"CRM" could not be assessed at all.

### Confidence

**High** for the fragmentation finding — corroborated by three independent surfaces (Orders grid,
CRM list, CRM executive KPI). **High** for the CRUD evidence. **Nil** for the nine absent
capabilities and for tenant isolation.

---

**No SQL. No Tinker. No database modification. No UI bypass. No code. No fixes. No records created
or mutated this campaign.**
