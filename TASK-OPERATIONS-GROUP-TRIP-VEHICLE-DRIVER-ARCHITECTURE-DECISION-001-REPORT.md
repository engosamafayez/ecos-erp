# TASK-OPERATIONS-GROUP-TRIP-VEHICLE-DRIVER-ARCHITECTURE-DECISION-001 — DECISION REPORT

**Date:** 2026-08-21
**Status:** ARCHITECTURE DECISION — ANALYSIS ONLY. Nothing implemented. No backend, frontend, database, migration, route, permission or business-data change. No commit.
**Method:** every conclusion is derived from existing code and live schema, quoted. Where the code does not decide something, that is stated rather than inferred.

> **Note on the brief.** The task message was **truncated mid-Part 14**, at *"If partial fulfillment means a"*. I have analysed Parts 1–13 in full and interpreted Part 14 as *"audit the existing partial-fulfilment architecture and determine whether one Group → multiple Trips is required to support it."* If that misreads your intent, Part 14 (§14) is the only section to revisit. No report filename was specified in the surviving text; this file follows the task-id convention.

---

## 1. Executive Summary

### The decision

# **OPTION A — Group → Trip**

**Group is the planning/preparation unit. Trip is the execution/transport unit.** The hypothesis the brief asked me to test survives testing, and it survives on evidence rather than elegance.

The single most important finding is that **Option A requires no new transport object, no new lifecycle, no new assignment table, and no migration.** Every part of the execution half already exists, is routed, is permission-gated, and has been exercised in this database.

### Why the evidence is stronger than expected

I previously reported that Trip was "wired but never run". **That was wrong**, and the correction matters to this decision. Auto-increment counters show the whole execution stack has been used heavily and then wiped by a fresh-data reset:

| Table | AUTO_INCREMENT | Live rows |
|---|---|---|
| `logistics_vehicles` | **580** | 0 |
| `logistics_drivers` | **396** | 0 |
| `distribution_trips` | **273** | 0 |
| `logistics_driver_vehicle_assignments` | **209** | 0 |
| `distribution_trip_orders` | **148** | 0 |
| `distribution_delivery_stops` | **121** | 0 |

Trip is not a speculative design. It is a proven, exercised subsystem whose data was cleared.

### The four load-bearing facts

1. **Vehicle and Driver are never assigned separately.** `DispatchReleaseService::releaseOne()` states the ownership in its own comments — Drivers (LOG-002) owns the pairing; Distribution (LOG-004B) owns the trip. The Trip holds **one** reference to **one** pairing.
2. **Trip already owns the entire execution lifecycle** — 13 states from `planning` to `closed`, with editability rules, capacity enforcement, dispatch blockers, driver acceptance, custody and settlement.
3. **A Trip is cheap to create** — only `company_id` and `name` are required; `capacity` defaults to 60, `type` to `company_vehicle`, `status` to `planning`, and wave/zone/shipping-company/driver-vehicle are **all nullable**.
4. **`distribution_trips.finalized_at` already exists, is fillable, is cast, is exposed by `TripResource` — and nothing writes it.** Finalize has a home that needs no migration.

### Why Options B and C are rejected

- **Option B (competing lifecycles)** would build a second 13-state machine beside Trip's, in the same module, duplicating vehicle/driver assignment, dispatch readiness and finalize. It is the only option that requires a migration on a certified table, and it is the option this codebase has repeatedly refused — `slotSummaries` reports `'status' => 'draft'` as a literal precisely to avoid *"inventing a status column or a second state machine."*
- **Option C (Trip → Group)** inverts the observed dependency. Trip is warehouse-agnostic and order-anchored; Group is warehouse-owned and zone-anchored. Making Trip own Group membership would move warehouse ownership into an object that has no warehouse column.

### What Option A does NOT give you — stated plainly

Choosing Option A does **not** deliver a working end-to-end fulfilment path, and it would be dishonest to imply otherwise:

- **`TripDispatched` has zero listeners.** Dispatching a Trip stamps the Trip and changes **no Order status and no inventory**.
- The only path that actually fulfils — deducting on-hand and reserved, writing an immutable `sales_issue` ledger row, consuming FIFO layers, moving orders to `out_for_delivery` — is `Operations/Loading`'s `DispatchVehicleAction → LoadVehicleWorkflow`, which is behind **BLOCKER VP-1** (uuid/bigint divergence) and is untested.

