# TASK-ORDER-PREPARATION-BUSINESS-CONTRACT-RESOLUTION-001 — Engineering Report

**Status:** CONTRACT RESOLVED — no production code, schema, migration, test, fixture, or runtime data was modified.
**Date:** 2026-08-12 · **Environment:** `C:\ecos-develop`, DB `ecos_dev` · **Branch:** `develop`

> **PARITY GATE (recorded, not used):** host `ce69612a5910ad7eb84c354895b45140` vs container `4c2903b8fc751d05755b6fb8cdfa3546` — **BROKEN**. No container demand or preparation output is used as evidence in this report. Every resolution below rests on ADRs and approved architecture documents, not on container runtime.

---

## 1. Executive Summary

**Seven of the eight questions are resolved from existing approved architecture. One is resolved with a documented caveat. None required invention.**

The decisive finding is that **ADR-027 already contains the authoritative answer to the central dispute — and the current code contradicts it.**

**ADR-027 §2 (Reservation Start Point)** states, unambiguously:

> Reservation can only be physically executed when `assigned_warehouse_id` is present. If warehouse is missing at the moment of the decision: Reservation Decision: **active**; Reservation Execution: **postponed**; **`reservation_status` remains `pending`** — meaning: *decision made, execution pending*.

**ADR-027 §10 (Warehouse Assignment)** states the no-coverage contract as a table row:

> | No coverage for governorate | `warehouse_assignment_source = 'unassigned'` + `reservation_status = pending` — **Command Center signal, not an error** |

**This settles Q2 and Q3 together.** A missing warehouse produces `reservation_status = pending` and **no lifecycle status change**. It never produces `awaiting_stock`. `BranchAssignmentEngine`'s contract is correct; **`ProcessOrderWorkflow:97-119` and `ConfirmOrderWorkflow:89-111` are in breach of ADR-027.**

Three further consequences follow directly:

1. **`awaiting_stock` is exclusively an FG-availability verdict.** ADR-027 §3 Case 4 defines it as the residual of the four FG reservation cases. It has no warehouse meaning.
2. **`WarehouseAssigned` is the canonical recovery event** — ADR-027 §2 and roadmap item **H3** name it explicitly, and already record it as **missing**. The `BranchAssigned` seam is not a new discovery; it is an ADR-registered gap that the engine swap widened.
3. **The blocker mechanism already exists and needs no schema change.** `warehouse_assignment_source`, `warehouse_assignment_failure_reason`, `reservation_failure_reason`, and `reservation_status = pending` are all present and populated.

**The product configuration is valid.** `can_manufacture = 0` beside an active BOM is an explicitly defined, non-error state (`DECISION-ENGINE-SPEC` rule **MFG-001** → `SKIP_NOT_MANUFACTURABLE`, *"Log only (skipped). No action."*). `allow_negative_stock = 0` on the FG with `= 1` on its components is exactly the two-level rule ADR-027 §16.3 and §6 describe. **ORD-00001 reaching `awaiting_stock` *after* warehouse assignment would be the correct ADR-027 outcome, not a defect.**

**One question carries a caveat.** Q1's canonical target is the master geography FK — `GEOGRAPHY-COVERAGE-ENGINE.md` §5 already specifies `geography_governorate_id` / `geography_zone_id` as order fields, and an existing resolver chain exists. But that document is *"APPROVED — Architecture Only"*, the order table carries no such column, and both brand-geography tables are **empty** in `ecos_dev`. The end-state is architecturally settled; the migration path requires an owner decision on sequencing (§3).

---

## 2. Source-of-Truth Hierarchy

Applied throughout, highest first. Where sources conflict, the higher tier wins and the conflict is stated rather than silently reconciled.

| Tier | Source | Status | Governs |
|---|---|---|---|
| **1** | **ADR-027** Reservation Ownership Policy v1.2 | Accepted; "This matrix is the law" (§9); supersedes all prior informal understanding | Reservation, warehouse assignment, negative stock, recipe gate, legacy data |
| **1** | **ADR-005** Order Ownership and Lifecycle | Accepted | ERP owns lifecycle; channel statuses are never first-class ERP states |
| **1** | ADR-024 Single Source of Truth · ADR-013 (ownership) · ADR-015 (fulfillment) | Accepted | Canonical entity representation; Product→Brand→Company |
| **2** | **DECISION-ENGINE-SPEC.md** (rules MFG-001/002) | Approved behavioural spec | Manufacturing decision semantics |
| **2** | **GEOGRAPHY-COVERAGE-ENGINE.md** | **APPROVED — Architecture Only** (not implemented) | Geographic identity, coverage, `no_coverage` exception |
| **2** | ENTERPRISE-FULFILLMENT-PLATFORM.md | Approved | *"Exceptions are first-class — none are swallowed silently"* (§line 24) |
| **3** | Domain enums (`OrderStatus`, `ReservationStatus`) + certified task contracts (F4/Option B, TASK-ORDERS-LIFECYCLE-ARCH-002) | Implemented, certified | The de-facto FSM |
| **4** | Service/class docblocks, `BRANCH-ASSIGNMENT-ENGINE.md` | Implementation notes | Descriptive only |
| **5** | Configuration data rows (`wave_engine_configurations`) | Mutable data | **Never authoritative** |

**Critical gap in the hierarchy:** ADR-005 §5 states — *"The internal order status FSM — including the specific states, transitions, and guard conditions — is defined in a dedicated **future** ADR."* **That ADR does not exist.** No ADR-level authority defines the order FSM. Tier 3 (the V3 enum + certified contracts) is therefore the highest authority on states themselves, which is precisely why two implementations could diverge without either violating an ADR — until ADR-027 §2/§10 constrained the reservation side.

**Status vocabulary note.** ADR-027 predates the V3 rename (TASK-ORDERS-LIFECYCLE-ARCH-002) and uses `Pending / Confirmed / Processing / Preparing / Ready`. Mapping applied throughout this report:

| ADR-027 term | V3 `OrderStatus` |
|---|---|
| Pending | `new` |
| Confirmed, Processing | `in_progress` |
| Preparing | *(no status — invisible engine state within `in_progress`)* |
| Ready | `ready_for_dispatch` |

---

## 3. Q1 Resolution — Geographic Comparison

### DECISION: **B — canonical FK / normalized geographic identity**, resolved through the **existing** chain (D). Not display-name matching.

**Confidence: HIGH on the end-state. The migration path requires an owner decision on sequencing.**

### Evidence

**`GEOGRAPHY-COVERAGE-ENGINE.md` §5** already specifies the resolved identity as an order field:

```
Order (fulfillment extension)
├── geography_zone_id             → Zone
├── geography_governorate_id      → Governorate
└── geography_resolved_at         timestamp
```

**§2 (Address → Zone Resolution)** makes resolution mandatory and defines the failure state:

> When an order is placed, its delivery address must **resolve to a `zone_id`**. […] An order with an unresolvable zone is flagged as `geography_unresolved` and **blocked from entering vehicle planning** until resolved.

**An existing canonical chain is already implemented** — `BrandConfigurationResolverService::resolveOrderGeography()`:

