# TASK-OPERATIONS-GROUP-TRIP-LOADING-INTEGRATION-001 — FINAL REPORT

**Date:** 2026-08-22
**Status:** **STOPPED — STOP CONDITION 13 TRIGGERED.** No implementation. No migration. No code change. No commit. Database access was `SELECT` / `information_schema` only; the Part 1 audit was completed first, as required.

---

## 1. Executive Summary

**Loading integration cannot be built as specified, and the blocker is the exact item Part 10 says to stop on.**

Every path into Actual Loading requires a `vehicle_assignments` row. Creating that row requires **two capacity dimensions that VP-1 deliberately did not establish, and a third that has no source column anywhere.**

| What Loading demands | Type on `vehicle_assignments` | What the canonical VP-1 Vehicle can supply |
|---|---|---|
| `capacity_weight_kg_snapshot` | `decimal(18,4)` **NOT NULL, no default** | `logistics_vehicles.capacity_weight_kg` — **NULLABLE, default NULL** |
| `capacity_volume_m3_snapshot` | `decimal(18,4)` **NOT NULL, no default** | `logistics_vehicles.capacity_volume_m3` — **NULLABLE, default NULL** |
| `refrigerated_snapshot` | `tinyint(1)` NOT NULL, default 0 | **no such column exists on `logistics_vehicles`** |

All six facts verified against the live `ecos_dev` schema, not from migrations.

The demand is not merely at the database layer — it is in the method signature and the request contract too:

```php
// AssignVehicleToSessionAction::execute — non-nullable PHP parameters
float $capacityWeightKg,
float $capacityVolumeM3,
bool  $refrigerated,

// AssignVehicleRequest
'capacity_weight_kg' => ['required', 'numeric', 'min:0'],
'capacity_volume_m3' => ['required', 'numeric', 'min:0'],
```

**Part 10 is unambiguous:** *"If Loading requires a capacity dimension that VP-1 did not establish: STOP. Do not invent one."* VP-1 / D4-C established **`capacity_orders` as the only approved dimension** — "No weight engine. No volume engine. No product dimension engine."

There is no way around it, because there is no Loading without a vehicle assignment:

- `loading_tasks.vehicle_assignment_id` — **NOT NULL**
- `driver_assignments.vehicle_assignment_id` — **NOT NULL**
- `LoadProductAction::execute(VehicleAssignment $assignment, …)` — the only task creator takes one

So the chain `Trip → Vehicle Assignment → Loading Session → Loading Tasks` cannot be entered at all without deciding weight, volume and refrigeration.

**This is a known-open owner decision, not a new discovery.** The VP-1 owner-decision report (§15) listed exactly three unresolved sub-decisions — null weight/volume, the `refrigerated` source, and the fate of the dead `VehicleCapacityValidatorService`. The D1–D4 approval settled the *key strategy* and ratified order-count capacity; it did not rule on those three. They are now load-bearing.

### 1.1 What is NOT the blocker

Checked explicitly so the decision is not confused with anything else. **Nine of the sixteen STOP conditions were evaluated and cleared:**

| # | Condition | Finding |
|---|---|---|
| 1 | Loading cannot consume canonical Trip | **Clear** — one additive nullable `trip_id` on `vehicle_assignments` suffices; no new table needed |
| 2 / 3 | Second Vehicle / Driver source of truth | **Clear** — the VP-1 resolver serves both; `vehicle_id`/`driver_id` are already `char(36)` |
| 5 | New key strategy | **Clear** — D1-C's uuid contract fits the existing `char(36)` columns unchanged |
| 6 | New Order status | **Clear** — none needed |
| 8 | Dispatch invocation | **Clear** — Loading completion need not call it |
| 9 | New permission | **Clear** — Loading already has a full seeded set (`loading.session.*`, `loading.vehicle.assign`, …) enforced by registered policies |
| 10 | State machine insufficient | **Clear** — 11 session states, 8 assignment states, 6 task states; ample |
| 11 | Redesign of certified Group/Trip contracts | **Clear** — none required |
| 13 | **Capacity requires a dimension VP-1 did not establish** | **TRIGGERED** |