So Option A cleanly delivers **Group → Finalize → Trip → Vehicle + Driver → dispatch status**. It does **not** on its own deliver **Loading → inventory → order lifecycle**. That remains gated on VP-1, which this task is explicitly forbidden to solve.

---

## 2. Part 1 — Group Responsibility

**Confirmed: Group remains the planning source of truth. Nothing should take ownership from it.**

| Group owns | Where | Verdict |
|---|---|---|
| Warehouse | `distribution_virtual_slots.warehouse_id` NOT NULL | **keep** — certified Part 5B; Trip has no warehouse column at all |
| Zones | `distribution_slot_zones`, unique `(window, warehouse, zone)` | **keep** — Trip's zone is a nullable descriptor, not a membership rule |
| Order membership | `distribution_window_orders.virtual_slot_id`, unique `(order_id)` | **keep** |
| Required | live `productAggregation` | **keep** — never stored, never duplicated |
| Prepared | `distribution_group_product_preparation`, unique `(virtual_slot_id, product_id)` | **keep** |
| Loading Preparation | the LP-1/LP-2 surface | **keep** |

**Neither Vehicle nor Trip should ever own Group membership, Required or Prepared.** Trip's own order table (`distribution_trip_orders`) is an *execution manifest*, not a planning membership: it carries `zone_code_snapshot` and `governorate_snapshot` — **snapshots**, the platform's established idiom for "a copy of a fact owned elsewhere".

---

## 3. Part 2 — Trip Responsibility

**Confirmed: Trip is already the canonical execution object. Do not build another.**

| Concern | Trip provides |
|---|---|
| Vehicle + Driver | `driver_vehicle_assignment_id` → `logistics_driver_vehicle_assignments` (canonical LOG-002 pairing) |
| Shipping operation | `shipping_company_id` → `logistics_shipping_companies`; `TripType::ExternalCarrier` |
| Loading | `TripStatus::Loading`, `LoadingCompleted` |
| Dispatch | `TripStatus::Dispatched`, `dispatched_at/by`, `dispatchBlockers()`, `GET /trips/{id}/dispatch-readiness` |
| Route | `distribution_delivery_stops`, `RoutePlan`/`RoutePlanStop` |
| Transport lifecycle | 13 states, `allowedTransitions()`, `isEditable()`, `isTerminal()`, `isOnTheRoad()` |
| Custody + settlement | `distribution_trip_custody`, `distribution_trip_settlements`, driver acceptance triple |

**Can Trip represent "this Group has been finalized for transport"?** **Yes** — `TripStatus::Planning → Loading` is exactly that transition (`isEditable()` = `[Planning, Loading]`, comment: *"Orders and custody may only be edited while the trip is still being built"*), and `finalized_at` exists unused as an explicit stamp.

---

## 4. Part 3 — Group → Trip Cardinality

### 4.0 The finding that frames everything else

**There is no Group→Trip relation in this codebase at all** — not a column, not a foreign key, not a join, not a query, not a route. `distribution_trips` has no `virtual_slot_id`, no `distribution_window_id` and no `warehouse_id`. A repo-wide grep for `virtual_slot_id|VirtualCapacitySlot` hits **15 files, every one Group-side**; `Trip.php`, `TripService.php`, `TripController.php` and `TripResource.php` appear in none. A scripted cross-check found **zero** files referencing both `TripOrder`/`distribution_trip_orders` and `DistributionWindowOrder`/`distribution_window_orders`.

**So whatever cardinality you approve is a net-new contract.** There is no column to reinterpret, no index to drop, no query to rewrite and no test to break. You are not reconciling two designs — you are writing the first one.

### 4.1 What the schema permits

Both memberships are globally unique per order:

```sql
distribution_window_orders  UNIQUE (order_id)   -- an order is in at most ONE Group
distribution_trip_orders    UNIQUE (order_id)   -- an order is on at most ONE Trip
```

`TripService::assignOrder` enforces the Trip half under a lock and refuses an order already on another trip (`DistributionException::orderAlreadyOnAnotherTrip`).

Because membership is **per order** on both sides, and a Trip's `distribution_zone_id` is nullable and descriptive, the schema permits a Group's orders to be partitioned across several Trips, and permits one Trip to draw orders from several Groups. **The schema does not enforce 1:1.**

### 4.2 What capacity forces

`Trip.capacity` is NOT NULL, defaults to **60**, and is **enforced**: `assignOrder` refuses at `isAtCapacity()`. `Group.capacity_orders` is nullable and enforced nowhere.

