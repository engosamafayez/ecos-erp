# TASK-PREPARATION-FG-OWN-RESERVATION-DEMAND-CERTIFICATION-001 — Engineering Report

**Date:** 2026-08-10
**Branch:** `develop` · **HEAD:** `6149875b`
**Runtime:** `ecos-dev-testrunner` → MySQL `ecos_dev_test` · PHP 8.4.24 · PHPUnit 11.5.55
**Verdict:** **C — `MaterialDemandCalculator` is not calculating Finished Product availability. The premise must be reframed.**

---

## 1 — Executive Summary

The open question was whether a Finished Good's reservation, held by the **same order** that is being
prepared, should reduce the availability used in Preparation demand — or whether subtracting it would
double-count the demand Preparation is already planning.

The scenario was built and it **is** constructible: an order can be simultaneously `reserved` and
Preparation-eligible. It was driven end to end through real workflows.

The answer is that the question does not apply to this engine.

`MaterialDemandCalculator` never reads the finished good's stock. It explodes BOMs and evaluates
**raw-material** availability only. In the certified scenario it produced **no row at all** for the
finished good, while the finished good sat at `on_hand 10 / reserved 10`.

Two further runtime facts close the double-counting question completely:

1. **Reserving a finished good does not reserve its components.** X held `reserved = 0` both before and
   after the finished good was fully reserved. So a component's `reserved_qty` can never originate from
   the wave's own finished-good demand.
2. **When a component *is* reserved by an order inside the wave**, that reservation belongs to the
   component's **product** demand (shipped as-is), which is a different quantity from its **material**
   demand (consumed to build). Runtime: product demand 8, material demand 10 — distinct, not duplicated.

Consequence: there is no configuration in which subtracting `reserved` from a component's `on_hand`
removes demand that Preparation is itself planning. The double-count risk that paused the repair does
not exist for this engine.

---

## 2 — Starting Commit

| Item | Value |
|---|---|
| HEAD / branch | `6149875b` / `develop` |
| Tracked diff at start **and end** | `8 files changed, 306 insertions(+), 27 deletions(-)` |
| Modified files | `ReserveOrderInventoryAction`, `EloquentProductRepository`, `ManufacturingAvailabilityService`, `PreparationSessionPolicy`, `PreparationWaveController`, `phpunit.xml`, `TestCase.php`, `ADR-027` |
| Untracked (prior task) | `RawMaterialDirectSaleBomComponentTest.php`, its report |
| Added by this task | `FinishedGoodOwnReservationDemandTest.php`, this report |
| Containers | DEV stack `ecos-dev-*`; MAIN stack `ecos-*` running and untouched |
| Host PHP processes | 0 |
| Other agents owning these files | none |

No file owned by another agent was touched, reverted or reformatted.

---

## 3 — Finished Product Fixture

One coherent company boundary:

```
Company A → Brand A → Finished Product A   (product_type = finished_good)
                    → Raw Material X       (product_type = raw_material)
Warehouse A
```

Products were created through `Product::factory()` with explicit `brand_id` and `company_id`, so brand
and company ownership are coherent and F4's company-scoping rules apply normally.

---

## 4 — BOM

```
Finished Product A ──► Raw Material X   @ 1.0 per unit, waste 0%, is_active = true
```

Written to `bills_of_materials` + `bill_of_material_lines` — the exact tables the engine reads. The
component is stocked at 20 so the Option B recipe gate has no reason to refuse reservation; this keeps
the audit focused on the availability question rather than on gate behaviour.

---

## 5 — Order

A real `Order` + `OrderLine`, created at status `new` so the **application** performs the transition:

```
Order A   line = Finished Product A × 10   status new
```

No status was written directly at any point after creation.

---

## 6 — Reservation

The real transition `POST /api/fulfillment/orders/{id}/transition` → `in_progress`.

`ProcessOrderWorkflow:123` calls `ReserveOrderInventoryAction` when the order is not already reserved
and a warehouse is assigned. Runtime result:

```
transition http           = 200
order status              = in_progress
order reservation_status  = reserved
FG on_hand / reserved     = 10 / 10
```

**This is the Part 8 fixture exactly: required 10, on_hand 10, reserved 10 by the same order.**

No reservation row was inserted directly.

> **Correction to an earlier finding.** A previous probe (AUDIT B1) concluded that entering
> `in_progress` does *not* create a reservation. That is wrong as a general statement:
> `ProcessOrderWorkflow` reserves on entry to `in_progress` whenever a warehouse is assigned, which is
> demonstrated here (`reservation_status = reserved`, `reserved_qty = 10`). This matters, because it is
> precisely what makes the same-order scenario constructible.

---

## 7 — Preparation Entry

The **certified** entry gate was used unchanged, and the order entered on its own merits:

```
wave create http        = 201
wave order membership   = ["019fed0e-d73c-734a-bda8-23813d34b7e1"]   ← the reserving order itself
```

No Part 13 conflict arose. `TASK-GOLIVE-PREPARATION-ENTRY-GATE-REPAIR-002` admits `in_progress`, and
reservation does not move the order out of `in_progress` — consistent with the V3 contract recorded in
`MoveToPreparationWorkflow`:

