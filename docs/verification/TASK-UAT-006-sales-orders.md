# TASK-UAT-006 — Enterprise Certification Campaign 6
## Sales & Orders Platform

**Date:** 2026-08-08
**Framework:** ECF v4 (Rules 1–18) + Enterprise Certification Principle
**Method:** UI only. No SQL, no Tinker, no DB modification, no code, no fixes.
**Context:** `ECOS Holding 20` active — the tenant owning both orders (`ECOS Main Store`).

**Question (Rule 18):** *Can a paying enterprise customer safely rely on ECOS ERP Sales & Orders?*

# Answer: **Nearly — and it is the strongest module audited. But it advertises inventory it cannot honour.**

---

## Special focus: is Orders a true orchestration layer?

**It orchestrates honestly. It does not orchestrate safely.**

Orders is the only module audited that genuinely *reaches into* other domains rather than
duplicating them. `ORD-00002` exposes 11 tabs, a live "Checking distribution status…" probe, and
an **Inventory** tab that reports the truth without flinching:

```
RESERVATION STATUS   Reserved At: Not Reserved      Shipped At: —
FULFILLMENT          Assigned Warehouse: —          Line Items: 1
INVENTORY ITEMS      عسل الصال كيلو  FG-000001  ×1
```

That is correct. There is no stock (Campaign 5), so nothing is reserved, and no warehouse is
assigned. **Orders reports the state of the world accurately.**

But the **Workflow** tab, on the same order, offers:

```
CURRENT STATUS   In Progress
AVAILABLE ACTIONS   ▸ Mark Ready  (primary)   Return to New   Awaiting Payment
                     Awaiting Stock   Put On Hold   ✕ Cancel
```

**`Mark Ready` is offered as the primary action on an order that is `Not Reserved` with no
assigned warehouse.** Orders reads inventory state and *displays* it, but does not *enforce* it.

| Integration | Verdict |
| --- | --- |
| **Inventory** | **Partial** — reads reservation and warehouse state accurately; does not gate transitions on it |
| **Products** | ✅ Real — line item resolves to `FG-000001` with name and image |
| **CRM / Customers** | ✅ Real — dedicated Customer tab; customer and phone on every row |
| **Finance** | ⚠️ Partial — financial summary with explicit formula, deposit and remaining balance, but **`Tax: N/A`** |
| **Preparation / Shipping** | ⚠️ Declared — `Shipping` tab and a distribution-status probe exist; unexercised |
| **Manufacturing** | ❌ **Impossible** — module does not exist (Campaign 4) |
| **Procurement** | ❌ Not reachable from Orders |
| **Executive KPIs** | ✅ Real — Dashboard `EGP 21.1K / 2 orders` reconciles exactly with the Orders total |
| **Notifications** | ⚠️ Unverified — no order-driven notification observed |

**Conclusion: Orders is a real orchestration layer whose control gates are missing.** It is
wired to the right systems and tells the truth about them; it just does not stop you.

---

## Coverage

**Scope: 24 areas. Audited: 10. Coverage ≈ 42%. Confidence: high.**

### Visited screens (5)

| # | Screen | Result |
| --- | --- | --- |
| 1 | Orders workspace (13-status band, filters, search) | ✅ **Excellent** |
| 2 | Order drawer — Summary | ✅ Excellent |
| 3 | Order drawer — **Workflow** | ⚠️ Ungated transitions |
| 4 | Order drawer — **Inventory** | ✅ Honest reporting |
| 5 | Customers (CRM) | ✅ Pass (Campaigns 2/5) |

Tabs present but not opened: History · Customer · Products · Timeline · Payment · Shipping ·
Notes · Locations.

### Blocked screens (0)

### Untested areas (14)

**No screen found (4):** Quotations · Order Approval · Order Returns · Order Reports.
**Exists, unexercised (10):** Imported Orders · Order Timeline · Allocation · Fulfilment ·
Preparation · Partial Fulfilment · Cancellation · Notifications · Permissions · Customer Selection.

### Skipped workflows

