# TASK-OPERATIONAL-FULFILLMENT-RETURNS-RECONCILIATION-001 — Implementation Report

**Date:** 2026-08-28
**Status:** CORE IMPLEMENTED & VERIFIED (isolated test DB) — two extensions DEFERRED as documented business decisions. Not committed, not deployed, no live data mutated.
**Scope:** the missing canonical Returns + Vehicle Reconciliation leg (Gate 4), per the approved contract. No competing return authority introduced; Finance untouched; Driver App UX untouched.

---

## 1. Executive Summary

The vehicle-return leg is now closed with canonical services. Two pieces were built:

- **Warehouse Return Receipt (World B — the approved §1 contract):** a new `ReceiveVehicleReturnAction` turns the existing shift reconciliation into a real receipt. The warehouse operator counts what physically came back and splits it into **accepted** (good) vs **damaged**; **shortage** is derived (`expected − actual`). Accepted good stock is restocked to the warehouse via the **canonical `AdjustmentIn`** (+ a FIFO receipt layer, exactly like `ReceiveReturnWorkflow`); **damaged never enters good stock** (condition-gate); **shortage stays visible** as the reconciliation variance and holds the shift **Disputed**; **vehicle custody reconciles** to the received total. The whole receipt is **idempotent** and **atomic**.
- **`returned_qty` single authority (§16):** a new projection listener `ProjectReturnedQuantityFromCustomerReturn` re-derives `order_lines.returned_qty := Σ customer_return_lines.quantity_returned` on `OrderReturnedEvent`, mirroring the certified `delivered_qty` projection — one writer, idempotent, writes only `returned_qty`.

**Verification (isolated `ecos_dev_test`):** the 14 new tests pass — `OK (14 tests, 52 assertions)`. Regression across the affected certified suites + the new ones: **`OK (80 tests, 561 assertions)` — no regression.**

**Two documented deferrals (business decisions — not guessed):** (a) a formal `WarehouseLiability` record for a driver-attributed shortage (the liability table has no driver/vehicle/trip column and no create action); (b) the order-lifecycle transition for *undelivered* stock (no `PartiallyReturned` state exists). Both are surfaced, neither is faked. Per §28 this is **PARTIALLY IMPLEMENTED** — the reconciliation loop itself is complete and proven; the two extensions await an owner decision.

---

## 2. Approved Business Contract (as implemented)

```
Vehicle custody (undelivered = loaded − delivered)
        → Warehouse physical count (operator)
        → actual_received = accepted + damaged
        ├─ accepted  → warehouse good stock (canonical AdjustmentIn + FIFO layer)
        ├─ damaged   → NOT admitted to good stock (condition-gate)
        └─ shortage  = expected − actual → visible variance, shift Disputed
        → vehicle custody reconciled (loaded − delivered − received)
```
The Driver **declares**; the Warehouse is the **actual-receipt authority**. The driver never writes warehouse stock.

---

## 3. Before-State (audit, code-verified)

| Authority | Current source | Writer (before) | Reader | Missing bridge (now built / deferred) |
|---|---|---|---|---|
| Expected return | `vehicle_shift_reconciliation_lines.quantity_returned_expected` = loaded − delivered | `VehicleShiftReconciliationService::open()` | reconciliation resource | — (already correct) |
| Actual receipt | `…lines.quantity_returned_actual` (absolute) | `recordReturnedActual()` | resource | count-only; **no restock / custody / classification** → BUILT |
| Warehouse restock | `stock_ledger_entries` + on_hand | `AdjustmentInAction` (used only by `ReceiveReturnWorkflow`) | inventory | not wired to vehicle returns → BUILT (`vehicle_return` ref) |
| Vehicle custody | `vehicle_inventory_items.quantity_returned` | `recordReturn()` **dead (`+=`, 0 callers)** | custody | absolute reconcile → BUILT (`reconcileReturn()`) |
| Damaged | — | — | — | condition-gate (not restocked) → BUILT; WasteInvestigation record → **DEFERRED** (count-session FK coupling) |
| Shortage | reconciliation `variance` / `has_variance` | reconciliation service | resource | visible → already; formal `WarehouseLiability` → **DEFERRED** (no driver attribution column) |
| `order_lines.returned_qty` | column exists | **none** | `OrderResource`, driver runtime | single projection → BUILT (`ProjectReturnedQuantityFromCustomerReturn`) |
| Order return transition | `OrderStatus::Returned` (terminal) | `ReturnOrderWorkflow` (customer RMA only) | — | undelivered-stock transition → **DEFERRED** (no `PartiallyReturned`) |

---

## 4. Canonical Authorities (reused — no competitors)