```
order.delivery_zone (text)
  → config_delivery_zones (brand-scoped, name match)  → master_zone_id
                                                      → delivery_geography_id
  → config_delivery_geographies.master_governorate_id → master_governorates.id  ← CANONICAL
```

`preparation_wave_orders` already persists both `master_governorate_id` and `master_zone_id`, i.e. **the preparation layer already expects a resolved canonical ID that the order layer never produces.**

**`CoverageResolutionService:38` bypasses this entire chain** and performs its own `LOWER(name)` string match against `master_governorates.name`. That is a **second resolver for an entity that already has a canonical one** — the precise pattern ADR-024 forbids (*"Every business entity in ECOS must have one canonical representation"*).

### Why not the alternatives

| Option | Verdict |
|---|---|
| **A — bilingual name matching (`name` + `name_ar`)** | **Rejected as the end-state.** Display names are localisation artifacts, not identity. Both `name` and `name_ar` are unconstrained by any unique index, so this creates a permanent collision surface and contradicts §5, which stamps an **ID**. Viable only as an interim step (below) |
| **C — existing city/zone relationship** | **Subsumed by B.** The zone relationship *is* the mechanism by which B is reached (`DeliveryZone → master_zone_id → DeliveryGeography → master_governorate_id`). It is not a separate option |
| **D — another existing mechanism** | **This is the mechanism for B.** `BrandConfigurationResolverService` already exists. No new geography system is required or permitted |

### The honest gap

Three facts complicate immediate adoption, and none is hidden:

1. `orders` has **no** `master_governorate_id` / `geography_governorate_id` column. `logistics_city_id` exists but is unused and points at a *different* table (`logistics_governorates`).
2. `config_delivery_geographies` and `config_delivery_zones` are **empty** in `ecos_dev` — the canonical chain has no data.
3. `resolveOrderGeography()` requires `delivery_zone`; **ORD-00001's is NULL**, so the existing chain would fail for it too.

**Consequence:** B cannot be reached without (a) populating brand geography, and (b) persisting the resolved ID on the order — a schema addition that `GEOGRAPHY-COVERAGE-ENGINE.md` §5 already sanctions but which no migration has performed.

**→ Owner decision on sequencing only (not on the target):** whether to ship a bilingual-match interim (option A) to unblock operations while B is built, or to go straight to B. The audit's recommendation is to treat A as an explicitly time-boxed bridge — but the choice is the owner's, and it is listed in §25.

---

## 4. Q2 Resolution — No-Coverage Contract

### DECISION: **No coverage does NOT change the lifecycle status.** The order remains in its current state with a fulfilment blocker recorded. `BranchAssignmentEngine` is correct; `ProcessOrderWorkflow` is in breach.

**Confidence: HIGH — ADR-027 §10 states this as an explicit table row.**

### Evidence

**ADR-027 §10 (Tier 1):**

> | No coverage for governorate | `warehouse_assignment_source = 'unassigned'` + `reservation_status = pending` — **Command Center signal, not an error** |

Three things are specified and one is conspicuously absent: source is stamped, reservation status is `pending`, the signal is explicitly *not an error* — and **`order.status` is never mentioned.** Under a section titled "Warehouse Assignment" whose entire purpose is to define this behaviour, the omission is dispositive: no lifecycle change is authorised.

**ADR-027 §2 (Tier 1)** independently confirms it: warehouse missing → *"`reservation_status` remains `pending` — meaning: decision made, execution pending."*

**`GEOGRAPHY-COVERAGE-ENGINE.md` §7 (Tier 2)** classifies `no_coverage` as a **Blocking exception** with a required action ("Add coverage or reassign channel rule") — an exception type, not a state transition.

**`ENTERPRISE-FULFILLMENT-PLATFORM.md` (Tier 2):** *"Exceptions are first-class — every stage has defined exception types; none are swallowed silently."* An exception represented by overwriting a lifecycle status is neither first-class nor distinguishable.

### Explicit answers

- **Does no coverage change the lifecycle status?** **No.**
- **What does it do instead?** Stamps `warehouse_assignment_source` (+ `warehouse_assignment_failure_reason`) and leaves `reservation_status = pending`. The order stays in its current lifecycle state and surfaces in the Command Center as a No Coverage Exception.
- **If `AwaitingStock` were retained, what would it mean?** It cannot be retained for this cause without contradicting ADR-027 §3 Case 4, which defines `awaiting_stock` solely as the residual of the FG-availability decision tree (§5).

### The conflict, stated rather than reconciled

`ProcessOrderWorkflow.php:29` (*"No warehouse → routed to AwaitingStock"*) is a **Tier 4** class docblock describing a **Tier 3** implementation. ADR-027 §10 is **Tier 1**. The workflow loses. The V3 lifecycle task that authored it never had ADR authority to redefine reservation semantics — ADR-027 §9 explicitly claims that ground (*"This matrix is the law"*).

**Source-value refinement (not a conflict):** ADR-027 §10 says source `'unassigned'`; the later `BranchAssignmentEngine` distinguishes `unassigned` (no governorate on the order) from `no_branch_coverage` (governorate present, no coverage). Both are non-error signals; the split is a strictly finer-grained implementation of the same contract and should be preserved.

---

## 5. Q3 Resolution — AwaitingStock Semantics

### DECISION: **A — inventory/material shortage only.** `awaiting_stock` may **not** represent warehouse assignment failure. **No new status is created.**

**Confidence: HIGH.**

### Evidence

**ADR-027 §3 (Tier 1)** defines the complete set of reservation outcomes. `awaiting_stock` is Case 4 — the residual of a decision tree whose every input is FG availability:

| Condition | Case | Outcome |
|---|---|---|
| `available ≥ requested_qty` | 1 — Standard | physical reservation |
| `can_manufacture = true` (as amended by §16) | 2 — Manufacturing | logical commit |
| `allow_negative_stock = true` AND `available = 0` | 3 — Negative Stock | logical commit |
| **None of the above** | **4 — Awaiting** | **`reservation_status = awaiting_stock`** |

Warehouse presence appears nowhere in this tree. It is handled separately in §2 as the **Execution Requirement**, whose failure state is `pending`.

**ADR-027 §2:** *"Reservation is **non-blocking**. It never prevents order progression. If **stock** is unavailable, the order transitions to `awaiting_stock` and the status pipeline continues."* — the transition is conditioned on *stock*, explicitly.

**ADR-027 §16.1** reinforces it: *"Required Raw Material unavailable AND `allow_negative_stock = false` → Recipe = outofstock → no manufacturing commitment → **Order = Awaiting Stock**"* — again purely a material verdict.

### Accounting for Preparation eligibility (as required)

`PreparationSessionPolicy::defaultEligibleStatuses()` = `['new', 'in_progress']`. Under the corrected contract this becomes *coherent* rather than merely restrictive:

- A no-coverage order **stays `new`** → **remains preparation-eligible by status**, and is held out only by the Entry Gate's warehouse check (`'no_warehouse_assigned'`) — a precise, correctly-attributed reason.
- Today it is wrongly moved to `awaiting_stock` → excluded by **status**, with the true cause (no warehouse) invisible to every status-driven consumer.

**The current behaviour therefore produces the right exclusion for the wrong reason** — which is exactly why no automatic recovery can find it (§7).

