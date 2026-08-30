# TASK-DRIVER-WAVE-1-CORE-LOADING-EXPERIENCE — REPORT

**Date:** 2026-08-24
**Mode:** Implementation — Driver Wave 1
**Final status:** **IMPLEMENTED / BLOCKED — OWNER DECISION REQUIRED**

Driver Home (Part A) + i18n (Part L) are **implemented and browser-verified in the user's actual Chrome (English + Arabic)**. The core operational Loading flow (Parts C–E, and the Start-Loading destination in A3) is **STOPPED per Parts F/N**: the load→vehicle-inventory *write* contract and the driver→shipment *bridge* both exist, but the **per-shipment "products + required quantities to load" *read* contract does not** — and there is **zero live data** to exercise any mutation path. No workaround was invented; no data was fabricated.

---

## 1. Executive Summary

- **Delivered (feasible, contract-safe, browser-verified):** the Driver Home was transformed into the approved shipment-oriented experience — driver identity, assigned-order count only, and a "No shipment assigned yet" state, with the internal Trip model no longer exposed (Active-Trips count and trip cards removed). All strings live in the `driver-mobile` i18n namespace (EN+AR, exact parity).
- **Blocked (reported, not worked around):** the Loading screen and the Loading→Vehicle-Inventory operation. The **existing backend can already *record* a load and move it into vehicle inventory correctly** (`LoadProductAction` + `VehicleInventoryService`), and a **driver can be bridged to a Loading `vehicle_assignment` through the trip** (`vehicle_assignments.trip_id = distribution_trips.id`). What is missing is the **read that tells the driver *what* to load** for their shipment — the per-vehicle_assignment list of `{pool_entry_id, product, preparation_wave_id, quantity_planned}`. `load-product` *requires* those as inputs, and **no existing endpoint produces them per shipment** (not even the operator UI). Building it means new backend contract work over a Preparation-pool feed the codebase already flags as the dead link — an owner decision. Independently, **zero live data** (0 drivers, 0 driver-vehicle assignments, 0 loading sessions/assignments/tasks/inventory) makes the mutation path un-verifiable without fabricating a Driver/Vehicle/Trip/Group/Session.

## 2. Current Driver Home (before)

Header + a 2-tile KPI row (**Active Trips**, Assigned Orders) + a list of **`DriverTripCard`** trip cards + an empty state reading *"No active trips / Trips will appear here once dispatched."* — i.e. it exposed the Trip model.

## 3. Approved Driver Home UX (after — implemented)

Shipment-oriented, Trip never surfaced:
- **A1 Identity:** driver name from the authenticated user context (`useAuthStore`), with a "Driver" role label. Not hardcoded, not derived from Trip metadata.
- **A2 Assigned orders:** the canonical assigned-order count only — `Σ trip.stops_count` (one delivery stop is one order; the existing source, not a second one). Rendered as `{{count}} orders assigned to you`.
- Removed: the **Active Trips** tile and the **trip-card list** (Trip over-exposure).

## 4. Assignment State (A3)

When the driver has an assignment, the Home shows the assigned-order count (A2). **The "Start Loading" CTA is intentionally NOT wired**: its only valid destination is the driver Loading workflow, which is blocked (§6). Wiring a CTA to a non-existent/blocked route, or to the internal Trip screen, was explicitly out of bounds — so it is reported rather than faked. (With zero live data this branch does not currently render; the empty state does.)

## 5. Zero Assignment State (A4) — browser-verified

- **EN:** "No shipment assigned yet" / "Your assigned shipment will appear here."
- **AR:** "لم يتم تعيين رحلة لك بعد" / "ستظهر هنا الشحنة عند تعيينها لك." (RTL)
- No "Active Trips: 0", no trip table.

## 6. Loading UX (Parts C/D — BLOCKED)

