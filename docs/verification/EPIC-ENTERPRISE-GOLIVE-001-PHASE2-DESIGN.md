# EPIC-ENTERPRISE-GOLIVE-001 — Phase 2
## Engineering Design: RC-9 and RC-10

**Date:** 2026-08-08
**Type:** Design only. No code, no patches, no implementation.
**Source of truth:** [Phase 1 Investigation](EPIC-ENTERPRISE-GOLIVE-001-PHASE1-INVESTIGATION.md) ·
[Phase 1.5 Server Enforcement](EPIC-ENTERPRISE-GOLIVE-001-PHASE1.5-SERVER-ENFORCEMENT.md)

---

## Design principle

Both root causes are **unowned seams**, not broken components. Every component examined is
correct in isolation. The design must therefore **assign ownership**, not rebuild capability.

Two rules govern every decision below:

> **R1 — One fact, one owner.** Any business fact has exactly one authoritative producer.
> Everything else derives from it or is explicitly labelled as a foreign fact.
>
> **R2 — Rules live where they can see their inputs.** A guard that must read reservation state
> cannot live in a layer that receives only strings.

---

# PART 1 — Canonical Inventory Truth

## 1.1 The single source of truth

**`InventorySummaryService` is confirmed as the canonical source for all inventory quantity and
availability facts.** No new component is introduced. The service already declares this authority
and already computes correctly:

```
available = Σ over warehouses of max(on_hand − reserved, 0)      [clamp-per-warehouse, then sum]
```

Its underlying record is `InventoryItem`, itself derived from the movement events in the stock
ledger. **That chain is not modified.**

## 1.2 The semantic split

RC-9 exists because two facts share one name. The design separates them permanently:

| Fact | Question it answers | Owner | Nature |
| --- | --- | --- | --- |
| **`available`** | *Do we physically have stock we can commit?* | `InventorySummaryService` | **ERP truth — derived** |
| **`stock_status`** | *What does the storefront currently advertise?* | WooCommerce channel sync | **Foreign fact — stored** |

**These are both legitimate and both must survive.** The defect is not that `stock_status` exists;
it is that it is displayed as though it answered the first question.

## 1.3 Field classification

### Derived — computed on read, never stored

| Field | Derivation |
| --- | --- |
| `on_hand` | Σ `InventoryItem.on_hand` |
| `reserved` | Σ `InventoryItem.reserved` |
| `available` | clamp-per-warehouse, then sum |
| `inventory_value` | `EnterpriseCostEngine`, FIFO basis |
| **`availability_state`** *(new derived concept)* | A derived enum over `available` — the ERP answer to "in stock?". **This is the value the ERP grid's status column must render.** |

`availability_state` introduces no new source. It is a presentation of `available`, produced by
the same service, so it can never disagree with the quantity beside it.

### External integration fields — stored, never authoritative

| Field | Rule |
| --- | --- |
| `products.stock_status` (`instock` / `outofstock` / `onbackorder`) | Retained. **Reclassified as a channel attribute.** Must be labelled as such wherever displayed — e.g. `Channel Stock Status` — and must never appear adjacent to `available` without that label. |

**Why retained rather than removed:** Phase 1 flagged an open question — whether outbound sync
publishes this value back to WooCommerce. Until that is answered, deleting the column risks
changing what the storefront advertises. **Retention is the safe design; relabelling is the fix.**

### Presentation-only

| Field | Rule |
| --- | --- |
| `Stock Status` **column** in the ERP grid | Must bind to `availability_state`, not to `products.stock_status`. |
| `In Stock` / `Out of Stock` **quick-filter chips** | Must filter on derived availability. **Note:** Phase 1 established the repository currently filters on `products.stock_status` (`EloquentProductRepository:119–121`); this filter path changes meaning and must be treated as a behavioural change, not a rename. |
| Item-count KPIs (`All Materials`, `Total Products`) | Must be produced by **the same query** as the list they head. The `stats` and `list` endpoints must not apply independent filters. |

