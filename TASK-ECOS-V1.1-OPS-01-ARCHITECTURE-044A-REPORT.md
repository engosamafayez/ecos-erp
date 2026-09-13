# TASK-ECOS-V1.1-OPS-01-ARCHITECTURE-044A

## Warehouse & Inventory Enhancements — Architecture & Design / Read-Only Reconciliation

**Phase:** Architecture & Design / Read-Only Reconciliation
**Workspace:** `E:\ECOS\ECOS-NEXT-OPS` · **Branch:** `feature/operations-v1.1` · **HEAD:** `cce124e3` (matches expected base)
**Date:** 2026-09-12
**Implementation: NONE. Commit: NONE. Push: NONE. Deployment: NONE. Data mutation: NONE.**

Method: five parallel read-only research passes (Inventory/FIFO/Warehouse-Receipt/Transfer; Loading/Vehicle-Driver-Custody; Driver-Return/Damaged-Stock/Liability/Waste/Settlement; Delivery-Outcomes/Order-Status/Partial-Quantity/Postponement; and a direct tenant-scoping code review after the dedicated security-review agent was blocked by an unrelated session-level safety gate), each required to cite `file:line` for every claim and to re-verify historical `.md` task reports against current code rather than trust them. Every cross-agent claim below that mattered for a decision was independently re-checked. Two additional facts were found only by direct verification during synthesis and are flagged as such.

---

## 1. Current Authority Map

### 1.1 Inventory / FIFO / Warehouse core

| State | Authority | Table & Model | Service/Action | Current writer(s) | Events | Tenant scope |
|---|---|---|---|---|---|---|
| On-hand / reserved qty | **InventoryItem** | `inventory_items` → `Modules\Inventory\InventoryItems\Domain\Models\InventoryItem` | 7 actions in `InventoryItems\Application\Actions`: `ReceiveStockAction`, `AdjustmentInAction`, `AdjustmentOutAction`, `ShipStockAction`, `ReserveStockAction`, `ReleaseStockAction`, `DirectIssueStockAction` | GoodsReceipt, CountSession approval, WarehouseLiability/WasteInvestigation approval, Commerce\Orders ship/reserve/release, Loading's `TransferLoadedStockToVehicleAction` (one of 3 handover paths — see §1.2), driver-return receipt (`ReceiveVehicleReturnAction`) | `InventoryStockReceived/Adjusted/Reserved/Released/Shipped`, dispatched only `afterCommit()` | **No Eloquent global scope on the model itself.** Repository exposes both a scoped and an unscoped finder; safety today rests on warehouse→company being fixed 1:1, not a filter (see §7) |
| Canonical ledger | **StockLedgerEntry** | `stock_ledger_entries` → same module | Written only via `InventoryItemRepositoryInterface::recordEntry()`, always inside the same transaction as the InventoryItem write | same as InventoryItem | n/a (ledger row) | `company_id` required on every insert; safe because every caller is trusted internal code |
| FIFO layer | **InventoryReceiptLayer** | `inventory_receipt_layers` → `Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer` | `CreateReceiptLayersAction` (GR/invoice path) + 6 other inline creators (count-approval, manual stock, transfer, disassembly, manufacturing execution, customer-return receipt) | see rows below | none directly | `company_id` NOT NULL since `2026_07_20_200004_*`; consumption service filters by it explicitly |
| FIFO consumption | **InventoryLayerConsumption** | `inventory_layer_consumptions` | `InventoryLayerConsumptionService::consume()` — strict oldest-first walk, `bcmath` precision, `lockForUpdate` | called from Ship, WarehouseLiability-approve, WasteInvestigation-resolve, Transfer (inline reimplementation) | none | company_id required param, enforced in query |
| Warehouse (entity) | **Warehouse** | `warehouses` → `MasterData\Warehouses\Domain\Models\Warehouse` | `Create/Update/DeleteWarehouseAction` | `WarehouseController` (`routes/api.php:620`) | none | **Correct, structural**: `addGlobalScope` via `TenantOwnershipResolver` (`Warehouse.php:58-85`) — the reference pattern (see §7). No `type`/`is_vehicle` concept; vehicle staging is deliberately modeled outside Warehouse |
| Warehouse receipt / goods inward (procurement) | **GoodsReceipt / GoodsReceiptLine** | `goods_receipts(_lines)` → `Purchasing\GoodsReceipts\Domain\Models` | `PostGoodsReceiptAction` — orchestrates `ReceiveStockAction` + `CreateReceiptLayersAction` in one transaction, gated by `GoodsInwardAuthority::receiptMayPost()` | `GoodsReceiptController::post`, `ReceivingCenterController::receive` | `InventoryStockReceived` | Same correct `TenantOwnershipResolver` global-scope pattern as Warehouse |
| Warehouse transfer | **WarehouseTransfer** | `warehouse_transfers` → `Inventory\Transfer\Domain\Models\WarehouseTransfer` | `TransferStockAction` — locks both `InventoryItem` rows, moves FIFO layers preserving cost/date/supplier, explicit `CrossCompanyTransferException` if either warehouse's company doesn't match | **NONE — zero HTTP route, zero Presentation controller.** Only callers found: a root-level ad-hoc script `verify_transfer.php` and one test | `InventoryTransferred`, `WarehouseTransferCompleted` — published, zero listeners (documented/deliberate per ADR-026) | Explicit company-match check in the action itself (not a global scope) — correct, just unreachable |
| Inventory count / cycle count | **InventoryCountSession/Line** | `inventory_count_sessions(_lines)` | `ApproveCountSessionAction` — overstock → `AdjustmentInAction`+layer; shortage/damage → pending `WarehouseLiability`/`WasteInvestigation` (no immediate stock write) | `InventoryCountController::approve` | `InventoryCountApproved` | Resolved via `session→warehouse→company_id` |
| Shortage/liability (warehouse-count origin) | **WarehouseLiability** | `warehouse_liabilities` | `ApproveWarehouseLiabilityAction` → `AdjustmentOutAction` + FIFO consume, on approval only | `WarehouseLiabilityController::approve/reject`, `permission:inventory.liabilities.approve/reject` | `InventoryStockAdjusted` | No party FK at all — no `driver_id`/`vehicle_id`/`trip_id` column exists on the table (confirmed by migration) |
| Waste (warehouse-count origin) | **WasteInvestigation** | `waste_investigations(+attachments,+events)` | `ResolveWasteInvestigationAction` → same AdjustmentOut+FIFO pattern for outcomes requiring deduction | `WasteInvestigationController::resolve`, `permission:inventory.waste.resolve` | `InventoryStockAdjusted` | `count_session_id`/`count_line_id` are **NOT NULL** — structurally cannot originate from anything but a warehouse cycle count |
| Analytics / dashboards | **InventoryControl** (ABC, cycle-count planning, dashboard, variance, warehouse-performance) | reads only | 4 services in `InventoryControl\Application\Services` | `AbcClassificationController`, `InventoryDashboardController`, `VarianceAnalyticsController`, `WarehousePerformanceController` | none | **See §7 — confirmed gap, not scoped at all** |

