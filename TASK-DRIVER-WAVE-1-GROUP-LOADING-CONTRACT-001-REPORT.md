# TASK-DRIVER-WAVE-1-GROUP-LOADING-CONTRACT-001 — DECISION MEMO

**Date:** 2026-08-24
**Mode:** Contract resolution (implement only if the existing contract supports it).
**Final status:** **BLOCKED — OWNER DECISION REQUIRED.**

The owner ruling **Group = Shipment** resolves the grain ambiguity from the previous memo, and — good news — the **Group-level READ manifest already exists** (`openGroupLoading` returns each product's Required / Prepared / Remaining from the canonical Group contract). But the **WRITE** half is blocked: the only loading-write engine (`LoadProductAction`) mandates NOT-NULL `pool_entry_id` + `preparation_wave_id`, the Group grain carries neither, no Group→pool link exists, and the pool-reservation event is never fired. Per Sections 7/22/23/27, inventing the missing link, mutating Preparation, or adding a migration all require an owner decision — so no bridge/endpoint/UI/table/migration was created and **no code was changed**.

---

## 1. Executive Summary

The driver flow is: driver → assigned **Group** → see the Group's products (Required/Prepared) → record **actual loaded** → vehicle inventory.

- **READ (what the driver must see) — PROVEN, exists.** `openGroupLoading` (`GroupLoadingContextService`) returns, per product, `total_quantity` (Required, live from the Group's orders via `DistributionAggregationService`), `prepared_qty` (Store B `distribution_group_product_preparation`), and `remaining_qty`. It is tenancy-fenced and idempotent (`GroupTripLoadingIntegrationTest`).
- **WRITE (record actual loaded → vehicle inventory) — BLOCKED.** `LoadProductAction` (the only writer of `loading_tasks` → `VehicleInventoryService`) requires `pool_entry_id` (NOT NULL) and `preparation_wave_id` (NOT NULL). The Group manifest has neither; the Group has a `distribution_window_id` (not a wave) and its orders can span multiple waves, so the Group does **not** determine a pool entry/wave; and `LoadingPoolReservedEvent` (which could create the link) is **fired by nobody**. Section 7 forbids inventing a Group→Pool resolver; recording Group-grain loading therefore cannot reach vehicle inventory through the existing engine without an owner decision.

## 2. Owner Decision (accepted)

**Group = Shipment**, one finalized Group → one Driver + one Vehicle, never split. Accepted and applied: the manifest resolves at **Group grain**; no group→trip/vehicle quantity split is used or introduced. Trip remains an internal bridge only (driver → trip → `virtual_slot_id` → Group).

## 3. Group-as-Shipment Contract

Confirmed the resolution chain exists read-only: `logistics_drivers.id` → `logistics_driver_vehicle_assignments` → `distribution_trips` → `Trip.group()` (`virtual_slot_id` → `VirtualCapacitySlot`). One Group per trip; `GroupLoadingContextService::resolveAssignment` already treats it as one `vehicle_assignment` per trip. No splitting concept exists in code — consistent with the ruling.

## 4. Existing Group Product Source (Required) — canonical, reuse

**`DistributionAggregationService`** produces the Group's product Required **live** from the group's order membership (order lines), never stored. `openGroupLoading` already surfaces it as `total_quantity` per product. This is the canonical "what products belong to this Group, and how many are required" — reuse it; do not recompute in the frontend.

## 5. Existing Preparation Sources (Prepared)

- **Store B `distribution_group_product_preparation`** (model `GroupProductPreparation`, grain (Group `virtual_slot_id`, product)) — operator-declared `prepared_qty` per group product, ceiling ≤ live Required (`GroupPreparationService`, absolute set under a group lock). **This is the canonical Group Prepared** and is what `openGroupLoading` returns as `prepared_qty`. Reuse for the driver's "Prepared" column. It carries **no** `pool_entry_id`, `preparation_wave_id`, warehouse, or loaded quantity.
- **Store A `prepared_products_pool`** (model `PreparedProductsPool`, grain (wave, product, warehouse)) — owns `pool_entry_id` + `preparation_wave_id`, warehouse-level `available/reserved/loaded`. `GroupPreparationService` documents it is a **different fact at a different grain** and is never read as a Group figure. It is **not** the Group manifest.

## 6. Group → Driver Assignment

Reuses the certified D-02 path (driver resolved by `user_id`; trip proven company-AND-driver-owned). The Group is reached from the driver's trip. No second ownership system needed for the READ.

## 7. Trip Internal Bridge

Trip is used only to locate the driver's Group (`vehicle_assignment.trip_id` → `distribution_trips` → `virtual_slot_id`). No Trip capacity/Finalize/manifest/snapshot/idempotency was read-for-write or modified; discovery was read-only.

## 8. Required Quantity

Canonical and live (`DistributionAggregationService` → `total_quantity`). Reuse; never recompute in the frontend.

## 9. Prepared Quantity

Canonical at Group grain (Store B `prepared_qty`). `openGroupLoading` exposes it. Prepared is **not** Loaded and is **not** auto-converted to inventory.

## 10. Loaded Quantity — the blocker

Actual loaded is recorded only by `LoadProductAction` → `loading_tasks` (`quantity_loaded`) → `VehicleInventoryService::recordLoad`. **`loading_tasks.pool_entry_id` and `preparation_wave_id` are NOT NULL** (verified in `create_loading_tasks_table`; no later migration relaxes them), and `vehicle_inventory_items.pool_entry_id` is NOT NULL. The Group manifest supplies neither, there is no Group→pool link, and the reservation event (`LoadingPoolReservedEvent`) has **no producer**. So the driver cannot record a Group-grain load through the existing engine. There is also **no Group-grain loaded field** (Store B has only `prepared_qty`).

## 11. Vehicle Inventory

`VehicleInventoryService::recordLoad` (accumulates actual loaded: 10 + 18 = 28) is correct and untouched. It is unreachable here only because its upstream (`LoadProductAction`) cannot be fed Group-grain data (§10).

## 12. Driver Ownership

Certified D-02 fail-closed contract would be reused for any driver read. Not implemented (Outcome B).

## 13. Tenancy

Both `distribution_group_product_preparation` and the Group aggregation are `company_id`-scoped; `openGroupLoading` is proven to reject another company's operator (`GroupTripLoadingIntegrationTest::test_another_companys_operator_cannot_open_loading_for_this_group`). A driver read would enforce the same server-side. Not implemented.

## 14. Driver Permission — owner decision

The only "open loading for a trip" entry (`openGroupLoading`) is gated by **`operations.preparation.update`** — too broad for a driver, and it also *creates* a session/assignment (a write). A driver READ could reuse the driver's existing **`loading.driver.operate`** on a thin `/api/driver/*` GET, so the READ needs **no new permission**. The WRITE entry, however, needs a driver-appropriate permission — deferred with the write decision (§19).

## 15. Loading Read Contract

The canonical Group read (product, `total_quantity`, `prepared_qty`, `remaining_qty`) exists but only inside the **POST** `openGroupLoading` response (which also creates the session/assignment and requires `operations.preparation.update`). A driver-facing **GET** manifest would be a thin read over the same Group aggregation + Store B — feasible — **but it was not built**, because without the write (§10) it is a non-operational, unverifiable (0 data) half-feature, and Section 23 makes Outcome A conditional on the loading action being able to consume the manifest, which it cannot.

## 16. Implementation

**None.** Outcome B. No endpoint, no UI, no table, no migration, no permission, no data. `LoadProductAction`, `VehicleInventoryService`, `GroupPreparationService`, `DistributionAggregationService`, `openGroupLoading`, Group/Trip/Finalize, and every Preparation table were left byte-for-byte unchanged.

## 17. UI

Not built (conditional on Outcome A). The Wave-1 Driver Home (identity + assigned-orders + "No shipment assigned yet") from the prior task stands unchanged.

## 18. Tests

None added/changed. The Section-24 matrix is deferred to Outcome A. Existing certified suites — including `GroupTripLoadingIntegrationTest` and D-02 `DriverRbacTenancySecurityTest` — are untouched and remain valid.

## 19. Static Verification

No code changed this task, so nothing new to lint/type-check; the tree is identical to the prior Wave-1 green state (tsc 23 baseline / none in driver files, ESLint clean, i18n parity 69/69). `php -l`/Pint/PHPStan N/A (no backend edit).

## 20. Browser Verification

**BROWSER MUTATION PATH NOT VERIFIED — NO LEGITIMATE DRIVER LOADING DATA.** Live counts remain 0 drivers / 0 driver-vehicle assignments / 0 loading sessions / 0 loading tasks / 0 vehicle inventory, and no finalized Group is assigned to a driver, so neither the manifest read nor the load mutation can be exercised with real data. The Wave-1 Driver Home + empty state remain browser-verified from the prior task.

## 21. Data Safety

No database changes, migrations, or business data created/modified. Discovery was read-only.

## 22. Remaining Contract Gaps

1. **Write grain mismatch.** `LoadProductAction`/`loading_tasks`/`vehicle_inventory_items` mandate `pool_entry_id`+`preparation_wave_id`; the Group shipment grain has neither.
2. **No Group→pool link.** The Group determines no wave/pool entry (window-scoped, multi-wave orders); `GroupPreparationService` is deliberately disjoint from the pool.
3. **Reservation flow dead.** `LoadingPoolReservedEvent` has no producer, so nothing records "these pool entries/quantities belong to this Group."
4. **No Group-grain loaded field.** Store B tracks only `prepared_qty`; there is no place to record actual loaded at Group grain outside the pool-grained engine.
5. **Driver write permission.** `openGroupLoading` requires `operations.preparation.update`.

## 23. Wave 1 Completion Status — OWNER DECISION REQUIRED

To let a driver record actual loaded against the Group and reach vehicle inventory, the owner must choose **one** (each needs sign-off — a migration and/or a contract change the task forbids doing unilaterally):

- **Option 1 (recommended — smallest, honest to Group=Shipment): make the loading write pool-agnostic.** Migration to make `loading_tasks.pool_entry_id`/`preparation_wave_id` and `vehicle_inventory_items.pool_entry_id` **nullable**, and let `LoadProductAction` record a Group-grain load keyed by `(vehicle_assignment, product)` with `quantity_planned` = Group Required (or Prepared) and no pool provenance. Vehicle inventory still accumulates the actual loaded via the unchanged `VehicleInventoryService`. This keeps one engine and one inventory, and matches the ruling that the Group (not the pool) is the shipment. **Cost:** a migration + a bounded `LoadProductAction` change (owner decision per §27; loosens the certified pool-provenance contract).
- **Option 2: wire the Group→pool reservation (the previous memo's D-A).** Fire the currently-dead `LoadingPoolReservedEvent` when loading opens for a Group, recording which `prepared_products_pool` entries + quantities serve the Group's products, then feed those to `LoadProductAction` unchanged. **Cost:** touches Preparation and adds reservation semantics (forbidden without owner sign-off per §7/§22) and still needs a Group-product → pool-entry rule when a product spans waves.
- **Option 3: Group-grain loaded store + write path.** Add loaded tracking at (Group, product) and a driver write that mirrors into vehicle inventory. **Cost:** a second write path/engine (contradicts §6) — least preferred.

Plus **Option P (permission):** approve a driver-specific loading permission for the write entry (not `operations.preparation.update`).

Once Option 1/2/3 + P is chosen, Wave 1 completes as a **thin driver GET** over the existing Group manifest (Required/Prepared/Remaining) → the (possibly pool-agnostic) `LoadProductAction` → `VehicleInventoryService`, with the Driver Loading UI, the §24 tests, and browser verification against legitimate fixtures.

---

## Final status

**BLOCKED — OWNER DECISION REQUIRED.** The Group=Shipment ruling resolves the grain and the Group **READ** manifest (Required/Prepared/Remaining) is proven to exist, but the existing loading **WRITE** engine is pool-grained (mandatory `pool_entry_id`/`preparation_wave_id`) and the Group grain cannot feed it — recording actual-loaded → vehicle inventory needs one of the Option 1/2/3 decisions (+ a driver write permission). No resolver, reservation, table, migration, engine, permission, or data was created; the load engine, vehicle inventory, Group, Trip, and Preparation are unchanged. Wave 2 and Wave 3 were not started.