So a Group holding more orders than a Trip's capacity **cannot** be expressed as one Trip. Splitting is not a preference — it is forced by an existing, enforced constraint.

**But note what capacity is NOT.** `Trip.capacity` is **not derived from the vehicle**. The dependency runs the other way: the Trip declares a requirement and the fleet supplies it — `AssignmentScoringService::capacityFit()` sets `$required = $trip->capacity`, `$available = $vehicle['capacity_orders']`, and rewards the smallest vehicle that fits; `DispatchProposalService` raises a hard `SOURCE_CAPACITY` blocker when the vehicle is smaller. Critically **that check lives only in the advisory Dispatch proposal path** — `Trip::dispatchBlockers()`, the gate the status machine actually enforces, never checks vehicle capacity, and `assignDriverVehicle()` only checks that the pairing is active. An operator can today set `capacity = 9999` on a trip whose van holds 60. The three matching `60` defaults (`trips.capacity`, `logistics_vehicles.capacity_orders`, `VehicleType::Van`) are independent literals, not a derivation.

### 4.3 The decision

> ## **1 Group → 1..N Trips**
> **1:1 in the common case. Never many Groups → one Trip.**

| Cardinality | Verdict | Reason |
|---|---|---|
| 1 Group → 1 Trip | **the normal case** | A Group is *"the orders that will move together"*; one vehicle, one journey |
| 1 Group → many Trips | **must be supported** | Forced arithmetically whenever the Group's order count exceeds a Trip's enforced `capacity`. Not optional |
| many Groups → 1 Trip | **reject** | Permitted by the schema, but it destroys the Group's meaning. A Group is **warehouse-owned**; merging two Groups into one Trip would put two warehouses' work on one vehicle with nothing recording that — see §5, where `assignOrder` never reads `assigned_warehouse_id` |
| many ↔ many | **reject** | The union of the two above; no business case found |

**Can one Group be split across multiple Trips? Yes — and it must be able to be**, because `Trip.capacity` is NOT NULL and enforced at both write paths (`assignOrder`, `moveOrder`) while `Group.capacity_orders` is nullable and enforced nowhere. A Group of 200 orders simply cannot be one Trip of default capacity 60.

**Can one Trip contain multiple Groups? Physically yes, architecturally no.** Nothing in the schema prevents it, but nothing records it either, and it would silently mix warehouses. Reject it as policy and enforce it in the Finalize action.

**What must NOT be used as the Group link:**

- **`distribution_trips.distribution_zone_id`** — nullable, no Eloquent relation, never compared against an order's zone at assignment, and populated from free-text `zone_code_snapshot` the operator types by hand. `AssignmentScoringService::zoneAffinity()` is a hard-coded `return 0.5;` placeholder. Worse, its grain is wrong: a Group is `(window, warehouse, zone)` and holds **many** zones, so one zone id cannot name a multi-zone Group and cannot distinguish two warehouses' Groups over the same zone — **exactly the defect the Part 5B unique index `dist_slot_zones_window_wh_zone_unique` exists to close.** Using it would silently reintroduce that defect.
- **`distribution_trips.preparation_wave_id`** — also nullable and descriptive. And the grains are incompatible by the Group module's own stated rule: a Group's orders *"can span several active waves, so binding a Group fact to one wave would be false"*, while a Trip holds at most one.

**An explicit link is therefore required** — a new nullable `virtual_slot_id` on `distribution_trips`, or a join table if many-to-many is ever approved. That is a migration, and it is the one this decision authorises costing (not writing).

---

## 5. Part 4 — Vehicle Ownership

**Canonical owner: `logistics_driver_vehicle_assignments`, referenced by Trip. Not Group. Not Loading's `VehicleAssignment`.**

`DispatchReleaseService::releaseOne()` names the owners in code:

```php
// ── 1. Drivers (LOG-002) owns the pairing ────────────────────
$v1Assignment = $this->assignments->assign($driver, $vehicle, notes: …);

// ── 2. Distribution (LOG-004B) owns the trip ─────────────────
$updatedTrip = $this->trips->assignDriverVehicle($trip, $v1Assignment->id);
```

`TripController::assignDriverVehicle` validates it properly:

```php
'driver_vehicle_assignment_id' => ['required', 'integer', 'exists:logistics_driver_vehicle_assignments,id'],
```

**`Operations/Loading`'s `vehicle_assignments` is explicitly NOT the canonical vehicle relation** — `vehicle_id` is an unconstrained `char(36)` against a `bigint` identity, with no FK, no ownership check, and mandatory weight/volume snapshots. That is BLOCKER VP-1, and this task does not touch it.

