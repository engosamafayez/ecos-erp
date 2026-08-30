# TASK-OPERATIONS-GROUP-TRIP-LOADING-CONTRACT-ALIGNMENT-001 — FINAL REPORT

**Date:** 2026-08-22
**Status:** IMPLEMENTATION COMPLETE. No Dispatch. No inventory mutation. No financial posting. No commit.

---

## 1. Executive Summary

**The Loading blocker is removed, and it was removed by deleting a requirement rather than inventing a value.**

The previous task stopped because creating a `vehicle_assignments` row — mandatory for any Loading Task or Driver Assignment — demanded three capacity values the canonical fleet cannot supply. That demand is now gone at every layer:

| Layer | Before | After |
|---|---|---|
| Schema | `capacity_weight_kg_snapshot` / `capacity_volume_m3_snapshot` **NOT NULL, no default** | **NULLABLE** |
| Action | `float $capacityWeightKg, float $capacityVolumeM3, bool $refrigerated` — required | optional, defaulting to `null` / `null` / `false` |
| Request | `['required','numeric','min:0']` ×2 | `['nullable','numeric','min:0']` ×2 |
| UI | — | absent entirely: not fetched, not typed, not rendered |

**Weight, Volume and Refrigeration are not ECOS business constraints.**

### 1.1 The one decision that mattered

The columns were made **nullable**, not defaulted to 0.

A `0` in `capacity_weight_kg_snapshot` is a real decimal that reads as *"this vehicle carries nothing"*. The moment anything consumed it — and `VehicleCapacityValidatorService` is sitting there, registered and waiting — the platform would silently refuse every load. `NULL` says *"not measured"*, which is the truth. Writing 0 would have satisfied the column while circumventing the contract, which this task explicitly forbids.

### 1.2 What now connects

```
Group  (distribution_virtual_slots)
  │  virtual_slot_id
  ▼
Trip   (distribution_trips)                    ← the execution source
  │  driver_vehicle_assignment_id → the canonical VP-1 pairing ledger
  │  trip_id  (NEW, nullable, no FK)
  ▼
Vehicle Assignment  (vehicle_assignments)      ← inside a warehouse+date session
  ▼
Loading Tasks       (loading_tasks)            ← one per product, by construction
```

No `group_vehicle`, no `group_driver`, no junction table, no second Vehicle or Driver source, and no new architectural entity.

### 1.3 Defects found and fixed along the way

**One pre-existing, in certified code:**

- **`LoadProductAction` was not idempotent.** It called `LoadingTask::create()` unconditionally with no unique index, so a retry — or two operators — produced two tasks and **doubled the loaded quantity**. It is now an absolute set under `lockForUpdate`, backed by a unique index on `(vehicle_assignment_id, product_id)`.

**Four of my own**, found by browser verification and by the focused suite over five runs. The full list is in §20.1; two are worth surfacing here:

- **`trip_id` was missing from `VehicleAssignment::$fillable`**, so `create()` dropped it silently and every retry produced a *second* vehicle assignment — defeating the exact idempotency this task requires. Nothing errored; only the assertion that two calls return the same id exposed it.
- **My integration addressed Trips by the internal bigint** while the API publishes the uuid. Browser verification caught the request side; the focused suite later caught the same confusion on the response side. **The tests alone could not have found the first** — they read the bigint straight from the database. That is precisely what browser verification is for.

---

## 2. Existing Loading Contract

Audited before modification. The canonical shape, unchanged by this task:

| Concept | Table | Grain |
|---|---|---|
| Loading Session | `loading_sessions` | **warehouse + operational date** — a shift container |
| Vehicle Assignment | `vehicle_assignments` | one vehicle inside a session |
| Driver Assignment | `driver_assignments` | one driver on a vehicle assignment |
| Loading Task | `loading_tasks` | one product on a vehicle assignment |

`LoadProductAction` is the sole task creator. `AutoAllocationService` runs **after** loading — it distributes already-loaded vehicle stock to orders, and is not a pool-to-session step.

---

## 3. Capacity Contract

**`assigned_order_count <= Group.capacity_orders`, and nothing else.**

