# TASK-VEHICLE-CUSTODY-AUDIT-001 — VEHICLE INVENTORY + DRIVER CUSTODY ARCHITECTURE AUDIT

**Date:** 2026-08-25 · **Branch:** `develop` · **Mode:** STRICT READ-ONLY.
**No file, schema, or data was modified. No migration created or run. Nothing committed or deployed.**

---

## 1. Executive Summary

The vehicle custody layer **exists and is well-modelled on the outbound half**, but the loop is **not closed**, and it is **disconnected from the canonical inventory ledger**.

What works today:
- **Planned vs Actual loading is correctly modelled and fail-closed.** `loading_tasks` carries `quantity_planned`, `quantity_loaded`, `quantity_short`; loading more than planned is refused server-side. Custody receives the **actual** quantity, never the planned one. This satisfies the §2 business requirement.
- **Delivery decrements custody correctly, including partial delivery.** `on_hand = loaded − delivered − returned`, delta-based and idempotent.
- **Every custody mutation appends an immutable movement row** (`vehicle_inventory_movements`).

What does not work:
- **Warehouse stock is NOT deducted when goods move onto a vehicle.** Deduction happens later, at **dispatch**. Between loading and dispatch the same units are counted **twice** — on-hand in the warehouse *and* on the vehicle. **(P0)**
- **On the Group/driver path, warehouse stock may never be deducted at all**, because the dispatch-time deduction keys off `allocation_records`, which that path never creates. **(P0)**
- **The return leg does not exist.** `VehicleInventoryService::recordReturn()` has **zero callers**; `quantity_returned` is permanently 0; **nothing anywhere credits warehouse stock on return**. **(P0)**
- **Custody is attributed to a `vehicle_assignment`, not to a driver.** There is no driver on the custody row, and the only bridge to a person is unconstrained and in a different key space from the ledger Distribution treats as authoritative. **(P1)**
- **Driver waste, shortage, and driver liability do not exist as domain objects.** The warehouse waste table is structurally inventory-count-only, and `warehouse_liabilities` has **no party FK**. **(P2)**
- **No tenant global scope on any of the five custody tables.** **(P0-security)**

**The vehicle custody lifecycle is NOT financially or physically traceable through the existing inventory ledger.**

Status: **AUDIT COMPLETE — IMPLEMENTATION NOT STARTED.** Several owner decisions (§24) are required before any implementation task can be scoped.

---

## 2. Current Architecture

Two parallel stacks, joined only at dispatch:

```
CUSTODY STACK (Modules/Operations/Loading — quantity only, no cost)
  loading_sessions → vehicle_assignments → loading_tasks
                                    ↓
       vehicle_inventory_items  (+ vehicle_inventory_movements, append-only)
                                    ↓
       allocation_records (per vehicle_assignment × order_line)
                                    ↓
       vehicle_shift_reconciliations (+ _lines)   ← measurement only

CANONICAL LEDGER (Modules/Inventory — value + FIFO layers)
  inventory_items.on_hand_qty ─ stock_ledger_entries (warehouse_id NOT NULL)
        ▲ deducted only at DISPATCH, via
        DispatchVehicleAction → LoadVehicleWorkflow → ShipOrderInventoryAction → ShipStockAction
```

**Live data:** every custody table is **0 rows** in `ecos_dev` (`vehicle_inventory_items`, `_movements`, `allocation_records`, `loading_sessions`, `vehicle_assignments`, `vehicle_shift_reconciliations`, `_lines`, `warehouse_liabilities`). `stock_ledger_entries` = 38 rows, whose movement types are only `reservation` (34), `adjustment_in` (2), `purchase_receipt` (1), `reservation_release` (1) — **no loading/vehicle/delivery movement has ever been posted.** The custody stack has never been exercised end-to-end here.

---

## 3. `vehicle_inventory_items`

**Migration:** `Modules/Operations/Loading/Infrastructure/Database/Migrations/2026_07_05_120900_create_vehicle_inventory_items_table.php` · **Model:** `Domain/Models/VehicleInventoryItem.php`

**Identity — verified in live MySQL:**
```
uq_vehicle_inventory_items_assignment_product  UNIQUE (vehicle_assignment_id, product_id)
```
Custody is keyed to **(vehicle assignment × product)** — *not* to the vehicle over time, *not* to a trip, *not* to a driver.

**Columns (live):** `id`, `company_id`, `vehicle_assignment_id` (FK→`vehicle_assignments`, restrict), `vehicle_id`, `product_id`, `sku_snapshot`, `name_snapshot`, `operational_date`, `pool_entry_id`, `loading_task_id` (FK, restrict), `quantity_loaded`, `quantity_allocated`, `quantity_delivered`, `quantity_returned`, `quantity_on_hand`, `quantity_unallocated`, `requires_refrigeration`, `status`, `last_movement_at`, timestamps, `created_by`, `updated_by`.

**Quantity semantics** (from the sole writer, `VehicleInventoryService`):
| Column | Meaning | Written by |
|---|---|---|
| `quantity_loaded` | cumulative loaded onto this assignment | `recordLoad` (`+=` delta) |
| `quantity_on_hand` | still physically on the truck — **derived** `max(0, loaded − delivered − returned)` | `recordLoad`, `recordDelivery`, `recordReturn` |
| `quantity_unallocated` | free to earmark | `recordLoad` / `allocate` / `unallocate` |
| `quantity_allocated` | earmarked to orders (no physical movement) | `allocate` / `unallocate` |
| `quantity_delivered` | handed to customer | `recordDelivery` |
| `quantity_returned` | **dead — permanently 0** (writer has no callers) | `recordReturn` |