**The missing contract:** `LoadProductAction` (`POST /api/loading/sessions/{s}/assignments/{a}/load-product`) requires the caller to supply, per product, `pool_entry_id`, `product_id`, `sku_snapshot`, `name_snapshot`, `preparation_wave_id`, `quantity_planned`, and `quantity_loaded`. There is **no read** anywhere that returns those "planned load lines" for a given `vehicle_assignment`/trip:
- `loading_tasks` are created **by** `load-product` (not before it), so they are empty until something is loaded — they cannot be the "what to load" source.
- The operator's own `loading-os-service.ts` has `loadProduct` but **no** "list products to load" read.
- `GET /api/preparation/pool` (`PreparedPoolController`) is **warehouse-scoped**, not shipment-scoped.
- Two prepared stores exist — Group `distribution_group_product_preparation` at `(Group, Product)`, and Preparation `prepared_products_pool` (which owns `pool_entry_id`) — and prior audit records that **the one feeding actual loading is the dead link**.

So the driver cannot be shown "what to load" without a new read that maps trip/`vehicle_assignment` → its pool entries. Per Parts F/N this is reported, not invented.

## 7. Required vs Actual Quantity

**The engine already preserves this correctly** — `LoadProductAction` stores `quantity_planned` (required) and `quantity_loaded` (actual) separately, computes `quantity_short = max(0, planned − loaded)`, and **fails closed on over-load** (loaded > planned → 422). It never replaces required with loaded. (UI blocked by §6.)

## 8. Product Confirmation

Backend states exist — `LoadingTaskStatus` (`pending`, `in_progress`, `loaded`, `short_loaded`, `blocked`, `skipped`); `loading_tasks.confirmed_by`/`confirmed_at` columns exist. No new status is needed for presentation. (UI blocked by §6.)

## 9. Loading Complete

`CompleteLoadingAction` + `POST /api/loading/sessions/{s}/complete-loading` exist and are the source of truth (session `loading → loading_complete`). No fake frontend completion state would be created. (UI blocked by §6.)

## 10. Loading Persistence

The source of truth is the backend (`loading_tasks` + `vehicle_inventory_items`), read via `GET .../assignments/{a}` (loading tasks) and `GET .../assignments/{a}/inventory`. A driver screen would re-read these on return — **no localStorage, no second persistence**. Contract exists; UI blocked by §6.

## 11. Partial Loading (Part H)

Satisfied by the existing contract: a short load stores `quantity_planned`, `quantity_loaded`, `quantity_short` and status `short_loaded`. The shortfall is **not** auto-delivered, auto-cancelled, or auto-returned — `LoadProductAction` only records the load and moves the *actual* quantity into inventory. Downstream disposition is left to a later workflow.

## 12. Vehicle Inventory Result (Parts E/E1)

`VehicleInventoryService::recordLoad(delta)` moves the **actual** loaded quantity into `vehicle_inventory_items` (keyed `(vehicle_assignment_id, product_id)`): `quantity_loaded/on_hand/unallocated += delta`, plus an append-only `vehicle_inventory_movements` row. Loaded 18 of a required 20 ⇒ inventory receives **18**, never 20. The engine exists; it is not reached only because there is no way to drive the driver load (§6) and no data.

## 13. Inventory Accumulation (Part E2)

`recordLoad` **adds** the delta rather than overwriting, so re-loading the same product in the same assignment accumulates (10 → +18 ⇒ 28). Note the existing contract keys inventory by `vehicle_assignment_id`, so a *new* loading cycle = a new `vehicle_assignment` = a new inventory item; this is the current contract behaviour and was **not** redesigned (Part F).

## 14. Authorization / Tenancy (Part G)

The certified D-02 driver runtime (`DriverRuntimeController`) is fail-closed: a driver is resolved by `logistics_drivers.user_id`, and every trip/stop is proven to be BOTH the actor's company AND the resolved driver's own. A driver Loading path would reuse that bridge (driver → `driver_vehicle_assignments` → `distribution_trips` → `vehicle_assignments.trip_id`) — no second ownership system. **Caveat (part of the blocker):** the existing loading entry `openGroupLoading` is gated by `operations.preparation.update`, not a driver permission, so a driver-facing thin entry would be required once the manifest read (§6) is decided.

## 15. Responsive UI (Part K)

The Home uses the approved ECOS design-system primitives (`Button`, `Skeleton`, lucide icons, cards) and is mobile-first with no horizontal scroll. The Loading screen was not built.

## 16. i18n (Part L)