> *"In V3, Preparing is an invisible engine state — orders stay In Progress while being prepared."*

Reservation therefore coexists with Preparation by design; it is not a post-Preparation-only state.

---

## 8 — Wave

One wave, one member: the same order that owns the reservation. No second order was created for the
demand, and no unrelated order was attached.

---

## 9 — Demand Generation

Through the production pipeline — `DemandCalculationService::recalculate()` →
`DemandProjectionBuilder` → `ProductDemandCalculator` → `MaterialDemandCalculator` → persisted
projections. No wave demand row was inserted directly.

```
wave product demand (A) = 10
```

---

## 10 — MaterialDemandCalculator Pipeline

Source-level scope, confirmed at `HEAD` (md5 `4c2903b8…`):

- Aggregates are keyed by `boml.raw_material_id` (`MaterialDemandCalculator:58`).
- Step 4 queries stock as
  `inventory_items whereIn('product_id', $materialIds)` where `$materialIds = array_keys($aggregates)`
  (`:94–:100`).

So the only `product_id`s whose stock is ever read are **BOM components**. The finished good's
inventory row is never consulted by this engine.

Runtime confirmation:

```
row for FINISHED GOOD A  = NONE
row for RAW MATERIAL X   = {"required":10,"available":20,"reserved":0,"missing":0}
```

**This is the finding that decides the task.** The engine emits no availability figure for the finished
good at all, so there is no "Preparation availability" of a finished good for its own reservation to
reduce or double-count.

---

## 11 — Same-Order Reservation

```
FG on_hand / reserved         = 10 / 10        ← reserved by the order in the wave
RM on_hand / reserved before  = 20 / 0
RM on_hand / reserved after   = 20 / 0         ← unchanged
row for FINISHED GOOD A       = NONE
```

The finished good is fully reserved by the very order being prepared, and:

- the engine produces no row for it, and
- the component's `reserved_qty` is completely unaffected.

`ReserveOrderInventoryAction` iterates `$order->lines` and reserves `$line->product_id`
(`:110`, `:120`). It never explodes a BOM. Reserving a finished good reserves the finished good — never
its components. This matches ADR-027: Orders reserve FG only; Manufacturing owns all RM decisions.

---

## 12 — Competing Reservation Control

```
order A (in wave)      qty 10   reserved
order B (NOT in wave)  qty 5    reserved
FG on_hand / reserved  = 15 / 15
wave order membership  = [order A]
wave product demand    = 10        ← order B's 5 excluded
row for FINISHED GOOD  = NONE
RM required/available/missing = 10 / 20 / 0
```

A competing order reserving the *same* finished good does not inflate wave demand and still produces
no finished-good row. Contamination is absent at both the product-demand and material-demand layers.

---

## 13 — Finished Product vs Raw Material Distinction

Kept strictly separate throughout, as Part 6 required:

| Quantity | Part 8 | Part 9 | Part 10 |
|---|---|---|---|
| Order line quantity | A × 10 | A × 10 (+B × 5) | A × 10, X × 8 |
| **Finished Product** reservation | 10 | 15 | 10 |
| Preparation (product) demand | A = 10 | A = 10 | A = 10, **X = 8** |
| **Raw Material** demand | X = 10 | X = 10 | X = 10 |
| **Raw Material** reservation | **0** | **0** | **8** |
| Finished Product on_hand | 10 | 15 | 10 |
| Raw Material on_hand | 20 | 20 | 20 |

The two reservation columns never merge. A finished-good reservation of 10 or 15 leaves raw-material
reservation at 0.

---

## 14 — Double-Counting Analysis

Part 10 required that CASE 1 and CASE 2 not be collapsed. They are answered separately, and a third
case that neither anticipated is answered too.

**CASE 1 — reservation belongs to the SAME order being prepared.**
The reservation lands on the *finished good*. The engine reads only *component* stock. The reservation
is invisible to the calculation, and it does not propagate to components (`0 → 0`). There is nothing to
subtract and nothing to double-count. *The case cannot arise inside this engine.*

**CASE 2 — reservation belongs to a DIFFERENT order not being prepared.**
Already certified by `TASK-PREPARATION-RM-DIRECT-SALE-BOM-COMPONENT-CERTIFICATION-001`: a direct order
for the component is competing demand, and the engine ignores it (reports 15 available where 7 are
free). Should reduce availability.

**CASE 3 — component reserved by an order INSIDE the wave** (not posed by the task; the only remaining
configuration in which a component carries an in-wave reservation, so it was tested):

```
X on_hand / reserved          = 20 / 8      ← reserved by order C, INSIDE the wave
X as PRODUCT demand  (ship)   = 8
X as MATERIAL demand (build)  = 10
total true need               = 18
engine available / missing    = 20 / 0
if reserved were subtracted   = 12
```

The reserved 8 corresponds to X's **product** demand — order C ships 8 units of X as-is. The material
demand of 10 is for *building* A, and it is not reserved by anything. These are two different demands
against the same stock, totalling 18 of 20 — not one demand counted twice.

