# TASK-DRIVER-WAVE-1-LOADING-MANIFEST-CONTRACT-001 — DECISION MEMO

**Date:** 2026-08-24
**Mode:** Architecture / contract discovery (implementation only if a canonical source is *proven*).
**Final status:** **BLOCKED — OWNER DECISION REQUIRED (Outcome B).**

The two existing prepared stores are **deliberately disjoint** ("different facts at different grains") and **cannot be reconciled into a per-shipment load manifest without inventing architecture**. Per Sections 2 and 15 the bridge, endpoint, and UI were **not** implemented and **no code was changed**. This memo states each store, why neither can be selected, and the exact decision required.

---

## 1. Executive Summary

`LoadProductAction` needs, per product: `pool_entry_id`, `preparation_wave_id`, `quantity_planned` (+ `quantity_loaded`). The task is to find the **canonical read** that produces, for a driver's assigned shipment (`vehicle_assignment` ← `trip_id`), the list of those planned load lines — **without** creating a second engine/table/source.

**Finding:** no such read exists, and it cannot be composed from the existing stores without owner-level architectural choices:
- **Store A `prepared_products_pool`** owns `pool_entry_id`/`preparation_wave_id` but is **warehouse-grained**, not shipment-grained, and can hold **several pool entries per product** (one per wave) → which entry belongs to a given trip is undefined.
- **Store B `distribution_group_product_preparation`** owns a per-**group** `prepared_qty` but has **no `pool_entry_id`, no wave, and no per-trip split** (a group fans out to N trips/vehicles).
- The one service touching both (`GroupPreparationService`) documents that it **never reads or writes** Store A: "Preparation Prepared and Group Prepared are different facts at different grains."
- `openGroupLoading`/`GroupLoadingContextService` build only the session + `vehicle_assignment` shell — **no planned lines, no pool reservation** tied to the trip.
- Even the existing tests **fabricate** `pool_entry_id => Str::uuid()` and `quantity_planned => 5` — there is no wired production source to copy.

Therefore the canonical source is **ambiguous**, and the task's Outcome-B rule applies.

## 2. Existing Prepared Stores

### Store A — `prepared_products_pool` (model `PreparedProductsPool`, module Operations\Preparation)
- **Purpose:** warehouse-level pool of prepared/QC'd product available for loading. **This is the `pool_entry_id` source** (`load-product` passes the row `id`).
- **Grain / unique key:** `(preparation_wave_id, product_id, warehouse_id)`.
- **Quantities:** `quantity_available`, `quantity_reserved`, `quantity_loaded` — **warehouse totals**, not per-shipment.
- **Ownership:** Preparation; `company_id` FK (tenant-safe).
- **Lifecycle:** written when preparation completes; `LoadingPoolReservedListener` bumps `quantity_reserved` (+ a `prepared_pool_movements` row) when Loading OS reserves — but reservation records **no vehicle/trip/session link**, only a warehouse-level delta.
- **Relations:** Wave = `preparation_wave_id` (keyed). Order = **none**. Group = **none**. Trip = **none**. Loading = referenced only as an opaque `pool_entry_id` snapshot by `load-product`.
- **Read by the Loading UI?** No. Exposed by `GET /api/preparation/pool` (`PreparedPoolController`), which is **warehouse-scoped** (filter by `warehouse_id`/quality); the operator loading UI (`loading-os-service.ts`) has **no** "products to load" read at all.
- **Written by the prep workflow?** Yes (availability by preparation; reserved/loaded by loading events).

### Store B — `distribution_group_product_preparation` (model `GroupProductPreparation`, module Logistics\Distribution)
- **Purpose:** operator-declared "how much of a product the warehouse separated for one distribution **Group**," with a live **Required** computed from the group's order membership (`GroupPreparationService`, absolute set, ceiling ≤ Required inside a group lock).
- **Grain / unique key:** `(virtual_slot_id [Group], product_id)`.
- **Quantities:** `prepared_qty` (stored); Required and Remaining are derived live, never stored.
- **Ownership:** Distribution; `company_id` + `distribution_window_id`.
- **Lifecycle:** set/updated by `GroupPreparationService::record`; **never touches inventory, orders, or any Preparation table** (its own docblock).
- **Relations:** Wave = **none** (`distribution_window_id`, not `preparation_wave_id`). Order = **indirect** (Required aggregates group order lines; `prepared_qty` is not order-keyed). Group = keyed (`virtual_slot_id`). Trip = **none** (one group → many trips; no split). Loading = **none**; **explicitly disjoint from Store A**.
- **Read by the Loading UI?** No (read via `DistributionWindowController`, the group planning surface).
- **Written by the prep workflow?** Yes (operator declares per group).