### Must never be edited

| Field | Reason |
| --- | --- |
| `on_hand` · `reserved` · `available` · `inventory_value` · `availability_state` | Derived from the ledger. Any write path makes the ledger non-authoritative. |
| `stock_status` **by a human in the ERP** | It is an inbound sync value. Human editing makes it neither an ERP fact nor a faithful channel mirror. **Today it is writable by three request classes** — `StoreProductRequest`, `UpdateProductRequest`, `PatchProductRequest`. |

## 1.4 Consequence

After this design, the two contradictions observed in Campaign 5 become impossible by
construction: the status column and the quantity column have **one producer**, and the KPI and its
table have **one query**.

---

# PART 2 — Enterprise Transition Architecture

## 2.1 The ownership problem

Phase 1.5 established that transition authority is currently split across **three places in two
layers, in two vocabularies**:

| Concern | Currently |
| --- | --- |
| Which actions to display | `OrderResource::resolveAllowedTransitions()` — Presentation, V3 |
| Which edges are legal | `FulfillmentController::resolveTransitionWorkflow()` — Presentation, V2 |
| Whether a legal edge is permissible now | **Nowhere** |

## 2.2 The five ownerships

| Responsibility | Owner | Layer | Rationale |
| --- | --- | --- | --- |
| **Transitions** — which edges exist | **`OrderStatus` state machine** | Domain | The graph is a property of the domain, not of an HTTP response. One definition, consumed by everyone. |
| **Business rules** — whether an edge may be taken *now* | **Transition Guards** | Domain | R2 — guards must read reservation, warehouse and payment state; only the domain can. |
| **Transition visibility** — what the UI offers | **`OrderResource`**, by *asking* the domain | Presentation | Correct layer for a display concern. It must consume the graph + guard results, not re-derive them. |
| **Transition execution** — the side effects | **Workflows**, orchestrated by `FulfillmentEngine` | Application | Already correct today. `ConfirmWorkflow` reserves, `CancelOrderWorkflow` releases, `ProcessOrderWorkflow` carries the delivery-date guard. **Not modified.** |
| **Validation** — payload shape and authorization | **FormRequest + route middleware** | Application | Already correct. `permission:operations.fulfillment.manage` is enforced. |

## 2.3 The single flow

```
                     ┌──────────────────────────────┐
                     │  OrderStatus state machine   │   Domain — the ONE graph
                     │  edges(from) → [to, …]       │
                     └──────────────┬───────────────┘
                                    │
                     ┌──────────────▼───────────────┐
                     │  TransitionGuard evaluators  │   Domain — the ONE rulebook
                     │  check(order, to) → Allowed  │   reads reservation, warehouse,
                     │                    | Blocked │   payment, stock
                     └──────────────┬───────────────┘
                                    │
              ┌─────────────────────┴─────────────────────┐
              │                                           │
   ┌──────────▼──────────┐                    ┌───────────▼──────────┐
   │  OrderResource      │  READ path         │  Controller          │  WRITE path
   │  offers only        │                    │  re-evaluates the    │
   │  guard-passing      │                    │  SAME guards, then   │
   │  transitions        │                    │  runs the workflow   │
   └─────────────────────┘                    └──────────────────────┘
```

**The read and write paths evaluate the same guards from the same source.** That is the structural
property RC-10 lacks: today they consult two different tables that disagree.

**The write path must re-evaluate — never trust the offer.** A client may post any `target_status`.

---

# PART 3 — Canonical Status Vocabulary

## 3.1 The single vocabulary

**`OrderStatus` (V3) is adopted as canonical, unchanged.** It is already the persisted value, so
adopting it requires no data migration.