**The integration is otherwise ready to build.** The architecture holds; one unresolved capacity ruling blocks the door.

---

## 2. Current Architecture

Established by reading the implementation before any edit (Part 1). The canonical entities are:

| Concept | Table | Key | Grain |
|---|---|---|---|
| Loading Session | `loading_sessions` | `char(36)` | **warehouse + operational date** — a shift container, NOT per-trip |
| Vehicle Assignment | `vehicle_assignments` | `char(36)` | one vehicle within a session |
| Driver Assignment | `driver_assignments` | `char(36)` | one driver on a vehicle assignment |
| Loading Task | `loading_tasks` | `char(36)` | one product on a vehicle assignment |
| Prepared Pool | `prepared_products_pool` | `char(36)` | **warehouse + product + preparation wave** |

The operational sequence the code actually implements:

```
CreateLoadingSessionAction (company, warehouse, operational_date)
        ↓
AssignVehicleToSessionAction  → vehicle_assignments
        ↓
AssignDriverAction            → driver_assignments
        ↓
LoadProductAction             → loading_tasks   (+ VEHICLE inventory only)
        ↓
StartLoadingAction / CompleteLoadingAction
        ↓
AutoAllocationService         → allocates LOADED vehicle stock to orders
        ↓
DispatchVehicleAction → LoadVehicleWorkflow     ← warehouse inventory boundary
```

**A correction to Part 2's assumed shape:** the brief's preferred model puts Vehicle Assignment above Loading Session. The schema is the other way round — `vehicle_assignments.loading_session_id` means the assignment belongs to the session. The true chain is **Session → Assignment → Tasks**, so a Trip links to the **Vehicle Assignment**, not to the Session. A session is a warehouse-day container that may hold many vehicles, and therefore many Trips.

**A correction on allocation:** `AllocatePoolToSessionAction` is *not* "pool → session products". It reads `VehicleInventoryItem` where `quantity_unallocated > 0` and distributes **already-loaded** stock to orders. It runs *after* loading, not before it.

---

## 3. Group Integration

**Not implemented.** The design established by the audit, ready to build once unblocked:

Group remains the planning source; Trip becomes the execution source. Loading reaches the Group **through** `distribution_trips.virtual_slot_id`, never by storing a group id of its own. No `group_loading_sessions` table is needed and none was created.

---

## 4. Trip Integration

**Not implemented.** Minimum required linkage identified: one **additive, nullable `trip_id`** on `vehicle_assignments`, indexed, no FK (crossing the module boundary, per `FOREIGN-KEY-STANDARDS.md:14`). Both tables are empty, so this would carry no data cost.

`loading_sessions.vehicle_plan_id` is **not** reusable — it points at the removed Virtual Vehicle Planning, and `vehicle_assignments.vehicle_plan_slot_id` is the same residue.

---

## 5. Vehicle Integration

**Blocked.** `vehicle_assignments.vehicle_id char(36)` accepts the VP-1 canonical uuid unchanged — that half is solved. What blocks it is the three capacity values required alongside it (§1). No second Vehicle record would be created; the VP-1 resolver would supply identity, registration and type from the registry.

---

## 6. Driver Integration

**Not implemented, and not blocked on its own.** `driver_assignments.driver_id char(36)` accepts the canonical driver uuid added by VP-1/D2. Loading would display the driver from the canonical pairing ledger as a pure consumer — no Group-Driver relation and no Loading-specific Driver relation. It is blocked only transitively: `driver_assignments.vehicle_assignment_id` is NOT NULL, so it cannot exist before the vehicle assignment.

---

## 7. Loading Session

**Not implemented.** `CreateLoadingSessionAction(companyId, warehouseId, operationalDate, actorId, sessionType, notes)` takes no trip, group or wave. The correct lifecycle point is therefore **one session per warehouse per operational date**, with a Group's Trip attaching as one vehicle assignment inside it — not one session per Group. Idempotency would key on `(company, warehouse, operational_date)`.