**There is no `quantity_damaged`, no `quantity_waste`, and no `quantity_shortage` column.** Shortage exists only as `loading_tasks.quantity_short` (planned−loaded, i.e. a *loading* shortfall, not a custody shortage). Variance exists only on `vehicle_shift_reconciliation_lines`.

**CHECK constraints confirmed present in live MySQL:** `quantity_loaded/allocated/delivered/returned/on_hand >= 0`, `status IN (active,depleted,returned,variance)`.

**Auditability:** timestamps + `created_by`/`updated_by`; **no soft delete**. Every mutation appends to `vehicle_inventory_movements` (append-only, `$timestamps = false`).

**Is it canonical for vehicle on-hand?** **Yes — it is the only store of vehicle on-hand quantity** (nothing else in the codebase holds it, and `Modules/Logistics/Distribution` never references it). **But it is canonical for quantity only** — it carries no cost, no layer, no ledger linkage, and it is not reconciled against `inventory_items`.

---

## 4. Stock Ledger Relationship

**The prior audit's finding is CONFIRMED: `vehicle_inventory_items` is a separate inventory engine that does not integrate with `stock_ledger_entries`.**

- Grep for `stock_ledger_entries|StockLedger|InventoryLayer|stock_movements` across the **entire** `Modules/Operations/Loading` tree → **no matches**. Repo-wide, `stock_ledger_entries` appears only in `Modules/Inventory`, `Commerce/Orders`, `Purchasing`, `Manufacturing`.
- `VehicleInventoryService` writes **exactly two tables**: `vehicle_inventory_items` and `vehicle_inventory_movements`.

**Warehouse → Vehicle (loading):**
| Effect | Present? |
|---|---|
| `stock_ledger_entries` row | **NO** |
| FIFO layer consumption | **NO** |
| transfer record | **NO** |
| `vehicle_inventory_items` | **YES** |
| `loading_tasks` (+ `vehicle_inventory_movements`) | **YES** |

Warehouse deduction happens **only at dispatch**: `DispatchVehicleAction:59 → LoadVehicleWorkflow:92 → ShipOrderInventoryAction:75/91 → ShipStockAction:88-106` (writes `on_hand_qty`, `reserved_qty`, and a `SalesIssue` ledger entry). Tellingly, the reason string written there is *"Inventory transferred to vehicle during loading"* — the ledger **claims** the transfer happened at loading but is written at dispatch.

**Vehicle → Warehouse (return):** **NO reverse movement exists anywhere.** Grep of the whole Loading tree for `InventoryItem|inventory_items|StockLedger|restock` returns nothing relevant.

**Structural constraint (decisive for Q4):** `stock_ledger_entries.warehouse_id` is **`char(36) NOT NULL`**. The canonical ledger is *warehouse-bound*, so a vehicle cannot be a ledger location without either (a) modelling the vehicle as a warehouse — which the business contract forbids — or (b) a schema change. `movement_type` is `varchar(255)` (not an enum), so **new movement types need no schema change**; only the location binding does.

**Verdict: the vehicle custody lifecycle is NOT traceable through the inventory ledger.** — **P0 / CONFLICTING**

---

## 5. Loading → Vehicle Custody

**Path:** `POST /api/loading/sessions/{s}/assignments/{a}/load-product` (`routes/api.php:982`) → `VehicleAssignmentController@loadProduct:88` (policy `operate` → `loading.session.operate`) → `LoadProductAction::execute()` → `VehicleInventoryService::recordLoad()`.
**Driver/Group path:** `POST /api/driver/loading/products/{productId}` (`api.php:3180`, `permission:loading.driver.operate`) → `DriverLoadingController@loadProduct:89`.

**Planned vs Actual — EXISTS and is fail-closed:**
```php
// LoadProductAction.php:71-77
if ($quantityLoaded - $quantityPlanned > self::EPSILON) { throw new RuntimeException(...); }
// :80
$quantityShort = max(0.0, $quantityPlanned - $quantityLoaded);
```
Custody receives **`quantity_loaded`** (actual), never `quantity_planned`. The §2 example (planned 20, loaded 18 → custody 18) is correctly implemented.

**Answer to the §5 multiple-choice: B (records the loading session + vehicle inventory) — NOT D.** It writes `loading_tasks`, `vehicle_inventory_items`, `vehicle_inventory_movements`, and `vehicle_assignments.loading_weight_kg`. It performs **no warehouse deduction**.

**Additional defects found on this path:**
- **Unit mismatch:** `$assignment->increment('loading_weight_kg', $delta)` (`:130`, `:168`) adds a **product quantity** into a **kilograms** column. **(P1)**
- **Negative-delta crash:** a downward correction passes a negative `$delta` into `appendMovement`, but the live CHECK `chk_vehicle_inventory_movements_quantity (quantity > 0)` **exists in MySQL** → the transaction aborts. **(P1 / UNSAFE)**
- **Pending migration:** the driver/Group path passes `poolEntryId: null, preparationWaveId: null`, but in `ecos_dev` `loading_tasks.pool_entry_id`, `loading_tasks.preparation_wave_id` and `vehicle_inventory_items.pool_entry_id` are all **NOT NULL**. The repo migration `2026_08_25_100000_allow_group_grain_loading_null_pool_provenance` that relaxes this is **present in the repo but NOT applied** (absent from the `migrations` table; latest batch 134). Until applied, group-grain driver loading **cannot insert**. **(P1 / BLOCKED-in-env)**

---

## 6. Delivery → Vehicle Inventory

**Path:** `POST /api/loading/sessions/{s}/assignments/{a}/allocation/deliver` (`api.php:998`) → `AllocationController@recordDelivery:176` (policy `allocate` → `loading.allocation.manage`) → `RecordProductDeliveryAction`.