| Canonical value | Meaning |
| --- | --- |
| `new` | Created, not yet activated |
| `scheduled` | Future-dated, awaiting activation |
| `awaiting_payment` | Held pending payment verification |
| `awaiting_stock` | Held pending stock availability |
| `in_progress` | Active; inventory reserved |
| `ready_for_dispatch` | Prepared and ready to leave |
| `out_for_delivery` | With a driver |
| `delivered` | Received by customer |
| `on_hold` | Suspended |
| `cancelled` | Cancelled (non-terminal — reopening is supported) |
| `returned` | Returned after delivery |

Every layer uses these strings verbatim: **Domain · API · Workflow · UI · Events · Integration.**

## 3.2 The V2 vocabulary to be retired

| V2 token | Canonical equivalent | Nature of the change |
| --- | --- | --- |
| `pending` | `new` | Rename |
| `confirmed` | `in_progress` | **Semantic merge** |
| `processing` | `in_progress` | **Semantic merge** |
| `preparing` | `ready_for_dispatch` *(candidate)* | **Semantic gap** |
| `completed` | `delivered` *(candidate)* | **Semantic gap** |
| `review` | *(none)* | **No equivalent** |
| `rescheduled` | `scheduled` *(candidate)* | Probable merge |

> ### The vocabulary reconciliation is not a rename — flag for Phase 3
>
> **Only `pending → new` is mechanical.** The rest are genuine semantic decisions:
>
> - **`confirmed` and `processing` are two distinct V2 states that both map to `in_progress`.**
>   The V2 routing table treats them differently — `processing → confirmed` is described as *"both
>   reserved, just a status label change"*. Collapsing them **loses a distinction the workflows
>   currently rely on**.
> - **`preparing` has no V3 case.** Preparation is owned by Operations (waves). Whether an order in
>   preparation is `in_progress` or `ready_for_dispatch` is undecided.
> - **`completed` has no V3 case**, yet the V2 chain ends `delivered → completed`. Whether
>   `delivered` is terminal is undecided.
> - **`review` has no V3 case at all.**
>
> **These are product decisions (certification `D-C`), not engineering choices. Phase 3 must not
> guess them.** Attempting a mechanical rename would silently change order semantics.

## 3.3 Translation layers that must disappear

| # | Layer | Disposition |
| --- | --- | --- |
| 1 | `FulfillmentController::resolveTransitionWorkflow()` — the V2 (current, target) table | **Replaced** by the domain state machine + guards. This is the single largest deletion. |
| 2 | `OrderResource::resolveAllowedTransitions()` — the V3 `match` | **Reduced** to a projection: ask the domain, render the answer. The graph knowledge leaves this file. |
| 3 | V2 status literals in workflow classes | **Normalised** to canonical values |
| 4 | Any V2 tokens in events, listeners or integration payloads | **Normalised** — must be surveyed; Phase 1 did not enumerate these |

**Target end state: zero status-vocabulary translation anywhere in the codebase.** A status string
means the same thing in the database, the API, an event payload and the UI.

---

# PART 4 — Transition Guard Model

## 4.1 Guard contract

Every guard is a domain object answering one question with a machine-readable reason:

```
check(Order, targetStatus) → Allowed | Blocked{ code, reason, remediation }
```

- Guards are **pure reads**. They never mutate.
- Guards are **composable** — a target state may require several.
- Guards are evaluated **identically** on the read path (visibility) and the write path (execution).
- A blocked guard returns **422** with `code` and `reason`; the UI renders `reason` and may use
  `code` to route the user to the remediation.

## 4.2 Guards by target state

**Legend — Status:** ✅ derivable from existing code · ⚠️ requires product decision (`D-C`)

### → `in_progress` (activation; inventory becomes reserved)

| Guard | Owner | Inputs | Failure reason | Error | Status |
| --- | --- | --- | --- | --- | --- |
| `OrderHasLines` | Orders domain | order lines | *"Order has no line items."* | `ORDER_EMPTY` | ✅ |
| `DeliveryDateReached` | Orders domain | `scheduled_date`, now | *"Scheduled for {date}; cannot activate before then."* | `SCHEDULE_NOT_DUE` | ✅ — exists in `ProcessOrderWorkflow` |
| `StockAvailableOrBackorderAllowed` | Inventory | `InventorySummaryService.available`, policy | *"Insufficient available stock for {sku}."* | `STOCK_UNAVAILABLE` | ⚠️ — is partial activation permitted? |

