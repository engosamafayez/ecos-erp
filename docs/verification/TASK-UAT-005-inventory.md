# TASK-UAT-005 — Enterprise Certification Campaign 5
## Inventory Platform

**Date:** 2026-08-08
**Framework:** ECF v4 (Rules 1–18) + Enterprise Certification Principle
**Method:** UI only. No SQL, no Tinker, no DB modification, no code, no fixes.
**Context:** `ECOS Holding 20` active — the tenant owning the only warehouse.

**Question (Rule 18):** *Can a paying enterprise customer safely rely on ECOS ERP Inventory?*

# Answer: **No. Inventory reports stock it does not have.**

---

## Special focus: is Inventory the system of record?

**No. There are at least two inventory states, and they disagree on the same screen.**

| Source | Says |
| --- | --- |
| Inventory Dashboard | `Total Inventory Value EGP 0.00` · **`0 units on hand`** · `Available Units 0` · `0 reserved` |
| Stock Ledger | **`No movements found`** — the audit trail is empty |
| Raw Materials — quantity columns | `On Hand 0` · `Reserved 0` · `Available 0` (red) · `Inventory Value EGP 0.00` |
| Raw Materials — **`Stock Status` column** | **`In Stock`** (green) on **both** materials |
| Raw Materials — **`All Materials` KPI** | **`0`** — while the grid beneath it lists **2 materials** |
| Products (Campaign 2) | `In Stock 1` KPI and `Stock Status: In Stock` badge |

**Dashboard and Stock Ledger agree: there is no stock, and no movement ever created any.** They
are consistent and, on the evidence, correct.

**`Stock Status` agrees with nothing.** It is not derived from the quantity displayed beside it,
from the ledger, or from the dashboard. The `All Materials` KPI does not agree with its own table.

**Root-cause candidate confirmed:** inventory *quantity* has a single authoritative source, but
inventory *state* (`Stock Status`, item-count KPIs) is computed somewhere else, from something
else. That is **RC-9** below.

---

## Coverage

**Scope: 27 areas. Audited: 7. Coverage ≈ 26%. Confidence: high.**

### Visited screens (6)

| # | Screen | Route | Result |
| --- | --- | --- | --- |
| 1 | Inventory Dashboard | `/app/inventory/dashboard` | ✅ Internally consistent |
| 2 | Stock Ledger | `/app/stock-ledger` | ✅ Consistent (empty) |
| 3 | Raw Materials / All Materials | `/app/raw-materials` | ❌ **Contradicts itself twice** |
| 4 | Inventory Count | `/app/inventory/count` | ✅ Pass (empty, full status band) |
| 5 | Waste Investigations | `/app/inventory/waste-investigations` | ⚠️ Listed; not opened |
| 6 | Warehouse Liabilities | `/app/inventory/warehouse-liabilities` | ⚠️ Listed; not opened |

### Blocked screens (0)

### Untested areas (20)

**No screen exists (11):** Goods Issues · Stock Adjustments · Warehouse Transfers · Transfer
Requests · Inventory Layers (FIFO) · Inventory Valuation · ABC Classification · Damaged Inventory ·
Inventory Reports · Inventory by Company · Inventory Transactions *(Stock Ledger may be this)*.

**Screen exists, could not be exercised (9):** Goods Receipts · Inventory Reservations ·
Inventory Availability · Blind Count · Count Approval · Inventory Variance · Waste Management ·
Inventory Notifications · Inventory Permissions.

### Skipped workflows — and the reason they compound

| Workflow | Reason |
| --- | --- |
| Receive stock (GRN) | **Procurement blocked** (Campaign 3, UAT3-001): supplier selectors offer another tenant's records |
| Produce stock | **Manufacturing does not exist** (Campaign 4, UAT4-001) |
| Count → variance → approval | Requires stock on hand |
| FIFO layers · valuation · cost accuracy | Requires at least two receipts at different costs |
| Reservations | Requires stock and an order |
| Transfers | **No transfer screen exists** |

> **This is the campaign's structural finding.** Inventory cannot be filled through *any* route the
> product offers: procurement is tenant-blocked, manufacturing is absent, and there is no manual
> adjustment or transfer screen. **A customer has no way to get opening stock into ECOS ERP.**
> Every quantitative inventory behaviour is therefore unverifiable — not because I ran out of time,
> but because the system provides no entry point.

---

# SECTION 1 — Individual Findings

### UAT5-001 — `Stock Status` reports "In Stock" when stock is zero · **P0**