---

## 8. Loading Tasks

**Not implemented. Two further issues found that must be settled once the capacity block clears** — reported now so they are not discovered late:

**8.1 `LoadProductAction` is not idempotent.** It calls `LoadingTask::create()` unconditionally, and there is no unique index on `loading_tasks (vehicle_assignment_id, product_id)`. Two calls — or two operators — produce two tasks for the same product. Parts 23 and 24 require that this cannot happen. The house pattern (unique index + absolute set, as LP-2 uses) would fix it on an empty table, but it does change existing certified Loading behaviour and should be acknowledged rather than slipped in.

**8.2 Task provenance is at WAVE grain, not Group grain.** `loading_tasks` requires `pool_entry_id` (NOT NULL) and `preparation_wave_id` (NOT NULL). `prepared_products_pool` is keyed at **(warehouse, product, preparation_wave)** and holds **0 rows** — it has no group or zone dimension. Part 6 requires products to correspond to the Group's demand and forbids using the whole wave as the source. The quantity can honestly come from the Group projection, but the *pool entry* cannot, because no Group-level pool entry exists. Supplying a fabricated uuid would be data fabrication (STOP 14). This needs a provenance decision.

Neither is the reason for stopping, but both would have blocked a clean implementation immediately after.

---

## 9. Quantity Contract

**Not implemented.** The four-way distinction is preserved in the design and nothing was collapsed:

| Term | Source |
|---|---|
| **Required** | canonical Group projection — `DistributionAggregationService::productAggregation()` |
| **Prepared** | approved LP-2 contract — `distribution_group_product_preparation` (2 live rows) |
| **Remaining** | derived, `max(0, Required − Prepared)` — never stored |
| **Loaded** | `loading_tasks.quantity_loaded` — physically loaded, **independent** |

`Loaded = Prepared` would never be set automatically. No second Required calculation would be introduced.

**One existing guard worth crediting:** `LoadProductAction` already refuses over-loading — `quantity_loaded − quantity_planned > EPSILON` throws, with the comment *"Over-loading has no approved contract, so it is refused."* Parts 19 and 25 are already satisfied by shipped code.

---

## 10. Capacity

**THE BLOCKER.** Detailed in §1. VP-1/D4-C ratified order count as the only dimension; Loading structurally demands weight, volume and refrigeration; the canonical source supplies none of the three.

No capacity validation would have been duplicated in Loading — VP-1's server-enforced `Group orders ≤ Vehicle.capacity_orders` remains the single authority, and Loading would only display it.

---

## 11. Loading State

**No new state needed — STOP 10 cleared.** Existing machines are more than sufficient:

- `LoadingSessionStatus` — draft, ready, loading, loading_complete, allocating, allocated, dispatching, dispatched, reconciling, closed, cancelled
- `VehicleAssignmentStatus` — pending, loading, loading_complete, dispatched, returning, reconciling, reconciled, cancelled
- `LoadingTaskStatus` — pending, in_progress, loaded, short_loaded, blocked, skipped

No parallel Group-Loading state machine would be created.

---

## 12. Tenant Scope

Achievable with existing guards; nothing new required. `LoadingSession`, `VehicleAssignment`, `DriverAssignment` and `LoadingTask` all carry NOT NULL `company_id`, and `VehicleAssignmentController::findSession()` already scopes by `$request->user()->company_id`. The VP-1 `FleetIdentityResolver` supplies tenant-safe vehicle/driver resolution through the Eloquent global scopes.

The three rejections Part 22 requires (Company A Group → Company B Trip / Warehouse B Trip / Company B assignment) are all expressible with these. **Not implemented**, since the entry point is blocked.

---

## 13. Warehouse Scope

`loading_sessions.warehouse_id` is NOT NULL and `distribution_virtual_slots.warehouse_id` carries the Group's ownership, so a session and a Group can be checked for warehouse agreement directly. `distribution_trips` deliberately has **no** `warehouse_id` — it derives via `Trip::operationalWarehouseId()` → `group->warehouse_id`, which is the correct source and must not be duplicated onto the Trip.