### 1.2 Loading / Vehicle & Driver Custody

| State | Authority | Table & Model | Service/Action | Current writer(s) | Events | Tenant scope |
|---|---|---|---|---|---|---|
| Loading session | **LoadingSession** | `loading_sessions` | `{Create,Open,Start,Complete,Cancel,Close}LoadingSessionAction` | `LoadingSessionController` (`routes/api.php:1386-1392`) | `LoadingSessionCreated/Closed/Cancelled` | No global scope; per-query `where('company_id', …)` + `LoadingSessionPolicy` |
| Vehicle/driver assignment | **VehicleAssignment / DriverAssignment** | `vehicle_assignments`, `driver_assignments` | `AssignVehicleToSessionAction`, `AssignDriverAction` | Pool flow: `VehicleAssignmentController`/`DriverAssignmentController`. Group/Trip flow: `GroupLoadingContextService::open()` calls `AssignVehicleToSessionAction` but **never** `AssignDriverAction` (driver pairing instead comes from the Distribution module's own `driver_vehicle_assignments`) | `VehicleAssigned`, `DriverAssigned` (never fires for Group/Trip) | Policy-gated; no global scope |
| **Handover — vehicle-side credit** | **VehicleInventoryItem/Movement** | `vehicle_inventory_items(+_movements)` | `LoadProductAction` → `VehicleInventoryService::recordLoad()`, one transaction | **3 independent call sites, only one closes the loop atomically — see §4** | none | **No global scope on any of the 5 Loading custody models** (confirmed independently by both direct code review and a dedicated research pass; unchanged from a 2026-08-25 audit finding) |
| **Handover — warehouse-side decrement** | `stock_ledger_entries` | — | Pool/session flow: deferred to **dispatch** (`DispatchVehicleAction`→`ShipStockAction`). Group/driver flow: `TransferLoadedStockToVehicleAction`, inside the same transaction as the driver's confirm-received call | see §4 | none | — |
| Vehicle inventory read | **VehicleInventoryItem** (read) | same table | — | `VehicleInventoryController`, driver read `DriverRuntimeController::vehicleInventory` | — | — |
| Dispatch | `vehicle_assignments.status` / `distribution_trips.status` | — | `DispatchVehicleAction` (pool) / `TripService::changeStatus`+`recordDriverAcceptance` (Group/Trip) | `VehicleAssignmentController::dispatch`, `DriverRuntimeController::startTrip` | `VehicleReleased`, `TripStatusChanged`, `TripDispatched` | policy-gated, no global scope on `Trip` |
| Equipment/cash custody | **TripCustody** | `distribution_trip_custody` | — | — | — | Distinct asset class from goods (cash float, POS device, ice boxes, bags) — **not** a duplicate of VehicleInventoryItem; confirmed no code relationship exists between the two, and none should |
| Settlement opening (physical) | **VehicleShiftReconciliation(+Lines)** | `vehicle_shift_reconciliations(_lines)` | `VehicleShiftReconciliationService::open()` | `VehicleShiftReconciliationController::open` | none | none |
| **Settlement close / warehouse return receipt** | **ReceiveVehicleReturnAction** | writes `vehicle_shift_reconciliation_lines` | one transaction: `AdjustmentInAction` + new `InventoryReceiptLayer` + `VehicleInventoryService::reconcileReturn()` (absolute-set, idempotent) | `VehicleShiftReconciliationController::receiveReturn` | none | warehouse_id derived server-side from the loading session (never client input) — correct pattern |

### 1.3 Delivery execution, Order outcomes, Returns

| State | Authority | Table & Model | Write path | Routed? | Writes canonical inventory? | Line-level qty split? |
|---|---|---|---|---|---|---|
| Order (commercial state) | **Order/OrderStatus** | `orders` | `FulfillmentEngine::run()` — the **only** legal writer of `Order.status` (`Order::booted()` throws on any other write) | — | n/a | — |
| Delivery-attempt outcome | **DeliveryStop/DeliveryAction/DeliveryException** | `distribution_delivery_stops` etc. | `DriverRuntimeController::stopAction()` | Y | n/a | via `FailureReason` enum on `DeliveryAction.reason` |
| Customer-facing return (order-line RMA) | **(a) CustomerReturn/CustomerReturnLine** | `customer_returns(_lines)` | `ReturnOrderWorkflow` (create) + `ReceiveReturnWorkflow` (accept) | **Y** (`routes/api.php:1514,1527`) | **Y** — `AdjustmentInAction` + new `InventoryReceiptLayer` | Partial — one `condition` enum per line (sellable/damaged/destroyed) |
| Customer-facing return, "Delivery OS" channel | **(b) DeliveryReturn/DeliveryReturnLine** | `delivery_returns(_lines)` | `DeliveryReturnService` (initiate/receive/verify) | **Y** — `routes/api.php:2417-2461`, `permission:delivery.return.manage` (**corrected during synthesis — see note below**) | **N** — reconciles against the warehouse's own count rather than writing stock itself; by design, not a defect (see §4) | shortage only (`discrepancy_qty`); no damaged/accepted split |
| Vehicle-custody-facing return (declaration) | **(c) TripReturn** | `distribution_trip_returns` | create: `DeliveryService::recordReturn` · confirm: `DeliveryService::confirmReturn` | Create **Y** (`DriverRuntimeController::addReturn`, `api.php:3786`). **Confirm: NOT ROUTED today** — see §4 regression finding | N | shortage only + a `driver_liable` **boolean** (no amount) |
| Vehicle-custody-facing return (warehouse receipt) | **(d) ReceiveVehicleReturnAction** | see §1.2 | — | **Y** | **Y** | **Y, fully** — accepted / damaged / shortage as three independent numeric columns on one line |

**Correction found during synthesis:** one research pass concluded `Modules\Logistics\Delivery` ("Delivery OS") has zero HTTP routes and is dead code. Direct verification of `routes/api.php:182-186` and `:2402-2472` disproves this — the module is imported under aliases (`DeliveryOsController`, `DeliveryOsReturnController`, etc.) that a bare-class-name search misses, and it carries 30+ live routes (Delivery, DeliveryAttempt, DeliveryPod, DeliveryCod, DeliveryReturn). Its actual role, confirmed by its own docblock (`DeliveryReturn.php:13-19`) and an explicit CTO decision recorded in `TASK-OPERATIONS-DRIVER-SETTLEMENT-DAY-CLOSING-DISCOVERY-001-REPORT.md`: **(b) and (c) are a deliberate split, not an accidental duplicate** — (c) `TripReturn` records what physically came back on the vehicle; (b) `DeliveryReturn` records what the *customer* did not accept, reconciled line-by-line against the warehouse's count. `Modules\Logistics\Distribution` (Trip→DeliveryStop, driven by `DriverRuntimeController`) remains the live driver-execution engine; "Delivery OS" is a separate, real, routed back-office/CS-facing system, not a dead parallel one.

### 1.4 Distribution planning / multi-warehouse

| State | Authority | Notes |
|---|---|---|
| Preparation-wave-level postponement | `preparation_wave_orders.postponed_at` + `WaveMembershipService` | Real, tracked, tested end-to-end (`WaveDeferredOrderCutoffReturnTest.php`) — order becomes eligible again automatically when the next wave opens |
| Delivery-execution-level postponement | none dedicated | Re-eligibility today is just `Order.status` reset to `InProgress` via `ReleaseForReplanningWorkflow` — see §3.2 |
| Distribution Group warehouse ownership | `distribution_virtual_slots` (Group) | A 2026-08-21 decision record (`TASK-OPERATIONS-DISTRIBUTOR-ORDERS-D-P5-1-DECISION-RECORD.md`) recorded this as **NOT yet implemented** and as "the gate on multi-warehouse Distribution being complete." **Direct grep during this reconciliation found `warehouse_id` used pervasively and described as NOT NULL on the Group entity in `DistributionWindowController.php` (lines 1426, 1438, 1649, 1837, 1863…)** — strong evidence this has since been implemented. This was a fast, targeted check, not a full trace like the five agent passes; treat as high-confidence but recommend a one-file confirmatory read (`Domain\Models\VirtualCapacitySlot.php` + its migration) before relying on it for planning. |
| Distribution Zone geography | `distribution_zones` | Still confirmed pure geography, no `company_id`/`warehouse_id` column — consistent with the decision record; zones are shared geography, groups (not zones) now appear to carry the warehouse ownership. |

---

## 2. Already-Implemented Capabilities (preserve — do not rebuild)

1. **Canonical inbound receiving** (procurement): `PostGoodsReceiptAction` → `ReceiveStockAction` + `CreateReceiptLayersAction`, one transaction, `GoodsInwardAuthority` prevents GR/Invoice double-posting, `InboundPostingGuard` gives idempotency. This is the reference pattern every other inbound path should be judged against.
2. **FIFO consumption engine** (`InventoryLayerConsumptionService`): strict oldest-first, arbitrary-precision arithmetic, immutable consumption audit rows, company-scoped queries.
3. **Driver-return → warehouse-receipt, for vehicle custody** (`ReceiveVehicleReturnAction`): fully qualifies as a real, canonical warehouse-receipt authority — routed, atomic, restocks accepted goods via the same `AdjustmentInAction`+FIFO-layer path as procurement, keeps damaged goods out of good stock, records shortage as a visible `variance` (never silently absorbed), refuses over-receipt beyond `loaded − delivered`, and is idempotent (a differently-split re-receipt on an already-received line is refused, not silently overwritten). Certified by 11 passing tests (`VehicleReturnReceiptTest.php`) and an independent implementation report (`docs/verification/TASK-OPERATIONAL-FULFILLMENT-RETURNS-RECONCILIATION-001-REPORT.md`, 2026-08-28).
4. **Customer-facing order-line return (RMA)** (`CustomerReturn`/`ReceiveReturnWorkflow`): equally canonical, equally real-inspector-gated, is the sole certified writer of `order_lines.returned_qty`. Not a competitor to (3) above — different triggering event (order-line RMA vs. vehicle custody), confirmed non-overlapping by the action's own docblock.
5. **WarehouseLiability and WasteInvestigation are genuine accountability workflows, not shadow ledgers** — both call the canonical `AdjustmentOutAction` + `InventoryLayerConsumptionService::consume()` on approval/resolution. They gate *when* a confirmed shortage/damage gets debited; they do not maintain a second inventory truth.
6. **Warehouse-to-warehouse stock transfer logic** (`TransferStockAction`): correct FIFO-layer-preserving move (same unit cost/date/supplier recreated at destination), proper company-match guard, proper row locking. The engineering is done — see §3 for what's missing.
7. **TripCustody is correctly separate from the per-SKU goods ledger** — it is an equipment/cash-float record (POS device, ice boxes, delivery bags, cash float), not a duplicate of `VehicleInventoryItem`. No fix needed here; this was a documented concern this reconciliation resolves as a non-issue.
8. **`Order.status` stays commercially pure.** 12 cases, none delivery-attempt-shaped ("postponed"/"refused"/"no_answer" do not exist as statuses); enforced structurally, not just by convention (`Order::booted()`'s `updating` hook throws outside `FulfillmentEngine::run()`). Delivery execution outcomes correctly live in a separate `DeliveryStopStatus`/`FailureReason` vocabulary. This is exactly the separation the ticket's §3 principle requires, and it already holds.
9. **Preparation-wave-level order postponement and replanning** is real, tracked, and tested — a postponed order becomes eligible again automatically the next time a wave opens, with no manual replanning step and no duplicate Order.
10. **Driver partial/full delivered-quantity recording is live and tested** (`RecordProductDeliveryAction`, reachable via `POST /driver/stops/{id}/deliver`, `DriverStopDeliveryTest.php`, 12 tests). Two 2026-08-26 reports diagnosing this as broken are **stale** — closed by `TASK-DRIVER-DELIVERY-ALLOCATION-BRIDGE-001` (committed 2026-08-30). Do not re-diagnose this as broken.
11. **`TenantOwnershipResolver`** is a correct, deliberately-designed, incident-driven (RC-6: "the create path took `company_id` from the client payload, while every read path took it from the authenticated user") canonical mechanism, already adopted on `Warehouse`, `GoodsReceipt`, `Product`, `Order`, `Vehicle`, `Driver`, and 10 other models. It is the right pattern to extend, not a new one to invent (see §7).
12. **Distribution Group-level warehouse ownership appears to already exist** (see §1.4) — the multi-warehouse Distribution gap recorded in the 2026-08-21 decision record looks closed; recommend a one-file confirmation, not new design work.

---

## 3. Confirmed OPS-01 Gaps

Each item below is evidenced, not inferred.

1. **`WarehouseTransfer` is fully built and unreachable.** No controller, no route, no Presentation folder in `Modules/Inventory/Transfer` at all. The only current callers are a standalone root-level script and one test. **This is the single most concrete, self-contained OPS-01 gap found.**
2. **Loading→vehicle custody has three non-equivalent handover paths, only one of which atomically ties the vehicle-side credit to a warehouse-side decrement:**
   - Pool/session flow: warehouse decrement is **deferred to dispatch**, creating a real double-count window (goods counted as on-hand in the warehouse *and* loaded on the vehicle) between loading and dispatch.
   - Group/driver flow, warehouse-confirmed then driver-confirmed: **atomic and correct** (`TransferLoadedStockToVehicleAction` inside the same transaction as the driver's confirmation).
   - Group/driver flow, **driver self-loads with no warehouse confirmation**: can never reach the transfer action at all (it requires `confirmed_at` to already be set) — the vehicle ledger shows goods loaded while the warehouse ledger never learns they left, with no error and no reconciliation trigger.
3. **Trip settlement can close independently of vehicle-return warehouse reconciliation.** `SettlementService::openSettlement()`/`finalize()` never reference `VehicleShiftReconciliation` anywhere — confirmed by reading the file and by grep (only two read-only presentation services reference it, neither is Settlement). A trip's cash settlement can fully finalize while the vehicle's physical return is still Open or Disputed. The team's own 2026-08-28 implementation report records this as an explicit open question ("does a non-zero variance block driver settlement? No approve action exists yet") — still unresolved three weeks later at current HEAD.
4. **Likely regression: the dispatcher's trip-return confirmation route no longer exists.** Two historical reports (2026-08-24/08-28) describe a live `PATCH /trips/{id}/returns/{returnId}/confirm`. Direct re-verification of current `routes/api.php` finds no such route (a case-insensitive search for `confirmReturn` across the whole file returns zero matches; the cited line number now holds unrelated code). The underlying controller method (`DeliveryController::confirmReturn`) still exists in source but is unreferenced by any route. Practical effect: every `TripReturn` row created today is a permanently-unverified driver self-declaration — this authority's warehouse-confirmation half is currently non-functional, not merely undesigned. **This needs owner triage** (§10) before being scheduled, since it may be a deliberate removal (superseded by `ReceiveVehicleReturnAction`'s dispatcher-side reconciliation) rather than an accidental regression.
5. **Trip "Dispatch Gate" can be set from raw client input, bypassing custody verification.** `DriverRuntimeController::advanceToDispatched()` correctly *derives* `driver_accepted_products/custody` from the real `LoadingCustodyService`/`VehicleInventoryItem` state. A second, independently reachable endpoint, `TripController::recordDriverAcceptance` (`permission:logistics.distribution.update`), writes the same three booleans directly from request input with no re-derivation — a dispatcher can mark custody "accepted" for a trip that still has an unconfirmed loaded product.
6. **Cross-company data exposure in the Inventory Control analytics surface — found and independently verified during this reconciliation, not sourced from any agent or report.** `InventoryDashboardService`, `VarianceAnalyticsService`, and `WarehousePerformanceService` (`Modules/Inventory/InventoryControl/Application/Services/`) issue raw `DB::table(...)` queries — confirmed by direct read of the full `InventoryDashboardService.php` — with **zero `company_id` filtering anywhere in any of the three files**. The route (`GET inventory/dashboard`, `routes/api.php:1098`) is gated only by `auth:sanctum` — no permission middleware, no scope. Concretely: any authenticated user of *any* company can retrieve every company's inventory-count accuracy %, shrinkage/adjustment values, top variance products (**real product names and SKUs**), and recent count sessions (**real warehouse names**). This is squarely inside OPS-01's own module ("Warehouse Performance" is one of the three affected services) and, unlike most findings here, is a live exposure rather than a design gap — see the recommendation in §11.
7. **No tenant global scope on the core Inventory ledger models** (`InventoryItem`, `StockLedgerEntry`, `InventoryReceiptLayer`) **or on any of the five Loading custody models** (`LoadingSession`, `VehicleAssignment`, `LoadingTask`, `VehicleInventoryItem`, `VehicleShiftReconciliation`) — `company_id` is present as a column everywhere but the automatic, structural protection used on `Warehouse`/`GoodsReceipt`/`Product`/`Order` is absent here. Isolation on these models depends entirely on every call site remembering to filter — the same class of risk `TenantOwnershipResolver` was built to close everywhere else (see §7).
8. **No idempotency key or lock-ordering discipline on `TransferStockAction`** — a client-side retry of the same transfer would create two independent, fully-posted transfers, and two concurrent transfers in opposite directions between the same two warehouses lock in reverse order (a deadlock shape, not data corruption, but avoidable). Low urgency only because the action is currently unrouted; becomes relevant the moment §3 item 1 is fixed.
9. **`ShipmentGroup`/`ShipmentGroupItem` (per-carrier grouping inside a loading session) have zero writers anywhere in the codebase** — a permanently-empty, read-only surface. Not causing active harm, but dead weight worth a decision (build it out or remove it) rather than silent presence.
10. **No domain event marks the warehouse↔vehicle handover itself** (`LoadingCustodyService`, `VehicleInventoryService`, `TransferLoadedStockToVehicleAction` — none dispatch an event). A future consumer (e.g., a Finance/COGS listener, or an analytics feed) has nothing to subscribe to today.
11. **`stock_movements` (legacy ledger) and `stock_balances` (a third, fully independent on-hand table used only by `Commerce\Fulfillments`) both still exist alongside the canonical ledger.** `stock_movements` is a deliberately-managed, in-progress consolidation (`LedgerCompatibilityReader`, EPIC-DATA-CONSOLIDATION-001, config-gated) — not an open OPS-01 gap. `stock_balances`, however, is a genuine shadow ledger with no relationship to `InventoryItem`/FIFO at all, and appears (from the code read) to never be replenished by any writer found — this is a **Commerce\Fulfillments-owned** finding, flagged here because it directly bears on "do not create a shadow ledger," but its remediation belongs to whichever track owns Commerce\Fulfillments, not OPS-01.

---

## 4. Driver Return → Warehouse Receipt Design

**Confirmed target flow, mapped to what exists today:**

```
Vehicle/Driver custody (VehicleInventoryItem — materialized correctly on 1 of 3 load paths, §3.2)
        │
        ▼
Physical return — driver declaration (TripReturn.create, DriverRuntimeController::addReturn)
        │   unverified self-report only; no inventory effect (by design — correct)
        ▼
Warehouse receipt / reconciliation — ReceiveVehicleReturnAction   ◄── THE canonical, real authority
        │   real operator physical count; refuses over-receipt beyond loaded−delivered; idempotent
        ├──► Accepted stock  → AdjustmentInAction + new InventoryReceiptLayer (restocked, FIFO-correct)
        ├──► Damaged stock   → excluded from good stock; NO disposition record (owner decision, §10)
        └──► Shortage        → recorded as visible `variance`; shift → Disputed; NO driver-liability
                                 attribution exists (owner decision, §10 — WarehouseLiability has no
                                 driver/vehicle/trip column at all)
        │
        ▼
Final custody settlement — TripSettlement / SettlementService
        │   GAP: finalize() never checks VehicleShiftReconciliation state (§3 item 3) — a trip can
        │   settle cash while physical reconciliation is still open or disputed
```

**What is real today:** the warehouse owns physical receipt, exactly as required. `ReceiveVehicleReturnAction` is not a paperwork exercise — it moves real, FIFO-correct stock, is gated by a real operator action (never inferred from order/delivery/trip status), and cannot be double-posted. This was checked specifically against the ticket's stated anti-pattern ("do not infer returned quantity merely from undelivered orders / planned trip quantity / order statuses") and **no such inference was found anywhere in the four candidate return authorities**. The one adjacent read-only mechanism (`MaterialDemandCalculator::expectedDriverReturns()`, a demand-planning projection that excludes trips still on the road) is correctly scoped and does not write anywhere.

**What is missing or broken, in priority order:**
1. Settlement is not gated on reconciliation state (§3.3) — a genuine integrity gap, and also a business-policy question (§10).
2. The dispatcher-side warehouse-confirmation route for `TripReturn` (authority (c)) appears to no longer exist (§3.4) — needs triage before scheduling.
3. Damaged-goods disposition and driver-attributed liability are both **already-identified, already-deferred owner decisions** (found independently in code comments in `ReceiveVehicleReturnAction.php` and in the team's own 2026-08-28 report) — not oversights this reconciliation is discovering fresh. See §10; do not re-litigate as a "gap" to silently implement.
4. **(a) CustomerReturn and (b) DeliveryReturn model the same real event (a customer not accepting/returning goods) from two independent modules with no shared idempotency key and no CTO decision reconciling them** — unlike the (b)/(c) split, which *is* explicitly decided. This is a latent duplication risk worth an owner decision, not a code defect to silently pick a winner for.

---

## 5. Partial Quantity Design

| Operation | Quantity-capable? | Where |
|---|---|---|
| Loaded | **Y** | `vehicle_inventory_items.quantity_loaded`, `allocation_records.quantity_loaded decimal(18,4)` |
| Delivered | **Y** | `allocation_records.quantity_delivered decimal(18,4)`, projects to `order_lines.delivered_qty` |
| Undelivered (remaining) | **Y (derived)** | `quantity_allocated − quantity_delivered`, computed server-side |
| Returned — commercial RMA | **Y** | `customer_return_lines.quantity_returned` → `order_lines.returned_qty` |
| Returned — vehicle custody | **Y** | `distribution_trip_returns.{dispatched_qty,returned_qty,warehouse_confirmed_qty,discrepancy_qty}` |
| Damaged | **Y** | `vehicle_shift_reconciliation_lines.quantity_damaged decimal(18,4)`, written by `ReceiveVehicleReturnAction` |
| Missing / shortage | **Partial — derived only** | Only as `variance`/`discrepancy_qty`; no first-class "declare N missing" input and no driver-liability writer (matches the deferred owner decision in §10) |
| Accepted into warehouse | **Y** | `vehicle_shift_reconciliation_lines.quantity_accepted decimal(18,4)` — the only one of these operations that moves real warehouse stock |

No path found that collapses these to an order-level boolean. The one partial exception is "missing," which is intentionally derived-only pending the liability-attribution decision — not a design flaw, a placeholder for an undecided business rule.

---

## 6. Inventory/FIFO Consequences

- The canonical chain (`InventoryItem` + `StockLedgerEntry` + `InventoryReceiptLayer`) is internally sound: every one of the 7 `InventoryItem` actions uses `lockForUpdate()`, defers domain events to `afterCommit()`, and the FIFO consumption walk uses arbitrary-precision arithmetic with an immutable audit row per layer slice touched.
- **Returned-accepted stock enters this chain correctly** via `ReceiveVehicleReturnAction` and via `CustomerReturn`'s `ReceiveReturnWorkflow` — both call the same `AdjustmentInAction`+`CreateReceiptLayersAction`-equivalent path used by procurement receiving, not a bespoke return-specific ledger.
- **Idempotency is real but uneven**: the GR/invoice inbound path has both a DB unique constraint (`add_unique_inbound_identity_to_receipt_layers`) and an application-level guard (`InboundPostingGuard`); the other 6 layer-creation paths (including customer-return receipt) rely on the guard alone, with no DB backstop. `ReceiveVehicleReturnAction` has its own, separate idempotency mechanism (refuses a conflicting re-receipt outright).
- **A concurrency note worth flagging, not yet a confirmed production risk**: `EloquentInventoryItemRepository::findOrCreate()` uses `insertOrIgnore`, but a migration (`2026_07_20_100000_fix_inventory_items_soft_delete_unique.php`) drops the plain unique constraint on MySQL and only recreates it as a partial index on PostgreSQL (per the migration's own comment distinguishing "(production)" from "(test/dev)"). Two concurrent first-receipts for the same (warehouse, product) could create duplicate `InventoryItem` rows on MySQL. **Whether this is live risk depends entirely on which database engine production actually runs** — this reconciliation could not confirm that from the codebase alone and flags it as a fact to verify, not a finding to act on blindly.
- The double-count window described in §3 item 2 (goods simultaneously "on hand" in the warehouse ledger and "loaded" on the vehicle, for the pool/session flow specifically) is the most consequential open inventory-integrity question, because it means the canonical `on_hand_qty` can be briefly wrong by construction, not by bug — until dispatch fires the deferred decrement.
- `stock_movements` (legacy) and `stock_balances` (Commerce\Fulfillments' independent table) sit outside this chain entirely — the former is a managed, in-progress consolidation; the latter is a genuine second on-hand authority for a different module, out of OPS-01's remit to fix but worth flagging upward.

---

## 7. Tenant/Security Findings

**Platform pattern (correct where used):** `App\Core\Company\TenantOwnershipResolver` + `CurrentCompanyService`, built specifically to close an earlier defect class (RC-6: create-path trusted client-supplied `company_id`, read-path trusted the authenticated user, so a row written under one answer was invisible under the other). It derives ownership **only** from the authenticated user (or an explicit `is_system` role bypass) — never from request input — and is applied today via `addGlobalScope('tenant', …)` on `Warehouse`, `GoodsReceipt`, `Product`, `Order`, `Vehicle`, `Driver`, `Supplier`, and several others (16 models total).

**Good, deliberate example of validated client input** (not a finding, cited for contrast): `StoreWarehouseRequest.php:39` — when a client *does* supply a `company_id`, it is checked via `TenantOwnershipResolver::owns()` rather than trusted outright.

| Area | Company-scope applied? | Mechanism / gap | Client-supplied ID risk? |
|---|---|---|---|
| `InventoryItem` / `StockLedgerEntry` / `InventoryReceiptLayer` | **N** (no global scope) | `company_id` is a data column only; protection is call-site convention | Not observed to be trusted from client input directly, but no structural backstop either |
| `Warehouse`, `GoodsReceipt` | **Y** | `TenantOwnershipResolver` global scope | Validated against `owns()`, not trusted blindly — correct pattern |
| `WarehouseTransfer` | **Y (different mechanism)** | Explicit `CrossCompanyTransferException` check in `TransferStockAction` itself | No — resolved server-side from the warehouse records, not client input |
| Loading custody models (`LoadingSession`, `VehicleAssignment`, `LoadingTask`, `VehicleInventoryItem`, `VehicleShiftReconciliation`) | **N** | No global scope on any of the five; confirmed independently twice (direct review + dedicated research pass) | Not observed directly, but no structural backstop |
| `CustomerReturn` | **N** (column present, no scope) | ad hoc | not observed |
| `DeliveryReturn` | **N — no `company_id` column at all** | Scoped only transitively via its `delivery_id` relation; `Delivery` itself **is** globally scoped, so a cross-company `{deliveryId}` should 404 via route-model-binding — this is a reasonable inference from the pattern used elsewhere in the codebase, not independently proven for this specific controller | Not observed |
| `ReceiveVehicleReturnAction` | **Y** | `warehouse_id` resolved server-side from the `LoadingSession` record, never from client input — correct | No |
| `WarehouseLiability`/`WasteInvestigation` approval | Authorization via **permission middleware** (`inventory.liabilities.approve/reject`, `inventory.waste.resolve`), not a Policy class — a valid, consistently-used alternative pattern in this codebase, not a gap in itself | — | — |
| **Inventory Control analytics** (Dashboard/Variance/WarehousePerformance) | **N — confirmed live gap** | Raw `DB::table()` queries, zero `company_id` filter anywhere; route gated only by `auth:sanctum`, no permission middleware | **N/A — worse than client-supplied-ID risk: no scoping input is even accepted or needed to see every company's data** |

**Test coverage:** dedicated tenant-isolation regression tests exist for `Warehouse`, `Product`, `Supplier`, `PurchaseMaterial`, `Order`, `Channel` (6 areas). **None exist for `InventoryItem`/`StockLedgerEntry`/`InventoryReceiptLayer`, for any Loading custody model, for `Distribution`, or for any of the four return authorities** — a documentation/verification gap distinct from (but adjacent to) the code gaps above.

---

## 8. OPS-01 Implementation Slices

Five slices, all reusing existing authorities; none require inventing a new subsystem.

### Slice 1 — Route the Warehouse Transfer engine
- **Goal:** make `TransferStockAction` reachable.
- **Existing authorities reused:** the action, its DTO, its FIFO-preservation logic, its company-match guard — all already correct.
- **Expected files:** new `Modules/Inventory/Transfer/Presentation/Http/Controllers/WarehouseTransferController.php` + `Requests/`, route registration in `routes/api.php`, a permission string (e.g. `inventory.transfer.create`) plus its grant.
- **Schema impact:** none for the core flow; recommend adding a caller-supplied idempotency key column (or reusing the existing `(reference_type, reference_id)` guard pattern) and a canonical lock ordering (e.g. by warehouse id) while touching this code.
- **Focused tests:** route reachability, cross-company rejection, FIFO-layer correctness at destination (largely already covered by the one existing test — extend it to the HTTP layer).
- **Dependency:** none. **Risk:** low.

### Slice 2 — Close the loading→vehicle double-count and driver-self-load gaps
- **Goal:** every load path either decrements the warehouse ledger atomically with the vehicle credit, or is explicitly disallowed.
- **Existing authorities reused:** `TransferLoadedStockToVehicleAction` (already correct for one path) as the template; `LoadingCustodyService::confirmReceived`'s transactional pattern.
- **Expected files:** `LoadProductAction`/`DriverLoadingController` (driver-self-load sub-path), `DispatchVehicleAction`/`LoadVehicleWorkflow` (pool/session deferred-decrement sub-path).
- **Schema impact:** likely none (behavioral fix), possibly a marker column distinguishing which of the (now two, ideally) paths produced a given custody row.
- **Focused tests:** double-count window test (assert warehouse on-hand decrements at load, not dispatch, for the pool path — or, if the business decision is to keep deferred-to-dispatch, assert the window is at least reconciled); driver-self-load now either transfers atomically or is rejected server-side.
- **Dependency:** none technically, but **the correct target behavior (decrement at load vs. at dispatch) is itself a business decision — see §9.**
- **Risk:** medium (touches a hot path with three existing call sites).

### Slice 3 — Gate custody settlement on warehouse-return reconciliation state
- **Goal:** `SettlementService::finalize()` (or `openSettlement()`) checks `VehicleShiftReconciliation` status before allowing a trip to close.
- **Existing authorities reused:** `VehicleShiftReconciliation` status machine (Open/Completed/Disputed/Approved) already exists; only the read/gate is missing.
- **Expected files:** `SettlementService.php`, possibly `Trip.php`'s `allStopsSettled()`-equivalent gate.
- **Schema impact:** none.
- **Focused tests:** settlement refused while reconciliation is Open/Disputed; settlement allowed once Approved (or per whatever the business decides — see §9).
- **Dependency:** **this is fundamentally a business-policy question first** (§9) — do not implement before that decision is made.
- **Risk:** medium (changes existing settlement UX; needs the business decision settled first).

### Slice 4 — Tenant-scope hardening on core Inventory and Loading custody models
- **Goal:** extend the already-proven `TenantOwnershipResolver` global-scope pattern to `InventoryItem`, `StockLedgerEntry`, `InventoryReceiptLayer`, and the five Loading custody models.
- **Existing authorities reused:** the exact pattern already live on `Warehouse`/`GoodsReceipt`/`Product`/`Order` — copy, don't invent.
- **Expected files:** each model's `booted()` method; audit call sites currently relying on manual `where('company_id', …)` for redundant-but-harmless overlap.
- **Schema impact:** none.
- **Focused tests:** a tenant-isolation regression test per model, mirroring the existing `WarehouseTenantIsolationTest`/`ProductTenantIsolationTest` pattern (closes the test-coverage gap in §7 simultaneously).
- **Dependency:** none. **Risk:** low-medium (a global scope can surface previously-hidden cross-company queries elsewhere — needs a full regression pass).

### Slice 5 — Fix the Inventory Control analytics cross-company exposure
- **Goal:** every query in `InventoryDashboardService`/`VarianceAnalyticsService`/`WarehousePerformanceService` filters by the acting company; the route requires an explicit permission, not just authentication.
- **Existing authorities reused:** `TenantOwnershipResolver::companyId()` for the filter value; the existing `permission:` middleware pattern used everywhere else in this module (e.g. `inventory.dashboard.view`).
- **Expected files:** the three services (add `where('company_id', ...)` / equivalent join-through-company to every raw query), `routes/api.php:1097-1103`.
- **Schema impact:** none.
- **Focused tests:** a cross-company isolation test asserting Company A's dashboard never surfaces Company B's product names/warehouse names/values.
- **Dependency:** none. **Risk:** low. **This should not wait for slice sequencing** — see §11.

---

## 9. Items Deferred to OPS-02/03/04 (or outside V1.1 entirely)

- **Delivery-execution-level postponement tracking + next-day targeting** (a `postponed_at`-equivalent for post-dispatch failures, mirroring what Preparation already has, plus an explicit "next attempt date" rather than "whenever the next sweep runs") — belongs to **OPS-02 (delivery/exception flow)**, not warehouse/inventory.
- **`DeliveryException` (ops/CS log) vs `FailureReason` (outcome taxonomy) consolidation** — two unlinked "something went wrong" vocabularies on the same stop; a documentation/reporting risk, not an inventory concern — **OPS-02**.
- **Driver-attributed liability** (party-bearing `WarehouseLiability` extension or a new model, with driver/vehicle/trip attribution, an explicit approval transition, and a monthly driver statement) — this already lives partly in `Inventory\WarehouseLiabilities` but is fundamentally a driver-financial-settlement concept — recommend **OPS-03 (internal shipping settlement)** own the design, with OPS-01 only providing the trigger (the `variance` already computed by `ReceiveVehicleReturnAction`).
- **Damage disposition record** (relaxing `WasteInvestigation`'s NOT-NULL count-session FK so return-sourced damage can be represented, or a dedicated disposition model) — touches Inventory\WasteInvestigations directly, so this could stay in **OPS-01** as a schema-relaxation slice once the business decides the shape (§10), or be folded into whichever track ends up owning driver liability, since the two are closely related.
- **Settlement/reconciliation-close coupling (Slice 3 above)** — the *code* is OPS-01/Loading-adjacent, but the *policy* (should cash settlement wait for physical reconciliation) is squarely **OPS-03**'s call; recommend joint sign-off.
- **`TripReturn` dispatcher-confirmation route restoration** — could be OPS-01 (it's a warehouse-receipt mechanism) or OPS-03 (it's part of trip/settlement lifecycle); needs owner triage before either track schedules it (§10).
- **`CustomerReturn` vs `DeliveryReturn` reconciliation** (shared idempotency key or an explicit decision that both running independently per channel is intended) — could be OPS-01 or OPS-02 depending on which team owns "Delivery OS"; needs triage.
- **`stock_balances` shadow ledger (Commerce\Fulfillments)** — **outside OPS-01 and outside this track's remit entirely**; flagged upward only.
- **`ShipmentGroup`/`ShipmentGroupItem` dead-code decision** (build out the per-carrier grouping feature, or remove it) — low priority, **outside the V1.1 plan** unless a specific need surfaces.
- **External carriers / `Logistics\Carriers` / `Logistics\ShippingCompanies`** — not investigated in this reconciliation at all; presumed **OPS-04**.

---

## 10. True Business Decisions Required

These are genuine "someone with product/ops authority must decide" questions — not technical gaps this reconciliation is positioned to resolve on its own.

1. **Should trip cash settlement be blocked while vehicle-return physical reconciliation is still open/disputed?** (§3.3, §8 Slice 3) The team itself posed this exact question on 2026-08-28 and it remains unanswered. This is an operational-policy call (does the business want to hold driver payouts hostage to a warehouse recount, or accept the current decoupling as intentional), not a pure engineering decision.
2. **Is the missing `TripReturn` dispatcher-confirmation route (§3.4) a deliberate removal (superseded by `ReceiveVehicleReturnAction`) or an accidental regression that must be restored?** Needs a direct answer before any implementation work touches authority (c).
3. **How should driver-attributed liability work** — attribution shape, approval authority, whether it's a `WarehouseLiability` extension or a new model, and how it feeds a monthly driver statement. Already recorded by the team as an explicit deferred decision (not discovered fresh here); still open.
4. **How should driver/vehicle-sourced damage be represented** — relax `WasteInvestigation`'s schema, or build a separate disposition record. Same status as #3: previously deferred, still open.
5. **Should `Order.status`/`order_lines` carry any signal at all for "goods came all the way back to the warehouse" vs. "delivery just failed, goods still out"?** Currently a full vehicle-custody return leaves zero trace on the Order — by design today, but worth an explicit confirmation that this remains the intended business shape as OPS-01/02 features build on top of it.
6. **Should `CustomerReturn` and `DeliveryReturn` be reconciled into one authority, or is running both independently (per sales/fulfillment channel) the intended architecture?** No existing decision record covers this pair, unlike the (b)/(c) split which is explicitly decided.
7. **Which database engine does production actually run** (§6)? Not a product decision, but a fact this reconciliation could not establish from source alone, and one that changes the severity of the `InventoryItem.findOrCreate()` concurrency note from "theoretical" to "live."

---

## 11. Recommended First Implementation Slice

**Slice 1 — Route the Warehouse Transfer engine.** It is the cleanest possible starting point: the entire engineering surface (locking, FIFO-layer preservation, company-match guard, audit trail) is already built and was independently verified correct; the only work is adding a Controller, routes, and a permission grant, plus closing the two small, low-risk hardening items (idempotency key, lock ordering) noted alongside it. It requires zero business decisions, touches no other in-flight authority, and delivers an immediately visible "Warehouse & Inventory Enhancement" capability that is currently invisible to every user of the system despite being fully built.

**Separately, and independent of slice sequencing:** §3 item 6 / §8 Slice 5 (the Inventory Control analytics cross-company exposure) is a live data-exposure issue in production-shaped code today, not a planned enhancement queued behind architecture sign-off. Recommend flagging it for immediate remediation on its own timeline regardless of which OPS-01 slice is formally scheduled first.

---

## STOP

Nothing implemented. No file outside this report was modified. No commit, no push, no deployment, no migration, no permission grant, no data mutation.

**Awaiting CTO review of §9's five implementation slices and §10's seven business decisions before any implementation begins.**