| Workflow | Reason |
| --- | --- |
| **Create a new order** | **Not attempted.** Requires selecting a product; the catalogue serves another tenant's products (Campaign 2, UAT2-002) and reports `In Stock` on zero stock (Campaign 5, UAT5-001). An order created now would commit non-existent inventory. |
| **`Mark Ready` transition** | **Deliberately not executed.** It would advance an unreserved order toward shipping and corrupt the only order data in the system. Documented instead — see UAT6-001. |
| Reservation / allocation / fulfilment | No stock exists to reserve (Campaign 5) |
| Returns · quotations · approval | No screen exists |

---

# SECTION 1 — Individual Findings

### UAT6-001 — Orders can be advanced toward shipping with no reservation and no warehouse · **P0**

| | |
| --- | --- |
| **Class (R9)** | **BUSINESS** |
| **Screen** | Orders → order drawer → Workflow / Inventory tabs |
| **Steps** | 1. Open `ORD-00002` (`In Progress`, EGP 7,044.00). 2. **Inventory** tab → `Reserved At: Not Reserved`, `Assigned Warehouse: —`. 3. **Workflow** tab → `Mark Ready` offered as the **primary** action. |
| **Expected** | An order cannot be marked ready to ship without reserved stock and an assigned warehouse, or the action is offered with an explicit warning and an override permission. |
| **Actual** | `Mark Ready` is the highlighted primary action. No warning, no gate, no indication that reservation is absent. The order's own Inventory tab simultaneously reports `Not Reserved`. |
| **Business consequence** | Warehouse staff receive a pick instruction for goods that were never reserved and, per Campaign 5, do not exist. The customer has been promised a delivery date the business cannot meet. **This is the mechanism by which the Campaign 5 falsehood (`In Stock` at zero) becomes a broken customer promise** — Orders is where the wrong data turns into a commitment. |
| **Root cause (R10)** | **Business Rule** — the transition guard was never defined. The state machine and the inventory read both exist; nothing connects them. |
| **Pattern (R13)** | **RC-10** *(new)* — orchestration without enforcement |
| **Fix strategy (R16)** | **BUSINESS DECISION** (what may be overridden, by whom) then **IMPLEMENTATION FIX** |
| **Impact (R17)** | Cross-module — Orders, Inventory, Preparation, Shipping |
| **Effort (R11)** | **M** |

### UAT6-002 — Orders carry no tax · **P1**

| | |
| --- | --- |
| **Class (R9)** | **BUSINESS** |
| **Actual** | Financial summary shows `Products Total EGP 7,044.00`, `Shipping —`, **`Tax N/A`**, `Grand Total EGP 7,044.00`, with the formula stated explicitly: *"Products Total = Grand Total"*. Both orders behave identically. |
| **Business consequence** | Egyptian VAT is mandatory on domestic sales. An order that cannot carry tax cannot produce a compliant invoice, and revenue posted to Finance will be understated by the VAT element. Campaign 1 found **no tax configuration screen** anywhere in Administration — so this is not a missing value on one order, it is an absent capability. |
| **Root cause (R10)** | **Missing Feature** — tax configuration does not exist (Campaign 1) |
| **Pattern (R13)** | **RC-3** |
| **Fix strategy (R16)** | **PRODUCT DECISION** then implementation |
| **Effort (R11)** | **L** |
| **Note** | The formula label is genuinely good practice — the UI states its own arithmetic. It is also how the omission became visible. |

### UAT6-003 — No Quotations, Order Approval, Returns or Reports · **P1**

| | |
| --- | --- |
| **Class (R9)** | **BUSINESS** |
| **Actual** | Four scoped capabilities have no screen. `RETURNED` exists as an order *status* in the band, but there is no returns workflow — no RMA, no credit, no restock path. |
| **Business consequence** | **Quotations** are the entry point of most B2B sales — without them a salesperson cannot issue a priced offer before commitment. **Order approval** means no credit or discount control: any user with Orders access can commit the business to any value. **Returns** exist as a status the system can display but not reach. |
| **Pattern (R13)** | **RC-3** |
| **Effort (R11)** | **XL** |