### → `ready_for_dispatch` — **the RC-10 state**

| Guard | Owner | Inputs | Failure reason | Error | Status |
| --- | --- | --- | --- | --- | --- |
| **`InventoryReserved`** | Inventory | `ReservationStatus` for every line | *"Order is not reserved. Reserve stock before marking ready."* | `NOT_RESERVED` | ✅ — the missing guard |
| **`WarehouseAssigned`** | Orders domain | `assigned_warehouse_id` | *"No warehouse assigned."* | `NO_WAREHOUSE` | ✅ |
| `PreparationComplete` | Operations | wave/preparation state | *"Preparation is not complete."* | `PREP_INCOMPLETE` | ⚠️ — is preparation mandatory? |
| `PaymentSatisfied` | Finance | payment state, terms | *"Payment not received and terms require prepayment."* | `PAYMENT_REQUIRED` | ⚠️ — depends on payment terms policy |

> **`InventoryReserved` + `WarehouseAssigned` are the two guards whose absence produced RC-10.**
> Both read state the order already exposes on its own Inventory tab.

### → `out_for_delivery`

| Guard | Owner | Inputs | Failure reason | Error | Status |
| --- | --- | --- | --- | --- | --- |
| `IsReadyForDispatch` | Orders domain | current status | *"Order is not ready for dispatch."* | `NOT_READY` | ✅ |
| `DriverAssigned` | Logistics | driver assignment | *"No driver assigned."* | `NO_DRIVER` | ⚠️ — own fleet vs carrier |
| `VehicleAssigned` | Logistics | vehicle assignment | *"No vehicle assigned."* | `NO_VEHICLE` | ⚠️ — same |

### → `delivered`

| Guard | Owner | Inputs | Failure reason | Error | Status |
| --- | --- | --- | --- | --- | --- |
| `WasDispatched` | Orders domain | current status | *"Order was never dispatched."* | `NOT_DISPATCHED` | ✅ |
| `ProofOfDeliveryCaptured` | Logistics | POD record | *"Proof of delivery not captured."* | `NO_POD` | ⚠️ — is POD mandatory? |

### → `cancelled`

| Guard | Owner | Inputs | Failure reason | Error | Status |
| --- | --- | --- | --- | --- | --- |
| `NotAlreadyDelivered` | Orders domain | current status | *"Delivered orders must be returned, not cancelled."* | `ALREADY_DELIVERED` | ✅ |
| `ReasonProvided` | Orders domain | reason | *"A cancellation reason is required."* | `REASON_REQUIRED` | ✅ — `requires_reason` already exists |

**Release of reservation on cancel is not a guard** — it is an effect, already owned by
`CancelOrderWorkflow`. **Not modified.**

### → `returned`

| Guard | Owner | Inputs | Failure reason | Error | Status |
| --- | --- | --- | --- | --- | --- |
| `WasDelivered` | Orders domain | current status | *"Only delivered orders can be returned."* | `NOT_DELIVERED` | ✅ |
| `ReasonProvided` | Orders domain | reason | *"A return reason is required."* | `REASON_REQUIRED` | ✅ |

### → `awaiting_stock` · `awaiting_payment` · `on_hold` · `scheduled`

| Guard | Owner | Inputs | Failure reason | Error | Status |
| --- | --- | --- | --- | --- | --- |
| `NotLocked` | Orders domain | `OrderStatus::isLocked()` | *"Order is locked in its current state."* | `ORDER_LOCKED` | ✅ — helper exists |
| `ReasonProvided` (on_hold) | Orders domain | reason | *"A hold reason is required."* | `REASON_REQUIRED` | ✅ |

## 4.3 Guards deliberately **not** designed