| | |
| --- | --- |
| **Class (R9)** | **DATA** |
| **Screen** | Inventory → Raw Materials (All Materials); also Commerce → Products |
| **Steps** | 1. `ECOS Holding 20` active. 2. Open **Raw Materials**. 3. Compare the `Stock Status` column to `On Hand` / `Available` on the same row. |
| **Expected** | `Stock Status` derives from available quantity. `On Hand 0`, `Available 0` → **Out of Stock**. |
| **Actual** | Both materials — `بطرمان كيلو` (`RM-000002`) and `عسل الصال` (`RM-000001`) — display a green **`In Stock`** badge while showing `On Hand 0`, `Reserved 0`, `Available 0` (rendered in **red**), `Inventory Value EGP 0.00`. The same pattern appears on Products: `Stock Status: In Stock`, KPI `In Stock 1`, against `Total Inventory Value EGP 0.00`. |
| **Corroboration** | Inventory Dashboard reports `0 units on hand`. Stock Ledger reports `No movements found` — **no receipt has ever occurred**, so stock cannot exist. |
| **Business consequence** | **This is the most dangerous finding across five campaigns.** A salesperson checking availability sees *In Stock* and commits to a customer. A buyer sees *In Stock* and does not reorder. A picker is dispatched for goods that were never received. Unlike a leak — which exposes data — this **causes wrong operational decisions and broken customer promises**. It also silently corroborates the Orders module: order `ORD-00001` sits in `Awaiting Stock`, which is correct, while the material it needs is labelled *In Stock*. Two screens, opposite answers, same question. |
| **Root cause (R10)** | **Implementation** — status is not derived from the quantity beside it. Whether it defaults to a constant or reads a stale field is **Unknown**; the UI cannot distinguish these. |
| **Pattern (R13)** | **RC-9** — inventory state computed independently of inventory quantity |
| **Fix strategy (R16)** | **IMPLEMENTATION FIX** — derive status from available quantity at one place |
| **Impact (R17)** | Cross-module — Inventory, Products, Orders, and any availability check |
| **Effort (R11)** | **S** |

### UAT5-002 — `All Materials` KPI reports 0 while listing 2 · **P1**

| | |
| --- | --- |
| **Class (R9)** | **DATA** |
| **Steps** | Open Raw Materials. Compare the `All Materials` KPI card with the row count immediately below it. |
| **Expected** | `All Materials 2`. |
| **Actual** | KPI reads **`0`**; the grid lists **2 materials**. Both are on screen simultaneously. |
| **Business consequence** | A KPI contradicted by the table directly beneath it destroys trust in every other figure on the page — including the four adjacent cards a customer would otherwise rely on. Rule 4 violation **within a single viewport**. |
| **Root cause (R10)** | **Implementation** — the stats endpoint and the list endpoint answer differently. Campaign 2 recorded `/api/products/stats?product_types=…` and `/api/products?…product_types=…` as separate calls; the stats call plausibly applies a filter the list does not. **Unknown** without server visibility. |
| **Pattern (R13)** | **RC-9** |
| **Fix strategy (R16)** | **IMPLEMENTATION FIX** |
| **Effort (R11)** | **S** |

### UAT5-003 — Stock cannot be created by any available route · **P0**

| | |
| --- | --- |
| **Class (R9)** | **BUSINESS** |
| **Actual** | The three ways to bring inventory into an ERP are all unavailable: **(a) Purchase receipt** — Procurement's supplier selector serves another tenant's records (UAT3-001), so a GRN cannot be raised safely; **(b) Manufacturing** — the module does not exist (UAT4-001); **(c) Manual adjustment / opening balance / transfer** — **no screen exists**. Inventory Count offers `New Count Session`, but counting presupposes stock and is a reconciliation tool, not an entry point. |
| **Business consequence** | **A new customer cannot load opening stock.** Day one of an ERP implementation is loading balances; there is no path. This also makes FIFO, valuation, layers, reservations, variance and ABC permanently unverifiable — and, more importantly, unusable. |
| **Root cause (R10)** | **Missing Feature** (adjustments/transfers) compounded by **RC-1** (procurement) and **RC-3** (manufacturing) |
| **Pattern (R13)** | **RC-3**, amplified by RC-1 |
| **Fix strategy (R16)** | **PRODUCT DECISION** then implementation |
| **Effort (R11)** | **L** for adjustments + transfers |

### UAT5-004 — Eleven inventory capabilities have no screen · **P1**