- `capacity_orders = NULL` means **unconstrained**, and the UI says so rather than rendering 0.
- Enforcement is unchanged and remains where VP-1 put it — server-side in `GroupVehicleAssignmentService`, inside the Group lock, reading the canonical `slotSummaries()`.
- Loading duplicates **no** capacity validation. It displays the two order counts and nothing more.

---

## 4. Weight / Volume / Refrigeration Analysis

Every site that imposed them, and its disposition:

| Site | Disposition |
|---|---|
| `vehicle_assignments.capacity_weight_kg_snapshot` | NOT NULL → **NULLABLE** |
| `vehicle_assignments.capacity_volume_m3_snapshot` | NOT NULL → **NULLABLE** |
| `vehicle_assignments.refrigerated_snapshot` | already had DB default 0; the **parameter** is now optional |
| `AssignVehicleToSessionAction::execute` | three required params → optional, null-defaulting |
| `AssignVehicleRequest` | `required` → `nullable` |
| `GroupLoadingContextService` | passes `null, null, false` — supplies nothing |
| Loading UI (`group-loading-execution.tsx`) | **absent** — not in the type, not fetched, not rendered |
| `VehicleCapacityValidatorService` | dead; left in place, documented (§23) |

The columns were **kept, not dropped**. They hold no rows, a service and a resource still reference them and compile, and dropping them would be a destructive change to a certified table for no operational gain. Existing technically is not the same as being a business rule.

---

## 5. Vehicle Assignment

Reached through the canonical VP-1 pairing. `GroupLoadingContextService` reads `trip->driverVehicleAssignment`, takes `vehicle->uuid` (the cross-module identifier — the internal bigint is never published) and snapshots registration and `type` **from the fleet registry**, not from a client assertion.

*Verified against the live schema rather than assumed:* the registry column is `type`, not `vehicle_type`. The Loading-side column being named `vehicle_type_snapshot` does not mean the source is called that.

No Loading Vehicle, Loading Driver, Group Vehicle or Group Driver was created.

---

## 6. Loading Session

**Contract preserved exactly.** A session remains `warehouse + operational_date`. A Trip does **not** create its own session — it joins the warehouse's session for the day as one vehicle assignment. Several Trips out of one warehouse share one session.

Session resolution locates under `lockForUpdate` before creating, so two operators opening Loading for two Trips in the same warehouse on the same day converge on one session rather than racing into two.

---

## 7. Group Provenance

Loading knows its Group through `vehicle_assignments.trip_id → distribution_trips.virtual_slot_id`. The Group id is never copied onto a Loading row — there is one path, and it is the canonical execution chain.

Membership is **never** reconstructed from the Preparation Wave, from warehouse-wide orders, or from all `ready_for_dispatch` orders.

### 7.1 The pool-entry problem — resolved without touching Preparation

`loading_tasks.pool_entry_id` and `preparation_wave_id` are both NOT NULL and both **wave-grain**, while `prepared_products_pool` is keyed `(warehouse, product, wave)` with no group dimension.

**No Preparation change was made and no group id was invented inside a pool entry.** The resolution is that Group context is carried by the *execution chain* (`trip_id`), not by the task's provenance columns. Those columns keep their existing meaning — which prepared stock a load drew from — and remain the caller's to supply on the existing `load-product` path, exactly as before. STOP condition 4 therefore did not trigger.

---

## 8. Trip Integration

One additive column: `vehicle_assignments.trip_id`, `unsignedBigInteger`, **nullable**, indexed, **no foreign key** (a cross-module reference, per `FOREIGN-KEY-STANDARDS.md` §1).

Nullable because the pre-existing standalone Loading path has no Trip and must keep working. No historical data is altered — both tables were verified empty before the migration ran.

---

## 9. Quantity Sources

Four distinct facts, never interchanged:

| Term | Source |
|---|---|
| **Required** | canonical Group projection — `DistributionAggregationService::productAggregation()` |
| **Prepared** | approved contract — `GroupPreparationService::preparedByProduct()` |
| **Remaining** | **derived**, `max(0, Required − Prepared)`, never stored |
| **Loaded** | `loading_tasks.quantity_loaded` — physical, independent |