**Do not add `distribution_virtual_slots.vehicle_id`.** The Group table's own creating migration states the boundary: a Slot *"is PLANNING capacity, not a Vehicle… No vehicle_id or driver_id column exists here, and that absence is deliberate."*

---

## 6. Part 5 — Driver Ownership

**Canonical owner: the same pairing. Driver belongs to the Trip, through `logistics_driver_vehicle_assignments`. Not to the Group.**

Evaluated against `releaseOne()` exactly as the brief directs: the driver is never attached alone. `DriverVehicleAssignmentService::assign()` creates the pairing under the DB invariant *one driver ↔ one vehicle* (unique indexes on `(driver_id, active_flag)` and `(vehicle_id, active_flag)`), and the Trip then references it.

**Do not create `group_driver`, and do not add `distribution_virtual_slots.driver_id` merely so the UI can show a driver.** The Group card's existing inert `Driver: Not assigned` row should render the driver **of the Group's Trip**, resolved through the relation — a read, not a new column.

---

## 7. Part 6 — Vehicle + Driver Combination

**Answer: D/E — an existing canonical structure.**

```
Driver ──┐
         ├──► logistics_driver_vehicle_assignments  (LOG-002 owns the pairing)
Vehicle ─┘                    │
                              │ ONE bigint reference
                              ▼
                distribution_trips.driver_vehicle_assignment_id
```

Rejected: **A** (a separate "trip assignment" object — the pairing already is one), **B** (Loading's `VehicleAssignment` — VP-1), **C** (Group assignment — contradicts the Group's stated boundary).

`TripType::requiresDriverVehicleAssignment()` returns false only for `ExternalCarrier`, so a carrier-operated Trip legitimately has no pairing — which is why `driver_vehicle_assignment_id` is nullable and why `dispatchBlockers()` checks the type before demanding one.

---

## 8. Part 7 — Finalize

**Finalize is a Trip transition, not a Group state.**

> **Finalize(Group)** = *the Group is complete as a planning unit* → **produce or attach its Trip(s)**, move each Trip `Planning → Loading`, and stamp `finalized_at`.

Mapped to the brief's options: **A + C together** — *create Trip (if none attached)* and *transition Trip*. Explicitly **not D** (transition Group only), because the Group has no state to transition and giving it one is Option B.

**This requires no migration.** `finalized_at` / `finalized_by` already exist on `distribution_trips`, are fillable, are cast to `datetime`, and are exposed by `TripResource` — **and nothing writes them today**. The column is waiting for exactly this action.

**Prerequisites are already computable** by `Trip::dispatchBlockers()`, which is the "READY TO FINALIZE" summary the earlier brief asked for: orders assigned, driver acceptance complete, and a linked *active* driver/vehicle assignment when the type requires one.

---

## 9. Part 8 — Trip Creation

**Answer: D — created or attached at Finalize.**

Creation is cheap and non-committal, which is what makes Finalize the right moment rather than an early one:

| Field | Requirement |
|---|---|
| `company_id`, `name` | required |
| `trip_number` | generated (`TripService::nextTripNumber`) |
| `capacity` | default **60** |
| `type` | default `company_vehicle` |
| `status` | default `planning` |
| `preparation_wave_id`, `distribution_zone_id`, `shipping_company_id`, `driver_vehicle_assignment_id` | **all nullable** |

Creating at Group creation (A) would produce empty Trips for Groups that never depart. Creating at vehicle/driver selection (B/C) inverts the dependency — the pairing attaches *to* a Trip. Creating at Loading (E) is too late; Loading needs the Trip to already exist.

**Nothing today creates a Trip from Distribution data.** `DistributionAggregationService`, `DistributionCollectionService` and `ManualAssignmentService` contain **zero** references to Trip. Trips are created only through `TripController::store` or referenced by Dispatch's release path. Group and Trip are currently disjoint siblings in one module — which is precisely the gap this decision closes.

---

## 10. Part 9 — Loading Boundary

### 10.1 The distinction is confirmed and must be preserved

| Loading Preparation (LP-1/LP-2) | Actual Loading |
|---|---|
| Required (live) · Prepared (stored) · Remaining (derived) | physically loaded quantity |
| `(Group, Product)` grain | `(vehicle_assignment, product)` grain |
| No inventory effect, ever | vehicle inventory at load; **warehouse inventory at dispatch** |