## 3. Canonical Source Decision

**NONE — ambiguous.** A driver load manifest needs `{product, quantity_planned, pool_entry_id, preparation_wave_id}` per `vehicle_assignment`.
- Store A gives `pool_entry_id`+wave but **warehouse-grained** quantity and **multiple entries per product** (per wave) → cannot say *which* entry or *how much* for a specific trip.
- Store B gives a **group-grained** `prepared_qty` but **no `pool_entry_id`, no wave, no per-trip split**.
- No table, service, event, or reservation links a pool entry to a `vehicle_assignment`/trip with a planned quantity. Composing one requires two **invented** resolutions (see §19).

Per Section 2 ("Do NOT arbitrarily choose one") the selection is **stopped**.

## 4. Trip → Preparation Resolution

What exists (read-only, unchanged): driver → `logistics_drivers.id` → `logistics_driver_vehicle_assignments` → `distribution_trips` → `vehicle_assignments.trip_id`; and `trip → virtual_slot_id → Group`; and `trip → distribution_trip_orders → orders → order_lines (product, qty)`.

What is **missing** to reach prepared pool entries: there is no `trip/group → prepared_products_pool` resolution. The group's `prepared_qty` (Store B) is not split per trip, and it carries no `pool_entry_id`/wave to reach Store A. So the traversal reaches *Group prepared totals* and *order product demand*, but **not** the pool entries `load-product` requires. No Trip/Group/Finalize/snapshot relationship was modified (discovery was read-only).

## 5. Driver Ownership

The certified D-02 fail-closed contract (`DriverRuntimeController`: driver resolved by `user_id`; every trip proven company-AND-driver-owned) is the correct basis and would be reused unchanged — **once a manifest source exists**. Not implemented here (Outcome B).

## 6. Tenancy

Both stores carry `company_id`; a compliant read would filter by the actor's company and never trust the client. Not implemented (Outcome B). The blocker is the manifest grain, not tenancy.

## 7. Manifest Read Contract

**Not created.** The conceptual shape in Section 7 (`{shipment:{assigned_orders_count}, items:[{product_id, quantity_planned, quantity_loaded, quantity_remaining, pool_entry_id, preparation_wave_id, status}]}`) is exactly what cannot be populated: `pool_entry_id`, `preparation_wave_id`, and a per-shipment `quantity_planned` have no canonical source (§3). Building the endpoint now would mean fabricating those identifiers — explicitly forbidden.

## 8. Quantity Contract

The `planned / loaded / remaining` distinction is already honoured **downstream** by `LoadProductAction` (stores `quantity_planned`, `quantity_loaded`, `quantity_short`; fails closed on over-load). The gap is purely the **planned** input at read time — there is no per-shipment `quantity_planned`.

## 9. Existing Load Engine

`LoadProductAction` is correct and must not change: idempotent absolute set on `loading_tasks(vehicle_assignment_id, product_id)`, over-load → 422, `quantity_short` computed, then `VehicleInventoryService::recordLoad(delta)`. It can consume canonical identifiers **if** a source provides them. Untouched.

## 10. Vehicle Inventory

`VehicleInventoryService::recordLoad` accumulates the **actual** loaded quantity (10 + 18 = 28) and is accepted as-is. Untouched. Not a blocker.

## 11. Driver Permission

`openGroupLoading` (the only "start loading for a trip" entry) is gated by **`operations.preparation.update`** — too broad to grant a driver. A driver loading flow would need a **driver-specific permission** (in the spirit of `loading.driver.operate`) on a thin driver entry that delegates to the existing loading actions. Per Section 11 this new permission is **documented, not invented** — it is part of the owner decision (§19).

## 12. Implementation

**None.** Outcome B → no bridge, no endpoint, no UI, no migration, no permission. `LoadProductAction`, `VehicleInventoryService`, `GroupPreparationService`, the pool, Group/Trip/Finalize, and Preparation were all left byte-for-byte unchanged.

## 13. UI