| | |
| --- | --- |
| **Class (R9)** | **BUSINESS** |
| **Actual** | No screen for Goods Issues · Stock Adjustments · Warehouse Transfers · Transfer Requests · **Inventory Layers (FIFO)** · **Inventory Valuation** · ABC Classification · Damaged Inventory · Inventory Reports · Inventory by Company. |
| **Business consequence** | **FIFO layers and valuation are the two that matter most.** Without a visible layer view, a finance team cannot substantiate closing stock value at audit — and inventory valuation flows directly into the balance sheet. Without transfers, a multi-warehouse operation cannot move goods. `Warehouse Liabilities` and `Waste Investigations` exist, which are comparatively advanced features — the *basics* are what is missing. |
| **Root cause (R10)** | **Missing Feature** |
| **Pattern (R13)** | **RC-3** |
| **Effort (R11)** | **XL** |

### UAT5-005 — `Allow Negative` is a live toggle with no visible governance · **P2**

| | |
| --- | --- |
| **Class (R9)** | **GOVERNANCE** |
| **Actual** | Each material row carries an **enabled `Allow Negative` toggle**, switchable directly from the list with no confirmation dialog and no visible permission gate. Both materials currently have it **ON**. |
| **Business consequence** | Permitting negative stock is a controlled accounting decision — it allows issuing goods that do not exist and drives inventory valuation negative. Exposing it as a one-click row control, defaulted ON, with no confirmation and no audit prompt, is a governance gap rather than a coding defect. Combined with UAT5-001 it is compounding: the system says *In Stock* when empty, **and** permits going below zero. |
| **Rule 12 category** | **Missing Governance Model** — who may permit negative stock, and on what authority |
| **Root cause (R10)** | **Governance Decision** — never made |
| **Fix strategy (R16)** | **PRODUCT DECISION** then permission gate + confirmation |
| **Effort (R11)** | **S** |

---

# SECTION 2 — Root Cause Matrix

**5 findings → 1 new root cause; 4 map to existing patterns.**

| Root cause | Class | Status | Findings | Sev | Effort | Fix strategy | Priority |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **RC-9** Inventory state computed independently of inventory quantity | **DATA** | **NEW** | UAT5-001, UAT5-002 | **P0** | S | IMPLEMENTATION FIX | **1** |
| **RC-3** Absent surfaces | BUSINESS | Existing | UAT5-003, UAT5-004 | **P0** | XL | PRODUCT DECISION | 2 |
| **RC-7** No governance model for controlled capabilities | GOVERNANCE | Existing *(extended)* | UAT5-005 | P2 | S | PRODUCT DECISION | 3 |

## RC-9 — Inventory state is computed independently of inventory quantity *(new)*

| | |
| --- | --- |
| **Rule 12 category** | Not applicable — engineering |
| **Root cause (R10)** | **Implementation** |
| **Evidence** | On one screen, one row: `Stock Status = In Stock` beside `On Hand 0`, `Available 0`. On the same screen: `All Materials KPI = 0` above a table of 2. Dashboard and Stock Ledger — the two quantity-derived views — agree with each other and contradict both. |
| **Why this is one root cause, not two findings** | Both are the same failure: **a displayed inventory fact that is not derived from the ledger.** Status and count are different symptoms of one missing derivation. Fixing them separately would leave the third, fourth and fifth symptom to be found later. |
| **Affected modules** | Inventory · Products · Orders (availability) · anything consuming stock state |
| **Findings explained** | 2 confirmed — **and it predicts more wherever stock state is displayed** |
| **Priority** | **1** — cheapest severe fix in the campaign, and the only one that makes displayed inventory trustworthy |

### Cross-campaign consolidation

| | Count |
| --- | --- |
| Findings this campaign | 5 |
| New root causes | **1** (RC-9) |
| Explained by existing root causes | 3 of 5 (60%) |
| **Total root causes across 5 campaigns** | **9** (RC-1 … RC-9) |
| **Total observed defects across 5 campaigns** | **~36** |

**RC-1 did not produce a new finding here** — Inventory screens showed only the active company's
(empty) data. That is not evidence of correct scoping: with zero stock there was nothing to leak.
**Inventory tenant isolation is UNVERIFIED, not passed.**

---

# SECTION 3 — Enterprise Risk Matrix

| Risk | UAT5-001 False "In Stock" | UAT5-003 No stock entry | UAT5-004 No FIFO/valuation | UAT5-002 KPI mismatch | UAT5-005 Allow Negative |
| --- | --- | --- | --- | --- | --- |
| **Customer** | **Critical** | **Critical** | High | Medium | Medium |
| **Operational** | **Critical** | **Critical** | High | Low | High |
| **Financial** | **Critical** | High | **Critical** | Low | **Critical** |
| **Security** | None | None | None | None | Low |
| **Compliance** | Medium | Low | **Critical** | None | High |
| **Data integrity** | **Critical** | Medium | High | High | **Critical** |
| **Reputation** | **Critical** | High | Medium | Medium | Low |
| **Engineering** | Low (S) | High (L) | **Critical** (XL) | Low (S) | Low (S) |