### UAT6-004 — Order search requires Enter and gives no feedback · **P3**

| | |
| --- | --- |
| **Class (R9)** | **UX** |
| **Actual** | Typing `ORD-00002` filters nothing until **Enter** is pressed; then it works correctly — `GET /api/orders?search=ORD-00002` → 200, list 2 → 1, `1 filter` badge, status counts recalculated (Campaign 1 evidence). |
| **Business consequence** | Minor, but this is the platform's **best** search implementation and it is inconsistent with the debounced behaviour users expect — and with Companies, where search issues no request at all (Campaign 1, UAT1-005). |
| **Pattern (R13)** | **RC-5** |
| **Effort (R11)** | **XS** |

---

# SECTION 2 — Root Cause Matrix

**4 findings → 1 new root cause.**

| Root cause | Class | Status | Findings | Sev | Effort | Fix strategy | Priority |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **RC-10** Orchestration without enforcement | **BUSINESS** | **NEW** | UAT6-001 | **P0** | M | BUSINESS DECISION → IMPL | **1** |
| **RC-3** Absent surfaces | BUSINESS | Existing | UAT6-002, UAT6-003 | P1 | XL | PRODUCT DECISION | 2 |
| **RC-5** No shared list-workspace contract | UX | Existing | UAT6-004 | P3 | XS | IMPLEMENTATION FIX | 3 |

## RC-10 — Orchestration without enforcement *(new)*

| | |
| --- | --- |
| **Rule 12 category** | **Missing Business Policy** — transition preconditions were never defined |
| **Root cause (R10)** | **Business Rule** |
| **Evidence** | A state machine exists and correctly offers only legal transitions from `In Progress`. An inventory read exists and correctly reports `Not Reserved`. **Neither consults the other.** |
| **Why this is not RC-9** | RC-9 is *data that disagrees with itself* — a wrong fact. RC-10 is *correct facts that do not constrain behaviour* — a missing rule. Fixing RC-9 makes `In Stock` truthful; the order would still be markable ready with nothing reserved. **They are independent, and both must be fixed.** |
| **Predicted reach** | Every guarded transition in the platform — Preparation, Shipping, Dispatch, Finance posting. Campaign 3 observed a rich Purchases approval chain; whether *those* transitions are guarded is **UNVERIFIED**. |
| **Priority** | **1** — it is the point where bad inventory data becomes a customer commitment |

### Cross-campaign consolidation

| | Count |
| --- | --- |
| Findings this campaign | 4 |
| New root causes | **1** (RC-10) |
| Explained by existing causes | 3 of 4 (75%) |
| **Total root causes across 6 campaigns** | **10** |
| **Total observed defects** | **~40** |

**RC-1 produced no finding here.** Orders showed only `ECOS Main Store` data under `ECOS Holding 20`
— consistent with correct scoping, but the tenant that owns the orders was the active one, so
**this is not evidence of isolation.** Order tenant isolation is **UNVERIFIED**.

---

# SECTION 3 — Enterprise Risk Matrix

| Risk | UAT6-001 Ungated transition | UAT6-002 No tax | UAT6-003 No quotes/approval/returns | UAT6-004 Search UX |
| --- | --- | --- | --- | --- |
| **Customer** | **Critical** | Medium | High | Low |
| **Operational** | **Critical** | Medium | High | Low |
| **Financial** | High | **Critical** | **Critical** | None |
| **Security** | None | None | Medium | None |
| **Compliance** | Low | **Critical** | Medium | None |
| **Data integrity** | High | High | Low | None |
| **Reputation** | **Critical** | Medium | Medium | Low |
| **Engineering** | Medium (M) | High (L) | **Critical** (XL) | Very low (XS) |

### Reading the matrix

**UAT6-001 is where the platform's data problems become customer-facing.** Campaign 5's false
`In Stock` is dangerous in principle; **this is the mechanism that converts it into a delivery
promise.** Customer, Operational and Reputation all Critical — and the fix is only **M**.