---

## 14. Permissions

**No new permission required — STOP 9 cleared.** Loading already owns a complete, seeded set enforced through registered policies:

```
loading.session.view / create / operate / cancel / dispatch
loading.vehicle.assign
loading.allocation.view / manage / override
loading.driver.operate
```

**One correction to Part 21:** the brief assumes warehouse/preparation operators act under `operations.preparation.update`. Loading does not use that permission at all — it uses the `loading.*` set above, via `LoadingSessionPolicy` / `VehicleAssignmentPolicy` / `AllocationRecordPolicy`. The route group carries only `auth:sanctum`; authorization is in the controllers, and every method calls `$this->authorize(...)`. Reusing the existing `loading.*` permissions is the correct path, and no new one is needed.

---

## 15. Concurrency

Existing patterns are adequate and would be reused: `LoadProductAction` wraps its work in `DB::transaction`, and the Distribution side already uses `lockForUpdate` + ceiling-inside-lock (`GroupFinalizationService`, `GroupPreparationService`, `GroupVehicleAssignmentService`). No new locking mechanism would be introduced.

**Gap:** duplicate Loading Tasks are not currently prevented (§8.1).

---

## 16. Idempotency

Would reuse the existing lock-then-re-read pattern; no new framework. **Gap:** the current task creator is not idempotent (§8.1). Session idempotency would key on `(company, warehouse, operational_date)`.

---

## 17. Inventory Boundary

**Verified by reading, and it holds.** Loading writes **vehicle** inventory only — `LoadProductAction` calls `VehicleInventoryService::recordLoad()`, which touches `vehicle_inventory_items` / `vehicle_inventory_movements`. The Loading module never names `inventory_items`.

Warehouse inventory, the stock ledger, FIFO consumption, COGS and `sales_issue` are all mutated **exclusively** at Dispatch, inside `DispatchVehicleAction → LoadVehicleWorkflow`. Nothing in the planned integration would move that boundary.

Since nothing was implemented, no inventory was touched at all in this task.

---

## 18. Dispatch Boundary

**Respected absolutely.** `LoadVehicleWorkflow` was not called, not modified, and would not be invoked from Loading. No automatic Dispatch trigger on loading completion was built. Dispatch remains reserved for a separately authorized workstream.

---

## 19. Order Lifecycle

**Untouched.** No new Order status. No order moved to `out_for_delivery` — that transition has exactly two writers (`DispatchOrderWorkflow:50`, `LoadVehicleWorkflow:94`), both on the Dispatch side, and both guarded by `OrderStatusGuard`. Nothing here alters that.

---

## 20. UI / UX

**Not implemented.** The design was to extend the existing Loading surface and add navigation from the Group workspace to it — reusing `UniversalDataGrid`, existing drawers, badges and toolbar, with no new visual system, and no duplicated Loading UI inside the Group panel. No component was created.

---

## 21. EN / AR

**Not applicable** — no new UI strings were introduced, so no keys were added and RTL is unaffected.

---

## 22. Tests

**None written and none run.** The 28 required checks all presuppose a working Trip → Loading linkage, which cannot be created (§1). Writing tests against an entry point that cannot be reached would produce either a red suite or tests asserting fabricated data.

Existing suites were **not** modified and remain as they were: `DistributionGroupTripTest` (63 tests) and `GroupVehicleAssignmentTest` (11 tests, 80 assertions) were both green at the end of the preceding task.

---

## 23. Browser Verification

**Not performed.** With no integration built there is nothing new to verify, and the workflow in Part 27 requires an existing canonical Vehicle and Driver — of which there are **zero** (`logistics_vehicles` = 0, `logistics_drivers` = 0). Producing them purely to walk the flow would be exactly the fabrication Part 27 and STOP 14 forbid.

---

## 24. Side Effects

**None.** No file was created, modified or deleted. No migration was written or run. No cache was cleared. No container was updated. Database access was `SELECT` / `information_schema` only.

