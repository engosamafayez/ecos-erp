# TASK-UAT-003 — Enterprise Certification Campaign 3
## Procurement Platform

**Date:** 2026-08-08
**Framework:** ECF v4 (Rules 1–18) + Enterprise Certification Principle
**Method:** UI only. No SQL, no Tinker, no DB modification, no code, no fixes.
**Context:** Audited with **`Nile Foods Trading`** active — a company created today that owns no
suppliers, no purchases, no warehouses. An ideal probe for the RC-1 pattern established in
Campaigns 1–2.

**Question (Rule 18):** *Can a paying enterprise customer safely rely on ECOS ERP Procurement?*

# Answer: **No — but for one reason, not many.**

---

## Coverage

**Scope: 19 areas. Audited: 12. Coverage ≈ 63%. Confidence: high for what was audited.**

This is the **highest coverage of any campaign so far**, because Procurement is the most complete
module audited to date — nothing was blocked.

### Visited screens (7)

| # | Screen | Route | Result |
| --- | --- | --- | --- |
| 1 | Procurement Hub | `/app/purchasing/hub` | ✅ Pass |
| 2 | Suppliers | `/app/suppliers` | ❌ **Cross-company leak** |
| 3 | Material Requests | `/app/purchasing/material-requests` | ✅ Pass (empty) |
| 4 | Purchases | `/app/purchasing/purchases` | ⚠️ Cross-company by design |
| 5 | Supplier Invoices | `/app/purchasing/supplier-invoices` | ✅ Pass (empty) |
| 6 | Receiving Center | `/app/purchasing/receiving` | ✅ Pass (empty) |
| 7 | Supplier Returns | `/app/purchasing/supplier-returns` | ✅ Pass (empty) |

### Blocked screens (0)

**No procurement screen was blocked.** Every workflow entry point is present and reachable —
notably better than Campaigns 1 and 2.

### Areas covered without a dedicated screen (5)

