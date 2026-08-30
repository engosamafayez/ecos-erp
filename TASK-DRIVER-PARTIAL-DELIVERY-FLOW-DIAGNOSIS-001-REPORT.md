# TASK-DRIVER-PARTIAL-DELIVERY-FLOW-DIAGNOSIS-001 — Driver Partial Delivery Flow Diagnosis

**Mode:** DIAGNOSIS ONLY — no code, schema, route, test, or frontend changed.
**Date:** 2026-08-26
**Method:** static code trace (4 parallel read-only audits) + read-only inspection of live `ecos_dev`.
**Classification:** **C — BUSINESS RULE DECISION REQUIRED** (effectively blocked until decided). See §17.

---

## 1. Executive Summary

**The driver cannot currently perform full or partial delivery through the canonical delivery lifecycle.**

The canonical delivered-quantity writer — `RecordProductDeliveryAction` — exists, is correct, and fully supports full *and* partial delivery. But it operates **exclusively on `allocation_records`**, and `allocation_records` are **never created for a driver-trip order**. ECOS has **two architecturally disjoint delivery worlds**:

- **Operator / allocation world** — `LoadingSession → pool allocation → allocation_records (order_line grain) → RecordProductDeliveryAction → quantity_delivered → order_lines.delivered_qty projection`. Gated `loading.allocation.manage`. Wave/pool-provenanced.
- **Driver / Group-custody world** — `Group → GroupFinalizationService → trip_orders → DeliveryStop (order grain) → DriverRuntimeController::stopAction (status only)`. Gated `loading.driver.operate`. Deliberately **no** wave/pool provenance.

There is **no code, event, or listener** bridging the two. The driver's stop lives in the second world; the delivered-quantity authority lives in the first. The sole allocation engine (`AutoAllocationService`) **structurally excludes** the driver's loads (they carry `preparation_wave_id = NULL`, which it filters out — it would create `0` records even if run against them). So the missing "bridge" cannot be built by reusing the existing allocation engine, and the task forbids a second allocation system — which makes this an **owner architecture/business decision**, not a mechanical wiring task.

**Live `ecos_dev` confirms the trace:** `allocation_records` total = **0**; the one existing driver stop (TRP-003, assignment 209, order `01a0228a…`) has **0 allocations** and `order_lines.delivered_qty = 0` on both lines — yet vehicle custody IS populated (2 `vehicle_inventory_items`, 2 `loading_tasks`). The driver world is live and populated; the allocation world it would need is empty and unreachable.

The two backend foundations built earlier (canonical writer + secure POD) are correct and unaffected — they simply sit on the far side of a bridge the driver cannot cross.

## 2. Canonical Delivery Lifecycle (as it actually is)

The lifecycle in the task brief (Driver → DeliveryStop → Order → `RecordProductDeliveryAction` → `allocation_records` → projection → POD) is the **intended** flow. The middle link is real only for the **operator**:

```
OPERATOR:  LoadingSession(LoadingComplete) → start-allocation → AutoAllocationService
           → allocation_records → AllocationController::recordDelivery → RecordProductDeliveryAction
           → allocation_records.quantity_delivered → ProductDeliveryRecorded
           → order_lines.delivered_qty (projection) ;  + vehicle custody 'delivered' movement
DRIVER:    Group → GroupFinalizationService → trip_orders → DeliveryService::generateStops
           → DeliveryStop → DriverRuntimeController::stopAction → DeliveryStop.status ONLY
           (POD via uploadDeliveryProof, stop-scoped, quantity-independent)
```
The driver branch never enters the allocation branch. `RecordProductDeliveryAction` (`RecordProductDeliveryAction.php:54-158`) touches only `allocation_records`, `vehicle_inventory_items`, and the `ProductDeliveryRecorded` event — never `DeliveryStop`.

## 3. Allocation Lifecycle