- **Decrements custody: YES.** `RecordProductDeliveryAction:130 → VehicleInventoryService:141-145`, delta-based, under `lockForUpdate`, idempotent (absolute set, delta propagated).
- **Writes:** `allocation_records`, `vehicle_inventory_items`, `vehicle_inventory_movements`. **Does NOT write** `order_lines` or `stock_ledger_entries`.
- **Over-delivery: fails closed — but only against `quantity_allocated`** (`:91-97`, surfaced as 422). It is **never** checked against `quantity_on_hand` or `order_lines.quantity`. See the override hole in §22-F4.
- **`order_lines.delivered_qty`: ZERO WRITERS — prior finding CONFIRMED.** It is DDL'd (`2026_07_14_100001_add_fulfillment_quantities_to_order_lines.php:23`), `fillable` (`OrderLine.php:53`), and read by `OrderResource.php:204` and `DriverRuntimeController.php:530,534` — **never assigned**. Siblings `loaded_qty`, `returned_qty`, `cancelled_qty` are likewise write-free. The customer-facing order line permanently reads **0 delivered** while `allocation_records.quantity_delivered` advances. **(P1)**

---

## 7. Partial Delivery — CORRECT

The §7 business example is implemented correctly. Vehicle 10, order 10, delivers 6:
```php
// RecordProductDeliveryAction.php:104
$locked->quantity_remaining = $allocated - $quantityDelivered;   // 10 − 6 = 4
// VehicleInventoryService.php:142-145
$item->quantity_on_hand = max(0.0, $loaded - $delivered - $returned);  // 10 − 6 − 0 = 4
```
Status → `partial_delivery` (`:111-118`); item status stays `active`. The remaining 4 **stays in vehicle custody**, does not disappear, does not auto-return, and does not become shortage. **EXISTS / correct.**

---

## 8. Failed Delivery — does NOT wrongly decrement, but does NOT release either

- **Failed:** `DriverRuntimeController@stopAction:186-227 → DeliveryService::completeStop:94-104` writes **only the delivery stop row** and dispatches `DeliveryStopCompleted` — which has **zero listeners**. `Modules/Logistics/Distribution` never references `vehicle_inventory_items` in any file.
- **Cancelled:** `CancelLoadingSessionAction:28-44` writes only `loading_sessions`; `LoadingSessionCancelled` has **zero listeners**. No inventory reversal, no allocation unwind.
- **Dead states:** `AllocationRecordStatus::Failed` / `::Cancelled` exist in the enum **and in the live CHECK constraint**, but **no code ever writes them**. `RecordProductDeliveryAction::advanceTo` can only reach `Delivered` / `PartialDelivery`.

**So the §8 requirement ("vehicle remains 10") is satisfied by accident — nothing happens at all.** The mirror defect is real: goods on a failed stop remain **earmarked** (`quantity_allocated` untouched, `unallocate()` never called on this path) and the allocation record is **non-terminal forever**. **(P1 / PARTIAL)**

---

## 9. Vehicle → Warehouse Return — MISSING

**`VehicleInventoryService::recordReturn()` (`:172-201`) has ZERO CALLERS — prior finding CONFIRMED.** Every `recordReturn` grep hit is a *different* symbol (`VehicleShiftReconciliationController::recordReturn`, `Distribution\DeliveryController::recordReturn`, `Distribution\DeliveryService::recordReturn`). The codebase admits it in its own comment:

> `DriverDaySettlementReadService.php:496-498` — *"the return leg is never posted back to vehicle inventory (VehicleInventoryService::recordReturn has no callers), so quantity_on_hand reads as loaded − delivered."*

Three partial, disconnected "return" surfaces exist — **none credits warehouse stock**:
1. **Reconciliation count** (`api.php:1011` → `VehicleShiftReconciliationService::recordReturnedActual`) — writes only `vehicle_shift_reconciliation_lines`.
2. **Trip returns** (`api.php:1884` → `DeliveryService::recordReturn/confirmReturn`) — writes `distribution_trip_returns` (incl. a `driver_liable` boolean). Pure paperwork.
3. **Delivery-OS returns** (`DeliveryReturnController::receive`) — writes `delivery_return_lines` only.

Support for the §9 requirements: actual returned quantity **PARTIAL** (recorded in paperwork tables only) · warehouse receipt **MISSING** · vehicle inventory decrease **MISSING** · warehouse inventory increase **MISSING** · audit trail **PARTIAL** · partial returns **PARTIAL**. **(P0 / MISSING)**

---

## 10. Physical Reconciliation

`VehicleShiftReconciliationService` computes and stores only:
```php
:199  variance = loaded − delivered − returned
:126  quantity_returned_expected = max(0, loaded − delivered)
```
Header rollup sets `has_variance` per-line (correctly avoiding offsetting lines netting to a false zero).

**It writes no inventory, no warehouse stock, and no liability.** `vehicle_inventory_items` is read-only here. **There is no waste concept and no shortage concept.** The columns for closure exist but are **never written by any code**: `variance_resolution` (live CHECK allows `balanced|late_confirmed|written_off|under_investigation`), `resolved_by`, `resolved_at`, `reconciled_by`, `approved_by`, `completed_at`, `variance_notes`. There is **no approve/complete endpoint** — only `show`, `open`, `lines/{lineId}/return`.

Therefore **neither §10 example is implementable today**: expected 10 / returned 8 / damaged 2 cannot be recorded (no waste field), and shortage cannot be derived or escalated (no shortage field, no approval workflow, no liability link). Worse, because the return leg never posts back (§9), **variance is structurally guaranteed non-zero** unless an operator manually types the exact remainder into the reconciliation UI. **(P2 / MISSING)**

---

## 11. Waste

**Existing architecture:** `Modules/Inventory/WasteInvestigations` — `waste_investigations` (+ `waste_investigation_attachments`, `waste_investigation_events`).