### No new status

`ENTERPRISE-FULFILLMENT-PLATFORM.md` requires first-class exceptions, not additional lifecycle states, and the discriminating fields already exist (§18/State-vs-Blocker). Per the standing instruction, `preparing`, `prepared`, and `confirmed` are **not** V3 statuses and are not introduced.

---

## 6. Q4 Resolution — Re-Trigger / Re-Evaluation

### DECISION: The existing event architecture **can** support all four scenarios. Two of the four required triggers are already specified in ADR-027 and recorded there as **missing**.

**Confidence: HIGH for A, B, D. MEDIUM for C (no ADR names an order-facing manufacturing-completion trigger).**

### Evidence

**ADR-027 §2 Trigger Events (Tier 1):**

| # | Trigger | ADR status |
|---|---|---|
| 1 | Order creation — warehouse already assigned | **Exists** |
| 2 | Warehouse assignment resolves for existing order | **Missing** — `WarehouseAssigned` event → reservation retry listener |
| 3 | New stock arrives for product with `awaiting_stock` orders | **Missing** — `StockReceived` event → reservation retry queue job |

**ADR-027 §15 roadmap** specifies both as named deliverables:

- **H3** — `ExecuteReservationOnWarehouseAssigned.php`: *"Listen to the `WarehouseAssigned` domain event. For the order on that event, if `reservation_status = 'pending'` and order status is in [pending, awaiting_payment, confirmed, processing], enqueue a `RetryReservationJob`."*
- **M6** — `RetryReservationOnStockReceived.php`: *"Listen to `StockReceived` / `PurchaseOrderReceived` events. Queue `RetryReservationJob` for `awaiting_stock` orders of the received product, **FIFO**."*

**The `BranchAssigned` seam is not a new defect — it is H3, still open, made worse by the engine swap.** ADR-027 named `WarehouseAssigned` as the trigger in v1.1; `BranchAssignmentEngine` later shipped a *different* event and no listener.

### Resolved re-trigger contract

| # | Blocker | Canonical event | Listener / workflow | Re-evaluation operation | Resulting state | Idempotency requirement |
|---|---|---|---|---|---|---|
| **A** | Warehouse unavailable | **`WarehouseAssigned`** (ADR-027 §2, H3) | `ExecuteReservationOnWarehouseAssigned` → `RetryReservationJob` | Execute the postponed reservation | `reservation_status` `pending` → `reserved` / `partial_reserved` / `awaiting_stock`; **lifecycle status unchanged by the retry itself** | Guard on `reservation_status = pending`; `ReserveOrderInventoryAction:73-85` already skips Reserved/Transferred/Consumed/Released |
| **B** | Stock unavailable | `InventoryStockReceived` (dispatched by `ReceiveStockAction:111`) | `RetryReservationOnStockAvailableListener` (**exists**; M6 partially delivered) | Re-attempt reservation, FIFO | `awaiting_stock` → `reserved` / `partial_reserved` | Same skip-list; FIFO ordering per M6 |
| **C** | Manufacturing unavailable | `ManufacturingJobCompletedEvent` (**exists**, Preparation-facing only) | **No order-facing listener exists**; none is specified by any ADR | Would re-attempt reservation for orders whose FG was produced | `awaiting_stock` → `reserved` | Must not double-commit a Case-2 logical commit |
| **D** | Reservation not possible | Same as A/B (reservation has no independent blocker) | — | — | — | `Released` is **terminal** (`ReservationStatus::canTransitionTo()` returns `false`); a released reservation must never be silently revived |

**Scenario C is the one genuine gap in the ADR set.** ADR-027 §4 assigns manufacturing responsibility but specifies no completion→reservation trigger, and §3 Case 2 makes it *arguably unnecessary*: a manufacture-backed order is committed as `reserved` **at reservation time**, so it never sits in `awaiting_stock` waiting for production. Under ADR-027, C is reachable only for products where Case 2 did not apply — i.e. `can_manufacture = false`, which by MFG-001 are not manufactured at all. **C is therefore likely a non-scenario by design**, but no source states this positively. Recorded as such in §25 rather than asserted.

---

## 7. Q5 Resolution — `can_manufacture = 0` with an Active BOM

### DECISION: **A — VALID.** The recipe exists but the product is not order-manufactured. This is an explicitly defined, non-error configuration.

**Confidence: HIGH.**

### Evidence

**`DECISION-ENGINE-SPEC.md` rules MFG-001 / MFG-002 (Tier 2) — the manufacturing decision table:**

| # | Rule | Condition | Decision | Action |
|---|---|---|---|---|
| 1 | `MFG-001` | `product.can_manufacture = false` | `SKIP_NOT_MANUFACTURABLE` | **Log only (skipped). No action.** |
| 2 | `MFG-002` | `can_manufacture = true` **AND** `recipe = null` | `FAIL_NO_RECIPE` | Log (failed). Visible in Operations Dashboard |

`can_manufacture = false` has its **own named decision outcome** whose prescribed action is "no action" — the definition of a valid, non-error state. It is evaluated **without reference to the recipe**. The spec defines exactly one invalid manufacturing configuration, and it is the **converse** of ours: `true` with no recipe.

**Corroboration:**

- `ManufacturingPolicy/Domain/ValueObjects/ProductContext.php` carries `can_manufacture` **and** `has_active_recipe` as **separate fields** — the platform models them as independent facts, not as one implying the other.
- **ADR-027 §16.1:** *"`recipe_missing` does not block. A finished good with no active recipe retains its prior behaviour."* Recipe presence is orthogonal to the flag in both directions.
- **The BOM is in active non-manufacturing use.** BOM-00001 carries `recipe_cost = 3155.0000`, `packaging_cost = 95.0000`, `cost_pending = 0`, `recipe_cost_updated_at`, and a populated `cost_summary` with `missing_material_count: 0`. `products.product_cost = 3155.0000` — **exactly the recipe cost.** The recipe is driving **cost rollup**, which is a legitimate purpose entirely independent of production.

### Noted tension (does not change the verdict)

`IMPLEMENTATION-PROGRESS.md:28` describes the column as *"Has a recipe; may be produced"* — a **Tier 4** column description implying coupling. It is outranked by the **Tier 2** behavioural spec, and MFG-002 proves the platform polices only the opposite pairing. The verdict is A.

**Whether FG-000001 *commercially should* be manufacturable is a separate question** — not one of configuration validity, and not raised by the sources. Listed in §25.

**The flag was not changed.**

---

## 8. Q6 Resolution — `allow_negative_stock` Meaning

### DECISION: `allow_negative_stock` operates at **two independent levels** with different, ADR-defined meanings. The FG-000001 configuration is **VALID**.

**Confidence: HIGH.**

### Evidence — ADR-027 §6, §3, §16.3 (all Tier 1)

**Material level (§16.3):** *"a material passes when `available > 0 OR allow_negative_stock = true`; a recipe is executable only if **every** required material passes."* `ManufacturingAvailabilityService` is *"the **only** engine that decides recipe availability."*

**Product level (§3 Case 3):** `allow_negative_stock = true` AND `available = 0` → *"Logical commit — OH will go negative at shipment."*