- **Sole production writer of `allocation_records`:** `AutoAllocationService` (`AutoAllocationService.php:177`, `AllocationRecord::create`). Keyed `(vehicle_assignment_id, order_line_id)`, carrying `loading_session_id`, `vehicle_inventory_item_id`.
- **Input it consumes:** on-vehicle products with `quantity_unallocated > 0` (`AutoAllocationService.php:85-88`) matched to serviceable orders derived from **preparation wave** provenance (`LoadingTask.preparation_wave_id` → `PreparationWaveOrder`, `:95-99, 262-273`).
- **Trigger (only one):** `AllocatePoolToSessionAction` ← `StartAllocationAction` (requires `LoadingSession` at `LoadingComplete`, moves it to `Allocating`) ← `AllocationController::startAllocation` ← `POST /loading/sessions/{id}/start-allocation` (`routes/api.php:1040`), gated **`loading.allocation.manage`** (`LoadingSessionPolicy.php:55-60`).
- **Driver path creates none:** `GroupFinalizationService::finalize` writes trip_orders only ("never touches Preparation — no wave, no wave item, no pool", `GroupFinalizationService.php:38-41`); `DriverLoadingController::loadProduct` calls `LoadProductAction` with `poolEntryId: null, preparationWaveId: null` (`:103-113`); `complete()` runs exactly finalize + `generateStops` + status changes, **no allocation step** (`:334-364`).
- **Structural exclusion:** even if `AutoAllocationService` ran over a session containing the driver's assignment, the driver's NULL-wave `loading_tasks` yield an empty `$waveIds` and it returns `records_created: 0` (`AutoAllocationService.php:95-103`). Driver loads cannot be allocated by this engine even by accident.
- **Guaranteed for a driver-trip order?** **Never, by default.** An order legitimately reaches the driver Orders/Stops UI (a `DeliveryStop` with `order_id`) with **zero** `allocation_records`.
- **Q3.6 (what happens on delivery attempt):** there is no driver delivery endpoint that writes quantity; the driver can only settle `DeliveryStop.status` via `stopAction`. A canonical quantity delivery is simply not reachable.

## 4. Driver Authorization

