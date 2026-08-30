# TASK — Canonical `order_lines.delivered_qty` Writer — AUDIT & DECISION REPORT

**Parent:** TASK-DRIVER-04 decision **A** (owner-authorized separate backend task).
**Mode:** audit-first (owner: "audit the existing delivery/fulfillment engine and identify the canonical place where `delivered_qty` belongs … If implementing the writer requires a new status, new inventory movement, new proof system, or redesign of the existing delivery engine: STOP and report the exact blocker").
**Date:** 2026-08-26
**Status:** ✅ **IMPLEMENTED + VERIFIED.** The audit overturned the premise (a canonical writer already exists); the owner then confirmed the projection approach with "inventory-neutral = warehouse-stock-neutral" (vehicle custody moves per ADR-015). The projection is implemented and tested. The driver-UI wiring (A-β) is the next, separate step — see §5.

> **Audit-first note (retained):** §1–§4 below are the discovery that reframed the task. §5 records what was implemented after the owner's decision.

---

## 1. The premise correction (this is the headline)

The task assumed `delivered_qty` "has no canonical writer — add one." The audit shows a **canonical delivered-quantity authority already exists**, and `order_lines.delivered_qty` is not the authority — it is an **unpopulated projection column**.

- **The authority:** `allocation_records.quantity_delivered`. Per **ADR-015 / PRODUCT-ALLOCATION-ENGINE.md §6 (APPROVED)**, `OrderAllocation` is *"the definitive record of what a driver should deliver for each order,"* carrying `quantity_delivered` ("confirmed delivered, updated by Logistics OS") and `quantity_remaining` ("computed: allocated − delivered") — quoted from `RecordProductDeliveryAction.php:26-33`.
- **The writer:** `Modules\Operations\Loading\Application\Actions\RecordProductDeliveryAction::execute()` — documented as the sole delivered-quantity writer (`routes/api.php:1043-1045`). It is **absolute-set** (idempotent replay), `lockForUpdate` on the row, fail-closed over-delivery ceiling, one `DB::transaction`, and is **designed for the driver** (`$actorType = 'driver'` default, `RecordProductDeliveryAction.php:57`). `AllocationRecord` carries `order_id`, `order_line_id`, `product_id`, `quantity_allocated`, `quantity_delivered` (`AllocationRecord.php:20-33`).
- **`order_lines.delivered_qty` is a projection, never written.** Confirmed by four independent sweeps: the whole `order_lines` fulfillment block (`loaded_qty`, `delivered_qty`, `returned_qty`, `cancelled_qty`, `prepared_qty`, `packed_qty`, `available_qty`) has **zero writers**; only `reserved_qty` is written (by `ReserveOrderInventoryAction`). `delivered_qty` is read only for display (`OrderResource.php:204`; `DriverRuntimeController.php:709,713` for a derived `remaining_qty`). It is always its migration default `0`.
- **Stack A (Logistics/Delivery)** never persists a per-line delivered quantity (its success path is status-granularity + a boolean `partial`) and is disjoint from the driver runtime. Its only per-line `delivered_qty` is on `delivery_return_lines` — an **operator-declared return input**, not a measured delivery.

So the correct shape of the work is **not "add a writer"** — it is **"project the existing canonical `allocation_records.quantity_delivered` onto `order_lines.delivered_qty`"** (a deterministic, idempotent reconciliation). That projection, in isolation, trips **none** of the STOP conditions (no new status / movement / proof / engine redesign): it would be a Commerce\Orders action computing `order_lines.delivered_qty := Σ allocation_records.quantity_delivered` per `order_line_id`, triggered by the delivery write. `Order.status` is untouched (it is ORM-guarded to `FulfillmentEngine` only), no stock-ledger entry, no remainder→waste conversion.

## 2. Why this still STOPs — two owner decisions

### Decision A-α — the §14 "inventory-neutral" tension (the real blocker)

The owner's A constraints contain a tension that the actual architecture makes unavoidable:

- *"Reuse the existing delivery/fulfillment architecture"* + *"do NOT create a second inventory movement"* ⇒ use `RecordProductDeliveryAction`.
- **But `RecordProductDeliveryAction` moves vehicle custody by design.** On a non-zero delivery delta it calls `VehicleInventoryService::recordDelivery()`, appending a `MovementType::Delivered` row to `vehicle_inventory_movements` and decrementing the vehicle's `on_hand` (`RecordProductDeliveryAction.php:122-138`). That is correct real-world semantics (goods leave the van when delivered), and it is the **existing** movement (reusing it is *not* "a second movement").
- TASK-DRIVER-04 **§14** also said *"delivery must remain inventory-neutral"* and *"do not change Vehicle inventory."*

These cannot both hold literally: **you cannot record the driver's actual delivered quantity through the canonical ADR-015 mechanism and simultaneously leave vehicle inventory unmoved.** The resolution hinges on what "inventory-neutral" meant:

- **If it meant "do not touch WAREHOUSE stock / the Stock Ledger / create waste"** — then `RecordProductDeliveryAction` is fully compliant: it moves only **vehicle custody** (a separate ledger; warehouse stock is deducted at dispatch, not delivery — `vehicle_custody_architecture`), creates no waste, and reuses the existing movement. ✅ The projection approach works cleanly.
- **If it meant "move NO inventory at all, including vehicle custody"** — then there is **no** inventory-neutral canonical delivered-quantity writer, and recording real delivered quantities is impossible without either a new writer (a *second* mechanism — forbidden) or abandoning quantity capture. ❌