**Through-stage policy (§6):** *"For `allow_negative_stock = true` products, the system must execute **every** stage including issuance that takes `on_hand_qty` below zero."* — reservation → allocation → picking → loading → `DirectIssueStockAction` proceeds, ledger records the negative.

### Meaning by context

| Context | Meaning | Authority |
|---|---|---|
| **Finished product** | The FG may be reserved and shipped on credit; OH goes negative at issuance | §3 Case 3, §6 |
| **Raw material** | The component counts as available for recipe executability | §16.3 |
| **Manufacturing** | A recipe is executable iff **every** component passes `available > 0 OR allow_negative = true` | §16.3 |
| **Preparation** | **Not gated by this flag.** The Entry Gate checks status + warehouse only. Brand policy carries a separate `negative_stock_handling` setting (default `'block'`) at wave level, not order level | `PreparationReleaseEngine`; `BrandConfigurationResolverService:46` |
| **Reservation** | Case 3 — logical commit of the full ordered quantity, zero physical lock | §3 |
| **Direct sale** | `DirectIssueStockAction` must proceed and drive OH negative (§6 records the current throw as **CRITICAL BUG C1**) | §6, §15 C1 |

### The specific question

> *Can an active recipe whose raw materials have `allow_negative_stock = 1` legitimately produce/prepare a finished product whose own `allow_negative_stock = 0`?*

**Yes — but only through Case 2 (manufacturing), which requires `can_manufacture = true`.** The two flags govern **different paths**:

- Component flags → recipe executability → **Case 2** (manufacturing commitment)
- FG flag → **Case 3** (direct negative-stock sale)

They are independent by design, and §16.2 states the separation as *"a hard requirement"*: FG stock and recipe availability must never gate one another.

**For FG-000001** (`can_manufacture = 0`, `allow_negative_stock = 0`): Case 1 fails (0 < 2), **Case 2 does not apply** (flag off — the recipe *is* executable, but is never consulted), **Case 3 does not apply** (flag off) → **Case 4 → `awaiting_stock`.**

**This is the correct ADR-027 outcome, not a defect.** Once warehouse assignment is fixed, ORD-00001 landing in `awaiting_stock` for genuine FG shortage is the contract working as designed.

**No product configuration was changed.**

---

## 9. Q7 Resolution — Existing Orders

### DECISION: **Reprocess via a supervised background job with dry-run.** No manual correction, no recreation, no discard. This is ADR-027 §12 verbatim.

**Confidence: HIGH.**

### Evidence — ADR-027 §12 (Tier 1)

> **Decision: Legacy orders must be reprocessed via a supervised background job.**
>
> | Condition | Required Action |
> |---|---|
> | Active status, WH=null, never shipped | Run `BranchAssignmentEngine` → if WH resolved, attempt reservation |
> | Active status, WH=null, WH unresolvable | **Flag in Command Center as "No Coverage Exception"** |
> | Active status, WH assigned, `reservation_status = pending` | Immediate reservation retry |
> | Active status, order already waved without reservation | Check wave state first — prevent double-allocation |
> | Terminal status | Mark as historical; **write retroactive audit** |

**§15 H4** names the deliverable: `orders:reprocess-legacy-reservations`, *"Must support dry-run mode."*

The precedent is exact — §12 even names two prior orders (ORD-00023, ORD-00024) in the same condition.

### Disposition

| | ORD-00001 | ORD-00002 |
|---|---|---|
| Condition | Active, WH = null, never shipped | Active, WH = null, never shipped |
| Governorate | `القاهرة` (present, unresolvable by current matcher) | **NULL** |
| §12 row | *"WH=null, never shipped"* → run `BranchAssignmentEngine`, then reserve | Same row → but assignment **cannot** succeed while `governorate` is NULL |
| Expected outcome after Q1 repair | Warehouse resolves → reservation attempted → `awaiting_stock` for genuine FG shortage (§8) | `markUnresolved()` again → **"No Coverage Exception" in Command Center** |
| Action | Reprocess (H4) | Reprocess (H4) → lands in Command Center for **address completion** |

**ORD-00002's NULL governorate specifically:** §12 provides no data-repair authority, and `GEOGRAPHY-COVERAGE-ENGINE.md` §7 classifies `address_unresolvable` as **Blocking** with required action *"Manual zone assignment."* **The order is not defective — its address is incomplete.** The correct disposition is operator address completion through the normal order-edit workflow (permitted: `isLocked()` is false for `new`), after which reprocessing resolves it. **No back-door mutation.**

### Additional correction the job must make

Both orders currently sit at `status = awaiting_stock` — a value that, under the resolved contract (§4, §5), **should never have been written for a warehouse failure**. The reprocessing job must therefore also restore the lifecycle status these orders would have held (`new`), and record the correction in `order_reservation_audits` + `OrderEvent`.

### Historical integrity

Preserved by ADR-027 §12's *"write retroactive audit entry"*, using mechanisms that already exist: `order_reservation_audits` (from/to/reason/actor) and `OrderEvent` (append-only, actor-stamped, per ADR-011). Existing audit rows are **never** rewritten — the correction is appended.

**No database mutation was performed by this task.**

---

## 10. Q8 Resolution — Wave-Eligible V3 Statuses

### DECISION: **`new` and `in_progress`. Exactly two.**

**Confidence: HIGH.**

### Evidence

`PreparationSessionPolicy::defaultEligibleStatuses()` — the code-level authority, and the only source expressed in valid V3 vocabulary:

```php
return [
    OrderStatus::NewOrder->value,    // 'new'
    OrderStatus::InProgress->value,  // 'in_progress' — subsumes the former confirm/confirmed
];
```

Cross-checked against **ADR-027 §9 Status Matrix** (*"This matrix is the law"*), mapped to V3: Pending/Confirmed/Processing (= `new` + `in_progress`) are the states carrying an active reservation decision, before Preparation owns Allocate/Pick.

**`"confirmed"` is rejected on three independent grounds:**
1. It is not a member of the V3 `OrderStatus` enum — a value no order can ever hold.
2. **ADR-005 (Tier 1):** *"The ERP domain model **must never** use channel status values as first-class ERP states."*
3. It is **Tier 5** configuration data, which is never authoritative.

### Status-by-status determination

| V3 status | Wave-eligible | Why |
|---|---|---|
| **`new`** | **YES** | ADR-027 §2: reservation decision is active from Pending. Wave *collection* is not allocation — `WaveMembershipService` attaches only while the wave is `Collecting`/`Preparing`, and §9 forbids Allocate/Pick at this status, not membership |
| **`in_progress`** | **YES** | §9: reservation Reserved; Preparation **OWNS** Allocate and Pick. The core preparation state — V3 keeps orders here *while being prepared* |
| `scheduled` | NO | `isPreActivation() = true`; `ProcessOrderWorkflow:63-73` blocks the operational queue before the delivery date |
| `awaiting_payment` | NO | Commercial gate unmet; §9 permits no preparation operation |
| `awaiting_stock` | NO | Reservation not executed; §9 has no row authorising Allocate/Pick. Under the corrected contract this status now means *genuine FG shortage*, which is a real reason to withhold preparation |
| `ready_for_dispatch` | NO | Preparation complete — this is its **exit** state |
| `out_for_delivery`, `delivered` | NO | Post-fulfilment |
| `on_hold` | NO | Explicitly ineligible (`OrderPreparationObserver:16`) |
| `cancelled`, `returned` | NO | Terminal (`isTerminal()`); §9 → Released |