- **Reconciliation & actual receipt:** `VehicleShiftReconciliationService` (`open`, `recordReturnedActual` — the absolute count authority; ADR-015 §6.4 variance = loaded − delivered − returned).
- **Warehouse-in movement:** `AdjustmentInAction` (`adjustment_in` ledger) + `InventoryReceiptLayer` (FIFO), with cost from `EnterpriseCostEngine::resolveUnitCost` — identical to `ReceiveReturnWorkflow`.
- **Vehicle custody:** `VehicleInventoryService` (new `reconcileReturn`, absolute — replaces the dead `recordReturn`).
- **returned_qty projection:** new `ProjectReturnedQuantityFromCustomerReturn`, mirroring `ProjectDeliveredQuantityFromAllocation`.
- **Order status:** never written directly (FulfillmentEngine-guarded, `Order.php:146`).

---

## 5. Return Lifecycle

Reuses the existing `ReconciliationStatus` (no new states, §9): `Open` → `Completed` (fully balanced) or `Disputed` (visible variance remains). `Approved` stays terminal. `ReceiveVehicleReturnAction` transitions the shift only once **every** line has a warehouse receipt; a balanced shift → Completed, any remaining variance → Disputed (never forced to zero, §5/§15).

## 6. Vehicle Warehouse Model

The Vehicle Warehouse remains the quantity-only custody engine (not a ledger location). `reconcileReturn` sets `quantity_returned` to the **absolute** received total and recomputes `on_hand = loaded − delivered − returned`; a positive `on_hand` is the visible shortage (kept, not zeroed). Warehouse inventory is posted separately by `AdjustmentIn`. Custody + warehouse never diverge because both are inside one transaction (§17).

## 7. Expected Return Calculation

`expected_return = loaded − delivered`, computed canonically by `VehicleShiftReconciliationService::open()` from the real loaded/delivered quantities (tests: full → 0, partial 10/6 → 4, failed 10/0 → 10). The driver never calculates it (§6).

## 8. Warehouse Actual Receipt

`ReceiveVehicleReturnAction::execute(line, quantityAccepted, quantityDamaged, damageReason, actorId)` (HTTP: `POST …/reconciliation/lines/{lineId}/receive`). `actual_received = accepted + damaged`, validated `≤ expected_return` (§11); negatives refused (§6). The absolute count flows through the existing `recordReturnedActual` (no second authority).

## 9. Accepted Return

Accepted good qty → warehouse via `AdjustmentInAction` (`reference_type='vehicle_return'`, `reference_id=<line id>`, cost resolved canonically) + a FIFO `InventoryReceiptLayer` so the units are consumable (CERT-GAP-002 parity). Test: accept 4 → warehouse on_hand +4, one `adjustment_in` ledger row, one FIFO layer.

## 10. Damage

Damaged qty is captured on the line (`quantity_damaged`, `damage_reason`) and **never** `AdjustmentIn`'d — identical to `ReceiveReturnWorkflow`'s condition-gate. Test: accept 3 + damaged 1 → warehouse on_hand +3 (not +4). The formal **WasteInvestigation record is DEFERRED** (§23 below): that model is NOT-NULL FK-coupled to inventory count sessions and deducts (`AdjustmentOut`) — it cannot represent a return without schema relaxation. "Damaged does not enter good stock" is nonetheless fully satisfied.

## 11. Shortage / Liability

`shortage = expected − actual = variance`. It is kept **visible** (`variance`, `has_variance`) and holds the shift **Disputed**; nothing is auto-written-off or auto-charged (§13). Test: accept 3 of expected 4 → variance 1, custody on_hand 1, status Disputed. A formal **driver-attributed `WarehouseLiability` is DEFERRED** — the table has only a `warehouse_manager` string (no driver/vehicle/trip column) and no create action; attributing/charging a driver is an owner decision (do not invent).

## 12. Inventory Movement

Uses the existing approved vocabulary: `adjustment_in` (no new ledger type, §14). Reference links the movement to the reconciliation line (`vehicle_return` / line id) and thus to the trip/vehicle/product/shift context.

## 13. Vehicle Custody Reconciliation

`reconcileReturn` (absolute) sets `quantity_returned` to `accepted + damaged` and recomputes `on_hand`. Balanced (`shortage 0`) → on_hand 0, custody Depleted; shortage > 0 → on_hand = shortage (visible). Tests assert `loaded − delivered − returned` for full-accept, partial, and damage cases.

## 14. returned_qty Authority