The endpoint reuses the existing `groupLoadingPreparation()` presenter verbatim, so no second Required calculation exists. `Loaded` is never set from `Prepared`, and the UI renders it as an explicit dash until a task exists — so "nothing loaded yet" cannot be mistaken for "loaded zero".

---

## 10. Loading Tasks

`LoadProductAction` remains the canonical creator. Two changes:

1. **Idempotent.** The row is located under `lockForUpdate` and its quantity is **set**, never added to. Re-sending the same quantity is a no-op by construction; a correction downwards moves vehicle inventory by the delta rather than being ignored.
2. **Ceiling preserved.** The existing over-load refusal is untouched: `quantity_loaded − quantity_planned > EPSILON` throws, with its original reasoning — *"Over-loading has no approved contract, so it is refused."* It is based on planned quantity, never on weight or volume.

Partial loading remains legal, exactly as before.

---

## 11. Idempotency

| Retry | Result |
|---|---|
| Open Loading | same session, same vehicle assignment — located, not re-created |
| Create Loading Task | one row per `(vehicle_assignment_id, product_id)`, enforced by unique index |
| Update loaded quantity | absolute set — the value is stated, not accumulated |

No idempotency framework was introduced. This is the same lock-then-re-read shape the Distribution services already use.

---

## 12. Concurrency

`DB::transaction` + `lockForUpdate` throughout — the existing house pattern, not a new mechanism. The unique index is what makes the task write safe rather than merely well-behaved: it collapses the race between the lookup and the write, so two simultaneous operators cannot both insert.

---

## 13. Tenant Scope

`assertConsistent()` checks company agreement on the **resolved** objects, not on request input, so it cannot be bypassed by ids that individually pass a filter applied at different moments. The window and group are resolved through the existing company-scoped `window()` / `slot()` helpers, and the Trip is resolved **from the Group** rather than looked up globally.

Verified live: a foreign trip uuid returns **404 "Trip not found for this group."**

---

## 14. Warehouse Scope

The Loading session's warehouse is `Trip::operationalWarehouseId()` → `group->warehouse_id`. It is derived, never copied onto the Trip — `distribution_trips` deliberately has no `warehouse_id`, and that stays true.

---

## 15. Permissions

**No new permission.** The route carries `operations.preparation.update` — the approved actor precedent, verified to exist in the live `permissions` table.

`logistics.distribution.update` was deliberately **not** used: the warehouse/preparation staff who physically load do not hold the distribution planner's permission.

*Documented for accuracy:* the Loading module itself uses its own seeded set (`loading.session.*`, `loading.vehicle.assign`, `loading.allocation.*`, `loading.driver.operate`) through registered policies. That contract is untouched.

---

## 16. UI

`group-loading-execution.tsx`, reached from the Group panel — Group → Trip → Vehicle/Driver → Loading in one operator flow, not a separate Loading system.

**Header:** Group · Trip · Vehicle · Driver · Session · Status
**Product table:** Product · SKU · Required · Prepared · Remaining · Loaded · Unit

Reuses `UniversalDataGrid`, existing `Badge` and `Button`. No new visual system.

**Weight, volume and refrigeration are absent, not hidden.** They are not in the TypeScript type, not requested from the server and not rendered — which is what stops them creeping back in as requirements. Verified live: a regex for `weight|volume|refrigerat` over the rendered panel returns nothing.

---

## 17. Inventory Boundary

**Nothing in this task touches warehouse inventory.** Loading writes vehicle inventory only, via the existing `VehicleInventoryService::recordLoad`. The module never names `inventory_items`.

Verified two ways:
- A before/after audit of all 16 tracked tables came back **identical** (§21).
- A focused test asserts `inventory_items`, `stock_ledger_entries` and `inventory_receipt_layers` counts are unchanged across opening Loading, and that no order status moved.

No FIFO consumption, no COGS, no `sales_issue`, no reserved or on-hand decrement.

---

## 18. Dispatch Boundary

`LoadVehicleWorkflow` was **not called, not modified, and is not invoked** from any path added here. Nothing triggers Dispatch when Loading completes. Loading ends at LOADED, leaving the canonical execution object ready for a later, separately authorised Dispatch.