### Noted tension (reported, not resolved by adding a gate)

ADR-027 §9 forbids Allocate/Pick at `new` (reservation not yet executed), but the Entry Gate admits `new` and has **no reservation prerequisite**. These are reconcilable because they gate **different boundaries**: the Entry Gate governs *admission to a collecting wave*; §9 governs *allocation and picking*. The reservation check belongs at the allocate/pick boundary, **not** at the Entry Gate.

Per the standing instruction ("do not invent additional gate conditions"), **no new Entry Gate condition is proposed.** Whether the allocate/pick boundary currently enforces §9 was **not audited** and is flagged in §25.

---

## 11. Authoritative Order State Contract

**11 states. No additions. `preparing`, `prepared`, `confirmed`, `refused` do not exist.**

| Group | States |
|---|---|
| Primary | `new` → `in_progress` → `ready_for_dispatch` → `out_for_delivery` → `delivered` |
| Exception | `awaiting_payment`, `awaiting_stock`, `scheduled`, `on_hold` |
| Terminal | `delivered`, `cancelled`, `returned` |

**Governing principles (all Tier 1):**

1. **The ERP owns the lifecycle after acceptance** (ADR-005 §1). Channel statuses are never first-class ERP states.
2. **Reservation is non-blocking** (ADR-027 §2). It never prevents order progression.
3. **The lifecycle status carries commercial/operational state. Fulfilment blockers are carried separately** (ADR-027 §2, §10) — see §18.
4. **`awaiting_stock` means FG unavailability, and nothing else** (ADR-027 §3 Case 4).
5. **Warehouse absence is an execution postponement, not a lifecycle event** (ADR-027 §2, §10).

---

## 12. Warehouse Assignment Contract

| Rule | Value | Authority |
|---|---|---|
| Nature | **Execution Requirement**, not the business-decision trigger | ADR-027 §10 |
| When | At ingestion (`CreateManualOrderAction`) and again on every re-assignment | §10 |
| Multi-warehouse | **Not permitted.** One order, one warehouse, one complete reservation | §10 |
| Resolution basis | Canonical master geography identity (§3) | GEOGRAPHY-COVERAGE-ENGINE §5 |
| On success | Stamp branch + warehouse + source; **dispatch the canonical `WarehouseAssigned` event** | §2, §15 H3 |
| **On no coverage** | Stamp `warehouse_assignment_source` + `failure_reason`; `reservation_status = pending`; **status unchanged**; Command Center signal | **§10** |
| On no governorate | Same class of signal; source `unassigned` | §10 + `BranchAssignmentEngine` refinement |
| Recovery | `WarehouseAssigned` → reservation retry (H3) | §2 |

---

## 13. Reservation Contract

**Decision and execution are separate** (ADR-027 §2).

| Aspect | Contract |
|---|---|
| Decision | Immediate on entering any active commercial state (`new`, `awaiting_payment`, `in_progress`); **not contingent on warehouse** |
| Execution | Requires `assigned_warehouse_id`; otherwise **postponed**, `reservation_status = pending` |
| Dependency | **FG availability only.** `available = on_hand_qty − reserved_qty`. Raw-material availability is irrelevant at reservation time (§3) |
| Case ladder | 1 Standard → 2 Manufacturing (gated by executable recipe, §16.1) → 3 Negative Stock → 4 Awaiting |
| Case 1 primacy | Evaluated first, **never** gated by the recipe (§16.2 — *"a hard requirement"*) |
| Recipe authority | `ManufacturingAvailabilityService` only; never recomputed (§16.3) |
| Scope | **Company**-scoped, fail-closed on null company (§16.4); **never** brand-scoped (§16.5) |
| Blocking | **Non-blocking** — never prevents order progression (§2) |
| Idempotency | Skip when Reserved / Transferred / Consumed / Released |
| Terminality | `Released` and `Consumed` are terminal |

---

## 14. Availability Contract

Four distinct concepts; **only #4 governs fulfilment**.

| # | Concept | Rule | Subject | Scope |
|---|---|---|---|---|
| 1 | `products.stock_status` | WooCommerce mirror, inbound-only (E-3) | product | channel |
| 2 | `availability_state` | `null → untracked`, `≤0 → out_of_stock`, else `in_stock` | product | all warehouses |
| 3 | Manufacturing availability | `available > 0 OR allow_negative_stock`, **every** component must pass | **components** | **company** (§16.4) |
| 4 | **Reservable availability** | `on_hand_qty − reserved_qty` (§3, "universal, non-negotiable") | **the FG** | **warehouse** |

**A Products Workspace "In Stock" badge is concept 3 and is never evidence of fulfilment readiness.**

Canonical clamp rule is flag-gated (`inventory_ledger.canonical_summary`, currently `false` → legacy sum-then-clamp).

---

## 15. Manufacturing Contract

| Rule | Value | Authority |
|---|---|---|
| `can_manufacture = false` | `SKIP_NOT_MANUFACTURABLE` — **valid**, log only, no action | MFG-001 |
| `can_manufacture = true` + no recipe | `FAIL_NO_RECIPE` — **the only invalid combination** | MFG-002 |
| `can_manufacture = false` + active recipe | **VALID** — recipe may serve costing (§7) | MFG-001 + ADR-027 §16.1 |
| Case 2 commitment | Only when the recipe is **executable** (Option B) | §16.1 |
| `recipe_missing` | Does **not** block | §16.1 |
| Recipe executability | Every component passes `available > 0 OR allow_negative_stock` | §16.3 |
| Sole authority | `ManufacturingAvailabilityService` | §16.3 |
| Ownership scope | Product → Brand → Company; fail-closed; brand scoping **forbidden** | §16.4, §16.5 |
| Reservation blocking | Manufacturing **never** blocks reservation (P05); zero FG is a manufacturing trigger, not an inventory exception | §13 P05 |

---

## 16. Preparation Eligibility Contract

**`PreparationReleaseEngine` is the sole Entry Gate.** Exactly two conditions — **no additions**:

1. `order.status ∈ eligible_order_statuses` (policy-driven; default `['new','in_progress']`)
2. `assigned_warehouse_id !== null`

**Deliberately NOT gates:** reservation state, material availability, negative-stock exception. Reservation enforcement belongs at the **allocate/pick** boundary per ADR-027 §9, not at admission (§10).

Detachment is event-driven via `OrderPreparationObserver` on `status` / `assigned_warehouse_id` change. **There is no constructive counterpart** — re-attachment depends entirely on the `WarehouseAssigned` seam (§18).

---

## 17. Wave Eligibility Contract

**Eligible: `new`, `in_progress`. Nothing else.**

Membership is collected by `WaveMembershipService::attachEligibleOrders()` while the wave is `Collecting` or `Preparing`, filtered by company + warehouse + `eligible_order_statuses`.

**The live `wave_engine_configurations.eligible_order_statuses = ["confirmed"]` is invalid** — a non-existent status, Tier 5 data, rejected on three grounds (§10). It must be corrected to the two-status list. **Not changed by this task.**