**UAT6-002 is the only Critical *Compliance* risk in this campaign.** No VAT means no compliant
invoice in Egypt. It is P1 rather than P0 solely because it blocks *invoicing*, not *operations* —
but no business can trade legally without it.

**UAT6-003 carries Critical Financial risk through absence of approval**: with no approval
workflow, any user with Orders access can commit the business to any value, at any discount, on
any credit terms.

---

# SECTION 4 — Engineering Backlog Recommendation

### Stage 0 — Decisions

| # | Decision | Owner | Blocks |
| --- | --- | --- | --- |
| **D13** | **What must be true before an order may be marked ready?** Reservation? Warehouse? Who may override? | Business + Operations | RC-10 |
| D14 | Tax model — rates, inclusive/exclusive, per-channel? | Finance + Product | UAT6-002 |
| D15 | Are quotations, order approval and returns in v1.0? | Product | UAT6-003 |

### Stage 1 — Immediate

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| **1** | **Gate `Mark Ready` (and equivalent transitions) on reservation + warehouse assignment**, per D13 | RC-10 | **M** |
| 2 | Audit every other guarded transition in the platform for the same gap | RC-10 | M |

> Item 1 should be sequenced **with** Campaign 5's `Stock Status` fix (**S**). Together they close
> the chain: inventory stops lying, and orders stop committing what inventory does not have.
> Either alone leaves the failure reachable from the other end.

### Stage 2 — Compliance

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 3 | Tax configuration + order tax lines per D14 | RC-3 | L |

### Stage 3 — Capability (after D15)

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 4 | Order approval workflow (credit / discount thresholds) | RC-3 | L |
| 5 | Quotations → order conversion | RC-3 | XL |
| 6 | Returns / RMA with restock and credit | RC-3 | XL |
| 7 | Debounce order search | RC-5 | XS |

---

## GO / NO-GO — Sales & Orders only

# NO-GO — but this is the closest any module has come

### Why NO-GO

An order can be advanced toward shipping while its own Inventory tab says `Not Reserved` and no
warehouse is assigned. That is the point at which the platform's inventory problems stop being
internal and become **a promise to a paying customer**. Add the absence of VAT — mandatory in this
tenant's jurisdiction — and the module cannot legally close a sale.

### Why this is nonetheless the strongest module audited in six campaigns

Orders is the **only** module that behaves like part of a system rather than a screen over a table:

- A **13-status band** with live counts *and values* per status (`AWAITING STOCK 1 · EGP 14,088.00`)
- A **real guarded state machine** — from `In Progress` it offers exactly the legal transitions, with `Cancel` styled as destructive
- An **Inventory tab that tells the truth**, including the inconvenient truth (`Not Reserved`, `Assigned Warehouse —`)
- A live **distribution-status probe** on open
- **Financial arithmetic stated explicitly** — *"Formula: Products Total = Grand Total"*
- **Executive KPIs reconcile exactly**: Dashboard `EGP 21.1K / 2 orders` = Orders `ALL 2 · EGP 21,132.00`
- Search that genuinely filters and recalculates every status count
- Arabic customer names, addresses and zones rendering correctly in a dense grid
- **Zero console errors**

**The orchestration design is right.** Orders knows about inventory, customers, channels,
products, distribution and finance, and surfaces each honestly. What is missing is not
architecture — it is **the rules that say "no"**.

### The honest limit

**No order was created and no transition executed.** Creating one would commit non-existent
inventory from another tenant's catalogue; `Mark Ready` would have advanced an unreserved order
and corrupted the only order data present. Consequently **allocation, fulfilment, partial
fulfilment, cancellation, returns and order-driven notifications are UNVERIFIED**, and order
tenant isolation is **UNVERIFIED** — the owning tenant was the active one throughout.

### Confidence

**High** for what was observed — the workflow and inventory contradiction is visible in two
screenshots of the same order. **Nil** for transactional behaviour and isolation.

---

**No SQL. No Tinker. No database modification. No UI bypass. No code. No fixes. No records created
or mutated — and one transition (`Mark Ready`) was deliberately left unexecuted and documented
instead.**