### Reading the matrix

**UAT5-001 is the most operationally dangerous finding in five campaigns.** Every prior P0 was a
*disclosure* — data reaching someone who should not see it, damaging but passive. This one is
**active**: the system asserts a fact that is false, and staff act on it. Sales commits stock that
does not exist; procurement does not reorder; pickers are dispatched for nothing. Customer,
Operational, Financial, Data-integrity and Reputation risk are all Critical, and the fix is **S**.

**UAT5-004 carries the only Critical Compliance risk here.** Inventory valuation feeds the balance
sheet; without a FIFO layer view, closing stock cannot be substantiated at audit.

**UAT5-005 scores Critical on Financial and Data integrity despite being P2** — because negative
stock, once permitted by default, corrupts valuation quietly and retrospectively.

---

# SECTION 4 — Engineering Backlog Recommendation

### Stage 0 — Decisions

| # | Decision | Owner | Blocks |
| --- | --- | --- | --- |
| **D11** | **How does opening stock enter the system?** Manual adjustment, import, or receipt-only? | Product + Business | RC-3 / UAT5-003 |
| D12 | Who may enable `Allow Negative`, and should it default OFF? | Business + Finance | RC-7 / UAT5-005 |
| D9 *(carried)* | Is Manufacturing in v1.0? | Product | UAT5-003 |
| D7 *(carried)* | Cross-company visibility policy | Product | RC-1 |

### Stage 1 — Immediate

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| **1** | **Derive `Stock Status` from available quantity, in one shared place** | RC-9 | **S** |
| 2 | Reconcile item-count KPIs with their list queries | RC-9 | S |

> **Item 1 is the highest value-per-hour fix identified in five campaigns.** Hours of work; it
> converts an inventory screen that actively misinforms into one that can be trusted.

### Stage 2 — After D11/D12

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 3 | Stock adjustment / opening-balance entry | RC-3 | M |
| 4 | Default `Allow Negative` OFF; gate by permission; add confirmation | RC-7 | S |
| 5 | Warehouse transfers + transfer requests | RC-3 | L |

### Stage 3 — Financial substantiation

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 6 | FIFO layer view + inventory valuation report | RC-3 | L |
| 7 | Goods issues · damaged inventory · ABC · inventory reports | RC-3 | XL |

---

## GO / NO-GO — Inventory only

# NO-GO

### Why

**The inventory module tells the user stock exists when it does not.** Two materials display a
green `In Stock` badge beside `On Hand 0` and `Available 0`, on a screen whose own KPI reports
`All Materials 0` above a table of two — while the Dashboard reports `0 units on hand` and the
Stock Ledger reports `No movements found`.

This is categorically different from the leaks found in Campaigns 2–4. Those exposed data. **This
one asserts a falsehood that people act on.**

### The compounding structural problem

**Inventory cannot be filled through any route the product offers.** Procurement receipt is
tenant-blocked, Manufacturing does not exist, and no adjustment or transfer screen exists. A
customer has no way to load opening stock — so FIFO, valuation, layers, reservations and variance
are not merely untested, they are **unusable**.

### Is Inventory the system of record?

**Partly — and partly is disqualifying.** Quantity has one authoritative source and the Dashboard
and Stock Ledger reflect it correctly and consistently. **Inventory *state* does not come from
that source**, so the platform presents two answers to "do we have stock?" and shows the wrong one
in the place operators look first.

### What is genuinely good

Stock Ledger is correctly framed as *"Complete audit trail of all inventory movements"* with
movement-type filters, date range and CSV export. Inventory Count offers a full session lifecycle
(Draft → In Progress → Pending Approval → Approved → Cancelled) with accuracy, shortage and waste
value columns. The Dashboard's `Count Session Health` and `Top Variances` sections are
well-conceived. `Waste Investigations` and `Warehouse Liabilities` are unusually mature features.
**Zero console errors across all screens.**

The design intent is sound and, in places, ahead of the basics — which is precisely the problem:
the advanced reconciliation tooling exists while stock entry and FIFO visibility do not.

### Confidence

**High** for what was observed — the contradiction is visible in a single screenshot and
corroborated by two independent views. **Nil** for FIFO, valuation, reservations, transfers,
variance and inventory tenant isolation, none of which could be exercised because **no stock can
be created**.

---

**No SQL. No Tinker. No database modification. No UI bypass. No code. No fixes. No records created
or mutated — no inventory transaction was possible through any route the product provides.**