Subtracting reserved gives 12 free for manufacturing against a material requirement of 10: correct, and
it correctly protects order C's 8 units. Not double-counting.

**Conclusion:** in all three configurations, subtracting `reserved` from a component's `on_hand`
removes only stock committed to *other* demand. It never removes the wave's own material demand,
because the wave's material demand is never reserved.

---

## 15 — Runtime Evidence

Provenance captured in the same command as execution:

```
4c2903b8fc751d05755b6fb8cdfa3546  MaterialDemandCalculator.php      ← ≡ HEAD
fdf609e5cee0eaa0534675ce5bd632cb  FinishedGoodOwnReservationDemandTest.php

✔ Finished good reserved by the same order that is being prepared
✔ Competing order reserves the same finished good but stays out of the wave
✔ Component reserved by an order inside the same wave

OK (3 tests, 19 assertions)
```

Required Part 14 evidence, all captured: Order ID, Order Status, Order Line, Finished Product ID,
FG on_hand, FG reserved, Wave ID, Wave order membership, Preparation demand, Raw Material demand,
Raw Material reserved, `available_qty`, `missing_qty`.

---

## 16 — F4 / Option B Preservation

Verified byte-identical between host worktree and container:

| File | md5 | Status |
|---|---|---|
| `ReserveOrderInventoryAction.php` | `670ba67a…` | MATCH |
| `ManufacturingAvailabilityService.php` | `14701fd3…` | MATCH |
| `EloquentProductRepository.php` | `d7e4753c…` | MATCH |

Neither was reopened or modified. Option B was additionally exercised *live*: Finished Product A has an
active BOM, so reservation passed through the recipe gate and succeeded (`reserved_qty = 10`),
demonstrating the gate is active and unweakened. No company-isolation rule was relaxed — every fixture
is inside a single company, and no cross-company path was introduced.

> A first parity check appeared to report DRIFT on all three files. That was a false alarm: Git Bash
> path-translated `/var/www/html/...` into `C:/Program Files/Git/var/www/html/...`, so the container-side
> `md5sum` never ran. Re-run inside the container's own shell, all three match.

---

## 17 — Production Changes

**None.** The tracked diff is byte-for-byte identical at the start and end of this task:
`8 files changed, 306 insertions(+), 27 deletions(-)`.

Not modified: `MaterialDemandCalculator`, `ReserveOrderInventoryAction`, Reservation services,
Preparation services, Inventory services, BOM services, Order services, F4, Option B.

`ManufacturingAvailabilityService` and `ReserveOrderInventoryAction` carry pre-existing F4 / Option B
changes from prior authorised work; they were read here, never written.

---

## 18 — Test Changes

Added: `backend/tests/Feature/Operations/DemandEngine/FinishedGoodOwnReservationDemandTest.php` (3 tests,
19 assertions, Pint clean).

**No existing test expectation was changed.** The pre-existing failure in
`MaterialDemandCalculatorTest::test_missing_qty_uses_available_not_on_hand` remains as found.

The new test asserts only structural facts that hold regardless of which availability rule is correct
(which product has a row, which order is in the wave, whether component reservation changed). The
disputed availability values are printed and recorded, never asserted.

---

## 19 — Final Verdict

> ### C — `MaterialDemandCalculator` is not calculating Finished Product availability, so the premise must be reframed
>
> The engine evaluates **raw-material** availability only. With the finished good at
> `on_hand 10 / reserved 10`, reserved by the very order in the wave, the engine produced **no row for
> the finished good at all**.
>
> A finished good's own reservation therefore cannot reduce, inflate or double-count anything in this
> calculation — it is never read.
>
> The reframed question, and its answer: *can a component's `reserved_qty` ever represent the same
> demand the wave is planning?* **No.** Reserving a finished good never reserves its components
> (`0 → 0`), and a component reserved by an in-wave order is reserved against its **product** demand,
> which is a separate quantity from its **material** demand (8 vs 10).

Neither A nor B is selected, because both presuppose that this engine computes finished-good
availability. It does not.

---

## 20 — Recommended Next Step

The blocker that paused `TASK-PREPARATION-MATERIAL-DEMAND-CALCULATION-REPAIR-001` is now cleared. The
double-count concern was the sole reason for the pause, and it has been shown not to arise in any of
the three possible configurations.

Recommended, for a **separate authorised task** — not started here:

1. Change `MaterialDemandCalculator:116` from `max(0.0, $onHand)` to `max(0.0, $onHand - $reserved)`,
   aligning it with `ManufacturingAvailabilityService` (ADR-027 §16.3's named sole authority) and the
   four other availability sites.
2. The pre-existing `test_missing_qty_uses_available_not_on_hand` becomes the acceptance test — it
   already encodes the target contract and must pass because production changed, not because the
   expectation moved.
3. Keep both certification tests as permanent regression guards.
4. Out of scope and still unexamined: `expected_today` and `in_transit_qty` are hard-coded to `0.0`.

**Per the final stop rule, work stops here.** The repair has not been performed. IAM has not been
started. Shipping has not been started. No test expectation was changed.
