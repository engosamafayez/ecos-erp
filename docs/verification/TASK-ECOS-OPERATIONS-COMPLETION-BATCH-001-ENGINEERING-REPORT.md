# TASK-ECOS-OPERATIONS-COMPLETION-BATCH-001 — Engineering Report

**Date:** 2026-08-18 · **Branch:** `develop` · **Worktree:** `C:\ecos-develop`

> ## BATCH STATUS
>
> | Workstream | Status | One-line result |
> |---|---|---|
> | **A — Inventory Reservation Lifecycle** | **IMPLEMENTATION COMPLETE** | A **real defect found and fixed**: after a warehouse reassignment, release threw and stranded the units |
> | **B — Preparation Wave** | **IMPLEMENTATION COMPLETE (no gaps)** | Contract already fully satisfied and proven — **nothing rewritten** |
> | **C — Recipe / Disassembly valuation** | **IMPLEMENTATION COMPLETE (already built)** | **NOT blocked** — the historical cost authority is proven to exist |
> | **D — Customer / Product read-model** | **PARTIAL** | D-1/D-2/D-6 already canonical; **one gap identified and deliberately NOT changed** (D-5) |
>
> Per project policy, **no final certification is claimed**. Final verification is a separate phase.

---

## Workstream A — Inventory Reservation Lifecycle Hardening

### A-1 · A-3 — contract inspected first

The Orders reservation contract was **preserved, not redesigned**. No order lifecycle, status,
availability formula, Material Demand formula, warehouse assignment or Wave timing was touched.

The release contract for **cancellation** already exists and is exercised in production
(`ReleaseOrderInventoryAction`, observed live during the Gate T browser smoke: ORD-00009 →
`cancelled` / `released`). This task therefore **invented no release semantics** — it corrected
*which warehouse* an already-established release targets. A-3's STOP was not triggered.

### A-2 / A-6 / A-7 — one root cause, three symptoms

**The defect (proven before any change was made):**

```
ReleaseOrderInventoryAction:83   warehouse_id: $order->assigned_warehouse_id
```

`assigned_warehouse_id` is the order's **current** warehouse, and it moves:
`WarehouseAssignmentEngine::override()` rewrites it with **no guard** requiring the order to be
un-reserved first. After a reassignment the release targets a warehouse where the order never
reserved anything, and `ReleaseStockAction` refuses:

| Destination state | Result |
|---|---|
| has an inventory row, `reserved_qty = 0` | `NegativeInventoryException: would become -5` |
| has **no** inventory row for the product | `InvalidInventoryMovementException: No inventory record found` |

Either way **cancellation becomes impossible** and the units held in the ORIGINAL warehouse are
**stranded** (A-7) with no path back — while A-6 is violated at the destination.

Proven by three failing tests before the fix:

```
1) test_release_after_reassignment_frees_the_reserving_warehouse   NegativeInventoryException (-5)
2) test_release_after_reassignment_does_not_disturb_the_destination NegativeInventoryException (-5)
3) test_release_succeeds_when_destination_has_no_inventory_row      InvalidInventoryMovementException
```

### The fix — minimal, reusing the existing authority

`ReleaseOrderInventoryAction` now releases against **the warehouse the reservation itself
recorded**, read from the canonical stock ledger:

```php
$releaseWarehouseId = $this->reservationWarehouseFor($order->id, $line->product_id)
    ?? $order->assigned_warehouse_id;   // fallback: pre-ledger rows keep prior behaviour
```

`reservationWarehouseFor()` reads the latest `Reservation` movement for
(`reference_type = sales_order`, `reference_id`, `product_id`). **This is the same ledger source
`ReconcileOrderMaterialReservationsAction::heldByThisOrder()` already trusts**, so no second
source of truth and no second idempotency framework was created (A-4).