All new strings are in the `driver-mobile` namespace, EN + AR, **exact parity (69/69 keys, zero diff)**. Selector-mode `t($ => $.…)`; no hardcoded JSX strings (ESLint `ecos-i18n` clean). Both languages browser-verified (§19).

## 17. Files Changed

| File | Change |
|---|---|
| `frontend/src/features/operations/driver-mobile/pages/driver-home-page.tsx` | Transformed to the shipment UX: identity (A1), assigned-order count only (A2), "No shipment assigned yet" state (A4); Active-Trips tile and `DriverTripCard` list removed. |
| `frontend/src/i18n/locales/en/driver-mobile.json` | Empty-state wording → shipment; added `home.assignedSummary`. |
| `frontend/src/i18n/locales/ar/driver-mobile.json` | Arabic mirror of the above. |

**No backend files changed. No migration. No new engine, table, route, or permission.**

## 18. Tests

- `tsc --noEmit -p tsconfig.app.json` → **23 errors = the pre-existing baseline, unchanged, none in any driver file.**
- ESLint on `driver-home-page.tsx` → **exit 0**.
- i18n parity → **69/69, zero diff**.
- Loading / vehicle-inventory backend tests: **not run — no backend code was changed** in this task, and no new backend was added (the load/inventory engine is untouched).
- D-02 `DriverRbacTenancySecurityTest`: unaffected (no backend change); the last run stands (OK, 21 tests).

## 19. Browser Verification (user's actual Chrome, `localhost:5173`)

- **`/app/driver/home` (EN):** renders "Administrator" / "Driver" + "No shipment assigned yet" / "Your assigned shipment will appear here." **No white screen. No console errors.**
- **`/app/driver/home` (AR):** `dir=rtl`, renders "سائق" + "لم يتم تعيين رحلة لك بعد" + "ستظهر هنا الشحنة عند تعيينها لك." The user's language was set to Arabic to verify, then **restored to English**.
- The **assignment-present** state and the **full Loading mutation path are NOT browser-verifiable**: there are 0 `logistics_drivers`, 0 `logistics_driver_vehicle_assignments`, 0 loading sessions/assignments/tasks/inventory (only 2 `distribution_trips`, unassigned). Verifying them would require fabricating a Driver/Vehicle/Trip/Group/Loading-Session — forbidden.

## 20. Data Safety

No database changes, migrations, or business data created/modified. This task was read-only backend discovery plus three frontend file edits. (The running dev server remains the clean `npm run dev` from the prior task.)

## 21. Wave 1 remaining + Wave 2 Remaining

**Wave 1 remaining (blocked — owner decision):**
- **Decide the canonical per-shipment load-manifest read** (which prepared store feeds actual loading; how trip/`vehicle_assignment` → `{pool_entry_id, product, preparation_wave_id, quantity_planned}` is resolved). Until then the driver cannot be shown "what to load."
- **A driver-facing thin loading entry/read** under `/api/driver/*` (gated by `loading.driver.operate`) that resolves ownership through the trip and delegates to the existing `StartLoadingAction`/`LoadProductAction`/`CompleteLoadingAction`/`VehicleInventoryService` — authored only after the manifest read is decided.
- Legitimate seed/UAT data (a driver + vehicle + trip + group + loading session) to browser-verify the mutation path.

**Wave 2 remaining (not started, per Part O):** driver order cards, order filters, Started Delivery, order details, partial delivery to customer, delivery proof, payment-transfer upload, failed delivery.

## 22. Wave 3 Remaining

Driver expenses, wallet, reports, commission, advances, shortage/liability, driver closing, monthly settlement.

---

## Final status

**IMPLEMENTED / BLOCKED — OWNER DECISION REQUIRED.**

- Implemented + browser-verified (EN+AR, user's real Chrome): Driver Home Part A (identity, assigned-orders, no-shipment state, Trip no longer exposed) + i18n.
- Blocked, reported, not worked around: the Loading screen and Loading→Vehicle-Inventory operation — the load *write* and the driver→shipment *bridge* exist, but the per-shipment "what to load" *read* is a missing contract over a flagged-dead Preparation-pool feed (owner decision), and zero live data prevents mutation-path verification without fabricating business data.
- Wave 2 and Wave 3 were not started.