---

## 19. Order Lifecycle

No new Order status. No order moved to `out_for_delivery` — asserted by test and confirmed by the side-effect audit. The two writers of that transition remain on the Dispatch side, guarded by `OrderStatusGuard`. Preparation's status contract is untouched.

---

## 20. Tests

`tests/Feature/Logistics/GroupTripLoadingIntegrationTest.php` — 10 focused tests, two-company fixtures, covering: the removed weight/volume requirement, null-not-zero storage, canonical Group/Trip/Vehicle/Driver/Warehouse resolution, Required/Prepared/Remaining sourcing, session and assignment idempotency, the unique-index guarantee, tenant and cross-group rejection, the inventory/ledger/FIFO/order-status boundary, and order-count capacity enforcement.

**Result: 10 of 10 passing — `OK`, 103 assertions, exit code 0, 7m20s.**

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
..........                                                        10 / 10 (100%)
Tests: 10, Assertions: 103, PHPUnit Deprecations: 32.
```

*The 32 deprecations are bootstrap-level and constant regardless of test count — a single-test run reports the same 32.*

### 20.1 It took five runs, and every failure was a defect of mine

Recorded rather than glossed, because two of them were worth more than the test that caught them:

| Run | Result | Defect |
|---|---|---|
| 1 | 5 fail | Route resolved Trips by the internal bigint; the API publishes the uuid |
| 2 | 6 fail | `(string)` cast applied to `VehicleType`, which the model casts to a **backed enum** — a fatal |
| 3 | 3 fail, 98 assertions | `trip_id` missing from `VehicleAssignment::$fillable`; plus an `actingAs` ordering bug in my own test |
| 4 | 1 error, 101 assertions | The response published the bigint while the route accepted the uuid |
| **5** | **OK, 103 assertions** | — |

**The two that mattered:**

- **`trip_id` was not in `$fillable`.** `create()` accepted the key, dropped it silently, and wrote `trip_id = NULL`. The idempotency lookup `where('trip_id', …)` then found nothing, so **every retry created a second vehicle assignment** — defeating precisely the guarantee this task requires. No error was raised anywhere; only the assertion that two calls return the same id exposed it.
- **My cross-tenant test never crossed tenants.** I interpolated `$this->windowId()` inside the request argument, and that helper itself calls `actingAs(companyA)`. Since `actingAs` mutates the shared authenticated user, the companyB actor was overwritten before the request fired. I checked the controller before assuming a hole — `window()` correctly filters `->where('company_id', $companyId)` — so the defect was the test's. A test that appears to prove tenant isolation while never leaving one tenant is worse than no test.

Runs 1 and 4 were the same bigint/uuid confusion in opposite directions: I fixed the request side and left the response side inconsistent with it.

Existing suites were not modified: `GroupVehicleAssignmentTest` (11 tests / 80 assertions) and `DistributionGroupTripTest` (63 tests / 612 assertions) were green at the end of the preceding task.

### 20.2 Regression — whole `tests/Feature/Logistics` suite

```
Tests: 772, Assertions: 5008, Failures: 27.
```

**All 27 are pre-existing and none is attributable to this task.** Both of my test classes passed inside that run. Attribution, with the evidence for each rather than an assertion:

| Count | Class | Cause | How it was proven |
|---|---|---|---|
| **22** | `DistributionModuleTest` | 403 on every `/trips/*` route — the `permissions` table is empty in `ecos_dev_test`, so `RequirePermissionMiddleware` denies | **Control run: the class fails with exactly 22 in ISOLATION**, identical to the full suite. Not caused by my code and not caused by ordering |
| **2** | `VehicleModuleTest` | Same missing-permission root cause (`can_manage_maintenance`, `…immutable_without_permission`) | The file has **zero** references to Loading, Distribution, `vehicle_assignments` or `trip_id`. `VehicleMaintenanceController` last modified **2026-08-05**; the two dirty Vehicles files were already in this task's baseline with mtimes of 2026-08-20 |
| **3** | `DistributionReadModelApiTest` ×2, `DistributionOrdersFilterApiTest` ×1 | All three query `?order_status=new`, and **`new` no longer exists** on `OrderStatus` — ADR-042 retired it on 2026-08-13 | Verified by grep: `case New` / `'new'` appear nowhere in the enum. These same failures were independently proven pre-existing by a control run during the earlier LP-1.0 task |

**A structural fragility observed, not introduced:** 15 Logistics test classes use `RefreshDatabase` (which runs `migrate:fresh` and wipes seeded permissions) while 16 use `DatabaseTransactions` (which assumes a seeded database). Wiping classes sort before assuming ones alphabetically, so the collision predates this task — `DistributionCoreTest` alone is enough to trigger it. My new class adds to that population but does not create the condition, and the isolation control proves it is not the cause here.

This is reported rather than fixed: repairing the suite's seeding contract is outside this task's scope, and the ratchet rule is to block new debt without failing an approved baseline.

---

## 21. Browser Verification

Against the real `DG-001` / `TRP-001`. **No fake vehicle, driver, trip, group, order or inventory was created.**

| Check | Result |
|---|---|
| Groups tab, DG-001 present | ✔ 5 orders, `capacity_orders: null` |
| Trip present | ✔ `TRP-001`, pairing `null` |
| Loading panel renders in the Group flow | ✔ |
| Open loading → real server response | ✔ **422 "This group has no vehicle and driver yet. Assign them before opening Loading."** |
| Foreign trip uuid rejected | ✔ **404 "Trip not found for this group."** |
| Weight / volume / refrigeration in the UI | ✔ **none** |
| Arabic | ✔ `lang=ar`, `dir=rtl`, translated, no raw keys |
| Inventory / FIFO / ledger / COGS unchanged | ✔ (§21 audit) |
| Order status not `out_for_delivery` | ✔ |
| Dispatch | **not pressed** |

**The full happy path could not be walked in the browser**, because DG-001 has no vehicle or driver — the fleet is genuinely empty (0 vehicles, 0 drivers) and Part 22 forbids fabricating them. What was verified live is the workflow-ordering guard and the ownership guard; the happy path is covered by the focused tests.

**This is UI handler/network verification. Browser human-click acceptance is NOT claimed.**

---

## 22. Side Effects

Before/after comparison of all 16 tracked tables — `distribution_virtual_slots`, `distribution_slot_zones`, `distribution_trips`, `distribution_trip_orders`, the pairing ledger, `vehicle_assignments`, `driver_assignments`, `loading_sessions`, `loading_tasks`, `orders`, `order_lines`, `preparation_waves`, `inventory_items`, `stock_ledger_entries`, `inventory_receipt_layers`, `distribution_group_product_preparation`:

```
diff baseline after  →  IDENTICAL — no side effects
```

No Loading execution record was created either, because the only Group that could produce one has no vehicle yet.

---

## 23. Static Gates

| Gate | Result |
|---|---|
| `php -l` | clean on every touched file |
| **Pint** | **passes.** It initially failed on my files — a control run on untouched Loading files passed, proving the failures were mine (Windows CRLF from my own writes). Formatted and re-verified. |
| **PHPStan L0** | **No errors** |
| **TypeScript** | 23 errors before, **23 after — zero in `distribution-workspace`.** Baseline held; unrelated errors not touched |
| **ESLint** | clean on the whole feature directory |
| Focused PHPUnit | §20 |

---

## 24. Existing Dead Services

**`VehicleCapacityValidatorService` — dead, and left in place.**

- Callers: **zero**. The only two references in `Modules/` and `tests/` are the provider's `use` statement and its `singleton()` binding.
- It reads `capacity_weight_kg_snapshot` and `capacity_volume_m3_snapshot` only. It does **not** read order count.
- It is therefore **outside the current ECOS contract** and was not reused merely because it exists.
- It was **not deleted** — production code is not removed automatically.

**A standing risk, recorded:** if this service is ever wired, it will impose weight and volume ceilings that contradict the approved contract, and it will read `NULL` snapshots. It should be removed or rewritten to order count by an explicit decision, not left as a trap.

---

## 25. STOP Conditions

| # | Condition | Status |
|---|---|---|
| 1 | Weight/Volume/Refrigeration structurally unremovable | **Clear** — removable; the only reader is dead, so no caller broke |
| 2 | Migration alters operational/financial history | **Clear** — both tables empty; only a NOT NULL was loosened |
| 3 | Group provenance requires invented data | **Clear** — carried by `trip_id` |
| 4 | `pool_entry_id` requires redesigning Preparation | **Clear** — Preparation untouched (§7.1) |
| 5 | New Order status required | **Clear** |
| 6 | New Permission required | **Clear** — `operations.preparation.update` reused |
| 7 | Inventory mutation required | **Clear** |
| 8 | Dispatch required | **Clear** |
| 9 | State machine insufficient | **Clear** — 11 / 8 / 6 states, none added |
| 10 / 11 | Tenant / warehouse isolation | **Clear** — enforced and verified live |
| 12 | Capacity not enforceable by order count | **Clear** |
| 13 | Idempotency needs an unrelated contract change | **Clear** — house pattern + one unique index |
| 14 | Canonical Vehicle/Driver must be duplicated | **Clear** |
| 15 | New architectural entity required | **Clear** |

**None triggered.**

---

## 26. Files Changed

Baseline recorded before modification: **753 pre-existing dirty paths** (other agents' work, untouched).

**Created**
- `backend/Modules/Operations/Loading/Infrastructure/Database/Migrations/2026_08_22_100000_align_loading_to_order_count_capacity.php`
- `backend/Modules/Logistics/Distribution/Domain/Services/GroupLoadingContextService.php`
- `backend/tests/Feature/Logistics/GroupTripLoadingIntegrationTest.php`
- `frontend/src/features/logistics/distribution-workspace/components/group-loading-execution.tsx`

**Modified**
- `AssignVehicleToSessionAction.php`, `AssignVehicleRequest.php`, `VehicleAssignmentController.php`, `LoadProductAction.php`
- `DistributionWindowController.php`, `routes/api.php`
- `distribution-groups-panel.tsx`, `types/index.ts`, `distribution-workspace-service.ts`, `use-distribution-workspace.ts`
- `i18n/locales/{en,ar}/logistics.json`

**Explicitly not mine:** `backend/tests/Feature/Operations/WaveDeferredOrderCutoffReturnTest.php` appeared during this session and belongs to a concurrent agent. It was neither modified nor reverted.

---

## 27. Risks

1. **`VehicleCapacityValidatorService` remains a trap** (§24) — dead today, contract-violating if wired.
2. **`pool_entry_id` / `preparation_wave_id` remain caller-supplied** on the existing `load-product` path, with no FK and format-only validation. Unchanged by this task, but it means a task can still reference a pool entry that does not exist.
3. **`prepared_products_pool` is empty**, so no real load has been exercised end to end anywhere.
4. **The fleet is empty** — 0 vehicles, 0 drivers. The happy path is test-covered but has never run against real fleet data.
5. **`LoadVehicleWorkflow` is still untested** and irreversible without a compensating transaction. Untouched here, but it sits directly behind the Dispatch door.
6. **Legacy weight/volume columns survive** on `vehicle_assignments`. Keeping them was the conservative choice; they should be removed once it is certain nothing reads them.

---

## 28. Final Verdict

# **GROUP → TRIP → VEHICLE/DRIVER → LOADING — IMPLEMENTED**

**Capacity: ORDER COUNT ONLY.**

> **Weight, Volume and Refrigeration are not ECOS business constraints.**

**BROWSER HUMAN-CLICK ACCEPTANCE: NOT VERIFIED** — verification was UI handler/network level (§21), and the full happy path could not be walked without fabricating fleet data, which is forbidden.

**Test status: 10/10 green, 103 assertions** (§20). Five runs were needed; every failure was a defect of mine, and two of them — a silent `$fillable` drop that broke idempotency, and a cross-tenant test that never crossed tenants — were caught only because the suite exists.

**DISPATCH: NOT IMPLEMENTED — RESERVED FOR NEXT AUTHORIZED WORKSTREAM.**

No inventory or financial certification is claimed. No commit.