---

## 25. Static Gates

Not run — there is no change to gate. The working tree is unchanged from the end of the preceding task.

---

## 26. STOP Conditions

| # | Condition | Status |
|---|---|---|
| 1 | Loading cannot consume canonical Trip | Clear — one additive nullable column suffices |
| 2 | Second Vehicle source of truth required | Clear |
| 3 | Second Driver source of truth required | Clear |
| 4 | VP-1 regression | Clear — nothing was touched |
| 5 | New key strategy required | Clear — the uuid contract fits |
| 6 | New Order status required | Clear |
| 7 | Inventory mutation required | Clear — Loading writes vehicle inventory only |
| 8 | Dispatch invocation required | Clear |
| 9 | New permission required | Clear — `loading.*` set already exists |
| 10 | State machine cannot represent the workflow | Clear — 11 / 8 / 6 states |
| 11 | Redesign of certified Group/Trip contracts | Clear |
| 12 | Tenant/warehouse isolation cannot be guaranteed | Clear |
| **13** | **Capacity requires a dimension VP-1 did not establish** | **TRIGGERED** |
| 14 | Data fabrication required for verification | Would trigger next (§8.2, §23) |
| 15 | Migration would alter certified financial/inventory history | Clear |
| 16 | New architectural entity required | Clear |

**One triggered; a second (14) would trigger immediately behind it.** Neither was worked around, and no additional task was created.

---

## 27. Files Changed

**None.**

---

## 28. Risks / Limitations

1. **The blocker is an owner decision, not an engineering one.** Three options exist and each is a business ruling:
   - **(a)** Make `logistics_vehicles.capacity_weight_kg` / `capacity_volume_m3` NOT NULL, and add a `refrigerated` column — imposes a data requirement on every vehicle.
   - **(b)** Make Loading's three snapshots nullable — changes a certified Loading contract.
   - **(c)** Define an explicit "unknown" convention (e.g. 0 / false) — **this is the dangerous one.** It is inert today only because `VehicleCapacityValidatorService` is registered and never called. The moment anyone wires it, a 0 ceiling rejects every load.
2. **Whichever is chosen, the dead `VehicleCapacityValidatorService` must be ruled on at the same time** — it is the only consumer of these snapshots, and leaving it undecided is what makes option (c) a latent trap.
3. **§8.1 (task idempotency) and §8.2 (pool provenance) must be settled with it**, or the implementation will stall again one step later.
4. **The fleet is still empty** — 0 vehicles, 0 drivers. Every option remains free of data-migration cost right now and will not stay that way after go-live data entry.
5. **`LoadVehicleWorkflow` remains untested** and is irreversible without a compensating transaction. Unchanged by this task, but it sits directly behind the Dispatch door.

---

## 29. Final Verdict

# **GROUP → TRIP → VEHICLE/DRIVER → LOADING — NOT IMPLEMENTED**
# **STOPPED: STOP CONDITION 13**

**Exact blocker:** creating a `vehicle_assignments` row — mandatory for any Loading Task or Driver Assignment — requires `capacity_weight_kg_snapshot` and `capacity_volume_m3_snapshot` (both `decimal(18,4)` **NOT NULL, no default**) plus `refrigerated_snapshot`, while the canonical VP-1 Vehicle offers `capacity_weight_kg` and `capacity_volume_m3` as **NULLABLE with NULL defaults** and **no refrigeration column at all**. VP-1 / D4-C established `capacity_orders` as the only approved dimension. Part 10 requires a stop rather than an invented one.

**Unblocking needs one owner ruling** on §28.1 (a/b/c), ideally together with the `VehicleCapacityValidatorService` decision. Everything else is ready: the Trip linkage is a single additive nullable column, the state machines suffice, the permissions exist, and the tenant guards are in place.

**DISPATCH: NOT IMPLEMENTED — RESERVED FOR NEXT AUTHORIZED WORKSTREAM.**

No Dispatch was implemented. No Inventory or Financial certification is claimed. No commit.