**One** writer: `ProjectReturnedQuantityFromCustomerReturn` on `OrderReturnedEvent`, absolute-set `returned_qty := Σ customer_return_lines.quantity_returned` per order line (condition-agnostic — a damaged unit was still returned). No Driver/Warehouse/Reconciliation/Delivery path writes it (§16/§18). Note the deliberate separation: `order_lines.returned_qty` is the **customer-RMA** fact; an **undelivered vehicle return** is a distinct fact tracked on vehicle custody — the receipt action does not write `returned_qty`, avoiding a competing authority.

## 15. Order Lifecycle Interaction

`Order.status` is never written by this task (FulfillmentEngine-guarded). The customer-RMA transition (`ReturnOrderWorkflow` → `Returned`) is unchanged. The **undelivered-stock order transition is undefined** (no `PartiallyReturned` state; `Returned` is a customer-RMA terminal) → **STOP + documented (§17)**; the vehicle receipt deliberately does not force an order transition.

## 16. Idempotency

`warehouse_receipt_at` on the line is the stable idempotency marker (§7): a repeated receipt with the same split is a no-op; a conflicting re-receipt is refused. `reconcileReturn` is absolute (no `+=`), and it appends a custody movement only on a real positive delta. `AdjustmentIn`/FIFO run only on the first receipt. Test: two identical receipts → warehouse credited once, one `adjustment_in` row, one FIFO layer, one custody `returned` movement.

## 17. Atomicity

The entire receipt runs in one `DB::transaction` (§8): count + classification + custody reconcile + warehouse `AdjustmentIn` + FIFO layer + status. A failure in any canonical movement rolls everything back. Test (`test_transaction_rolls_back_when_the_warehouse_movement_fails`): a shift pointed at a non-existent warehouse makes the `AdjustmentIn` `inventory_items` insert fail the warehouse FK → the whole receipt rolls back (no receipt marker, actual reverted to 0, custody unchanged, no FIFO layer).

## 18. Tests

New — `tests/Feature/Operations/VehicleReturnReceiptTest.php` (11) + `tests/Feature/Commerce/ReturnedQuantityProjectionTest.php` (3). Every quantity is produced by its real writer (load → deliver → open → receive). Mapped to §23:

| # (§23) | Scenario | Test |
|---|---|---|
| 1 | Full delivery → 0 expected | `test_full_delivery_yields_zero_expected_return` |
| 2 | Partial → correct expected | `test_partial_delivery_yields_correct_expected_return` |
| 3 | Failed → full expected | `test_failed_delivery_yields_full_expected_return` |
| 4 | Warehouse accepts full return | `test_warehouse_accepts_full_return_restocks_and_reconciles` |
| 5 | Partial receipt | `test_partial_receipt_leaves_visible_shortage_and_disputes_the_shift` |
| 6 | Shortage visible | (same) — variance 1, Disputed |
| 7 | Damage captured | `test_damage_is_captured_and_kept_out_of_good_stock` |
| 8 | Damaged not in good stock | (same) — on_hand +3 not +4 |
| 9 | Accepted enters warehouse | `test_warehouse_accepts_full_return…` — on_hand +4, ledger, FIFO layer |
| 10 | Vehicle custody reconciles | asserted in 4/5/7 (loaded − delivered − returned) |
| 11 | Duplicate receipt idempotent | `test_duplicate_receipt_is_idempotent` |
| 12 | Inventory movement not duplicated | (same) — one `adjustment_in` row |
| 13 | Liability/waste not duplicated | (same) — one custody movement; no waste/liability spawned (deferred by design) |
| 14 | Rollback on movement failure | `test_transaction_rolls_back_when_the_warehouse_movement_fails` |
| 15 | returned_qty one authority | `test_projects_…`, `test_sums_across_multiple_returns_and_is_idempotent` |
| 16 | Order.status protected | `test_writes_only_returned_qty_never_status_or_delivered` |
| — | Over-receipt / negative refused | `test_over_receipt_beyond_expected_is_refused`, `test_negative_quantity_is_refused` |
| — | Conflicting re-receipt refused | `test_conflicting_re_receipt_is_refused` |

**Result:** `OK (14 tests, 52 assertions)` (isolated `ecos_dev_test`).

## 19. Regression

Run (isolated `ecos_dev_test`), affected certified suites + the new ones, in one invocation:

```
OK (80 tests, 561 assertions)
```

Suites: `VehicleReturnReceiptTest` (11, new) · `ReturnedQuantityProjectionTest` (3, new) · `VehicleShiftReconciliationTest` · `DriverLoadingCustodyHandoffTest` · `DriverStopDeliveryTest` · `RecordProductDeliveryOrderLineProjectionTest` · `RecordProductDeliveryHttpTest`. **No regression** — every existing certified suite stayed green alongside the additive changes (new `reconcileReturn` method, the 4 additive line columns, the returned_qty listener registration, the new controller endpoint/route). No pre-existing failures surfaced; nothing discarded.