---

## 18. Event Contract

### Resolution

| # | Question | Answer | Authority |
|---|---|---|---|
| 1 | **Which event is authoritative?** | **`WarehouseAssigned`** | ADR-027 §2 (*"As soon as a `WarehouseAssigned` event fires… execution must automatically retry"*) and §15 **H3**, which names the listener |
| 2 | **Should `BranchAssigned` be translated to `WarehouseAssigned`?** | **Yes — this is the correct resolution.** `BranchAssignmentEngine` must emit the canonical `WarehouseAssigned` (in addition to, or in place of, `BranchAssigned`) so every assignment source converges on one event | ADR-024 (one canonical representation); ADR-027 §2 |
| 3 | **Should the preparation listener subscribe directly to `BranchAssigned`?** | **No.** That would create a second canonical assignment event and require every future consumer to subscribe twice — the exact duplication ADR-024 forbids | ADR-024 |
| 4 | **Is `WarehouseAssigned` still authoritative architecture?** | **Yes.** ADR-027 v1.1/v1.2 names it as *the* trigger. It is not legacy | ADR-027 §2, §15 H3 |
| 5 | **Is there a legacy event to retire or bridge?** | **`BranchAssigned` is the newcomer, not the legacy.** It carries genuinely richer data (branch + previous branch/warehouse) and should be **retained as an additional, branch-specific notification** — but it must not be the assignment trigger. **Bridge, do not duplicate** | ADR-024; ADR-027 §2 |

### Resolved contract

```
BranchAssignmentEngine::assign()  /  ::override()
        │
        ├──▶ WarehouseAssigned   ← CANONICAL assignment trigger (ADR-027 §2, H3)
        │        ├──▶ ExecuteReservationOnWarehouseAssigned   (H3 — MISSING)
        │        └──▶ WarehouseAssignedListener               (EXISTS — preparation auto-attach)
        │
        └──▶ BranchAssigned      ← branch-specific detail; NOT the assignment trigger
```

`WarehouseAssignmentEngine` (legacy, still reachable via `WarehouseAssignmentController`) already emits `WarehouseAssigned` and therefore needs no change. **No new event is created.**

### State vs Blocker

**The existing model already supports blocker/reason. No schema change is required.**

| Mechanism | Column / table | Populated today? |
|---|---|---|
| Reservation execution postponed | `orders.reservation_status = 'pending'` (ADR-027 §2: *"decision made, execution pending"*) | Yes — semantics defined |
| Assignment blocker class | `orders.warehouse_assignment_source` (`unassigned` / `no_branch_coverage`) | **Yes** |
| Assignment blocker detail | `orders.warehouse_assignment_failure_reason` | **Yes** — "No Branch Covers Destination" |
| Reservation blocker detail | `orders.reservation_failure_reason` | **Yes** |
| Audit trail | `order_reservation_audits` (from/to/reason/actor), `order_events` | **Yes** |

**ECOS already represents `Order Lifecycle State + Fulfilment Blocker Reason`.** The defect is not a missing mechanism — it is that `ProcessOrderWorkflow` **overwrites the lifecycle status instead of relying on the blocker fields that were already correctly populated by `BranchAssignmentEngine` moments earlier.**

**Smallest required architectural change: none.** The repair is to stop writing the status, not to add a field.

---

## 19. Re-Evaluation Matrix

| BLOCKER | EVENT | RE-EVALUATION | RESULT | IDEMPOTENT? |
|---|---|---|---|---|
| **Warehouse unavailable** | — (blocker recorded) | none | Order **remains `new`** (or its current state); `reservation_status = pending`; Command Center No Coverage Exception; **not** preparation-eligible (gate: `no_warehouse_assigned`); not in a wave; **manual triage required** | n/a |
| **Warehouse becomes available** | **`WarehouseAssigned`** | `ExecuteReservationOnWarehouseAssigned` → `RetryReservationJob` (**H3 — missing**) | Reservation executes → `reserved` / `partial_reserved` / `awaiting_stock`. Status → `in_progress` on success. **Becomes preparation-eligible**; `WarehouseAssignedListener` attaches to today's session; enters an active wave if one is Collecting | **Yes** — guard `reservation_status = pending`; action skip-list covers Reserved/Transferred/Consumed/Released |
| **Stock unavailable** | — | none | Order is `awaiting_stock` (**genuine FG shortage**); reservation `awaiting_stock`; **not** preparation-eligible (status); not in a wave | n/a |
| **Stock becomes available** | `InventoryStockReceived` | `RetryReservationOnStockAvailableListener` (**exists**; M6 partially delivered — FIFO not implemented; NULL-warehouse orders excluded by design once §4 is applied, since they are no longer `awaiting_stock`) | `awaiting_stock` → `reserved` / `partial_reserved`; status → `in_progress`; **becomes preparation-eligible**; enters wave | **Yes** — same skip-list |
| **Manufacturing unavailable** | — | none | Under ADR-027 §3 Case 2 a manufacture-backed order is committed **at reservation time** and never waits here. Reachable only when `can_manufacture = false` (MFG-001 → not manufactured at all) | n/a |
| **Manufacturing completes** | `ManufacturingJobCompletedEvent` | `ManufacturingJobCompletedListener` → updates `preparation_production_requirements` **only**. **No order-facing re-evaluation exists, and none is specified by any ADR** | Order **remains unchanged**. Recovery only indirectly, when produced stock is received (`InventoryStockReceived`) | n/a — **see §25** |
| **Reservation not possible** | — | Reservation has no independent blocker; it is always gated by warehouse or stock | Covered by the two rows above | — |
| **Reservation becomes possible** | `WarehouseAssigned` or `InventoryStockReceived` | H3 / M6 | As above | **Yes** — but a `Released` reservation is **terminal** and must never be silently revived; release requires an explicit new commercial decision |

---

## 20. ORD-00001 Expected Path

**Under the resolved contract**, with Q1 repaired and H3 delivered:

```
order created (governorate القاهرة)
  → BranchAssignmentEngine::assign()
  → canonical geography resolution → master_governorates.id (Cairo)
  → coverage → Cairo HQ → Main Warehouse (same company)
  → assigned_warehouse_id set, source = branch_coverage
  → WarehouseAssigned dispatched (canonical)
       ├→ ExecuteReservationOnWarehouseAssigned  (H3)
       └→ WarehouseAssignedListener → preparation session attach
  → ReserveOrderInventoryAction:
       Case 1  available 0 < requested 2                       → skip
       Case 2  can_manufacture = 0                             → skip  (recipe IS executable, never consulted)
       Case 3  allow_negative_stock = 0                        → skip
       Case 4  → reservation_status = awaiting_stock, reason "Insufficient Inventory"
  → status = awaiting_stock          ← CORRECT under ADR-027 §3 Case 4
```

**The order legitimately waits for finished-goods stock.** Recovery: `InventoryStockReceived` for FG-000001 into Main Warehouse → retry listener → `reserved` → `in_progress` → preparation-eligible.

**Contrast with today:** identical end status, entirely different meaning — today it means *"we could not find a warehouse"* and is unrecoverable; afterwards it means *"we have no stock of this product"* and is automatically recoverable.