**Can it represent DRIVER/VEHICLE waste? NO — the schema forbids it:**
```php
// 2026_07_06_200001_create_waste_investigations_table.php:21-22  (no ->nullable())
$table->uuid('count_session_id');
$table->uuid('count_line_id');
// 2026_07_20_100001_add_fks_to_waste_investigations.php:22-23
->references('id')->on('inventory_count_sessions')->restrictOnDelete();
->references('id')->on('inventory_count_lines')->restrictOnDelete();
```
Both are **NOT NULL with RESTRICT FKs to inventory-count entities**, and the table has **no** `vehicle_id`, `driver_id`, `trip_id`, or `vehicle_assignment_id`. A vehicle-origin waste has no inventory count, so it is **not representable** without either faking a count session or changing the schema. The only escape hatch is the untyped `metadata` JSON — not a structural representation.

Requirements coverage: Product ✅ · Quantity ✅ · Reason ✅ (`damage_reason`) · **Image ✅** (attachments exist: pdf/jpg/png/mp4, ≤20 MB, private `local` disk) · Investigation ✅ (two states only: `pending_investigation` → `resolved`) · Approval ❌ (no approval state).

**§11 classification: C — INCOMPATIBLE** for driver/vehicle waste as it stands. (A nullable-FK extension would be option B, but that is a schema change and an owner decision, not an audit conclusion.)

**`ResolveWasteInvestigationAction` — BOTH prior claims CONFIRMED, still present (NOT fixed):**
```php
// ResolveWasteInvestigationAction.php:114-115
'status' => 'approved',        // liability created ALREADY APPROVED
'approved_by' => $resolvedBy,  // client-supplied
// WasteInvestigationController.php:128,138
'resolved_by' => ['required','string','max:255'],  →  resolvedBy: $request->validated('resolved_by')
```
No `auth()` call in that controller; the approver identity is **free text from the caller**. Because `approved` is terminal, this also **bypasses** `ApproveWarehouseLiabilityAction` and its AdjustmentOut + FIFO consumption. **(P0 / UNSAFE — reported only, per §23.)**

---

## 12. Driver Liability

- **`warehouse_liabilities` has NO party FK — prior finding CONFIRMED.** The party is free text: `warehouse_manager` `string(255) nullable` (`:28`) and `approved_by` `string(255) nullable` (`:40`). Its FKs point only at `companies`, `warehouses`, `products`, `inventory_count_sessions`, `inventory_count_lines`, `waste_investigations`. **No `employee_id`, `driver_id`, or `user_id`.** A liability cannot be joined to a person.
- **No canonical driver liability model exists.** Grep for `driver_liabilit*`, `DriverLiability`, `driver_statement`, `driver_advance`, `driver_deduction`, `driver_balance` across the backend → **zero PHP source matches**. What exists is a **boolean flag**: `distribution_trip_returns.driver_liable` (client-settable via `DeliveryController:227`) — no amount, no currency, no cost snapshot, no approval state, no ledger.

**Minimum architecture needed** (reported, NOT built — requires owner decisions Q5–Q8):
1. A **party-bearing liability record** (driver identity FK + amount + cost snapshot + currency + status + approver + approved_at + period/month).
2. A **driver waste/shortage investigation** object able to reference a *custody* origin (vehicle assignment / trip) rather than an inventory count, carrying quantity + reason + image + investigation state.
3. An **explicit approval transition** (never auto-approved; approver = authenticated user, not client input).
4. A **driver statement / monthly closing** projection over approved liabilities + advances.
5. A **link from reconciliation shortage → investigation → approved liability**, so shortage becomes liability *only* through approval.

---

## 13. Driver Custody

**Structurally, custody attaches to a `vehicle_assignment` (vehicle + loading session) — NOT to a person.**

On the custody row: `vehicle_id` ✅, `vehicle_assignment_id` ✅ (FK), loading session (indirect, via `vehicle_assignments.loading_session_id`), **driver ❌**, **trip ❌**.

`vehicle_assignments` itself has **no `driver_id`**. Reaching a person requires `driver_assignments` (`vehicle_assignment_id` FK + `driver_id`), but that `driver_id` is a **bare uuid with no FK**, has **no unique index** on `vehicle_assignment_id` (multiple active driver rows possible), and its `reassigned_at/by/reason` columns are **written by no code**. Meanwhile `logistics_driver_vehicle_assignments` — the DB-enforced pairing ledger Distribution treats as authoritative — uses **bigint** keys against `logistics_drivers`/`logistics_vehicles`, a **different key space** from Loading's UUID `vehicle_id`. The two identity systems do not join. `distribution_trips` deliberately holds neither driver nor vehicle, only `driver_vehicle_assignment_id`, and has **no link to `vehicle_assignments` at all**.

`vehicle_inventory_movements.actor_id/actor_type` records **who performed the mutation**, not who holds custody.

**Conclusion:** the business requirement ("the driver is accountable for goods entrusted to them") is **not representable today** without an owner decision on attribution (Q1). No driver liability was invented. **(P1 / MISSING)**

---

## 14. Multiple Loading Cycles

**Supported — but only within a single `vehicle_assignment`.**

`VehicleInventoryService::recordLoad` uses `firstOrNew(['vehicle_assignment_id','product_id'])` and **increments** (`+= $quantity`). The caller normalises to an absolute set: `LoadProductAction` passes the **delta** for an existing task and the full quantity for a new one, so the item total always equals `loading_tasks.quantity_loaded`.