### 10.2 How Loading identifies things today, and the minimum link required

**There is no Trip↔Loading relationship in code.** An exhaustive grep of `Modules/Operations/Loading` returns **zero** occurrences of `trip_id`, `distribution_trips`, or the `Modules\Logistics\Distribution` namespace — the only `trip` substrings are the enum value `DriverAssignmentStatus::OnTrip` and one permission description. The reverse grep is equally clean. No Loading table carries a trip column.

| Loading identifies | By |
|---|---|
| Vehicle | `vehicle_assignments.vehicle_id` — unconstrained `char(36)`, **BLOCKER VP-1** |
| Driver | `driver_assignments.driver_id` — unconstrained `char(36)`, same blocker |
| Orders | `allocation_records.order_id` + `order_line_id`, `unique(vehicle_assignment_id, order_line_id)`, **no FKs**. Written in exactly one place: `AutoAllocationService:177` |
| Loading tasks | `loading_tasks`, keyed to `vehicle_assignment_id` |

**Loading never receives an order list from upstream — it re-derives one.** `AutoAllocationService::resolveOrdersForAssignment()` starts from the **preparation wave**, then takes `OrderLine::where('order_id',…)->whereIn('product_id', $productIds)` where `$productIds` are the products physically on the vehicle (`vehicle_inventory_items.quantity_unallocated > 0`).

**So the only existing shared key between a Trip and Actual Loading is `preparation_wave_id`** — and it is not trip-identifying. Many trips can share a wave; the index on `(preparation_wave_id, distribution_zone_id, status)` is non-unique.

**Minimum link options** (enumerated, not recommended — this decision does not authorise choosing one):

1. add `trip_id` to `loading_sessions` or `vehicle_assignments`;
2. drive Loading from the Trip's `preparation_wave_id` and accept that a session covers *all* trips in that wave;
3. leave Loading alone and let the Trip carry loading state through `TripStatus::Loading` / `LoadingCompleted` without ever creating a `loading_session`.

**Option 3 deserves serious consideration**, because it is the only one that does not touch VP-1 — but it forfeits `loading_tasks`, allocation records and the inventory-mutating dispatch. That trade is a separate decision.

**Correction to my earlier reporting:** I previously wrote that the Loading module has no test coverage. That is **false** — `tests/Feature/Operations/` contains `VehicleShiftReconciliationTest`, `VehicleShiftReconciliationHttpTest` and `RecordProductDeliveryHttpTest`: 3 files, ~1,222 lines, 37 test methods that construct real `LoadingSession` and `VehicleAssignment` rows and drive `LoadProductAction`, `RecordProductDeliveryAction` and the reconciliation service. What remains uncovered is specifically the **dispatch** wiring (`DispatchVehicleAction`, `LoadVehicleWorkflow`) — the inventory-mutating step.

---

## 11. Part 10 — Dispatch Boundary

**The existing path, and what each step actually does:**

```
Trip ──► TripService::changeStatus(→ Dispatched) ──► stamps dispatched_at, fires TripDispatched
                                                     └── ZERO listeners → NO order, NO inventory effect

VehicleAssignment ──► DispatchVehicleAction ──► LoadVehicleWorkflow ──► INVENTORY MUTATION
                      POST /loading/sessions/{s}/assignments/{a}/dispatch
```

**Dispatch expects two different objects, and they are unconnected.** The Trip is the canonical dispatch *subject*; the Loading `VehicleAssignment` is where fulfilment actually happens. `Modules/Logistics/Dispatch` (42 routes) dispatches neither — its terminal act only pairs driver+vehicle onto a Trip.

**Do not create a second Dispatch path.** The eventual connection must go through the existing Loading dispatch, which is gated on VP-1.

---

## 12. Part 11 — Inventory Boundary

**Confirmed, and this is the section that exists to stop inventory mutation leaking into Loading Preparation.**

| Step | Warehouse inventory |
|---|---|
| Loading Preparation (Prepared) | **NONE** — certified in LP-2 |
| `load-product` (Actual Loading) | **NONE** — `VehicleInventoryService` writes only `vehicle_inventory_items` / `_movements`; the Loading module never names `inventory_items` |
| `complete-loading` | **NONE** — flips session status, fires `VehicleLoaded` (zero listeners) |
| **`.../assignments/{a}/dispatch`** | **YES — all of it** |

At dispatch, in one transaction, `LoadVehicleWorkflow`:

