# TASK-DRIVER-DELIVERY-ALLOCATION-BRIDGE-001 — Driver Delivery ↔ Canonical Allocation Bridge

**Date:** 2026-08-26
**Status:** ✅ IMPLEMENTED + VERIFIED (backend). The driver can now record full and partial delivery through the existing canonical delivery authority. No second delivery writer, no second allocation engine, no double warehouse deduction.
**Verification:** focused gate **27/27 green** (bridge + writer regression); §R/§S regression pending (see §15).

---

## 1. Root Cause

Diagnosis TASK-DRIVER-PARTIAL-DELIVERY-FLOW-DIAGNOSIS-001 proved the driver's Group→Trip→DeliveryStop world and the operator's allocation world were **disjoint**: the sole canonical delivered-quantity writer (`RecordProductDeliveryAction`) operates only on `allocation_records`, and driver-trip orders **never** get allocation_records (the only creator, `AutoAllocationService`, is wave-provenanced and skips the driver's NULL-wave loads). There was **no driver route** to the writer and **no bridge** between the two worlds. Result: a driver could reach a stop but could not record any delivered quantity canonically. Live `ecos_dev` confirmed it: 0 `allocation_records`, `order_lines.delivered_qty = 0` on the one driver stop, custody populated.

## 2. Architecture Before

```
Driver → Trip → DeliveryStop → stopAction (status ONLY, no quantity)         [driver world]
                                   �️  no link  ⇎
Operator → LoadingSession → AutoAllocationService → allocation_records
        → RecordProductDeliveryAction → quantity_delivered → order_lines projection   [allocation world]
```
Two worlds; the driver could not cross into the one that owns delivered quantities.

## 3. Architecture After

```
Driver → Trip → DeliveryStop
   POST /driver/stops/{id}/deliver
      → EnsureStopDeliveryAllocationsAction   (THE BRIDGE — creates allocation_records from custody+demand)
      → RecordProductDeliveryAction           (the SOLE canonical writer, reused unchanged)
      → allocation_records.quantity_delivered (canonical source of truth)
      → ProductDeliveryRecorded → order_lines.delivered_qty  (existing projection)
      → VehicleInventoryService::recordDelivery → vehicle custody ↓ + `delivered` movement
```
The driver now reaches the **same** canonical authority the operator uses. Nothing new was invented downstream of the bridge.

## 4. Canonical Source

`allocation_records.quantity_delivered` remains the single source of truth (ADR-015). `order_lines.delivered_qty` remains a projection of it (via the existing `ProjectDeliveredQuantityFromAllocation` listener). The bridge does **not** write `order_lines.delivered_qty` directly and adds no second writer.

## 5. Allocation Bridge — `EnsureStopDeliveryAllocationsAction`

`Modules/Logistics/Distribution/Application/Actions/EnsureStopDeliveryAllocationsAction.php` (NEW). For a DeliveryStop it resolves the canonical relationships — `vehicle_assignments.trip_id → this trip`, `stop.order_id → Order → OrderLine`, and the product's `vehicle_inventory_items` custody row — and, for each order line whose product is **actually in this vehicle's custody**, creates one `AllocationRecord` (idempotent on the `(vehicle_assignment_id, order_line_id)` unique key). It reuses the canonical `AllocationRecord` model and sets `quantity_allocated = order_line.quantity` — a **demand map identical to `AutoAllocationService`'s output in the no-shortage case**, not an invented split.

Explicit non-goals (why it is not a second allocation system):
- It creates an allocation **only** when there is a real basis: the product is in this vehicle's custody AND demanded by a line on this trip's order. A product not on the vehicle gets **no** allocation and cannot be delivered here.
- It does **not** reproduce `AutoAllocationService`'s priority split (the only place that engine is non-trivial is deciding who absorbs a shortage — which needs the wave provenance the driver flow lacks). Shortage of physical stock is handled at delivery time by the on-hand guard, not by guessing a partition.
- It does **not** earmark the loading-time pool (`quantity_unallocated`). Delivery is post-dispatch; the load-correction guard that reads the earmark can no longer run, and delivery guards on live `quantity_on_hand` instead. `quantity_loaded` on the allocation is left 0 — the delivery path never reads it.

## 6. Driver API — `POST /api/driver/stops/{stopId}/deliver`

`DriverRuntimeController::deliver` (NEW). Body: `{ "lines": [{ "order_line_id": "...", "delivered_qty": <cumulative total> }, …] }`.
- Fail-closed via `ownedStop()` (company + driver ownership re-asserted on the parent trip).
- Refuses unless `trip.status->acceptsDeliveryExecution()` (on the road: dispatched / out_for_delivery / in_progress).
- Ensures allocations (the bridge), then for each line calls the **canonical** `RecordProductDeliveryAction` (reused; not re-implemented), gated `loading.driver.operate` like the rest of `/driver/*`. No new permission added.
- Read model: `order_line_id` was added to the stop-detail line payload so the client can address lines unambiguously (the read model already carried ordered/loaded/delivered/returned/remaining).

## 7. Full Delivery

Ordered 10 → post `delivered_qty: 10`: `allocation_records.quantity_delivered = 10`, `order_lines.delivered_qty = 10`, remaining 0, vehicle custody −10, and the stop is settled **Delivered** (only because every line is fully delivered). Proven by `test_full_delivery_writes_canonical_delivered_and_projects_and_closes`.

## 8. Partial Delivery

Cumulative absolute semantics (matching `RecordProductDeliveryAction`): the client posts the **running total** delivered, never an increment.
- Post 4 → delivered 4, remaining 6, custody −4, stop **not** completed.
- Post 7 (cumulative) → delivered 7, remaining 3, custody at 3.
- Post 10 (cumulative) → delivered 10, remaining 0, custody 0, stop Delivered.
The endpoint refuses a cumulative **below** the recorded total (the §4 footgun — a 422, never a silent reduce). Proven by `test_partial_delivery_…`, `test_multiple_cumulative_partials_converge_and_finally_close`, `test_a_cumulative_total_below_the_recorded_delivered_is_refused`.

## 9. Vehicle Custody Reconciliation

Delivery lowers vehicle custody via the existing `VehicleInventoryService::recordDelivery` (delta-based): `quantity_delivered += delta`, `quantity_on_hand` recomputed, one `vehicle_inventory_movements` `delivered` row appended. The endpoint additionally guards `delta ≤ quantity_on_hand` **before** recording, so custody can never go negative (no silent absorption; §5 — negatives only if a policy explicitly allowed, which delivery does not). Proven by `test_delivery_lowers_vehicle_custody_…` and `test_delivery_exceeding_on_hand_custody_is_refused`.

## 10. Warehouse Stock Behavior

Customer delivery creates **no** warehouse stock-ledger movement and does not decrement warehouse `inventory_items`. Warehouse stock was already deducted once, at the Warehouse→Vehicle confirm-received transfer (`TransferLoadedStockToVehicleAction → ShipStockAction`, verified this task). `test_delivery_lowers_vehicle_custody_but_never_touches_warehouse_stock` asserts the `stock_ledger_entries` count for the product is unchanged across a delivery.

## 11. Idempotency

- **Same cumulative replay:** a no-op — the endpoint short-circuits when the posted total equals the recorded total; `RecordProductDeliveryAction`'s absolute set + delta-guard mean custody is not deducted twice and no second `delivered` movement is appended; the allocation stays a single row. Proven by `test_replaying_the_same_delivery_does_not_double_anything` (custody stays 6, one allocation, one movement).
- **Reduce refused:** a cumulative below the recorded total is a 422, not a reduction.
- **Projection** re-derives `order_lines.delivered_qty` from the canonical sum, so it is replay-immune.

## 12. Authorization

`loading.driver.operate` + `ownedStop()` fail-closed. Proven: correct driver allowed; another driver → 404 (`test_a_driver_cannot_deliver_another_drivers_stop`); non-driver → 403; unauthenticated → 401. No existing permission changed.

## 13. Atomicity

The whole stop's lines are recorded inside one `DB::transaction`. A refusal on any line (over-delivery, insufficient custody, reduce) throws and rolls the entire delivery back — there is no "delivered updated but custody not lowered" or vice-versa. `RecordProductDeliveryAction` is itself atomic (allocation write + custody move in one transaction); the projection write runs in the same outer transaction and rolls back with it. The stop is settled Delivered only if every line is fully delivered, and never if already settled (keeps replay clean).

## 14. Tests

`tests/Feature/Operations/DriverStopDeliveryTest.php` (NEW, 12 tests) covering §14 A–Q over the real HTTP stack: full (A/K/L/M/N), single partial (B/M/O), multiple cumulative partials (C), over-delivery rejected (D), custody down + warehouse untouched (I/J), idempotent replay (H/P/Q), cumulative-below refused, on-the-road guard, wrong driver 404 (F), non-driver 403 (E-neg), unauthenticated 401 (G), insufficient-custody refused. Plus the writer regression `RecordProductDeliveryOrderLineProjectionTest` + `RecordProductDeliveryHttpTest`. **27/27 green** via the pinned gate. Nothing writes `delivered_qty` by hand; events are not faked (the real projection listener runs).

## 15. Regression Results

Regression suite (§R custody + §S trip-lifecycle): **69 / 70 pass.** All of `DriverLoadingCustodyHandoffTest`, `LoadingCustodyWorkflowTest`, `DriverVehicleInventoryAndIdentityTest` pass. One failure in `TripLifecycleCertificationTest::test_02_driver_confirmations_are_resolved…` (`driverConfirms(...)` expected 200, got 422).

**Diagnosed — PRE-EXISTING, not caused by this task (not hidden, per §15):**
- The failing assertion is on the driver **confirm-received** path: `driverConfirms` → `DriverLoadingController::confirmReceived` → `TransferLoadedStockToVehicleAction` → `ShipStockAction`. That path contains **none** of this task's files.
- `git status` shows every file on that path is the **custody-transfer task's** uncommitted work — `DriverLoadingController.php` (`??`), `TransferLoadedStockToVehicleAction.php` (`??`), `ShipStockAction.php` (`M`), and `TripLifecycleCertificationTest.php` itself (`??`, an untracked test added by that task). This task's files (`EnsureStopDeliveryAllocationsAction`, the `deliver` method/route, `DriverStopDeliveryTest`) are disjoint and additive.
- Root cause: since the custody-transfer wiring made confirm-received deduct warehouse stock, a confirm now requires seeded warehouse `inventory_items`. `test_02` seeds **none** (grep: only `warehouseLoads`/`driverConfirms`, no stock seeding), so `ShipStockAction` refuses (`allow_negative_stock=false`) and `confirmReceived` returns 422. The sibling confirm-received suites that the custody-transfer task **did** update to seed warehouse stock (`DriverLoadingCustodyHandoffTest`, `LoadingCustodyWorkflowTest`) pass in this very run — the only differentiator is the missing warehouse-stock fixture, unrelated to delivery.
- **Not modified.** The fix belongs to the custody-transfer task (seed warehouse `inventory_items` in `TripLifecycleCertificationTest::test_02`'s fixture, exactly as it did for the other two suites). Flagged for the owner; left untouched here.

This task's own focused gate is **27/27 green** (§14), and the delivery bridge introduces no regression on any of the custody/lifecycle suites.

## 16. Browser Verification

Not performed. Per the task's live-data rule, no real business data was mutated and no browser E2E was run against a real shipment (TRP-003/ORD-00014 untouched). The backend is verified by the focused test suite above. Browser verification of the driver UI is deferred to the frontend-wiring step (§18); it will require a safe fixture shipment or explicit owner approval to use a real one.

## 17. Files Changed

- **NEW** `backend/Modules/Logistics/Distribution/Application/Actions/EnsureStopDeliveryAllocationsAction.php` — the allocation bridge.
- **EDIT** `backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverRuntimeController.php` — `deliver()` + `settleStopIfFullyDelivered()` + 2 imports + `order_line_id` on the stop-detail line payload.
- **EDIT** `backend/routes/api.php` — `POST /driver/stops/{stopId}/deliver`.
- **NEW** `backend/tests/Feature/Operations/DriverStopDeliveryTest.php` — 12 tests.

No migration, no schema change, no permission change, no frontend change. Loading-Complete gate, custody semantics, `GroupFinalizationService`, trip lifecycle, Preparation waves, Day Settlement, and the Warehouse→Vehicle transfer are all untouched. All changes uncommitted.

## 18. Deferred Issues

1. **Frontend UI wiring** — render Required/Delivered/Remaining on the driver stop detail and call `POST …/deliver`. The read model is ready (`order_line_id` + ordered/delivered/remaining exposed). This is the remaining presentation step; no backend work left for the happy path.
2. **Shortage split across lines sharing one product** — when one product's custody is short of the summed demand of multiple lines, deciding which line absorbs the shortfall is `AutoAllocationService`'s priority job (needs wave provenance). The bridge deliberately does not reproduce it; each line delivers against live on-hand and refuses beyond it. If this scenario becomes real for driver trips, it is an owner decision (route those trips through the operator allocation flow, or define a driver-grain split rule).
3. **POD duplicate-row idempotency** (from the prior diagnosis) — `$stop->proof()->create()` has no unique constraint; retried uploads append rows. Flagged; not in scope here.
4. **Warehouse quantity revised after the custody transfer** is not re-bridged (inherited limitation of TASK-DRIVER-CUSTODY-INVENTORY-TRANSFER-001).
5. **Stop status for partial/failed** — the deliver endpoint auto-settles only the fully-delivered case (Delivered). Partial-stop or failed-stop status remains the driver's explicit act via the existing `stopAction` (§10 — no auto-complete while remaining > 0).

## 19. BEFORE / AFTER Evidence

- **BEFORE (diagnosis + live `ecos_dev`):** 0 `allocation_records`; the driver stop's order lines at `delivered_qty = 0`; no driver route to the canonical writer; delivery impossible canonically.
- **AFTER (this task, verified by tests):** a driver delivery request creates the canonical `allocation_records`, writes `quantity_delivered` through `RecordProductDeliveryAction`, projects `order_lines.delivered_qty`, lowers vehicle custody, and leaves warehouse stock untouched — for full (10/10) and partial (4→7→10) alike, idempotently and fail-closed. 27/27 green.

## 20. Is the Driver Delivery lifecycle now COMPLETE?

**Backend: YES.** The driver can record full and partial customer delivery through the canonical authority — allocation_records → RecordProductDeliveryAction → order_lines.delivered_qty projection → vehicle-custody decrement — with no second writer, no second allocation engine, and no double warehouse deduction. **End-to-end (with UI): NOT YET** — the driver-facing screen still needs to render Required/Delivered/Remaining and call the new endpoint (§18.1). The read model already carries every field that screen needs; the remaining work is pure presentation wiring.