- `RecordProductDeliveryAction::execute(record, quantityDelivered, actorId, actorType='driver')` **already accepts `actorType='driver'`** (`RecordProductDeliveryAction.php:57`) — but that is only an attribution label written to `updated_by`; the action performs **no ownership/tenancy check of its own**.
- Authorization lives entirely in the **only** controller that calls it: `AllocationController::recordDelivery` → `authorize('allocate', $session)` → `loading.allocation.manage` (`AllocationController.php:176`, `LoadingSessionPolicy.php:55-60`), plus `findSession` company scoping and `assignment.loading_session_id` scoping (`:178-190`).
- **The action does NOT verify the authenticated driver owns the trip/stop, nor that the order is in the driver's operational scope.** Those guarantees exist only for the operator route's session scope. There is **no** driver-scoped, fail-closed route to the action. (By contrast, the driver runtime's own writes — `stopAction`, `uploadDeliveryProof` — are fail-closed via `ownedStop()` re-asserting company + driver on the parent trip.)
- **Could another driver submit for this order?** Not through any existing driver route (none reaches the action). Through the operator route, any user with `loading.allocation.manage` in the order's company could — driver identity is not enforced there.
- **Is the action already fail-closed?** For *quantity* it is guarded (over-delivery/terminal/negative refused), but for *identity* it is **not** — it trusts the caller. A driver-facing path would need its own `ownedStop`-style guard.

## 5. Full Delivery Path (Required = Delivered = 10)

1. **Endpoint/action:** operator-only `POST /loading/sessions/{id}/assignments/{id}/allocation/deliver` → `RecordProductDeliveryAction`. (No driver route.)
2. **Record updated:** the addressed `AllocationRecord`, re-loaded under `lockForUpdate` (`:71`); one-to-one, no new row.
3. **Written:** `quantity_delivered = 10` (absolute set, `:103`).
4. **Event:** `ProductDeliveryRecorded(orderId, orderLineId, companyId)` after commit (`:151-155`).
5. **`order_lines.delivered_qty`:** set by the listener to `Σ allocation_records.quantity_delivered` for the line (`ProjectDeliveredQuantityFromAllocation.php:35-45`).
6. **Delivery Stop:** **untouched** — the action has no `DeliveryStop` reference.
7. **Order lifecycle:** untouched — `Order.status` is not written (ORM-guarded to FulfillmentEngine).
8. **Vehicle inventory:** delta (`10 − prev`) propagated to `vehicle_inventory_items` + a `delivered` movement (`:124-139` → `VehicleInventoryService.php:251-285`).
9. **Idempotent:** yes — replaying 10 gives delta 0 → no vehicle move, no extra movement; projection recomputes to the same value.

## 6. Partial Delivery Path (Required 10, delivered 7, remaining 3)

1. **Can it record 7?** Yes — `7 − 10 ≤ EPSILON`, over-delivery guard passes.
2. **7 stored at:** `allocation_records.quantity_delivered = 7` (`:103`).
3. **Remaining 3:** `quantity_remaining = allocated − delivered = 3` — a **computed column on the same allocation row**, not a separate record.
4. **Another delivery later?** Yes — the **same** allocation row is re-locked and updated in place.
5. **Second delivery creates/updates?** **Updates the same row** (absolute set). To finish the line the caller must send the **cumulative total 10**, not the incremental 3. ⚠️ **Footgun:** because the write is absolute, sending `3` on the second call *overwrites* `quantity_delivered` to 3 (not 7+3). A driver-facing UI must post the cumulative delivered total, never the increment.
6. **Projection becomes:** `order_lines.delivered_qty = 7`, then `10` after the finishing call. ✔ idempotent recompute.
7. **Delivery Stop after 7:** unchanged by the quantity write. Stop status is a *separate* fact set by `stopAction` (e.g. `partial`), with no numeric link to the 7.
8. **Order:** untouched.
9. **Vehicle inventory:** delta 7 (then +3) moved; on-hand recomputed.
10. **Is the remaining 3 still physically in the vehicle?** In the allocation/vehicle model, yes — `quantity_on_hand = max(0, loaded − delivered − returned)` still holds the 3 (`VehicleInventoryService.php:260-263`).
11. **What tracks the remaining 3?** `allocation_records.quantity_remaining` (canonical) and the vehicle item's `quantity_on_hand`. **But none of this exists for a driver-trip order (no allocation row).**

**Bottom line:** partial delivery is fully modeled — *in the allocation world the driver cannot reach.*

## 7. Delivered-Qty Projection

Correct and idempotent: `ProductDeliveryRecorded` carries **ids only** (`ProductDeliveryRecorded.php:24-28`); the listener re-derives `order_lines.delivered_qty := Σ allocation_records.quantity_delivered` per line and absolute-updates (`ProjectDeliveredQuantityFromAllocation.php:35-45`), registered synchronously (`OrderServiceProvider.php:124`). For driver-trip orders the sum is over **zero** allocations, so the projection correctly yields `0` — i.e. the projection is fine; it simply has no input on the driver path.

## 8. Delivery Stop Lifecycle

`DeliveryStop` is order-grain (`order_id`, `trip_id`, `sequence`), created by `DeliveryService::generateStops` from `distribution_trip_orders`. It is settled by `DriverRuntimeController::stopAction` → `DeliveryService::completeStop`, which writes **only** `status/completed_at/attempted_at` (+ notes/GPS) and dispatches `DeliveryStopCompleted` (`DeliveryService.php:78-107`). That event **has no listener anywhere**, so completing a stop has zero effect on allocation quantities. The stop's `status` enum can be `partial`/`delivered`/`failed`, but it carries **no quantity** and is not numerically reconciled against `delivered_qty`.

## 9. Vehicle Inventory During Delivery (delivery only — NOT Warehouse→Vehicle custody)

- `RecordProductDeliveryAction` **does** invoke `VehicleInventoryService::recordDelivery` (`:124-139`), gated on non-zero delta + a linked `vehicle_inventory_item_id`, under `lockForUpdate`.
- It **decrements** vehicle inventory: `quantity_delivered += delta`, `quantity_on_hand = max(0, loaded − delivered − returned)`, flips to `Depleted` at zero (`VehicleInventoryService.php:259-269`).
- It appends **one** canonical vehicle-custody movement row (`vehicle_inventory_movements`, `movement_type='delivered'`, positive magnitude), `:273-281`.
- **The moved quantity is the DELTA**, not the absolute (`RecordProductDeliveryAction.php:100-101,134`).
- It creates **no warehouse `stock_ledger_entries`** — delivery is warehouse-stock-neutral by design (`VehicleInventoryService.php:96-99`; pinned by `RecordProductDeliveryOrderLineProjectionTest.php:175-179`).
- **Verified by** `VehicleShiftReconciliationTest` (`:456-470` one movement per actual change; replay appends none) and `RecordProductDeliveryHttpTest:311`.
- **Again: for a driver-trip order none of this fires**, because there is no allocation row to deliver against.

## 10. POD Relationship

POD is a **stop-scoped evidence artifact, fully decoupled from delivered quantity and from stop status**. `DeliveryProof` belongs to a stop via `stop_id` only (no `order_id`, `order_line_id`, or quantity column). `UploadDeliveryProofAction` records files + notes and nothing else. `uploadDeliveryProof` (`DriverRuntimeController.php:373-407`) gates only on ownership (`ownedStop`) + non-empty evidence — **no** quantity or status check. So POD can be uploaded after full delivery, after partial delivery, or at any stop state. No delivery-quantity rule exists anywhere in the POD lifecycle.

⚠️ **Incidental POD gap (idempotency):** `uploadDeliveryProof`/`captureProof` do an unconditional `$stop->proof()->create(...)`, and `stop_id` is indexed but **not unique** (`2026_07_28_100005…:33`); the relation is `hasOne()->latestOfMany()`. So a retried upload **appends duplicate proof rows** for one stop (reads stay deterministic — latest wins — but the table accumulates). No supersede/dedup and no test pins "one row after N uploads." Not in scope to fix here; flagged.

## 11. Driver UI Readiness

The read model is **structurally ready** but **semantically empty on the driver path**:
- `DriverRuntimeController::orderPayload` (stop **detail** only, `withLines:true`) exposes per line: `ordered_qty` (=`order_lines.quantity`), `loaded_qty`, `delivered_qty`, `returned_qty` (columns), and a **server-computed** `remaining_qty = max(0, quantity − delivered − returned − cancelled)` (`DriverRuntimeController.php:794-808`), plus stop `status`.
- So "Required 10 / Delivered 7 / Remaining 3" is renderable **purely from canonical read data** — no frontend reconstruction. The frontend `StopOrderLine` type already declares all five fields.
- **But two caveats:** (a) the driver stop-detail UI currently renders only `ordered_qty × name` + `line_total` — the Required/Delivered/Remaining display is unwired (a pure presentation change); (b) `delivered_qty` is fed **only** by the allocation projection, so for driver-trip orders it is **always 0** — the UI would truthfully show "Delivered 0 / Remaining 10" forever, because the driver cannot record a delivery.

**No read field is missing.** The read model is not the blocker; the missing thing is the *write path* that would populate `delivered_qty` for a driver trip.

## 12. Idempotency

- **Delivered quantity — fully idempotent by construction:** absolute-set on the allocation (`:103`), delta-only propagation gated `abs(delta) > EPSILON` (`:124,134`), `lockForUpdate` on both the allocation and the vehicle item, terminal-status guard (`:77-81`), over-delivery/negative refused, and a recompute-from-source projection immune to HTTP retry and event replay. Verified (`RecordProductDeliveryHttpTest:293-312`, `VehicleShiftReconciliationTest:331-344,456-470`, `RecordProductDeliveryOrderLineProjectionTest:104-117`).
- **Allocations / vehicle movements:** no duplicates — movements append only per actual change; replay appends none.
- **POD — NOT idempotent (gap, §10):** duplicate rows per retry.

## 13. Existing Tests

- **RecordProductDeliveryAction:** `RecordProductDeliveryHttpTest` (full/partial/over/tenant/replay/auth), `VehicleShiftReconciliationTest` + `…HttpTest` (delivery + reconciliation, delta semantics), `RecordProductDeliveryOrderLineProjectionTest` (projection).
- **Driver authorization (two models):** `RecordProductDeliveryHttpTest:340-367` (operator `allocate` policy, 401/403); `DriverDeliveryProofSecureUploadTest:148-180` (driver `ownedStop` fail-closed, 404/403/401); `DriverVehicleInventoryAndIdentityTest`, `TripLifecycleCertificationTest`, `DriverLoadingCustodyHandoffTest` (driver identity/ownership).
- **Partial:** `RecordProductDeliveryHttpTest:164-188`, `RecordProductDeliveryOrderLineProjectionTest:78-91`, `VehicleShiftReconciliationTest:196-207,266-277`, `DistributionModuleTest:544-566` (stop-level partial status).
- **Split allocation:** `RecordProductDeliveryOrderLineProjectionTest:119-136`, `VehicleShiftReconciliationTest:313-325`.
- **delivered_qty projection:** `RecordProductDeliveryOrderLineProjectionTest` (whole file).
- **Vehicle-inventory delivery movement:** `VehicleShiftReconciliationTest:456-470`, `RecordProductDeliveryHttpTest:311`, `RecordProductDeliveryOrderLineProjectionTest:159-179`.
- **POD:** `DriverDeliveryProofSecureUploadTest` (upload/reject/retrieval/legacy/migration), `DistributionModuleTest:568-582`. **No test** pins POD single-row-per-stop (the §10 gap).
- **No test exists** for a *driver* recording a delivery quantity — because no such path exists.

## 14. Gaps Found

1. **PRIMARY — no driver path to canonical delivery.** Driver-trip orders have **no `allocation_records`** (never created; the allocation engine structurally excludes NULL-wave driver loads), and there is **no driver route** to `RecordProductDeliveryAction`. The DeliveryStop world and the allocation world are unbridged. ⇒ the driver cannot record full or partial delivery canonically.
2. **Two incompatible delivery models.** Operator (allocation/wave/pool-based) vs driver (Group/custody-based). The architecture does not define how a driver-trip order produces canonical delivered quantities. Reconciling them cannot reuse `AutoAllocationService` (it rejects driver loads) and must not become a second allocation system.
3. **Action identity guard.** `RecordProductDeliveryAction` trusts its caller for ownership; any driver-facing path must add an `ownedStop`-style fail-closed guard (the action's `actorType='driver'` is only attribution).
4. **Partial absolute-set footgun.** A driver UI must post the *cumulative* delivered total, never the increment, or the field is overwritten.
5. **Driver UI display unwired.** `delivered_qty`/`remaining_qty` are available on the read model but not rendered (and would read 0 until gap #1 is resolved).
6. **POD duplicate-row idempotency gap** (§10) — incidental, not blocking delivery.

## 15. Recommended Next Task

**An owner decision, not an implementation.** Before any driver delivery UI, the owner must decide **how a driver-trip (Group/custody) order produces the canonical delivered quantity**, given `RecordProductDeliveryAction`/`allocation_records` is the sole authority and a second allocation system is forbidden. Candidate directions (for the owner to choose, not for me to pick):

- **D1 — Extend allocation to the driver custody grain.** At Loading-Complete/finalize, create `allocation_records` for the driver's vehicle custody (`vehicle_inventory_item × order_line`) through a **driver-grain allocation writer that shares the `AllocationRecord` model + `RecordProductDeliveryAction`** (no second *delivery* writer), plus a fail-closed driver route to record delivery. Owner must rule whether a driver-grain allocation *creator* counts as an acceptable extension or a forbidden second allocation system.
- **D2 — Bridge the DeliveryStop world to the canonical writer.** Give the driver a fail-closed route that resolves the stop's order+custody to the canonical writer, creating the backing allocation on demand. Same "is this a second system?" question.
- **D3 — Make driver Group loading wave/pool-provenanced** so the existing `AutoAllocationService` can allocate driver loads. Larger change to the certified Group-custody loading model.
- **D4 — Defer driver delivery-quantity capture.** Driver records stop *status* only for now; delivered quantities are captured by an operator, and the driver UI shows Required/Delivered/Remaining read-only (Delivered stays 0 until an operator records it).

**Smallest safe next step:** a scoped owner decision on D1–D4. Once chosen, the follow-up is a single bounded backend bridge (per the choice) + the fail-closed driver route + the UI wiring — none of which should be started before the decision.

## 16. Explicitly NOT Changed

No modification was made to: controllers, actions, services, routes, migrations, schema, permissions, frontend, or tests. No orders, allocations, deliveries, stops, POD, inventory, or trip state were created or mutated. Live `ecos_dev` was read-only (SELECT/COUNT only). The Loading-Complete gate, driver custody semantics, Delivery/POD, the `delivered_qty` projection, Day Settlement, and the trip lifecycle are untouched. No discovered gap was "fixed."

---

## 17. CLASSIFICATION — **C — BUSINESS RULE DECISION REQUIRED**

The canonical delivery action exists and fully supports full + partial delivery, so this is not merely a missing service. But the driver **cannot reach it**, driver-trip orders **never** have the `allocation_records` it requires, and the sole allocation engine **structurally cannot** create them for driver loads. The architecture therefore does not define how a driver-trip order produces canonical delivered quantities, and closing that gap without a second allocation system requires an owner architecture/business decision (§15). Until that decision is made the driver delivery path is effectively **blocked** — but because a canonical action does exist and the resolution is a *rule/architecture* choice rather than a missing primitive, the correct classification is **C**, not B (no clean reuse-only bridge) and not D (the canonical *service* exists; it is the *rule for driver data* that is undefined).