- decrements `inventory_items.on_hand_qty` **and** `reserved_qty`
- appends an **immutable** `sales_issue` row to `stock_ledger_entries`
- consumes FIFO `inventory_receipt_layers`
- stamps `actual_cogs_amount` / margin
- moves every order to `out_for_delivery`

It has **zero test coverage**, and recovery requires a two-leg compensating transaction.

**Nothing was modified. The operative rule for implementation: inventory mutation belongs at dispatch and nowhere earlier.**

---

## 13. Part 12 — Order Lifecycle

### 13.0 The answer: Dispatch alone owns the transition, and it is structurally enforced

**`orders.status` is owned exclusively by `Operations\Fulfillment`, and the ownership is enforced by the model itself, not by convention:**

```php
// Order::booted() — throws UnauthorizedOrderStatusWriteException on any dirty
// `status` while OrderStatusGuard is inactive.
```

Only `FulfillmentEngine::run()` and `LoadVehicleWorkflow` ever activate that guard.

**Exactly two code paths in the entire backend write `OrderStatus::OutForDelivery`:**

| # | Writer | Reached from |
|---|---|---|
| 1 | `DispatchOrderWorkflow:50` — single order, guarded to source `ReadyForDispatch` | `POST /fulfillment/orders/{order}/dispatch`, the transition endpoint, `POST /fulfillment/bulk/dispatch`, and `PatchOrderAction` — all through `FulfillmentEngine::run()` |
| 2 | `LoadVehicleWorkflow:94` — **batch, per vehicle assignment** | only `DispatchVehicleAction:59` ← `VehicleAssignmentController::dispatch` ← `POST /loading/sessions/{s}/assignments/{a}/dispatch` |

**Neither Group nor Trip writes an Order status.** `TripService::changeStatus` writes only `distribution_trips`; `TripDispatched` has **zero listeners**; and no file in `Modules/Logistics` writes `orders.status` at all.

### 13.1 Consequence for the decision

**Group/Trip lifecycle must NOT touch Order status.** Under Option A, finalizing a Group and dispatching its Trip changes **no order**. Orders reach `out_for_delivery` only through writer #2 — the Loading dispatch — which is the same call that mutates inventory (§12).

That is coherent, and it is the correct boundary: *planning objects plan; fulfilment moves orders*. **No new Order status is required, and none is proposed.**

### 13.2 What is already established

- **No new Order status is required or proposed.**
- `ready_for_dispatch` is set by `MoveToPreparationWorkflow` when a preparation wave starts.
- **Distribution never writes `orders.status`** — `DistributionCollectionService` states it: *"This class never touches `orders`."*
- **Trip dispatch does not move orders** — `TripDispatched` has zero listeners.
- `out_for_delivery` is written by the Loading dispatch path (`LoadVehicleWorkflow`).

---

## 14. Part 13 — Group Membership After Finalize

**Answered entirely by existing lifecycle architecture. No Reopen needs inventing — one already exists.**

This corrects my previous report, which said no Reopen mechanism existed. That was true **of the Group**; it is not true of the Trip:

```php
// TripStatus::allowedTransitions()
self::Loading           => [self::LoadingCompleted, self::Planning, self::Cancelled],   // ← REOPEN
self::LoadingCompleted  => [self::DriverAccepted, self::DispatchBlocked, self::Loading, self::Cancelled],
self::DispatchBlocked   => [self::LoadingCompleted, self::DriverAccepted, self::Cancelled],

// "Orders and custody may only be edited while the trip is still being built."
public function isEditable(): bool { return in_array($this, [self::Planning, self::Loading], true); }
```

| Question | Answer under Option A |
|---|---|
| Can Zone be added/removed after Finalize? | **Group-side yes; Trip-side gated.** The Trip refuses order changes once past `Loading` (`isEditable()`), via `DistributionException::tripNotEditable` |
| Can orders enter/leave? | Same gate — enforced by `assignOrder` / `removeOrder` |
| Can Vehicle/Driver change? | Yes, by re-pointing `driver_vehicle_assignment_id`; the pairing's own `active_flag` invariant prevents duplicates |
| Can the Group be reopened? | **Yes — `Loading → Planning` is an allowed transition** |
| Can the Trip be detached? | Not modelled today. `distribution_trip_orders` rows can be removed while editable; detaching a whole Trip from a Group is undefined because the link itself does not yet exist |