**`confirmed` and `reserved` appear in the brief but are not `OrderStatus` cases.**

- **`confirmed`** is V2 vocabulary. Whether it returns as a distinct state is decision `D-C` (§3.2).
- **`reserved`** is not a status at all — it is `ReservationStatus`, a **separate axis** that varies
  independently of order status. Modelling it as an order state would collapse two dimensions.

**Designing guards for either would presuppose the product decision. They are listed as open.**

---

# PART 5 — Impact Analysis

### Backend

| Component | Change | Risk |
| --- | --- | --- |
| `OrderStatus` enum | Add edge definitions (the state machine) | Low — additive |
| **Transition guards** (new) | New domain objects | Low — new code, no existing behaviour |
| `FulfillmentController::resolveTransitionWorkflow()` | **Replaced** by machine + guards | **High** — the single riskiest change |
| `FulfillmentController::transition()` | Evaluate guards, map to 422 | Medium |
| `OrderResource::resolveAllowedTransitions()` | Reduced to projection | Medium |
| Workflow classes | Normalise V2 literals | **High** — semantic merges (§3.2) |
| **15 dedicated fulfillment routes** | **Must be surveyed** — Phase 1.5 did not trace them; they bypass the routing table and may enforce differently | **Unknown — survey first** |
| `InventorySummaryService` | Add `availability_state` derivation | Low — additive |
| `EloquentProductRepository:119–121` | Availability filter now derived | Medium — **changes filter meaning** |
| Product request classes ×3 | Remove `stock_status` from human-writable input | Low |
| Products `stats` endpoint | Share the list query's filters | Medium |

### Frontend

| Component | Change | Risk |
| --- | --- | --- |
| `order-detail-drawer.tsx` | Render `reason` on blocked transitions; stop relying on `idx === 0` for primacy | Low |
| Raw Materials / Products grids | Bind status column to `availability_state`; relabel channel field | Low |
| Quick-filter chips | Point at derived availability | Medium |
| i18n (`orders.json` EN/AR) | Guard reason strings ×2 locales | Low — **must not regress the i18n ratchet** |

### Database

| Change | Risk |
| --- | --- |
| **No status data migration** — V3 already persisted | — |
| `products.stock_status` retained, reclassified | Low |
| Possible index on reservation/warehouse for guard reads | Low |

### API

| Change | Risk |
| --- | --- |
| Transition response gains `code` + `reason` on 422 | Low — additive |
| `OrderResource.transitions[]` may gain `blocked` + `reason` | **Contract change** — frontend must ship together |
| Product payloads gain `availability_state`; `stock_status` relabelled | **Contract change** — channel sync may consume it |

### Tests

| Area | Need |
| --- | --- |
| Guard unit tests | One per guard, allowed + blocked |
| State machine tests | Every legal edge; every illegal edge rejected |
| **Read/write parity test** | *Offered transitions ≡ accepted transitions* — the property RC-10 violated |
| Regression | The 15 dedicated routes, after survey |
| Inventory derivation | `availability_state` agrees with `available` by construction |

### Events

| Concern | Need |
| --- | --- |
| Order status-change events | **Survey for V2 literals** — Phase 1 did not enumerate |
| Inventory events | Unchanged — the ledger chain is not touched |

### Integrations

| Concern | Need |
| --- | --- |
| **WooCommerce outbound sync** | **Open question from Phase 1 — must be answered before Part 1 ships.** If outbound publishes `stock_status`, its meaning must not change. |
| Channel status mapping | Must translate canonical → channel vocabulary **at the integration boundary only** |

---

# PART 6 — Implementation Strategy

**Every step leaves the platform runnable. No step depends on a later step to be correct.**

### Step 0 — Answer the blocking questions *(no code)*