---

## 21. ORD-00002 Expected Path

```
order created (governorate = NULL)
  → BranchAssignmentEngine::assign()
  → governorate === '' → markUnresolved()
  → warehouse_assignment_source = 'unassigned', assigned_warehouse_id = NULL
  → STATUS UNCHANGED → remains `new`         ← ADR-027 §10
  → reservation_status = pending                ← ADR-027 §2
  → Command Center: address_unresolvable (GEOGRAPHY-COVERAGE-ENGINE §7, Blocking)
  → Preparation Entry Gate: 'no_warehouse_assigned' → correctly held out
  ─── waits for OPERATOR ADDRESS COMPLETION (not a code repair) ───
  → operator completes governorate via normal order edit (isLocked() = false at `new`)
  → re-assignment → WarehouseAssigned → H3 → reservation → same ladder as ORD-00001
```

**Q1's repair does not affect ORD-00002.** Its blocker is missing data, not a matcher defect — correctly classified by `GEOGRAPHY-COVERAGE-ENGINE.md` §7 as `address_unresolvable`, whose required action is manual assignment.

---

## 22. Implementation Boundaries

| Item | Class | Boundary |
|---|---|---|
| Canonical geography resolution (Q1) | **E — integration** (or **A** if an interim bilingual match is approved) | Touches ingestion + coverage; **must not** create a second geography system |
| No-coverage status removal (Q2) | **D — state machine** | `ProcessOrderWorkflow` **and** `ConfirmOrderWorkflow` (identical guards). Reservation code untouched |
| `awaiting_stock` semantics (Q3) | **G — already correct in ADR**; enforcement is part of the Q2 repair | No new status |
| H3 retry listener (Q4-A) | **C — workflow** | New listener only; `ReserveOrderInventoryAction` untouched |
| M6 FIFO + retry hardening (Q4-B) | **C — workflow** | Existing listener; do not widen to NULL-warehouse orders once Q2 lands |
| Event bridge (§18) | **E — integration** | Emit canonical `WarehouseAssigned`; retain `BranchAssigned` as detail. **No new event** |
| `can_manufacture` / `allow_negative_stock` | **G — already correct** | **Do not change** |
| Wave config `["confirmed"]` (Q8) | **B — data repair** | Config row only; no code |
| `StockAddedListener` column | **A — one-file defect** | Violates *"none are swallowed silently"* |
| `MoveToPreparationWorkflow` null-warehouse guard | **C — workflow** | Align with the Q2 ruling |
| Legacy order reprocessing (Q7) | **C — workflow** (ADR-027 H4) | Supervised job, dry-run mandatory. **No manual DB writes** |
| Preparation Entry Gate | **G — already correct** | **No new gate conditions** |
| Container parity | Deployment action | `docker cp` |

**Protected and untouched:** `MaterialDemandCalculator`, reservation engine, `PreparationReleaseEngine`, F4/Option B, tenant isolation, IAM, certified availability contracts.

---

## 23. Required Implementation Tasks — Dependency Order

| # | Task | Depends on | Class |
|---|---|---|---|
| **T0** | Restore container parity (`docker cp` `MaterialDemandCalculator`); verify `ce69612a` both sides | — | deployment |
| **T1** | Correct `wave_engine_configurations.eligible_order_statuses` → `['new','in_progress']` | Q8 ✔ | B |
| **T2** | Fix `StockAddedListener` column `on_hand_quantity` → `on_hand_qty`; surface the exception rather than swallowing it | — | A |
| **T3** | **Emit canonical `WarehouseAssigned` from `BranchAssignmentEngine`** (retain `BranchAssigned` as detail) | §18 ✔ | E |
| **T4** | **ADR-027 H3** — `ExecuteReservationOnWarehouseAssigned` → `RetryReservationJob`; guard `reservation_status = pending` | T3 | C |
| **T5** | **Remove the no-coverage status write** from `ProcessOrderWorkflow` **and** `ConfirmOrderWorkflow`; leave `reservation_status = pending`, status unchanged | Q2 ✔, T4 | D |
| **T6** | Align `MoveToPreparationWorkflow` null-warehouse handling with T5 | T5 | C |
| **T7** | Command Center "No Coverage Exception" surface (source + failure_reason already populated) | T5 | E |
| **T8** | Canonical geography resolution (Q1) — **awaits the §25 sequencing decision** | §25 D1 | A or E |
| **T9** | **ADR-027 M6** — FIFO ordering in the stock-arrival retry | T5 | C |
| **T10** | **ADR-027 H4** — `orders:reprocess-legacy-reservations` with dry-run; also restores mis-set `awaiting_stock` → `new` and writes retroactive audit | T5, T8 | C |
| **T11** | Audit whether the allocate/pick boundary enforces ADR-027 §9 (reservation before Allocate/Pick) | — | audit |

**T5 is the keystone.** Landing it before T3/T4 would leave orders correctly *unmoved* but still unrecoverable; landing T3/T4 first makes recovery work the moment T5 stops mislabelling.

---

## 24. Certification Strategy

| Component | Impact | Action |
|---|---|---|
| **ADR-027 compliance** | `ProcessOrderWorkflow` / `ConfirmOrderWorkflow` are in **breach of §2 and §10** | Re-certify against §9's matrix after T5 |
| **Orders lifecycle (V3)** | Component certification **stands** — the enum and guards are correct | Re-certify the assignment↔reservation seam only |
| **Reservation / F4 / Option B** | **No impact** — behaviour is exactly as ADR-027 §3/§16 specify | No re-certification |
| **`ManufacturingAvailabilityService`** | **No impact** — §16.3 rule honoured verbatim | No re-certification |
| **Preparation Entry Gate** | **No impact** — no gate condition changes | Regression-test only |
| **`MaterialDemandCalculator`** | **Cannot be assessed** — parity broken | Re-certify **after T0**; certification neither confirmed nor revoked |
| **Branch Assignment (scenario C)** | Passes but is **component-scope-only** — cannot detect the workflow overwrite | Add an integrated test spanning assign → ProcessOrderWorkflow |
| **Tenant isolation / IAM** | **No impact** | None |
| **Shipping (future)** | **Unexercised** — no order in this environment has reached `in_progress` | Do not certify until T10 produces one complete traverse |

**Principle:** every defect found sits in a **seam between** certified components. **No component certification is revoked.** The gap is integrated-workflow certification, which does not currently exist for the assignment → reservation → preparation chain.

---

## 25. Remaining Decisions

Genuinely unresolved by existing sources.

| # | Decision | Why unresolved | Blocks |
|---|---|---|---|
| **D1** | **Q1 sequencing** — ship a bilingual-name interim (option A) as a time-boxed bridge, or go straight to canonical FK (option B)? | The **target** is settled (GEOGRAPHY-COVERAGE-ENGINE §5). The **path** is not: B needs an order-level ID column and populated brand geography (both tables empty); A is zero-schema but architecturally disfavoured | T8 |
| **D2** | **Scenario C** — should manufacturing completion trigger order re-evaluation? | No ADR specifies an order-facing trigger. ADR-027 §3 Case 2 arguably makes it a non-scenario, but **no source states this positively** | Q4-C |
| **D3** | Is FG-000001 **commercially** intended to be manufacturable / credit-eligible? | Configuration validity is **resolved** (§7, §8 — both valid). Commercial intent is not addressed by any source | Nothing — informational |
| **D4** | Does the allocate/pick boundary enforce ADR-027 §9 (reservation before Allocate/Pick)? | **Not audited in this task** | T11 |
| **D5** | Should the ADR-005 "future ADR" defining the order FSM finally be written? | ADR-005 §5 promises it; it does not exist. Its absence is why two implementations diverged without either breaching an ADR | Long-term governance |