**The one genuine gap:** the Group→Trip link does not exist, so *"a finalized Group must not silently change"* has nothing to enforce it **on the Group side**. Under Option A this is enforced at the Trip, which is where composition actually matters. Whether Group-side zone edits should also be blocked once its Trips are past `Loading` is a **policy question for implementation**, not an architecture gap — the mechanism (`isEditable()`) already exists to consult.

---

## 15. Part 14 — Split / Partial Fulfilment

> *Interpreted from a truncated brief — see the note at the head of this report.*

**Answer: partial fulfilment does NOT require 1 Group → many Trips.** It is handled two ways today, neither of which is a split shipment.

**1. Short quantities recorded in place.** The platform records shortfall at five grains rather than re-shipping it: `allocation_records.is_partial` / `partial_reason` / `quantity_allocated` vs `quantity_requested`; `AllocationPolicyService::allowsPartialAllocation()` and `maxPartialTolerancePct()`; `LoadingTaskStatus::ShortLoaded` + `quantity_short`; `wave_product_demand.shortage_decision` (*"continue despite shortage"*); and `CustomerReturn`. **The shortfall is disposed of by RETURNING it, never by re-shipping it.** `DeliveryStatus::PartiallyDelivered`'s only allowed transitions are `[Returning, Delivered]`.

Every outbound cardinality is 1:1 at the database level — `distribution_trip_orders.unique(order_id)`, `distribution_window_orders.unique(order_id)`, `delivery_deliveries.unique(order_id)`.

**2. Carry-over into the NEXT WAVE — a correction.** My analysis initially claimed *"no code anywhere creates a second trip, shipment, group, wave or order for a remainder"*. The **wave** half of that is false, and adversarial verification caught it. `HandlePreparationWaveClosed` is a live registered listener on `WaveClosed`, and its **CASE C** does exactly this:

> *"CASE C — not shipped and not fully prepared → RETURN TO IN PROGRESS, through `ReturnToProcessingWorkflow`. In Progress IS fulfilment-eligible, so the next cycle collects it automatically — that is the carry-over."*

Completeness is an explicit fulfilment test (`OrderPreparationCompletionReader::fullyPreparedOrderIds`). So unprepared work **does** re-enter a second wave — at wave grain, not trip grain.

**Neither mechanism creates a second Trip.** The codebase demonstrably knows how to model 1→many partial fulfilment — it does exactly that **inbound** (`purchase_order_lines.received_qty` = *"cumulative quantity received across all posted Goods Receipts"*, and `DecisionOrchestrator`: `is_partial = true → DEFER (await remaining shipment)`) — and deliberately does not do it outbound.

**Conclusion: do not build a split architecture for partial fulfilment.** The only thing that forces 1 Group → many Trips is **capacity** (§4.2).

---

## 16. Security Finding — pre-existing, unrelated to this decision

Surfaced while auditing Trip's scoping, and reported because it should not wait for a decision:

**`GET /api/logistics/distribution/trips` appears to leak across tenants.** `Trip extends Model` with **no global tenant scope**; `TripController::index()` applies `company_id` **only if the request supplies it**; and the route carries only `auth:sanctum`. An unfiltered call therefore returns every company's trips.

Contrast the Group side of the same module, where `DistributionWindowController::companyId()` aborts 403 on a null company and explicitly refuses the `->when($companyId, …)` pattern *"which silently drops the filter when the company is null and therefore returns every tenant's rows"*.

I have **not** verified this against a live request (there are 0 trips), so it is stated as a strong code-level indication rather than a confirmed exploit. **It is not caused by this decision and must not be bundled into its implementation** — but if Option A routes Group work through Trip, it becomes load-bearing and should be fixed first.

Related, same area: `TripService::assignOrder()` never reads the order's `assigned_warehouse_id`, so **one Trip may legally hold orders from two warehouses today**. That is the mechanism behind §4.3's rejection of many-Groups→one-Trip.

---

## 17. Risks / Limitations