**Owner must state which reading applies.** (Recommendation: the first — vehicle custody *should* move on delivery; that is the point of the vehicle-custody engine, and it is warehouse-stock-neutral.)

### Decision A-β — the two-world gap (driver reachability)

The canonical writer lives in the **operator / loading-session** world (`POST /sessions/{id}/assignments/{id}/allocation/deliver`, `permission:loading.session.operate`, `AllocationController::recordDelivery`). The **driver** operates in the **Distribution Group→Trip→DeliveryStop** world; `stopAction` records a stop *status* only and never touches allocations. They share only the `loading_session`/`vehicle_assignment` (allocations are created by the operator's `AllocatePoolToSessionAction → AutoAllocationService`, keyed `vehicle_assignment_id + order_line_id`).

Consequence: the owner's end goal — *"wire the Driver Partial Delivery UX to that canonical writer"* — requires giving the driver a fail-closed route to `RecordProductDeliveryAction` that resolves the driver's own `allocation_records` for the stop's order+product. That is feasible (the action is already `actorType='driver'`), **but it depends on `allocation_records` existing for the driver's trip orders** — which is produced by the operator pool-allocation step, not by the driver's own loading/custody path. Whether that step always runs for driver trips must be confirmed before the driver wiring can be relied upon; if it does not, bridging the two worlds is a larger integration (a redesign — the STOP trigger).

## 3. Recommended path (pending the two decisions)

If the owner confirms A-α = "warehouse-stock-neutral" (recommended):

1. **Backend (this task):** add a Commerce\Orders action `ProjectOrderLineDeliveredQuantities` that sets `order_lines.delivered_qty := Σ allocation_records.quantity_delivered` per `order_line_id` (absolute-set, idempotent, tenant-scoped via the company-scoped `Order`, no `Order.status` write, no ledger write). Trigger it from the delivery write (an event dispatched by `RecordProductDeliveryAction`, consumed by a Commerce listener — keeps the cross-aggregate write inside Commerce, mirroring how `ReserveOrderInventoryAction` owns `reserved_qty`). Focused tests via the pinned gate. **No migration** (column exists).
2. **Then TASK-DRIVER-04 (separate, later):** add a driver-scoped route to `RecordProductDeliveryAction` (resolving the driver's own allocations for the stop), and the partial-delivery UI. Confirm allocations exist for driver trips first (A-β).

## 4. STOP-condition assessment (honest)

- The **projection** alone trips no STOP condition (no new status/movement/proof; not a redesign).
- The **overall task as the owner framed it** (driver records real partial delivered quantities) forces the A-α tension and the A-β cross-world bridge, both of which are architectural decisions on **certified** areas (ADR-015 allocation engine, vehicle custody, the guarded Commerce\Orders aggregate). Per the owner's own instruction — audit, identify the canonical place, and STOP if implementation touches those — I am stopping to confirm the model before modifying certified code.

**(Superseded by §5 — the owner decided "proceed, warehouse-stock-neutral".)**

## 5. Implemented + verified (owner decision: proceed)

**What was built — a projection, not a second writer:**
- `Modules\Operations\Loading\Domain\Events\ProductDeliveryRecorded` — a new event carrying **ids only** (`orderId`, `orderLineId`, `companyId`); never the quantity, so the listener re-derives from the canonical source.
- `RecordProductDeliveryAction` (the certified sole writer) now **dispatches** that event after its commit — one additive line, mirroring how `DeliveryService::completeStop` dispatches `DeliveryStopCompleted`. Nothing else in the certified action changed.
- `Modules\Commerce\Orders\Application\Listeners\ProjectDeliveredQuantityFromAllocation` — on the event, sets `order_lines.delivered_qty := Σ allocation_records.quantity_delivered` for the affected line (sums across allocations → split shipments correct; recompute-from-source → idempotent). Writes **only** `delivered_qty`.
- Registered in `OrderServiceProvider` (same place the wave/stock listeners live).

**Boundaries honoured:** no migration (column existed); no second delivered-quantity source (reads back the canonical one); no new inventory movement (the existing vehicle-custody `Delivered` movement is untouched and still fires); `Order.status` never written (ORM-guarded to FulfillmentEngine — verified unchanged in the test); warehouse stock ledger untouched by delivery; no reservation/COGS change.

**Tests (all green, via the pinned gate):** `RecordProductDeliveryOrderLineProjectionTest` — projection, partial (7 of 10), full (10), idempotent replay, sum-across-allocations (split shipment), and custody-moves-yet-only-the-order-line-is-touched (asserts `order_lines.delivered_qty` set, `VehicleInventoryItem.quantity_delivered` moved + a `delivered` vehicle-movement row, **and** `Order.status` unchanged + zero `stock_ledger_entries` for the product). Regression: `RecordProductDeliveryHttpTest` + `VehicleShiftReconciliationTest` + `VehicleShiftReconciliationHttpTest` all pass — the certified writer + shift reconciliation are unaffected by the added event.

**Next (A-β — separate, NOT done here):** wiring the *driver's* partial-delivery UI to the canonical writer needs (1) a driver-scoped route to `RecordProductDeliveryAction` (already `actorType='driver'`), and (2) confirmation that `allocation_records` exist for driver-trip orders (they are created by the operator pool-allocation step; the driver's Distribution flow does not create them). Until (2) is confirmed, the driver path cannot rely on this projection. Surfaced for direction rather than auto-started.