---

## MANDATORY FINAL TABLE

| QUESTION | DECISION | SOURCE | CONFIDENCE | IMPLEMENTATION IMPACT |
|---|---|---|---|---|
| **Q1** Geographic comparison | **B — canonical FK / normalized geographic identity**, resolved via the existing `BrandConfigurationResolverService` chain (D as mechanism). **Not** display-name matching. *Sequencing of the migration is D1.* | GEOGRAPHY-COVERAGE-ENGINE §5, §2 (Tier 2); ADR-024 (Tier 1); existing resolver | **HIGH** on target; **MEDIUM** on path | **T8** — class E (or A if an interim is approved). Requires order-level ID + populated brand geography |
| **Q2** No-coverage contract | **Status does NOT change.** Stamp `warehouse_assignment_source` + `failure_reason`, `reservation_status = pending`, Command Center signal. **`BranchAssignmentEngine` wins; `ProcessOrderWorkflow` is in breach** | **ADR-027 §10** + §2 (Tier 1); GEOGRAPHY §7; ENTERPRISE-FULFILLMENT §24 | **HIGH** | **T5** — class D. Both `ProcessOrderWorkflow` and `ConfirmOrderWorkflow` |
| **Q3** AwaitingStock semantics | **A — inventory/material shortage ONLY.** Never warehouse failure. **No new status** | **ADR-027 §3 Case 4**, §2, §16.1 (Tier 1) | **HIGH** | Enforced by T5; no separate task |
| **Q4** Re-trigger / re-evaluation | Existing event architecture **suffices**. A: `WarehouseAssigned` → H3 (**missing**). B: `InventoryStockReceived` → M6 (partial). C: **no path, likely a non-scenario** (D2). D: no independent blocker; `Released` terminal | **ADR-027 §2 Trigger Events**, §15 **H3/M6** (Tier 1) | **HIGH** (A,B,D) · **MEDIUM** (C) | **T3, T4, T9** — classes E, C, C |
| **Q5** `can_manufacture = 0` + active BOM | **A — VALID.** Recipe exists; product is not order-manufactured. `false` is a defined non-error outcome; the BOM actively serves **costing** | **DECISION-ENGINE-SPEC MFG-001/002** (Tier 2); ADR-027 §16.1; `ProductContext` | **HIGH** | **NONE — class G.** Do not change the flag |
| **Q6** `allow_negative_stock` | **Two independent levels.** Material → recipe executability. Product → Case 3 credit sale. FG-000001's config is **VALID**; RM `=1` + FG `=0` produces `awaiting_stock` **correctly** via Case 4 | **ADR-027 §6, §3 Case 3, §16.2, §16.3** (Tier 1) | **HIGH** | **NONE — class G.** Do not change the flag |
| **Q7** Existing orders | **Reprocess via supervised background job with dry-run** (H4). No manual correction, no recreation, no discard. ORD-00002 needs **operator address completion**, not a code repair. Retroactive audit preserves history | **ADR-027 §12** + §15 **H4** (Tier 1); GEOGRAPHY §7 | **HIGH** | **T10** — class C. Must also restore mis-set `awaiting_stock` → `new` |
| **Q8** Wave-eligible statuses | **`new` and `in_progress` — exactly two.** `"confirmed"` rejected: not in the V3 enum; ADR-005 forbids channel statuses as ERP states; Tier 5 data is never authoritative | `PreparationSessionPolicy::defaultEligibleStatuses()` (Tier 3); **ADR-027 §9**; **ADR-005 §5** (Tier 1) | **HIGH** | **T1** — class B, config data only |

---

## FINAL VERDICT

1. **Order lifecycle contract.** 11 V3 states. The ERP owns the lifecycle after acceptance (ADR-005). Reservation is non-blocking and never prevents progression (ADR-027 §2). Lifecycle status carries commercial/operational state; **fulfilment blockers are carried in dedicated fields that already exist and are already populated.** No new status, no schema change.

2. **What `awaiting_stock` means.** Finished-goods unavailability, and nothing else — ADR-027 §3 Case 4, the residual of the FG decision tree. It has no warehouse meaning.

3. **How warehouse assignment failure is represented.** `warehouse_assignment_source` (`unassigned` | `no_branch_coverage`) + `warehouse_assignment_failure_reason` + `reservation_status = pending`, with the **lifecycle status untouched**, surfaced as a Command Center exception. ADR-027 §10: *"Command Center signal, not an error."*

4. **How warehouse assignment recovery works.** `WarehouseAssigned` — the canonical, ADR-named event — fires on every assignment, from any engine, and triggers `ExecuteReservationOnWarehouseAssigned` → `RetryReservationJob`, idempotently guarded on `reservation_status = pending`. This is ADR-027 roadmap item **H3**, open since v1.1.

5. **Which V3 statuses enter Preparation Waves.** `new` and `in_progress`. Exactly two. `"confirmed"` is invalid on three independent grounds.

6. **How the `BranchAssigned` / `WarehouseAssigned` seam should work.** `WarehouseAssigned` is authoritative. `BranchAssignmentEngine` must emit it; `BranchAssigned` is retained as branch-specific detail but is **not** the assignment trigger. **Bridge, do not duplicate** — no new event, and the preparation listener stays on the canonical event.

7. **Is the product configuration valid?** **Yes — both flags.** `can_manufacture = 0` beside an active BOM is MFG-001's defined `SKIP_NOT_MANUFACTURABLE`, and the BOM is actively serving cost rollup. `allow_negative_stock = 0` on the FG with `= 1` on its components is exactly ADR-027's two-level rule. **Neither flag should be changed**, and ORD-00001 reaching `awaiting_stock` *after* warehouse assignment is the contract working correctly.

8. **Exact implementation tasks, in dependency order.** **T0** parity → **T1** wave config → **T2** StockAddedListener → **T3** emit canonical `WarehouseAssigned` → **T4** H3 retry listener → **T5** remove the no-coverage status write *(keystone)* → **T6** MoveToPreparation guard → **T7** Command Center surface → **T8** canonical geography *(awaits D1)* → **T9** M6 FIFO → **T10** H4 legacy reprocessing → **T11** allocate/pick §9 audit.

---

## Compliance Statement

No production code, migration, database write, order mutation, reservation mutation, test expectation, fixture, or configuration was changed. `CoverageResolutionService`, `StockAddedListener`, wave configuration, `MaterialDemandCalculator`, Preparation, Shipping, and all event listeners are untouched. No status was changed. Container parity was verified before any runtime consideration and is recorded as **BROKEN**; no container demand or preparation output was used as evidence. The sole deliverable is this contract and the implementation sequence in §23.