**Not built** (conditional on Outcome A). The Wave-1 Driver Home (identity + assigned-orders + "No shipment assigned yet") from the prior task stands unchanged.

## 14. Tests

**None added/changed** (no code to test; implementing tests against a fabricated source would violate the task). The requested test matrix (Section 21) is deferred to Outcome A. Existing certified tests (incl. D-02 `DriverRbacTenancySecurityTest`) are untouched and remain valid.

## 15. Static Verification

No code was changed in this task, so there is nothing new to lint/type-check; the tree is identical to the prior Wave-1 task's green state (tsc 23 baseline / none in driver files, ESLint clean, i18n parity 69/69). `php -l`/Pint/PHPStan are N/A (no backend edit).

## 16. Browser Verification

**BROWSER NOT VERIFIED — NO LEGITIMATE DRIVER LOADING DATA**, and no manifest endpoint exists to exercise. Live counts remain 0 drivers / 0 driver-vehicle assignments / 0 loading sessions / 0 loading tasks / 0 vehicle inventory. The Wave-1 Driver Home + empty state remain browser-verified from the prior task.

## 17. Data Safety

No database changes, migrations, or business data created/modified. Discovery was read-only (`SELECT`/schema reads + source reads).

## 18. Contract Gaps

1. **No per-shipment planned-load line.** Nothing maps `vehicle_assignment`/trip → `{product, quantity_planned, pool_entry_id, preparation_wave_id}`.
2. **Store A grain ≠ shipment.** Warehouse-level; multiple pool entries per product (per wave) with no trip selection rule.
3. **Store B lacks pool identity + trip split.** Group-grained `prepared_qty`; no `pool_entry_id`/wave; no group→trip allocation.
4. **Reservation is not a manifest.** `LoadingPoolReserved` only adjusts warehouse `quantity_reserved`; it records no vehicle/trip link.
5. **Driver loading entry permission.** `openGroupLoading` requires `operations.preparation.update`.

## 19. Owner Decisions (required to unblock)

The owner must choose **one** canonical design; each needs sign-off because each adds architecture the task forbids inventing unilaterally:

- **D-A — New loading-reservation store (recommended).** A thin table linking `vehicle_assignment_id` (or `trip_id`) → `prepared_products_pool.id` with a reserved `quantity_planned` per product (populated when loading is opened/reserved for the trip). This *is* the canonical manifest and directly feeds `load-product`. It is a **new table**, so Section 24 requires an explicit owner decision.
- **D-B — Elect Store B canonical + define two resolvers.** (i) how a Group's `prepared_qty`/Required splits across its trips/vehicles; (ii) how `(trip, product)` selects a `prepared_products_pool` entry (which wave/warehouse when several exist). Both resolvers are new business rules.
- **D-C — Elect Store A canonical + define per-trip scoping.** How a warehouse-level pool entry is apportioned to a specific trip's planned quantity, and how a trip enumerates its pool entries. Also a new rule.
- **D-D — Driver loading permission.** Approve a driver-specific loading permission for a thin driver entry (not `operations.preparation.update`).
- **D-E — Order-level traceability.** Decide whether the manifest must carry order references (neither store is order-keyed today).

## 20. Recommendation for Wave 1 Completion

Adopt **D-A + D-D**: a small **loading-reservation manifest** at `(vehicle_assignment, prepared_products_pool entry)` carrying `quantity_planned`, written when loading is opened for a trip (reusing `GroupLoadingContextService` + the existing pool-reservation event so the pool's `quantity_reserved` stays correct), plus a driver-specific loading permission. Then Wave 1 completes as a **thin READ** over that manifest → the existing `LoadProductAction` → `VehicleInventoryService` (no second engine), and the Driver Loading UI + tests + browser verification (needs legitimate fixtures) follow. This keeps the two prepared "facts at different grains" intact and introduces exactly one missing link — the per-shipment reservation — rather than guessing a mapping between them.

---

## Final status

**BLOCKED — OWNER DECISION REQUIRED.** The two prepared stores are deliberately disjoint and cannot be reconciled into the required per-shipment load manifest without an architectural decision (§19). No bridge, endpoint, UI, table, permission, or data was created; `LoadProductAction`/`VehicleInventoryService`/Group/Trip/Preparation were left unchanged. Wave 2 and Wave 3 were not started.
