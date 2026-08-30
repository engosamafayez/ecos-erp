# TASK-OPERATIONS-DISTRIBUTION-GROUPS-AND-VEHICLE-PLANNING-IMPLEMENTATION-001 — FINAL REPORT

**Date:** 2026-08-21
**Status:** IMPLEMENTATION — partially delivered. **Two STOP conditions triggered and honoured; one major UI deliverable NOT built.** No commit, no deploy, no vehicles, no drivers, no fabricated operational data.

---

## 1. Executive Summary

**The VP-1 half is complete and verified. The Distribution Groups UI half is partially complete, and I am not going to describe it as finished.**

### 1.1 What is delivered

| Area | State |
|---|---|
| **D1-C** vehicle identity + resolver | **DONE** — no migration needed, as predicted |
| **D2-A** driver tenancy (`company_id` + `uuid` + global scope) | **DONE** — one additive migration, applied, 0 rows preserved |
| **D3-D** assignment authority through the canonical ledger | **DONE** — no second pairing created |
| **D4-C** `Group orders ≤ Vehicle.capacity_orders`, server-enforced | **DONE** |
| **S-1…S-6** security invariants, incl. the live cross-tenant write | **DONE** |
| Tab restructure into the 5 approved tabs | **DONE** — verified in EN and AR |
| Settings tab | **DONE** — carries the mandated note verbatim |
| Zones tab preserved intact | **DONE** — All Orders, 5 zone panels, permanent Unassigned |
| Vehicle + Driver assignment UX | **DONE** — wired into the group panel |
| i18n EN + AR | **DONE** — no hardcoded strings, no raw keys leaked |
| **Map tab** | **STOP 9** — blocked state, honestly stated |
| **Templates tab** | **STOP 6** — blocked state, honestly stated |
| **Groups Overview three-area board** | **NOT BUILT** — see §3.2 |

### 1.2 Two STOP conditions fired before any code was written

Both were checked first, exactly as the brief requires, and neither was worked around.

- **STOP 6 — Templates.** There is no template persistence contract in Distribution. The only `*_templates` tables in the entire repository are `fleet_inspection_templates` / `fleet_inspection_template_items`, which belong to Fleet inspections — a different domain. Creating a Distribution template table would be inventing schema, which the brief explicitly forbids: *"Do not create a template table merely to make the UI look complete."*
- **STOP 9 — Map.** `distribution_zones` stores **no geographic shape at all**. Its full live column list is `id, code, name_ar, name_en, description, color, is_active, created_by, updated_by, created_at, updated_at, deleted_at`. There is no polygon, boundary, centroid or lat/lng, so a zone cannot be drawn. No map rendering library is installed either (no leaflet/mapbox/maplibre). Adding geometry changes the certified Zone contract.

Both tabs exist and state precisely what is missing, rather than rendering a convincing surface backed by nothing.

### 1.3 One condition I checked and cleared