## 20. Demo Data Protection

No demo data touched. ORD-00014 and all live trips/groups/vehicles/drivers/inventory/returns untouched. All tests build isolated fixtures via real writers.

## 21. Live Data Protection

No live business data mutated. Verification is isolated test DB only; nothing was loaded, delivered, returned, reconciled, or restocked on `ecos_dev`. No canonical mutating service run against live data.

## 22. Files Changed

**New (source):**
- `backend/Modules/Operations/Loading/Application/Actions/ReceiveVehicleReturnAction.php` — the warehouse return receipt orchestrator.
- `backend/Modules/Operations/Loading/Presentation/Http/Requests/ReceiveVehicleReturnRequest.php`
- `backend/Modules/Operations/Loading/Infrastructure/Database/Migrations/2026_08_28_120000_add_return_receipt_classification_to_reconciliation_lines.php` — adds `quantity_accepted`, `quantity_damaged`, `damage_reason`, `warehouse_receipt_at` (additive only).
- `backend/Modules/Commerce/Orders/Application/Listeners/ProjectReturnedQuantityFromCustomerReturn.php` — the sole `returned_qty` writer.

**Edited (additive):**
- `…/Loading/Domain/Services/VehicleInventoryService.php` — new `reconcileReturn()` (absolute custody return).
- `…/Loading/Domain/Models/VehicleShiftReconciliationLine.php` — fillable/casts/doc for the 4 new columns.
- `…/Loading/Presentation/Http/Controllers/VehicleShiftReconciliationController.php` — new `receiveReturn` endpoint.
- `backend/routes/api.php` — the `…/lines/{lineId}/receive` route.
- `…/Commerce/Orders/Infrastructure/Providers/OrderServiceProvider.php` — register the returned_qty projection on `OrderReturnedEvent`.

**New (tests):** `VehicleReturnReceiptTest.php`, `ReturnedQuantityProjectionTest.php`.

No existing test weakened. The dead `recordReturn()` was left in place (not wired), as instructed (§3).

## 23. Remaining Decisions (owner)

1. **Shortage → formal liability:** should a vehicle shortage raise a driver/vehicle-attributed `WarehouseLiability`? Requires schema (driver/vehicle/trip columns + a `driver_shortage` type) and a create action. Shortage is already visible + accountable via the Disputed reconciliation.
2. **Damaged → waste disposition record:** should a vehicle-return damage create a `WasteInvestigation`? Requires relaxing its NOT-NULL count-session coupling (or a return-specific waste model). Damage is already kept out of good stock.
3. **Undelivered-stock order lifecycle:** should an order with undelivered stock returning transition (e.g. reschedule / `PartiallyReturned`)? No such state exists (§17). Decide the FSM contract before wiring.
4. **Reconciliation approval:** who moves `Completed → Approved`, and does a non-zero variance block driver settlement? (No approve action exists yet.)

## 24. Remaining Gaps

- Formal shortage-liability and damage-waste **records** (dispositions) are deferred (above) — the operational inventory outcome (good restocked, damaged excluded, shortage visible) is complete without them.
- Operator delivery path guard vs custody `on_hand` (scenario-8 asymmetry from the validation) is unrelated to returns and untouched here.

## 25. Certification Status

Against the §28 standard:

| §28 requirement | Status |
|---|---|
| Driver declaration separated from warehouse acceptance | ✅ (declaration `DeliveryService::recordReturn`; acceptance `ReceiveVehicleReturnAction`) |
| Expected return from canonical loaded/delivered | ✅ |
| Warehouse actual receipt is canonical | ✅ (`recordReturnedActual` + receipt action) |
| Good returned stock reaches warehouse | ✅ (AdjustmentIn + FIFO; tested) |
| Damage does not enter good stock | ✅ (condition-gate; tested) |
| Shortage visible and accountable | ✅ visible + Disputed; formal liability record DEFERRED |
| Vehicle custody reconciles | ✅ (tested) |
| returned_qty has one authority | ✅ (projection; tested) |
| Duplicate receipt idempotent | ✅ (tested) |
| Transaction rollback proven | ✅ (tested) |
| Existing delivery/custody behavior green | ✅ (`OK (80 tests, 561 assertions)`, no regression) |
| Order.status protected | ✅ (never written) |
| Finance untouched | ✅ |

**Overall: PARTIALLY IMPLEMENTED — the core reconciliation loop is CERTIFIED (all functional §28 items proven; 14 new tests + 80-test regression green, no regression); two extensions (a formal driver-attributed shortage-liability record, and the undelivered-stock order transition) are explicitly DEFERRED as owner decisions with documented blockers.** Not committed, not deployed, no live data mutated. STOP per the final condition.