| Area | Where it appears |
| --- | --- |
| Supplier Management / Status | Suppliers grid — Active/Inactive filters, saved views (All/Active/**Preferred**) |
| Supplier Health | Suppliers KPI band — `Needs Review 1`, `Delayed Orders 0` |
| Approval Workflows | Purchases status band — Draft → Under Review → Awaiting Supplier → Approved → Purchasing → Receiving → Completed, plus On Hold / Rejected / Cancelled |
| Procurement KPIs | Hub (4 cards) · Purchases (10 cards) · Suppliers (8 cards) |
| Procurement Dashboard | Procurement Hub |

### Untested areas (7)

| Area | Reason |
| --- | --- |
| Purchase creation → approval → receipt → invoice (**the core lifecycle**) | **Deliberately not attempted.** Executing it requires selecting a supplier and warehouse; under the active company both selectors offer **another company's records** (UAT3-001). Creating a purchase order would have written a cross-tenant transaction into a live ledger. As a customer I would not do that, and Rule 3 could not be satisfied without it. |
| Goods Receipt (GRN) execution | Depends on an approved purchase — see above |
| Supplier Returns execution | Depends on a receipt |
| Demand Planning · Suggested Purchases | **No screen found** in Procurement navigation |
| Price History · Supplier Performance | **No dedicated screen found.** Suppliers grid shows `Last Purchase`, `Total Purchased Value`; no trend or history view |
| Procurement Reports | **No Reports screen found** in the module |
| Procurement Notifications | Not generated — requires the lifecycle above |

### Skipped workflows

| Workflow | Reason |
| --- | --- |
| Create purchase / receipt / invoice / return | Cross-tenant selectors made writing unsafe (see above) |
| Import | **No Import control on any procurement screen.** Suppliers offers Export CSV only |
| Bulk actions | Row checkboxes present on Suppliers; no bulk toolbar observed |
| Localization (AR/RTL) | Not re-tested this campaign |
| Permissions | Single administrator account; restricted-role testing remains unavailable |

---

# SECTION 1 — Individual Findings

### UAT3-001 — Suppliers leak across companies · **P0**

| | |
| --- | --- |
| **Class (R9)** | **SECURITY** |
| **Module / Screen** | Procurement → Suppliers |
| **Workflow** | Supplier directory under company context |
| **Steps** | 1. Set active company to **`Nile Foods Trading`** (owns no suppliers). 2. Open **Suppliers**. |
| **Expected (R6)** | **COMPANY SCOPED.** Empty state. |
| **Actual (R6)** | ECOS Holding's supplier `ابراهيم اليمني` (`398830`, phone `01008200808`) displayed, with `Opening Balance`, `Purchase Balance`, `Current Supplier Balance`, `Total Paid`, `Total Purchased Value`. KPI band reports `Total Suppliers 1`, `Active 1`, `Needs Review 1`. |
| **Network (R7)** | `GET /api/suppliers?status=all&page=1&per_page=20&sort_by=created_at&sort_dir=desc` → **200, no `company_id`**. `GET /api/suppliers/stats` → **200, no `company_id`**. On the same page load, `GET /api/brands?…&company_id=019fe003…` and `GET /api/warehouses?…&company_id=019fe003…` **do** carry the tenant. |
| **Console** | Zero errors. |
| **Business consequence (R6)** | Supplier identity, contact details and financial balances exposed to an unrelated tenant. Supplier lists are competitively sensitive — they reveal sourcing relationships and negotiated standing. |
| **Root cause (R10)** | **Implementation** — same omission as RC-1 |
| **Pattern (R13)** | **RC-1** — Tenant scope not applied in the query layer |
| **Fix strategy (R16)** | **ARCHITECTURAL FIX** (part of the RC-1 scope layer) |
| **Impact (R17)** | Cross-module |
| **Effort (R11)** | **S** within the RC-1 fix |

### UAT3-002 — Purchases offers cross-company browsing as a feature · **P1**

| | |
| --- | --- |
| **Class (R9)** | **GOVERNANCE** |
| **Screen** | Procurement → Purchases → filter bar |
| **Steps** | Open Purchases with `Nile Foods Trading` active. Inspect the filter row. |
| **Expected (R6)** | **COMPANY SCOPED** — purchases belong to the active company; no company chooser needed. |
| **Actual** | The filter bar exposes **`Select company…`** alongside `All Warehouses`, plus a `Company` column in the results grid. Cross-company browsing is presented as a normal capability. |
| **Business consequence** | This is not an accidental leak — it is a **deliberate design that contradicts tenant isolation**. Whether a group-level buyer *should* browse across subsidiaries is a legitimate business question, but it has been answered in the UI without an accompanying permission. Every user with Purchases access inherits group-wide visibility. |
| **Rule 12 category** | **Missing Governance Model** — no policy on cross-company procurement visibility |
| **Root cause (R10)** | **Governance Decision** — never made |
| **Fix strategy (R16)** | **PRODUCT DECISION** |
| **Effort (R11)** | **XS** to remove · **M** to gate behind a group-buyer permission |
| **Note** | Distinct from UAT3-001. That is a bug; **this is intent without governance.** Reporting them as one finding would hide the difference. |

### UAT3-003 — No Demand Planning, Suggested Purchases, Price History, Supplier Performance or Reports · **P1**

| | |
| --- | --- |
| **Class (R9)** | **BUSINESS** |
| **Actual** | Five scoped capabilities have no screen. Suppliers surfaces `Last Purchase` and `Total Purchased Value` as columns, and `Needs Review` as a KPI, but there is no trend, history or performance view behind them. No procurement Reports screen exists. |
| **Business consequence** | Procurement can **record** transactions but cannot **inform** them. Buyers cannot see price movement before negotiating, cannot rank suppliers on delivery performance, and cannot plan demand — so reorder decisions rest on memory. For a mid-size distributor this is the difference between an ordering system and a procurement platform. |
| **Rule 12 category** | **Missing Product Decision** (v1.0 scope) |
| **Root cause (R10)** | **Missing Feature** |
| **Pattern (R13)** | **RC-3** — Absent surfaces |
| **Fix strategy (R16)** | **PRODUCT DECISION** then implementation |
| **Effort (R11)** | **XL** |

### UAT3-004 — No Import path anywhere in Procurement · **P2**

| | |
| --- | --- |
| **Class (R9)** | **BUSINESS** |
| **Actual** | Suppliers offers **Export CSV** but no Import. No procurement screen offers Import. Products (Campaign 2) does. |
| **Business consequence** | A new customer must key in their entire supplier master by hand. For a distributor with 200–2,000 suppliers this alone can stall a go-live by weeks, and it is one of the first questions asked in an ERP evaluation. |
| **Root cause (R10)** | **Missing Feature** |
| **Fix strategy (R16)** | **IMPLEMENTATION FIX** |
| **Effort (R11)** | **M** |

### UAT3-005 — `Needs Review 1` is not actionable · **P3**

| | |
| --- | --- |
| **Class (R9)** | **UX** |
| **Actual** | Suppliers KPI shows `Needs Review 1`. The card is not clickable, no filter or saved view corresponds to it, and no row indicator identifies which supplier needs review. |
| **Business consequence** | A KPI that reports a problem without a route to it generates work rather than removing it. |
| **Pattern (R13)** | **RC-5** — no shared list-workspace contract |
| **Fix strategy (R16)** | **IMPLEMENTATION FIX** · **XS** |

---

# SECTION 2 — Root Cause Matrix

Per the Enterprise Certification Principle: **5 findings → 3 root causes, 2 of which already exist.**

| Root cause | Class | New or existing | Findings | Sev | Effort | Fix strategy | Priority |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **RC-1** Tenant scope not applied in query layer | SECURITY | **Existing** — now confirmed in a 3rd module | UAT3-001 | **P0** | S (within RC-1) | ARCHITECTURAL FIX | **1** |
| **RC-3** Administrative & analytical surfaces absent | BUSINESS | **Existing** — now +6 capabilities | UAT3-003, UAT3-004 | P1 | XL | PRODUCT DECISION | 3 |
| **RC-5** No shared list-workspace contract | UX | **Existing** | UAT3-005 | P3 | XS | IMPLEMENTATION FIX | 5 |
| **RC-7** No cross-company visibility policy | **GOVERNANCE** | **NEW** | UAT3-002 | P1 | XS–M | PRODUCT DECISION | **2** |

## RC-7 — No governance model for cross-company visibility *(new)*

| | |
| --- | --- |
| **Rule 12 category** | **Missing Governance Model** |
| **Root cause (R10)** | **Governance Decision** — never made |
| **Evidence** | Purchases exposes a `Select company…` filter and a `Company` column, offering group-wide browsing to any user with module access, with no corresponding permission. |
| **Affected modules** | Procurement confirmed; likely Finance and Executive, which are group-level by nature |
| **Findings explained** | 1 confirmed — but it **reframes RC-1**: some cross-company visibility may be *intended*, so the scope layer cannot simply deny everything |
| **Why it matters more than its count** | RC-1's fix depends on this answer. Enforcing strict isolation everywhere would break a deliberate group-buyer capability; leaving it open leaves the leak. **RC-7 must be decided before RC-1 is implemented.** |
| **Priority** | **2** — gates RC-1 |

### Consolidation

| | Count |
| --- | --- |
| Findings this campaign | **5** |
| New root causes | **1** (RC-7) |
| Findings explained by existing root causes | **4 of 5 (80%)** |
| Modules where RC-1 is now confirmed | **3** — Administration, Master Data, Procurement |

**RC-1 has now been confirmed in every module audited.** It should be treated as
platform-wide until proven otherwise, not as a per-module defect.

---

# SECTION 3 — Enterprise Risk Matrix

| Risk | UAT3-001 Supplier leak | UAT3-002 Cross-company by design | UAT3-003 Missing analytics | UAT3-004 No import | UAT3-005 Dead KPI |
| --- | --- | --- | --- | --- | --- |
| **Customer** | **Critical** | High | High | High | Low |
| **Operational** | Medium | Medium | **Critical** | **Critical** | Low |
| **Financial** | High | Medium | High | Medium | None |
| **Security** | **Critical** | High | None | None | None |
| **Compliance** | **Critical** | **Critical** | Low | None | None |
| **Data integrity** | Medium | Low | None | Medium | None |
| **Reputation** | **Critical** | Medium | Medium | Low | Low |
| **Engineering** | Low | Low | **Critical** (XL) | Medium | Low |

### Reading the matrix

**UAT3-001 is the certification blocker** — Security, Compliance and Reputation all Critical.
Supplier balances and sourcing relationships crossing tenants is a disclosure event.

**UAT3-002 scores Critical on Compliance alone.** Nothing is broken; the system does exactly what
it was built to do. But an ungoverned group-wide visibility capability cannot survive an audit
that asks *"who authorised this user to see subsidiary purchasing?"* — the answer today is
"the menu did".

**UAT3-004 (no import) carries the highest *Operational* risk of any single finding in this
campaign** and is only P2. Nothing is unsafe; it simply means a customer cannot get their data in.
That is a go-live schedule risk, not a correctness risk — and it is exactly the kind of item a
defect-count-driven audit would under-weight.

---

# SECTION 4 — Engineering Backlog Recommendation

**Root causes before individual bugs.**

### Stage 0 — Decisions (no engineering)

| # | Decision | Owner | Blocks |
| --- | --- | --- | --- |
| **D7** | **Is cross-company procurement visibility intended?** If yes, behind which permission? | Product + Business | **RC-1, RC-7** |
| D8 | v1.0 scope for Demand Planning, Suggested Purchases, Price History, Supplier Performance, Reports | Product | RC-3 |

> **D7 is now on the critical path for the whole platform.** RC-1's scope layer cannot be
> implemented until it is known whether "no cross-company data" is the rule or has sanctioned
> exceptions. This did not surface in Campaigns 1–2 and would have caused rework.

### Stage 1 — Certification blockers

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 1 | Add `company_id` to `/api/suppliers` and `/api/suppliers/stats`, **enforced server-side** | RC-1 | S |
| 2 | Fold Procurement into the platform-wide tenant-scope layer | RC-1 | *(within RC-1 M)* |

### Stage 2 — Governance (after D7)

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 3 | Remove the Purchases company filter, **or** gate it behind a group-buyer permission | RC-7 | XS–M |

### Stage 3 — Operational readiness

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 4 | Supplier import (CSV/Excel) with validation and dry-run | RC-3 | M |
| 5 | Make `Needs Review` clickable → filtered view | RC-5 | XS |

### Stage 4 — Capability build (after D8)

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 6 | Price History · Supplier Performance | RC-3 | L |
| 7 | Demand Planning · Suggested Purchases · Reports | RC-3 | XL |

---

## GO / NO-GO — Procurement only

# NO-GO

### Why

One P0: **supplier identity and financial balances are served to a company that owns neither**,
on a request carrying no tenant identifier, while the same page scopes `/api/brands` and
`/api/warehouses` correctly.

That is the **only** correctness defect found in this module.

### What is genuinely strong — and this is the most positive finding of any campaign

Procurement is **the most complete module audited so far**:

- **Zero blocked screens** — every workflow entry point exists and is reachable
- A **real approval workflow**: Draft → Under Review → Awaiting Supplier → Approved → Purchasing → Receiving → Completed, with On Hold / Rejected / Cancelled
- Coherent module design — Hub with alerts and quick actions, keyboard hints (`I`, `M`, `P`), status bands, financial KPI rows, saved views including `Preferred` suppliers
- Honest empty states everywhere: *"No purchases yet. Click 'New Purchase' to create the first purchase order."*
- **Zero console errors across all seven screens**
- `Supplier Invoices` states its own posting semantics in the subtitle: *"Mode 3 Purchasing — supplier invoices post directly to inventory"*

**The procurement workflow model is sound.** Nothing here needs redesign.

### The honest limit of this verdict

**I did not execute a single procurement transaction.** No purchase order, no goods receipt, no
supplier invoice, no return — because the supplier and warehouse selectors offered another
company's records, and creating a purchase order would have written a cross-tenant transaction
into a live ledger.

**Everything I verified is structure, not behaviour.** The workflow *looks* correct and complete;
whether a purchase order actually posts to inventory and the general ledger correctly is
**UNVERIFIED**. The integrations this campaign was asked to check — Inventory, Finance, Products,
Notifications, Executive Dashboard — could not be exercised for the same reason.

**Fixing UAT3-001 unblocks that verification.** It is a small fix that converts ~63% structural
coverage into real end-to-end coverage, and it should be sequenced first for that reason as much
as for the disclosure risk.

### Confidence

**High** for structure, navigation, screen inventory and the request-level evidence.
**Nil** for transactional behaviour and every cross-module integration.

---

**No SQL. No Tinker. No database modification. No UI bypass. No code. No fixes. No records created
or mutated — deliberately, because cross-tenant selectors made writing unsafe.**