1. **Option A does not deliver fulfilment.** It delivers planning → finalize → trip → vehicle/driver → dispatch *status*. Inventory and order lifecycle stay behind **BLOCKER VP-1**, which this task is forbidden to solve. Anyone reading "Group → Dispatch implemented" as "orders ship" would be wrong.
2. **Trip has no warehouse column**, while the Group is warehouse-owned (NOT NULL, certified Part 5B). Under Option A the warehouse is *known* (via the Group) but not *carried* on the Trip. Whether that is acceptable, or whether `distribution_trips` needs a `warehouse_id`, is a follow-on decision. `preparation_wave_id` does **not** cover it — it is nullable and a Group spans waves.
3. **The Group→Trip link requires a migration** on `distribution_trips` (a certified, live, routed table). Small and additive (one nullable column), but real.
4. **Trip capacity is operator-declared, not vehicle-derived**, and the only check against real vehicle capacity lives in the advisory Dispatch proposal path — not in `dispatchBlockers()`. A finalized Trip can therefore over-commit a vehicle without any gate objecting.
5. **All conclusions are static + live-schema.** With 0 vehicles, 0 drivers and 0 trips, nothing was exercised end-to-end. The auto-increment evidence shows the stack *was* exercised historically, but not by me.
6. **Two analyses were refuted during verification** and corrected in place: "Loading has no test coverage" (false — 3 files, 37 methods; it is *dispatch* that is uncovered) and "nothing creates a second wave for a remainder" (false — `HandlePreparationWaveClosed` CASE C). Both corrections are folded into §10.2 and §15.
7. **`docs/architecture/VEHICLE-ARCHITECTURE-SPEC.md` is still marked APPROVED** and describes a uuid-keyed Vehicle that was never built. It contradicts `logistics_vehicles` and is the likely origin of VP-1. Retiring or superseding it would stop the next reader repeating the mistake.

---

## 18. STOP Conditions

**None triggered — this task is analysis, and the analysis completed.**

| Would have stopped if | Status |
|---|---|
| The canonical relationship could not be determined from code | **No** — determined, §4.3 |
| A new Vehicle or Driver architecture were required | **No** — `logistics_driver_vehicle_assignments` covers it |
| Virtual Vehicle Planning were required | **No** — not revived, not referenced |
| A new transport object were required | **No** — Trip already is one |
| A new Order status were required | **No** — §13 |
| Reopen had to be invented | **No** — `Loading → Planning` already exists |
| A second Dispatch path were required | **No** — the existing one is identified |
| VP-1 had to be solved here | **No** — explicitly untouched |

**Nothing was implemented and nothing was modified.**

---

## 19. Final Recommendation

### **APPROVE OPTION A — Group → Trip, with 1 Group → 1..N Trips**

```
Group  (plan)                          Trip  (execute)
├── warehouse            ─ Finalize ─►  ├── driver_vehicle_assignment_id ──► logistics_driver_vehicle_assignments
├── zones (1..N)                        ├── shipping_company_id
├── orders                              ├── trip_orders (unique per order)
├── Required (live)                     ├── capacity (enforced)
├── Prepared (stored)                   ├── 13-state lifecycle
└── Loading Preparation                 └── finalized_at ── dispatched_at
```

**Finalize(Group)** = create or attach Trip(s) → assign orders → move each `Planning → Loading` → stamp `finalized_at`.

### Why this and not the others

It is the only option that adds **no new lifecycle, no new assignment object, no new dispatch path and no new state machine.** Options B and C each duplicate something Trip already owns, and B additionally requires giving the Group a state machine this codebase has twice refused to invent.

### The smallest implementable first slice

1. **One additive migration** — nullable `virtual_slot_id` on `distribution_trips`, plus an index. Nothing else.
2. **A Finalize action** in Distribution: create/attach Trip(s), assign the Group's eligible orders via the existing `TripService::assignOrder` (which already enforces capacity and one-trip-per-order), stamp `finalized_at`, move `Planning → Loading`.
3. **Reuse `Trip::dispatchBlockers()`** verbatim as the Part-17 readiness summary. Do not write a second one.
4. **Vehicle + Driver**: no new UI concept — reuse `PATCH /trips/{id}/assignment` with `driver_vehicle_assignment_id`. The Group card's inert `Vehicle`/`Driver` rows become reads through the Trip relation.
5. **Permissions**: `logistics.distribution.update` already gates every Trip mutation. **No new permission.**

### What must be decided before that slice

- **Warehouse on Trip** — carry it, or resolve through the Group? (§17.2)
- **Enforce one-Group-per-Trip** in the Finalize action (schema will not enforce it) — §4.3.
- **Fix the Trip tenant scope first** if Group work is going to route through Trip — §16.

### What must NOT be in that slice

Loading integration, inventory, order-status transitions, and anything touching VP-1. Those follow a separate decision, and §10.2 sets out the three options without choosing one.

---

**Nothing was implemented. No backend, frontend, database, migration, route, permission or business-data change. No commit. Awaiting approval of Option A and of the three open questions above.**