**Files changed: 1 production file.** No formula, no lifecycle, no availability logic touched (A-6:
the fix is at the lifecycle's actual authority, not in the availability formula).

### A-4 · A-5 — preserved

Double release still raises `OrderAlreadyReleasedException` and does not double-decrement
(2 tests). Tenant scoping is untouched — the ledger lookup is keyed by the order's own id, so it
cannot reach another company's reservations, and no cross-company data appears in any error.

### A-8 — targeted verification

`tests/Feature/Inventory/ReservationWarehouseAuthorityTest.php` *(new)* — **6 tests / 12
assertions, OK**, covering A (create), B (release), C (release twice), H (warehouse-specific
release), K (no negative `reserved_qty`), L (no stranded reservation).

**Regression:** 76 tests across `ReservationWarehouseAuthorityTest`,
`OrderReservationLifecycleTest`, `OrderAvailabilityLifecycleContractTest`,
`OrderDrivenMaterialReservationTest`, `WaveCarryOverDependencyTest` — **2 failures, both
PRE-EXISTING** (`OrderReservationLifecycleTest` expects `ReserveOrderInventoryAction` to throw;
the committed contract moved that to the returned status). My change is in *Release*, not
*Reserve*, so it cannot reach them. **Nothing was weakened to obtain green.**

**Static:** `php -l` PASS · **PHPStan: no errors** · Pint PASS.

### A-3 — events NOT changed, and why

| Event | Finding |
|---|---|
| Order cancellation | established release contract → **fixed the warehouse authority only** |
| Shipment | `ShipOrderInventoryAction` owns it; consumption ≠ release. **Not touched** |
| Wave completion | carry-over **deliberately does not release** (proven in Workstream B). **Not touched** |
| **Recipe/BOM change** | **NO established release contract found.** Per A-3 this is reported, not invented — see *Contract gaps* below |

### A-7 — stranded reservations

The only stranded class identified is the one this fix removes (reassigned-then-released orders).
Valid historical Wave memberships and intentionally active reservations were **not** classified as
stranded, per A-7.

---

## Workstream B — Preparation Wave Gap Closure

### **NO GAPS FOUND. NOTHING WAS REWRITTEN.**

| Contract | Evidence | Verdict |
|---|---|---|
| **B-2** one authoritative timezone | `WaveEngine/CompanyTimezoneResolver` — `companies.timezone` is the authority and it **explicitly rejects** `config('app.timezone')`, the server clock, `wave_engine_configurations.timezone` and the browser. **Fails closed** on a missing/invalid zone | ALREADY SATISFIED |
| **B-3** start / intake / collection | `test_no_wave_is_opened_before_the_start_boundary`, `test_exactly_one_wave_opens_at_the_start_boundary_with_cross_day_bounds`, `test_eligible_orders_are_collected_into_the_open_wave`, `test_collection_is_idempotent_within_the_same_wave` | ALREADY SATISFIED |
| **B-4** cutoff | `test_orders_still_enter_just_before_the_cutoff_and_never_after_it` | ALREADY SATISFIED |
| **B-5** end | `test_preparation_continues_between_cutoff_and_end_then_the_wave_ends`, `test_wave_end_returns_only_unshipped_and_unprepared_orders`, `test_a_partially_prepared_order_is_not_treated_as_complete` | ALREADY SATISFIED |
| **B-6** carry-over, history, no duplicate reservation/demand | `test_carry_over_creates_a_second_membership_and_keeps_the_first`, `test_an_order_cannot_hold_two_active_memberships`, `test_carry_over_does_not_disturb_the_order_reservation`, `test_demand_for_a_carried_over_order_is_scoped_to_one_wave_at_a_time`, `test_a_fully_prepared_order_is_not_collected_into_the_next_cycle` | ALREADY SATISFIED |
| **B-7** cross-midnight | `WaveScheduleResolver` implements a single next-instant-after rule ("including across midnight and across a DST shift"); the boundary test above proves cross-day bounds | ALREADY SATISFIED |
| **B-8** boundary | Preparation writes only warehouse-assignment fields; status changes go through canonical workflows | ALREADY SATISFIED |

**Verification:** `WaveOperationalCycleTest` — **18 tests / 55 assertions, OK**.

### Correction to a previous report

`TASK-WAVE-CARRYOVER-RELEASE-DEPENDENCY-CLOSURE-001` §11 recorded cross-day timing as "not
independently re-verified". **That was too cautious.** `WaveOperationalCycleTest` does prove the
cross-day boundary; I had not run that suite when writing it. The contract is covered.

---

## Workstream C — Recipe / Disassembly Cost Valuation

### **NOT BLOCKED — the contract is proven and already implemented.**

C-3's STOP condition was tested against real repository evidence and **did not trigger**. All
seven C-2 questions answered:

| # | Question | Evidence |
|---|---|---|
| 1 | per-component costs in the snapshot? | **YES** — `recipe_cost_snapshot_lines`: `raw_material_id`, `unit_cost`, `extended_cost`, `quantity`, `waste_percentage`, `sku_snapshot`, `name_snapshot` |
| 2 | what identifies the approved version? | `bom_id` + `bom_version_number`, unique index `recipe_cost_snapshots_bom_version_unique` |
| 3 | approval state? | **YES** — activation IS approval in this platform (`SetBomStatusAction`); `is_active` |
| 4 | approval timestamp? | **YES** — `approved_at` + `approved_by`, on both `bills_of_materials` and the snapshot |
| 5 | deterministic reconstruction? | **YES** — "Resolved at approval and stored, not derived on read" |
| 6 | canonical Disassembly cost authority? | `DisassemblyWorkflow` Stage 3 → `RecipeCostSnapshotResolver`, **fails closed** with `recipe_cost_snapshot_missing` / `recipe_cost_snapshot_stale` |
| 7 | FIFO layer cost? | from the resolved snapshot's `unitCosts` |

**C-1's rule is already the implemented behaviour**: disassembly values components from the
approved recipe snapshot, never from the finished product's shipment/current cost. A recipe with
no approved snapshot is **refused**, not approximated — exactly what C-3 demands.

**Nothing was implemented, backfilled or redesigned** (the rule: if it exists and is correct,
do not rewrite it).

**Verification:** `RecipeCostSnapshotValuationTest` + `DisassemblyTest` — **49 tests / 145
assertions, OK**.

**Observation, not changed:** `DisassemblyWorkflow:132` falls back to `?? 0.0` for a component
absent from the snapshot map. Unreachable today because the workflow fails closed earlier on a
missing/stale snapshot. Recorded, not "fixed" — C-5 forbids redesign and no defect was observed.

---

## Workstream D — Customer / Product Read-Model & UI Contract

### **PARTIAL** — three items already canonical, one gap identified and deliberately not changed.

| Item | Finding | Action |
|---|---|---|
| **D-1** `preferredGovernorate` | **Canonical definition EXISTS**: `CustomerOrderMetricsService::preferredGovernorateForCustomers(array $customerIds, string $companyId)` — company-scoped, with its own test (`CustomerPreferredGovernorateTest`). Its docblock records that the metric was **moved out of React into the service** | **Reuse — no STOP, nothing invented** |
| **D-2** `lastOrderDate` | **Canonical definition EXISTS and is explicit**: `MAX(order_date)` — not `MAX(created_at)`. Documented in `CustomerOrderMetricsService` and mirrored in `OrderResource` | **Reuse — no STOP** |
| **D-3 / D-4** tenant scope | `preferredGovernorateForCustomers` takes `companyId` as a required argument; controller passes the resolved company. Existing scoping preserved, access not broadened | **Not regressed** |
| **D-6** no duplicate UI computation | Searched `features/customers` and `order-customer-badge` for residual client-side frequency/most-common logic: **zero hits**. The UI consumes `customer.preferred_governorate` from the API | **Already satisfied** |
| **D-5** every Product must have a Unit | **GAP CONFIRMED** — see below | **NOT CHANGED — reported** |

### D-5 — the identified gap, and why I did not close it

`StoreProductRequest:62` validates:

```php
'unit_id' => ['sometimes', 'nullable', 'uuid', 'exists:units,id'],
```

`nullable` contradicts the stated contract "Every Product must have a Unit".

**Blast radius, measured:** in `ecos_dev`, **6 of 18 products (33%) have `unit_id IS NULL`**.
Tightening the rule to `required` would immediately break editing a third of existing products,
and would affect the WooCommerce import path.

Closing it therefore needs a **backfill decision that no approved contract defines**: which unit
the 6 existing null-unit products receive, whether the rule applies to imported products, and
whether it is enforced on update as well as create. Per the batch's MOST IMPORTANT PROJECT RULE
("If a business rule is undefined: STOP. Do not invent the missing rule"), I stopped and reported
rather than inventing a backfill.

**Recommended follow-up:** `TASK-PRODUCT-UNIT-CONTRACT-ENFORCEMENT-001` — decide the backfill
value + scope, migrate, then tighten validation.

### D-1/D-2 verification — not run

The focused customer suites (`CustomerPreferredGovernorateTest`, `CustomerLastOrderContractTest`)
could **not be executed**: the shared test runner was held by **another session's ungated phpunit
process** (`[GATE] busy — an ungated phpunit process is running`). The D-1/D-2 findings above rest
on **code inspection**, which is sufficient to answer "does a canonical definition exist" but is
**not** a runtime proof. Recorded honestly rather than claimed.

---

## Contract gaps reported, not invented

| # | Gap | Workstream | Why not closed |
|---|---|---|---|
| 1 | **Recipe/BOM change has no established reservation-release contract** | A-3 | A-3 explicitly forbids inventing release semantics. Changing a BOM alters future material requirements; whether it must release an existing reservation is a business decision |
| 2 | **Product Unit contract vs `nullable` validation** | D-5 | Backfill rule for 6 existing null-unit products is undefined |
| 3 | `WarehouseAssignmentEngine::override()` permits reassignment of an already-reserved order with no guard | A-2 | The release side is now safe. Whether reassignment should *move* the reservation (rather than leave it in the origin) is an undefined business rule |

---

## Files changed by this batch

| File | Workstream | Type |
|---|---|---|
| `backend/Modules/Commerce/Orders/Application/Actions/ReleaseOrderInventoryAction.php` | A | **production fix** (1 file) |
| `backend/tests/Feature/Inventory/ReservationWarehouseAuthorityTest.php` | A | new test (6) |
| this report | — | documentation |

**No production file was changed for B, C or D.** No test was weakened or deleted; no assertion
was relaxed; no unrelated baseline failure was modified. Nothing was committed, deployed or
migrated.

## Notifications (A-9 / B-10 / C-7)

Three notifications were **requested** through the harness push mechanism, one per completed
workstream. The tool returned *"Mobile push requested"* for each — that confirms the request was
accepted, **not** that the device displayed it. Per A-9 ("do not claim notification success unless
actually confirmed"), delivery is reported as **requested, not confirmed**.

## Final status

> ### A: IMPLEMENTATION COMPLETE · B: IMPLEMENTATION COMPLETE (no gaps) · C: IMPLEMENTATION COMPLETE (already built) · D: PARTIAL
>
> **Final project certification deliberately NOT claimed** — deferred to the separate final
> verification phase, per project policy.