Consequences:
- The §14 example (10 on board + 15 new = 25) holds **only if both loads are on the same assignment**, and the second call states the new **cumulative** total for that task — otherwise the delta is negative, which (a) reduces on-hand and (b) trips the live `quantity > 0` movement CHECK (§5).
- A **new assignment starts from zero** (unique key is per assignment): stock does **not** carry over across assignments/days/trips for the same physical truck.
- **Per-operation auditability: PARTIAL.** Each `recordLoad` appends an immutable movement row (`loaded`, `reference_type=loading_task`, actor, timestamp), but `loading_tasks` is **overwritten in place** with no history, so the previously stated quantity is lost — only deltas survive.

---

## 15. Driver / Vehicle Changes

**Existing behaviour: reassignment is allowed with NO stock guard whatsoever.**

Reassignment paths: `GroupVehicleAssignmentService::assign:57-122`, `DriverVehicleAssignmentService::assign:35-107` (explicitly documented as "Change Vehicle"), `TripService::assignDriverVehicle:277-288`.

Every guard present concerns **capacity, tenancy, lifecycle status, or pairing uniqueness** — **never stock**:
- capacity: order-count vs `capacity_orders`; driver archived / vehicle status; vehicle already taken; assignment must be active.
- A grep for `quantity_on_hand` combined with any guard/throw across `Modules` returns **zero matches**. **Nothing in any reassignment path reads `vehicle_inventory_items` at all.**

The change-vehicle path silently closes the old pairing and releases the outgoing vehicle; `TripService::assignDriverVehicle` is a bare overwrite. So: **a driver can be swapped off a vehicle still holding non-zero `quantity_on_hand`; custody is neither blocked, transferred, nor recorded.** Because custody is keyed to the *assignment*, the goods remain attached to the **old** assignment while the person changes. **(P1 / UNSAFE — owner decisions Q2, Q3, Q11, Q12.)**

---

## 16. Tenancy

**All five custody models carry a `company_id` column but have NO tenant global scope.**

| Model | `company_id` | Global scope |
|---|---|---|
| `VehicleInventoryItem` | yes | **NONE** |
| `VehicleAssignment` | yes | **NONE** |
| `LoadingSession` | yes | **NONE** |
| `AllocationRecord` | yes | **NONE** |
| `VehicleShiftReconciliation` | yes | **NONE** |

`grep addGlobalScope` across the entire `Modules/Operations/Loading` tree → **zero matches**. `company_id` is not even an FK in the Loading migrations, and there is no index on it in `vehicle_inventory_items`. Isolation depends entirely on each call site remembering `->where('company_id', …)` — `LoadingSessionPolicy` and `VehicleAssignmentController::findSession` do enforce it on the session path, and delivery/reconciliation resolve through the session (so foreign ids 404). **But `VehicleInventoryService::recordLoad`'s `firstOrNew` key omits `company_id` entirely.**

Verdict for the §16 assertions: they hold **by convention on the audited HTTP paths only**, not structurally. This is materially weaker than the Logistics fleet models, which do have tenant global scopes. **(P0-security / PARTIAL)**

---

## 17. Audit Trail

| Movement | Who | When | Qty | Product | Trip | Vehicle | Driver | Source | Dest | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| Warehouse → Vehicle | ✅ actor_id | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ | ⚠️ warehouse implied, not recorded | ✅ vehicle | ⚠️ `short_reason` on task only |
| Vehicle → Customer | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | ⚠️ actor only | ✅ | ✅ order_id | ❌ |
| Vehicle → Warehouse | ❌ **no movement recorded at all** | ❌ | ⚠️ paperwork tables only | ⚠️ | ❌ | ⚠️ | ❌ | ❌ | ❌ | ⚠️ |

**Missing audit links:** (1) no `trip_id` on any custody row or movement; (2) **no driver identity** on custody or movements (actor ≠ custodian); (3) **no return movement**; (4) no source-warehouse reference on the load movement; (5) no cost/value dimension anywhere; (6) `loading_tasks` overwritten without history.

---

## 18. Finance

**Confirmed: `TripSettled` and `CodCollected` produce no financial posting — but NOT because Finance lacks a posting mechanism.**

> **Correction.** An earlier draft of this section stated that `Modules/Finance` has "no Listeners/Subscriber directory and no `listen(` registration at all." **That was wrong**, and is corrected here. A working Finance posting bridge exists and is **enabled by default**. The audit's conclusion is unchanged, but the reason is different — and sharper.