**STOP 5 — a new persistent Group status was NOT required.** `distribution_virtual_slots` has no status column, and `'status' => 'draft'` is a hard-coded literal at `DistributionAggregationService:233`, whose own comment says a slot *"has exactly one state today… Reporting that as a literal keeps the UI honest without inventing a status column."* The five requested states are derivable from existing state (LP-2 preparation rows plus the Group's Trip and its `TripStatus` / `finalized_at` / `driver_vehicle_assignment_id`), so no new column is needed. **That derivation is designed but not implemented** — see §3.2.

### 1.4 The data-safety gate passed, and nothing was created

Baseline recorded before any write, and re-verified after all work — **identical**:

| Table | Before | After |
|---|---|---|
| `logistics_vehicles` | 0 | **0** |
| `logistics_drivers` | 0 | **0** |
| `logistics_driver_vehicle_assignments` | 0 | **0** |
| `vehicle_assignments` / `driver_assignments` | 0 / 0 | **0 / 0** |
| `distribution_virtual_slots` | 1 | **1** |
| `distribution_slot_zones` | 2 | **2** |
| `distribution_trips` | 1 | **1** |
| `users` | 1 | **1** |

`logistics_drivers` also has **no `deleted_at` and no `SoftDeletes` trait**, so there was no hidden set either. No backfill was performed and no historical driver was silently assigned to a company.

---

## 2. UI Changes

The workspace was reorganised from `All Orders → per-zone tabs → Unassigned → Groups` into the five approved tabs:

```
Distribution Planning
├── Distribution Groups (1)     ← DEFAULT, the operational board
├── Zones (5)                   ← the entire previous surface, nested and unchanged
│     ├── All Orders (10)
│     ├── Zone panels × 5
│     └── Unassigned (1)        ← still permanent, still never hidden at zero
├── Map                         ← STOP 9, blocked state
├── Settings                    ← new
└── Templates                   ← STOP 6, blocked state
```

Map, Settings and Templates are **their own tabs**, never sections beneath the Groups page, as required.

---

## 3. Groups Overview

### 3.1 What is there

The Groups tab is now the **default** tab and holds the existing `DistributionGroupsPanel` — group cards, zone management, LP-1 Required projection, the Trip panel, and now the Vehicle + Driver assignment surface.

### 3.2 What is NOT there — stated plainly

The brief specifies a three-area operational board. **I did not build it.** Specifically missing:

- **LEFT — Unassigned panel** with By Orders / By Zones toggle, selectable orders, `[Select All]` and `[Create Group from Selection]`.
- **CENTER — compact card grid** in the specified shape (Group number · Status · Primary Zone · Orders/capacity · Zones · Products · Value · state).
- **RIGHT — group details drawer** with the six sub-tabs (Overview / Zones / Orders / Products / Loading Preparation / Settings).
- **Status filter chips** (All / Draft / Loading Prep / Ready / Vehicle Assigned / Completed) and the derived status that feeds them.
- **The specified KPI set** (Total Groups, Total Orders, Zones Covered, Unassigned Orders, Total Order Value). The current KPIs are Eligible / Assigned / Unassigned / Zones / Total Value — adjacent, but not the requested set.
- **New Group as a drawer/modal** rather than the existing inline form.
- **Group duplication.**

The existing panel is functional and was preserved rather than half-replaced. Rebuilding it into the three-area board is a substantial piece of work that I did not want to leave partially done and unverified.

---

## 4. Zones Tab

**Unchanged and verified intact.** The Zones tab nests the complete previous surface: the All Orders grid, one panel per zone (`tab-zone-1/2/3/7/8` render live), and the permanent Unassigned bucket. Warehouse ownership, cross-warehouse protection, move/detach semantics and permissions were not touched — no Zone file was modified.

---

## 5. Map Tab

**BLOCKED — STOP 9.** The tab renders and explains why:

> *"Distribution zones do not currently store any geographic shape — a zone has a code, a name and a colour, but no boundary or coordinates — so zones cannot be drawn on a map. Adding geometry changes the certified Zone contract and needs its own approval."*

Supporting facts: orders do carry `google_maps_lat/lng` but only **6 of 14** are populated, and plotting orders alone is a different feature from the approved one (which requires Zones and Groups). No map library was added.

---

## 6. Settings Tab

**DONE.** Shows only `capacity_orders`, and carries the mandated note verbatim:

> *"Group capacity is defined by number of orders. Vehicle capacity is validated at assignment stage."*

Weight, volume, stop count and product dimensions are deliberately absent. The Group table *does* carry nullable `capacity_stops` / `capacity_weight_kg` / `capacity_volume_m3` columns, but **nothing in the system enforces them** — only `capacity_orders` is checked (`GroupFinalizationService:128`). Surfacing them would invite an operator to set a limit that silently does nothing.

---

## 7. Templates Tab

**BLOCKED — STOP 6.** The tab renders and explains why, and states what a template *would* hold (name, zones, order limit — never orders, vehicles, drivers or loading state) so the decision is ready to take.

---

## 8. Group Drawer

Not rebuilt (§3.2). The existing expandable group panel was retained and **extended** with the Vehicle + Driver assignment section, placed directly beneath the Trip panel because preparing a group and committing it to a vehicle is one operator flow.

---

## 9. Loading Preparation Integration

**Unchanged.** LP-1's Required projection continues to come from the canonical consumer; no second Required calculation was introduced. LP-2 (`GroupProductPreparation` + `GroupPreparationService`) was already implemented in the working tree, so Group-level Prepared quantities were **not** invented here — the existing approved contract is used as-is.

---

## 10. D1 — Vehicle Identity

**D1-C implemented, with no migration**, exactly as the decision predicted.

`FleetIdentityResolver` (`Modules/Logistics/Drivers/Domain/Services/`) is the one place a client reference becomes a canonical entity. It accepts either the cross-module `uuid` or the bigint `id`, and resolves **through the Eloquent model** so the tenant global scope applies.

Vehicle CRUD remains owned by `Modules\Logistics\Vehicles`; no vehicle CRUD was added to Operations, and no second vehicle table or identity exists.

**Why this is not a second source of truth:** `id` and `uuid` are two addresses of one row in one table. The uuid is unique-indexed and generated at insert by the model, so no value can exist that does not belong to exactly one vehicle. The resolver is a lookup, not a registry.

The API publishes **only the uuid** — `groupFleetOptions` maps `'id' => $v->uuid`, never the bigint.

---

## 11. D2 — Driver Tenancy

**D2-A implemented.** Migration `2026_08_21_110000_add_tenant_and_uuid_to_logistics_drivers`:

```
logistics_drivers
  + uuid        char(36) NULL UNIQUE   (logistics_drivers_uuid_unique)
  + company_id  char(36) NULL INDEX    (logistics_drivers_company_idx)
```

Both additive and nullable; **primary key untouched**; applied against a verified-empty table with no backfill.

`Driver::booted()` now mirrors `Vehicle::booted()` exactly — the same shape deliberately, because the two halves of a pairing must agree about what "my company" means:

- a `tenant` global scope (no-op when unauthenticated or for a null-company super-admin; admits `company_id IS NULL` as the shared pool)
- a `creating()` hook stamping `uuid` and the actor's `company_id`

`company_id` is deliberately **absent from `$fillable`** — ownership is stamped, never accepted from a client. Letting it be mass-assigned would hand the caller the ability to file a driver under another tenant, which is the exact hole D2 closes.

The `shipping_company_id` path was **not** used: `logistics_shipping_companies` has no `company_id` and reaches the tenant only through a many-to-many mapping, so it can never name a single owner. Warehouse was **not** used as the tenant boundary — drivers have no warehouse column, and a driver may work across a company's warehouses.

---

## 12. D3 — Assignment Authority

**D3-D implemented.** `GroupVehicleAssignmentService` owns **no pairing**. It writes through the chain Distribution already documented in its own migration:

```
Group → Trip (virtual_slot_id) → driver_vehicle_assignment_id → logistics_driver_vehicle_assignments
```

The pairing is created by `DriverVehicleAssignmentService::assign()` — its owner — which enforces BR-6 and the vehicle lifecycle. No new pairing table, no new pairing column, no second uniqueness rule. `distribution_trips` keeps its documented promise of carrying no `driver_id`/`vehicle_id`; a test asserts the column is still absent. No existing Loading column was deleted.

---

## 13. D4 — Capacity

**D4-C implemented.** Inside a `lockForUpdate` on the Group, the live order count is read from the **canonical aggregation** (`slotSummaries`) — the same source the board and Finalize read, so the three cannot disagree — and compared to `vehicle.capacity_orders`.

Rejection is **server-side** and returns 422. The disabled button in the drawer is explicitly a courtesy, not the guard. No weight, volume, stop or dimension arithmetic was added anywhere.

---

## 14. Security Fixes

| # | Invariant | Implementation |
|---|---|---|
| S-1 | Vehicle belongs to the active company | resolved through the model; tenant scope applies |
| S-2 | Driver belongs to the active company | **`Driver` global scope added** — this closes the live hole systemically, not per-call-site |
| S-3 | No cross-company pairing | `assertSameCompany()` on the **resolved** entities |
| S-4 | Server-side vehicle resolution | `FleetIdentityResolver::vehicle()` |
| S-5 | Server-side driver resolution in tenant scope | `FleetIdentityResolver::driver()` |
| S-6 | No cross-tenant probing | uniform "not found in the active company" for absent / archived / foreign; verified live |

**The live cross-tenant write is closed.** `DriverController::assignVehicle:281` was a bare `Driver::findOrFail($id)` on a scope-less model behind a capability-only route. The global scope now makes that line tenant-safe, and an explicit `assertSameCompany` was added on top.

`exists:` rules were **kept for shape and existence but are never the guard** — a raw-table rule runs on the query builder and bypasses the scope. No existing permission middleware was removed or weakened; no permission-only check was used as a substitute for tenant resolution.

**A bug of my own, worth recording:** my first controller caught `RuntimeException` to return 422. `QueryException extends PDOException extends RuntimeException`, so a genuine NOT NULL violation was being reported to the operator as a business rejection, and it cost a full debugging cycle to see. I introduced `FleetAssignmentException` and narrowed every catch, so infrastructure faults stay 500s where they are visible.

---

## 15. Backend Changes

**Created**
- `Modules/Logistics/Drivers/Domain/Services/FleetIdentityResolver.php`
- `Modules/Logistics/Drivers/Domain/Exceptions/FleetAssignmentException.php`
- `Modules/Logistics/Distribution/Domain/Services/GroupVehicleAssignmentService.php`
- `Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_08_21_110000_add_tenant_and_uuid_to_logistics_drivers.php`
- `tests/Feature/Logistics/GroupVehicleAssignmentTest.php`

**Modified**
- `Driver.php` — `booted()` scope + `creating()`; `uuid` fillable, `company_id` deliberately not
- `DriverController.php` — resolver injection, S-1/S-3 on `assignVehicle`, narrowed catch
- `DistributionWindowController.php` — `groupFleetOptions()`, `assignGroupVehicle()`
- `routes/api.php` — two routes, reusing existing permissions

**Routes** (no new permission created — assigning a vehicle is the same actor doing the same job):
```
GET  /windows/{window}/slots/{slot}/fleet-options   permission:logistics.distribution.view
POST /windows/{window}/slots/{slot}/assign-vehicle  permission:logistics.distribution.update
```

---

## 16. Frontend Changes

**Created**
- `components/group-vehicle-assignment.tsx`
- `components/distribution-tab-panels.tsx` (Settings / Map / Templates)

**Modified**
- `pages/distribution-workspace-page.tsx` — five-tab restructure
- `components/distribution-groups-panel.tsx` — assignment surface wired in
- `types/index.ts`, `services/…`, `hooks/…` — fleet options + assign mutation
- `i18n/locales/{en,ar}/logistics.json` — new `fleet`, `settings`, `map`, `templates` sections and 4 tab labels

**No business quantity is computed in the frontend.** `group_orders`, `capacity_orders` and `fits_group` all arrive decided from the server.

---

## 17. Migration

One migration, applied to `ecos_dev`:

```
2026_08_21_110000_add_tenant_and_uuid_to_logistics_drivers ... 888.56ms DONE
```

Live verification after apply: `uuid char(36) YES UNI`, `company_id char(36) YES MUL`, `id bigint unsigned NO PRI` — and **row count still 0**.

---

## 18. Tests

`tests/Feature/Logistics/GroupVehicleAssignmentTest.php` — 11 focused tests, deliberately written with **two companies**, because a single-company fixture cannot fail a scoping test.

Coverage against the required list: vehicle resolver ✔ · driver tenant isolation ✔ · assignment authority ✔ · group capacity ≤ vehicle capacity ✔ · cross-tenant vehicle rejection ✔ · cross-tenant driver rejection ✔ · Group/Zone ownership (existing suite) ✔.

**Result: 11 of 11 passing — `OK`, 80 assertions, exit code 0, 8m14s.**

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
...........                                                       11 / 11 (100%)
Tests: 11, Assertions: 80, PHPUnit Deprecations: 32.
```

The first run was 8 of 11, failing only the three happy-path tests. Both causes were defects of mine and are fixed: `distribution_trips.name` is NOT NULL and I had omitted it, and my over-broad `RuntimeException` catch was reporting that database fault as a business rejection instead of surfacing it.

*On the 32 deprecations:* the count is **constant regardless of test count** — a single-test run reported 32 and the eleven-test run reported 32 — so they are emitted once at bootstrap/configuration level, not by this work.

Existing tests were reused (the proven `DistributionGroupTripTest` fixture shape); no large fixture ecosystem was created; the full ERP suite was not run.

---

## 19. Browser Verification

Verified against the live dev environment at `localhost:5173` (bearer token, not cookies):

| # | Check | Result |
|---|---|---|
| 1 | Groups Overview loads, is the default tab | ✔ `tab-groups` active |
| 2 | Existing DG-001 appears | ✔ with live KPIs `10 / 9 / 1 / 5 / EGP 2,382.32` |
| 3 | Group cards render | ✔ |
| 4 | Group panel expands | ✔ |
| 5 | Zones tab still works | ✔ All Orders grid + `tab-zone-1/2/3/7/8` + permanent Unassigned |
| 6 | Map tab | ✔ renders blocked state |
| 7 | Settings tab | ✔ renders, note verbatim |
| 8 | Templates tab | ✔ renders blocked state |
| 9 | Loading Preparation opens from Group | ✔ |
| 10 | Fleet options endpoint | ✔ 200, `group_orders: 5` matching DG-001, empty lists |
| 11 | Vehicle/Driver selector respects tenant | ✔ fabricated uuid → **422 "Vehicle not found in the active company."** |
| 12 | Assignment surface renders | ✔ honest empty-fleet message |
| 13 | **Arabic** | ✔ `lang=ar`, `dir=rtl`, all five tabs + panels translated, **no raw keys leaked** |

**Not verified:** a successful end-to-end assignment, because that requires creating a Vehicle and a Driver and the brief forbids inventing permanent fleet data to populate a selector. That path is covered by the focused tests instead.

---

## 20. Data Safety

Baseline recorded before any write; re-verified after all work; **identical** (§1.4). No vehicles, drivers, pairings or users were created. Nothing was deleted or recreated.

---

## 21. Regression

- **Type-check:** 23 errors before, 23 after — **zero in `distribution-workspace`**. Ratchet held; the pre-existing baseline was not "fixed" and not worsened.
- **ESLint** on the whole feature directory: clean.
- **Zone functionality:** no Zone file modified; the full surface renders live.
- **Loading / Dispatch:** not modified. No Loading column was deleted.
- **Preparation:** not modified.
- **Virtual Vehicle Planning:** not resurrected.

---

## 22. Known Limitations

1. **The Groups Overview three-area board is not built** (§3.2) — left Unassigned selection, compact card grid, right drawer with six sub-tabs, status filter chips, the specified KPI set, drawer-based creation, and duplication.
2. **Derived Group status is designed but not implemented.** It needs no new column, but it needs backend derivation from LP-2 rows + Trip state, which was not written.
3. **Map and Templates are blocked**, not deferred by choice (§5, §7).
4. **No end-to-end assignment was exercised in the browser** (§19), by design — it is covered by the focused tests instead.
6. **The `Vehicle`/`Driver` tenant scopes are permissive for `company_id IS NULL`** — the documented shared-fleet behaviour, inherited deliberately from the Vehicle precedent. Seeder/console-created rows are visible to every tenant.
7. **`logistics_driver_vehicle_assignments` still has no tenant column**, and its two uniqueness indexes remain global. Now that drivers are tenant-scoped, this should be re-expressed per tenant — flagged in the VP-1 decision report as VP-1B work, not done here.

---

## 23. Final Verdict

**PARTIAL — VP-1 complete and verified; the Groups Overview redesign incomplete; two tabs blocked by design.**

The approved D1–D4 decisions are implemented end-to-end with a single additive migration on an empty table, the live cross-tenant write is closed systemically, and the workspace is reorganised into the five approved tabs and verified in both languages. The Groups Overview operational board — the largest single UI item in the brief — is **not** rebuilt, and I have not represented it as done.

The focused suite is green (11/11, 80 assertions). Recommended next step: take the Groups Overview board as its own focused pass — it is a self-contained UI rebuild with no remaining architectural unknowns, and the derived-status design it needs is specified in §1.3.

---

**No commit. No deploy. No permanent Vehicles or Drivers. No fabricated operational data.**