| # | Question | Blocks |
| --- | --- | --- |
| Q1 | Does outbound sync publish `products.stock_status`? | Step 1 |
| Q2 | Do the 15 dedicated fulfillment routes enforce guards independently? | Step 5 |
| Q3 | `D-C`: what must be true before `ready_for_dispatch`? Which ⚠️ guards apply? | Step 4 |
| Q4 | `confirmed` vs `processing`; `preparing`; `completed`; `review` (§3.2) | Step 6 |

> **Steps 1–3 are safe to begin before Q3/Q4 are answered. Steps 4–6 are not.**

### Step 1 — Derive `availability_state` *(additive; nothing consumes it)*

Add the derivation to `InventorySummaryService` and expose it alongside existing fields. **No UI
change.** Platform behaviour identical. Verifiable by test.

### Step 2 — Repoint the UI status column *(RC-9 item-level closed)*

Bind the grid's status column to `availability_state`; relabel the stored field as a channel
attribute. **Campaign 5's `In Stock` at zero stock is fixed here.** Smallest change with the
largest observable effect.

### Step 3 — Reconcile KPI and list queries *(RC-9 aggregate-level closed)*

Make `stats` and `list` share filters. `All Materials 0` above two rows is fixed here.

### Step 4 — Introduce the state machine and guards *(dormant)*

Add edges to `OrderStatus` and the guard objects. **Wire them to nothing.** Fully unit-tested.
Platform behaviour unchanged — the V2 routing table still runs.

> **Critical sequencing note.** Phase 1.5 proved the generic endpoint currently 422s on
> *everything* for V3 orders. **Steps 4–6 must land together in the same release.** Repairing the
> vocabulary before the guards are live would expose illegal transitions for the first time — the
> precise risk Phase 1.5 identified.

### Step 5 — Switch the write path to machine + guards

Replace `resolveTransitionWorkflow()`. Guard failures return 422 with `code`/`reason`.
**Prerequisite: Q2 answered** — the dedicated routes must not be left behind.

### Step 6 — Switch the read path to the same source

`OrderResource` asks the domain instead of matching. Frontend renders blocked reasons.
**Ship with Step 5** — this is the API contract change.

**At the end of Step 6, offered transitions ≡ accepted transitions, by construction.**

### Step 7 — Remove the translation layers

Normalise remaining V2 literals in workflows, events and integrations. Add a guard test asserting
no V2 token appears outside the integration boundary.

### Step 8 — Close the human write path on `stock_status`

Remove it from the three request classes. Last because it is the only step that removes a
capability a user may currently rely on.

---

## Sequencing summary

| Step | Closes | Runnable after | Blocked by |
| --- | --- | --- | --- |
| 1 | — (foundation) | ✅ | — |
| 2 | **RC-9 item level** | ✅ | Q1 |
| 3 | **RC-9 aggregate level** | ✅ | — |
| 4 | — (dormant) | ✅ | Q3 |
| 5 | **RC-10 write path** | ✅ | Q2, Step 4 |
| 6 | **RC-10 read path** | ✅ | Step 5 |
| 7 | Vocabulary debt | ✅ | Q4 |
| 8 | `stock_status` write path | ✅ | Q1 |

**RC-9 closes at Step 3 and requires no product decision beyond Q1. RC-10 closes at Step 6 and
cannot start until `D-C` is answered.**

---

## What this design deliberately does not do

- **Does not delete `products.stock_status`** — it is a legitimate channel fact, and Q1 is unanswered.
- **Does not rename V2 statuses mechanically** — four of the seven mappings are semantic decisions.
- **Does not modify the workflows** — they already enforce their effects correctly.
- **Does not modify the inventory ledger, events or `EnterpriseCostEngine`** — that chain is correct.
- **Does not introduce a new inventory service** — `InventorySummaryService` already holds the authority.
- **Does not invent the ⚠️ guards** — preparation, payment, driver, vehicle and POD preconditions are product decisions and are listed as open.

---

**No code was written. No patch was generated. No platform redesign is proposed. This document is
a design blueprint only; every open question is recorded as open rather than resolved by
assumption.**