Independently verified:
- The bridge exists: `Modules/Finance/Integration/Application/Bridge/EventPostingSubscriber.php` + `EventPostingCatalog.php`, registered in `FinanceServiceProvider.php:128,188-205`.
- It is **ON by default**: `config/finance.php:44` — `'auto_subscribe' => env('FINANCE_AUTO_SUBSCRIBE', true)`, and `FINANCE_AUTO_SUBSCRIBE` is **not set** in `.env`/`.env.example`, so the default applies. (Note: the subscriber's own docblock still claims it is "off by default" — **the docblock is stale and contradicts the config**, which `FinanceServiceProvider:185-187` calls the single source of truth. Flagged, not fixed. Existing project memory recording "auto_subscribe OFF" is likewise stale.)

`TripSettled` / `CodCollected` still cannot reach Finance, for **three independent reasons**:
1. **Not in the catalog.** `EventPostingCatalog` maps exactly five keys — `pos.sale.finalized`, `pos.sale.refunded`, `inventory.stock.received`, `inventory.stock.transferred`, `inventory.stock.adjusted`. No logistics/distribution/trip/delivery/COD entry. The provider subscribes only `foreach ($catalog->knownEventNames() ...)`, so the subscriber never attaches to either event.
2. **Wrong bus.** The bridge subscribes on `EnterpriseEventBus`; both events use Laravel's `Dispatchable` (`SettlementService.php:208`, `CodCompletionService.php:74`). Different transports — cf. [Enterprise Event Bus] known behaviour.
3. **Wrong shape.** The subscriber duck-types on `eventName()`/`eventId()` and bails when either is null (`EventPostingSubscriber.php:50-54`); neither event implements them, so both would be dropped even if wired.

The only `TripSettled::class` / `CodCollected::class` references in the whole backend are in one test (`tests/Feature/Logistics/DeliveryModuleTest.php`). **Net effect: money collected at the door and trips settled produce no financial posting** — POS sales and three inventory event types do post, logistics settlement does not.

**Do vehicle custody movements affect valuation/COGS/ledger/wallet/cash/settlement?** **No.** `vehicle_inventory_movements` records quantity, type, reference, actor — **no unit cost, no total value, no currency, no cost method, no journal reference**. (Contrast: `waste_investigations` and `warehouse_liabilities` *do* carry cost snapshots.) COGS/ledger impact occurs only at dispatch, via the Inventory module, and is unaware of the vehicle. **(P2 / MISSING — reported only.)**

---

## 19. Existing UI

| Surface | Status | Evidence |
|---|---|---|
| **A. Loading** | **EXISTS** | `frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx` — per-product required vs loaded, confirm + complete. Back-office: `loading-os-workspace-page.tsx`. |
| **B. Vehicle Stock** | **MISSING** | No page/component/service call. Grep for `vehicle_inventory|vehicleInventory|vehicleStock` across `frontend/src` hits **one** back-office type file only. **The driver cannot see what is on their vehicle.** |
| **C. Return to Warehouse** | **PARTIAL** | Two overlapping *order-scoped* pages (`driver-returns-page.tsx`, `driver-custody-return-page.tsx`) + the back-office reconciliation count input. **None reconciles against vehicle stock.** |
| **D. Waste** | **MISSING** (driver) | Waste UI is back-office and inventory-count-scoped (`waste-investigations-page.tsx`). Driver nearest equivalent is a delivery exception of type `damaged` — not a waste investigation. |
| **E. Shortage** | **PARTIAL / display-only** | `custody-return-list.tsx:68` renders "Driver is liable for the shortage" from the `driver_liable` boolean. Read-only; no quantity, no value, no raise path. |
| **F. Driver Liability** | **MISSING** | No page, no route, no service call — consistent with §12 (no backend model to render). |

The approved future Driver UI (Home · Loading · **Vehicle Stock** · Orders · Started/Failed Delivery · Expenses · Wallet) is therefore missing exactly the custody-visibility screen. **Not built, per instruction.**

---

## 20. Business Rules Verification

| # | Rule | Status |
|---|---|---|
| 1 | Vehicle is NOT a Warehouse | ✅ **UPHELD** — separate tables; ledger is warehouse-bound |
| 2 | Actual loaded quantity controls custody | ✅ **UPHELD** — fail-closed over-load guard |
| 3 | Delivery decreases vehicle custody | ✅ **UPHELD** |
| 4 | Partial delivery leaves remainder in custody | ✅ **UPHELD** |
| 5 | Failed delivery does not consume goods | ✅ **UPHELD** (vacuously — nothing happens; allocation is never released) |
| 6 | Returned goods physically move back to Warehouse | ❌ **VIOLATED** — no return posting, no warehouse credit |
| 7 | Waste requires quantity + reason + image | ⚠️ **PARTIAL** — exists for warehouse counts only; **not representable for drivers** |
| 8 | Waste does NOT automatically become liability | ❌ **VIOLATED** — `ResolveWasteInvestigationAction` creates the liability **already approved** |
| 9 | Shortage → liability only after approved investigation | ❌ **MISSING** — no shortage object, no investigation link, no driver liability |
| 10 | Driver advances/liabilities need monthly reporting | ❌ **MISSING** — no driver liability model; `month` exists only on warehouse liabilities |
| 11 | Existing vehicle stock + new actual loading | ⚠️ **PARTIAL** — true within one assignment; resets across assignments |
| 12 | No automatic fabrication of inventory movements | ✅ **UPHELD** — custody moves only on explicit action |

---

## 21. Code Trace

| # | Flow | File · Class · Method | Tables | Event | Route |
|---|---|---|---|---|---|
| 1 | **Loading** | `Operations/Loading/Application/Actions/LoadProductAction::execute` (+ `VehicleAssignmentController@loadProduct`, `DriverLoadingController@loadProduct`) | `loading_tasks`, `vehicle_assignments` | — | `POST /api/loading/sessions/{s}/assignments/{a}/load-product` (api.php:982); `POST /api/driver/loading/products/{id}` (api.php:3180) |
| 2 | **Vehicle inventory** | `Loading/Domain/Services/VehicleInventoryService::recordLoad/allocate/unallocate/recordDelivery/recordReturn` · `Domain/Models/VehicleInventoryItem` | `vehicle_inventory_items`, `vehicle_inventory_movements` | — | `GET .../assignments/{a}/inventory` (api.php:1004) |
| 3 | **Delivery** | `Loading/Application/Actions/RecordProductDeliveryAction::execute` · `AllocationController@recordDelivery` | `allocation_records`, `vehicle_inventory_items`, `_movements` | — | `POST .../allocation/deliver` (api.php:998) |
| 4 | **Partial delivery** | same as (3), `:104` + `VehicleInventoryService:142-145` | same | — | same |
| 5 | **Failed delivery** | `Distribution/.../DriverRuntimeController@stopAction` → `Distribution/Domain/Services/DeliveryService::completeStop` | `distribution_delivery_stops` only | `DeliveryStopCompleted` (**0 listeners**) | driver stop-action route |
| 6 | **Return** | `VehicleInventoryService::recordReturn` (**0 callers**); `VehicleShiftReconciliationService::recordReturnedActual`; `DeliveryService::recordReturn/confirmReturn`; `DeliveryReturnController::receive` | `vehicle_shift_reconciliation_lines`; `distribution_trip_returns`; `delivery_return_lines` | — | `POST .../reconciliation/lines/{id}/return` (api.php:1011); `POST .../returns` (api.php:1884) |
| 7 | **Waste** | `Inventory/WasteInvestigations/Application/Actions/ResolveWasteInvestigationAction` · `WasteInvestigationController` | `waste_investigations` (+ attachments, events) | `WasteInvestigationEvent` rows | waste-investigation routes |
| 8 | **Liability** | `WarehouseLiabilities/.../ApproveWarehouseLiabilityAction` · `WarehouseLiabilityController` | `warehouse_liabilities` | — | warehouse-liability routes |
| 9 | **Driver assignment** | `Loading` `driver_assignments`; `Logistics/Drivers/Domain/Services/DriverVehicleAssignmentService::assign`; `Distribution/GroupVehicleAssignmentService::assign`; `TripService::assignDriverVehicle` | `driver_assignments`; `logistics_driver_vehicle_assignments`; `distribution_trips` | — | group assign-vehicle; driver-vehicle routes |
| 10 | **Trip closing** | `Distribution/Domain/Services/SettlementService` (+ `VehicleShiftReconciliationService` for the vehicle side) | `distribution_trips`, `vehicle_shift_reconciliations` | `TripSettled` (**0 listeners**) | settlement routes; `POST .../reconciliation/open` (api.php:1010) |

---

## 22. Findings

### P0 — financial / inventory safety
| ID | Finding | Class |
|---|---|---|
| F1 | **Double-count window:** goods are on the vehicle *and* still on-hand in the warehouse between loading and dispatch; nothing reconciles the two | CONFLICTING |
| F2 | **Group/driver path may never deduct warehouse stock at all** — `LoadVehicleWorkflow` keys off `allocation_records`, which that path never creates → `return []` → zero ledger impact ever | UNSAFE |
| F3 | **Return leg absent:** `recordReturn()` has zero callers; `quantity_returned` permanently 0; **nothing credits warehouse stock on return** | MISSING |
| F4 | **Allocation override bypasses the over-delivery ceiling:** `AllocationDecisionChainService:114-119` raises `quantity_allocated` with no ceiling and no re-earmark; delivery is then checked only against that value, and `max(0, …)` silently clamps `quantity_on_hand` to 0 — over-delivery absorbed invisibly | UNSAFE |
| F5 | **No tenant global scope** on any of the 5 custody models; `recordLoad`'s `firstOrNew` key omits `company_id` | PARTIAL |
| F6 | **`ResolveWasteInvestigationAction` creates a liability already `approved` with a client-supplied `approved_by`** — bypasses the approval control and the AdjustmentOut/FIFO path | UNSAFE |

### P1 — business correctness
| ID | Finding | Class |
|---|---|---|
| F7 | `order_lines.delivered_qty` / `loaded_qty` / `returned_qty` / `cancelled_qty` — **zero writers**; order lines permanently read 0 | MISSING |
| F8 | Failed/cancelled delivery **never releases** the allocation; `AllocationRecordStatus::Failed`/`Cancelled` are never written | PARTIAL |
| F9 | **Custody has no driver**; the only bridge (`driver_assignments.driver_id`) is unconstrained, non-unique, and in a different key space from the authoritative Logistics pairing ledger | MISSING |
| F10 | **No stock guard on driver/vehicle reassignment** — swap allowed with non-zero `quantity_on_hand` | UNSAFE |
| F11 | Negative-delta correction violates live CHECK `chk_vehicle_inventory_movements_quantity (quantity > 0)` → transaction abort | UNSAFE |
| F12 | `loading_weight_kg` incremented with a **product quantity**, not kilograms | CONFLICTING |
| F13 | Migration `2026_08_25_100000_allow_group_grain_loading_null_pool_provenance` **present in repo, NOT applied** to `ecos_dev`; group-grain driver loading cannot insert (NOT NULL `pool_entry_id`/`preparation_wave_id`) | BLOCKED |

### P2 — missing workflow
F14 No waste/shortage concept in vehicle custody (no columns) · F15 `waste_investigations` is inventory-count-only by schema → driver waste **not representable** · F16 No driver liability model; `warehouse_liabilities` has no party FK · F17 Reconciliation is measurement-only: no approve/complete endpoint, `variance_resolution`/`approved_by`/`completed_at` never written · F18 `TripSettled`/`CodCollected` are absent from the Finance bridge catalog (and are on the wrong bus / wrong shape), so logistics settlement + COD never post — while POS and inventory events do; custody itself has no cost dimension · F19 No monthly driver closing/statement.

### P3 — UI/UX
F20 **Vehicle Stock screen missing** for drivers · F21 Driver waste missing; shortage display-only; driver liability missing · F22 Two overlapping order-scoped return pages, neither reconciles vehicle stock.

### P4 — technical debt
F23 Dead columns/statuses (`quantity_returned`, `variance_resolution`, `Failed`/`Cancelled`, `reassigned_*`) · F24 `loading_tasks` overwritten in place, no history · F25 `DeliveryStopCompleted` / `LoadingSessionCancelled` events have no listeners · F26 Ledger reason string claims "transferred to vehicle during loading" but is written at dispatch.

---

## 23. Recommended Architecture (advisory — NOT implemented)

Reported for owner consideration only; every item below depends on the decisions in §24.

1. **Make the custody boundary a real inventory event.** Either (a) post Warehouse→Vehicle at *loading* as a transfer/issue and Vehicle→Warehouse at *return* as a receipt, or (b) keep the ledger warehouse-only and treat vehicle custody as an explicitly reconciled off-ledger location. **(a) requires resolving the `stock_ledger_entries.warehouse_id NOT NULL` constraint without turning the vehicle into a warehouse.** Closing F1/F3 requires this decision first.
2. **Close the return leg** by wiring the existing, already-written `recordReturn()` to the reconciliation/return surfaces, plus a warehouse receipt.
3. **Attribute custody to a person** by adding a driver dimension to the custody row (or by making `driver_assignments` a unique, FK-constrained, single-source bridge) — and reconcile the UUID vs bigint key spaces.
4. **Introduce driver waste + shortage as first-class custody objects** (quantity, reason, image, investigation, approval) rather than overloading the inventory-count waste tables.
5. **Introduce a party-bearing liability** with an explicit approval transition (approver = authenticated user), fed only from an approved investigation.
6. **Add tenant global scopes** to the five custody models.
7. **Add a Vehicle Stock read surface** for the driver.

---

## 24. Required Owner Decisions

The repository does **not** answer these; they must be decided before implementation.

| Q | Question | What the code says today |
|---|---|---|
| Q1 | Custody attributed to Vehicle, Driver, Trip, or Assignment? | **Assignment** (unique on `vehicle_assignment_id, product_id`); no driver, no trip on the row. Business intent (driver accountable) is unrepresented. **DECISION REQUIRED** |
| Q2 | Can Driver/Vehicle change while goods remain in custody? | Today: **yes, unguarded**. **DECISION REQUIRED** |
| Q3 | If yes, automatic transfer or physical handover? | Nothing exists. **DECISION REQUIRED** |
| Q4 | Should vehicle custody participate directly in stock ledger movements? | Today **no**. Constraint: `stock_ledger_entries.warehouse_id` is NOT NULL (`movement_type` is free-form varchar). **DECISION REQUIRED** |
| Q5 | How should driver waste be represented? | Warehouse waste tables are **incompatible** (NOT NULL count FKs). **DECISION REQUIRED** |
| Q6 | How should approved shortage become Driver Liability? | No shortage object, no driver liability, no link. **DECISION REQUIRED** |
| Q7 | Who approves driver waste? | No approval state exists (only pending→resolved). **DECISION REQUIRED** |
| Q8 | Who approves driver shortage/liability? | `approved_by` is client-supplied free text. **DECISION REQUIRED** |
| Q9 | Can a vehicle receive new loading while stock remains? | **Yes, within the same assignment** (increments); resets on a new assignment. Confirm intended. |
| Q10 | Vehicle stock when a Trip is cancelled? | Nothing happens; no listener. **DECISION REQUIRED** |
| Q11 | Vehicle stock when the Driver changes? | Stays attached to the old assignment; unguarded. **DECISION REQUIRED** |
| Q12 | Vehicle stock when the Vehicle changes? | Same as Q11. **DECISION REQUIRED** |
| Q13 | Should vehicle stock be FIFO-valued? | Custody has **no cost dimension at all**. **DECISION REQUIRED** |
| Q14 | Should monthly closing reconcile Loaded − Delivered − Returned − Approved Waste − Shortage? | Reconciliation computes only `loaded − delivered − returned`; no waste/shortage/closing. **DECISION REQUIRED** |

---

## 25. Proposed Implementation Tasks (NOT started)

Sequenced, each gated on the decisions above — listed for planning only:
1. **T1 (P0):** Close the return leg — wire `recordReturn()` + warehouse receipt. *(gated on Q4)*
2. **T2 (P0):** Resolve the double-count window / group-path non-deduction. *(gated on Q4)*
3. **T3 (P0):** Fix the allocation-override over-delivery hole (F4) + negative-delta crash (F11).
4. **T4 (P0-sec):** Add tenant global scopes to the five custody models (F5).
5. **T5 (P0-sec):** Repair `ResolveWasteInvestigationAction` approval bypass (F6) — *separate, already-known defect*.
6. **T6 (P1):** Custody→driver attribution + reassignment guard. *(gated on Q1, Q2, Q3, Q11, Q12)*
7. **T7 (P2):** Driver waste + shortage domain objects. *(gated on Q5, Q7)*
8. **T8 (P2):** Driver liability + approval + monthly closing. *(gated on Q6, Q8, Q14)*
9. **T9 (P1):** Write-back of `order_lines.delivered_qty` etc. (F7).
10. **T10 (P3):** Driver Vehicle Stock screen (F20).

---

## 26. Data Safety

Verified: **no INSERT, no UPDATE, no DELETE, no migration created or executed, no loading session, no inventory movement, no driver liability, no financial mutation.**

All my database access was `SELECT` / `information_schema` only. Row counts were re-checked after inspection and are **unchanged**: `vehicle_inventory_items` 0, `vehicle_inventory_movements` 0, `allocation_records` 0, `loading_sessions` 0, `vehicle_assignments` 0, `vehicle_shift_reconciliations` 0, `stock_ledger_entries` 38, `order_lines` 22, `warehouse_liabilities` 0. No source file was modified; the only artifact created is this report. No verification step required a write, so the §26 STOP condition was never reached.

---

## 27. Conclusion

The outbound half of the custody contract (**Warehouse → Actual Loading → Vehicle Custody → Delivery → decrement**) is implemented and behaves correctly, including planned-vs-actual and partial delivery. The **inbound half does not exist**: there is no return posting, no warehouse credit, no waste, no shortage, no liability, and no closing. Custody is also **structurally disconnected from the canonical inventory ledger** and from the **driver as a person**.

The most consequential findings are F1/F2/F3 (inventory truth), F4/F6 (control bypass), F5 (tenancy), and F9/F10 (custody attribution and unguarded reassignment).

**FINAL STATUS: AUDIT COMPLETE — IMPLEMENTATION NOT STARTED.**

Nothing was implemented, verified, or certified. No commit, no deploy. Owner decisions Q1–Q14 (§24) are required before any of the tasks in §25 can be scoped.
