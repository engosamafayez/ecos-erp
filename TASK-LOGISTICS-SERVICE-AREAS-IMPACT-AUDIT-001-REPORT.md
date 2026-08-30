# TASK-LOGISTICS-SERVICE-AREAS-IMPACT-AUDIT-001 — REPORT

**Date:** 2026-08-24 · **Branch:** `develop` · **Mode:** STRICT READ-ONLY audit.
**Nothing was modified, created, deleted, migrated, deployed, or committed.**

---

## 1. Executive Summary

"Service Areas" is the **`Logistics/Network`** module (EPIC-LOG-V2, Phase 2) — a carrier/service
**coverage + capacity** configuration layer. A Service Area stores *no geography of its own*; it
references existing V1 rows (`distribution_zones`, `logistics_cities`, `logistics_governorates`) via
an include/exclude membership seam, layers "service levels" (SLA promises) on top, and exposes an
**advisory** capacity ledger ("Network advises, Orders decides").

**It is not part of the canonical Order → Delivery workflow.** No canonical module references it, it
holds zero data, and Distribution Planning runs fully without it. Its **only** consumers anywhere in
the codebase are two sibling EPIC-LOG-V2 planning modules — `Logistics/Operations`
(ResourcePool/capacity) and `Logistics/Dispatch` (DispatchBoard/allocation) — which are themselves
unpopulated and structurally (FK) bound to the Network tables.

**Primary verdict: C — UI-REDUNDANT BUT BACKEND RELEVANT.** The Service Areas UI can be retired from
the operator navigation without affecting any canonical workflow; the backend/schema must remain
because `Logistics/Operations` and `Logistics/Dispatch` depend on the `network_*` tables by foreign
key. Full removal (verdict E) is **not** justified without separately auditing those two modules.

---

## 2. Current Service Areas Architecture

Backend root: `backend/Modules/Logistics/Network/` · Frontend: `frontend/src/features/logistics/network/` · Route: `/logistics/network` · Nav label: **"Service Areas"** (key `logistics-network`, section `network-section`).

Design governed by "Directive 8 — no duplicate geography." Entities:

- **ServiceArea** (`network_service_areas`) — company-scoped; `code`, `name`, `status` (draft/active/paused/closed), `default_lead_time_hours`, `priority`, `color`, optional `dispatch_region_id`. Tenant global scope in `booted()` (null company ⇒ `whereRaw('1=0')`).
- **ServiceAreaMember** (`network_service_area_members`) — the anti-duplication seam: `member_type` ∈ {zone, city, governorate} + `member_id` (soft pointer to `distribution_zones`/`logistics_cities`/`logistics_governorates`) + `is_excluded`. No FK/morph; resolved live.
- **DispatchRegion** (`network_dispatch_regions`) — a planning grouping of service areas, anchored to a warehouse/branch origin.
- **ServiceLevel** (`network_service_levels`) + **CoverageRule** (`network_coverage_rules`) — SLA promises (cutoff, lead time, surcharge, served days) per (area, level).
- **Capacity** — `network_capacity_plans` / `_slots` / `_commitments`, an advisory booking ledger (`CapacityLedgerService`).
- **Services:** `ServiceAreaService` (lifecycle), `CoverageResolverService` (`resolveForCity`, `coverageFor` — address→serving area + promises), `CapacityLedgerService`.
- **Events:** `ServiceAreaOpened` / `ServiceAreaClosed` — dispatched on status change, **zero listeners**.
- **No jobs, commands, schedulers, or observers.** Capacity `sweepExpired` is a manual HTTP endpoint only.

## 3. Business Purpose (Part 3 answers, from code evidence)

1. **Owner:** the **Company**; areas are grouped under a `DispatchRegion` (Logistics Network module).
2. **Company-scoped?** **Yes** (`company_id` + tenant global scope).
3. **Shipping-Company-scoped?** **No** — no `shipping_company_id`; ShippingCompanies never reference it.
4. **Channel-scoped?** **No.**
5. **Warehouse-scoped?** Only indirectly — a `DispatchRegion` may anchor to a `warehouse_id`/`branch_id`; the ServiceArea itself is not warehouse-keyed.
6. **Driver/Vehicle-scoped?** **No.**
7. **Governorate/City-scoped?** It **aggregates** geography (zone/city/governorate members, include/exclude) — it references geography, it is not scoped to a single one.
8. **Represents service coverage?** **Yes** — "where a service/carrier can operate," plus SLA promises.
9. **Represents distribution grouping?** **No** — that is Distribution Zones (`distribution_zones`), a different module.
10. **Controls order eligibility?** **No** — Orders never query it.
11. **Controls carrier selection?** **No / not wired** — ShippingCompanies/Carriers never query it.
12. **Controls delivery availability?** **Not in the canonical flow** — capacity is advisory and consumed only by the (empty) Operations/Dispatch stack.

## 4. Frontend Audit

- Page `pages/service-areas-page.tsx` (route `/logistics/network`), hooks `hooks/use-network.ts`, service `services/network-service.ts`, types `types/network.ts`, components `service-area-drawer.tsx`, `area-status-badge.tsx`, `capacity-commitments-panel.tsx`.
- Displays: header metrics, status filters, a paginated table of areas, a **read-only** detail drawer, and a capacity-commitments panel. **No create/edit form for areas, members, regions, or levels is wired** — those mutations exist in the hook layer but no rendered control invokes them; the UI is effectively read-only apart from capacity reserve/commit/release.
- **Inbound references from other workspaces: none.** The only references to the network feature from outside its folder are app-shell wiring: `router/router.ts` (import + route), `router/routes.ts` (path constant), and i18n strings. No other workspace imports its API/hooks/service. Dispatch defines its own local `DispatchRegionRef` type rather than importing from Network.
- Frontend classification (Part 12): **B — CRUD UI** (self-contained), with the area-mutation controls dormant. Not consumed by any other workflow (not C/D beyond its own module).

## 5. Backend Audit

Fully self-contained under `Modules/Logistics/Network`: 8 models, 1 controller (`NetworkController`, 18 actions), 3 services, 1 resource, 2 (dead) events, enums, 5 migrations, 1 provider (singletons + migrations only). Routes at `backend/routes/api.php:2071-2109`. No console/job/scheduler wiring. See §18–§20.

## 6. Database Audit

All Network tables exist and are **empty** in `ecos_dev`:

| Table | Rows | Class |
|---|---|---|
| network_service_areas | 0 | C/D (built, unused; but structurally referenced) |
| network_service_area_members | 0 | C/D |
| network_service_levels | 0 | C/D |
| network_coverage_rules | 0 | C/D |
| network_dispatch_regions | 0 | C/D |
| network_capacity_plans / _slots / _commitments | 0 / 0 / 0 | C/D |

- **Outbound FKs:** network tables → `companies`, and the internal Network chain (areas→regions, members→areas, plans→areas, slots→plans/levels, commitments→slots).
- **Inbound FKs (external):** only from the EPIC-LOG-V2 siblings — `ops_resource_pools.service_area_id`→network_service_areas, `ops_resource_pools.dispatch_region_id` & `dispatch_boards.dispatch_region_id`→network_dispatch_regions, `ops_capacity_reservations.*`→network_capacity_slots/commitments. **No inbound FK from any canonical Orders/Distribution/Loading table.** Those consumer tables are themselves **0 rows**.
- No `service_area_id`/`service_level_id` column exists on any Orders/Distribution/Zone/Group/Loading table.

## 7. Orders Impact

**No dependency.** Zero references to any Network class or `network_*` table anywhere under `backend/Modules/Commerce` (Orders + OrderImport). Order creation, import, verification, address handling, and geography binding do not query Service Areas. `CoverageResolverService` is never called from Orders (the routes comment "Orders decides" describes an *intended* integration that is **not wired**).

## 8. Geography Impact

**No dependency; coupling is one-way (Network → Geography).** Geography (governorates/cities, 27/211 rows, plus shipping pricing) has no reference to any Network class. The link is the reverse: `CoverageMemberType`/`ServiceAreaMember` softly reference `logistics_governorates`/`logistics_cities`/`distribution_zones` as membership targets. Geography loads and functions independently.

## 9. Distribution Impact

**No dependency.** The only references in the entire Distribution module are two **PHPDoc comments** (`VirtualCapacitySlot.php:20` and a migration note) that explicitly state the Network capacity ledger is "a different bounded context … not reused as the storage here." Distribution has its own `distribution_virtual_slots`/`VirtualCapacitySlot`. Distribution Planning was verified fully functional (wave, 12 orders, 6 zones, 3 groups) with **zero** service areas.

## 10. Distribution Zones Comparison (Part 15 — mandatory)

| Capability | Service Areas | Distribution Zones |
|---|---|---|
| Purpose | Carrier/service **coverage** + SLA + advisory capacity | Distribution **grouping** of eligible orders |
| Owner | `Logistics/Network` (EPIC-LOG-V2) | `Logistics/Distribution` (canonical) |
| Geographic meaning | Aggregates zones/cities/governorates (include/exclude) | An operational zone (`distribution_zones`) |
| Used by Orders | **No** | Yes (order → zone binding) |
| Used by Templates | **No** | **Yes** |
| Used by Groups | **No** | **Yes** |
| Used by Map | **No** | **Yes** |
| Used by Carrier | **No** (unwired) | No |
| Used by Delivery | **No** (advisory only, unwired) | Yes (via group → loading → delivery) |
| Current necessity | **Not required by canonical flow** | **Current canonical** |

They are **not** equivalent: a Service Area may *reference* a Distribution Zone as a coverage member, but the canonical flow uses Distribution Zones and never touches Service Areas.

## 11. Templates Impact

**No dependency.** Distribution Group Templates (`distribution_group_templates`) reference zones (and now recommended drivers) — never Service Areas. Retiring Service Areas is **SAFE** for templates.

## 12. Groups Impact

**No dependency.** Groups (`distribution_virtual_slots`) derive from zones; no Network reference. **SAFE.**

## 13. Shipping Companies Impact

**No dependency.** No reference from `ShippingCompanies` or Carrier/Bosta integrations to any Network class/table. Carrier coverage and selection do not use Service Areas. **SAFE.**

## 14. Channels Impact

**No dependency.** Zero references under `Commerce/Channels` (incl. WooCommerce sync). **SAFE.**

## 15. Loading Impact

**No dependency.** Neither `Operations/Loading` nor Distribution's loading controllers reference any Network class/table. **SAFE.**

## 16. Drivers / Vehicles Impact

**No dependency.** `Logistics/Vehicles` and `Logistics/Drivers` contain no Network reference; driver/vehicle eligibility and assignment never consult Service Areas. **SAFE.**

## 17. Delivery Impact

**No dependency.** `Logistics/Delivery`/driver-mobile contain no Network reference; Trips live in Distribution and reference no Network class. **SAFE.**

## 18. APIs

All under `Route::middleware('auth:sanctum')->prefix('logistics/network')` (api.php:2071-2109), `NetworkController`:
- `network.view`: GET `/options`, `/service-areas`, `/service-areas/{id}`, `/service-areas/{id}/capacity-plans`, `/dispatch-regions`, `/service-levels`; POST `/coverage/resolve`, `/capacity/availability`.
- `network.manage`: POST/PATCH/DELETE service-areas, members, regions, levels.
- `network.capacity.commit`: POST `/capacity/reserve`, PATCH `/capacity/{id}/commit|release`.
- `network.capacity.manage`: POST `/capacity/sweep-expired`.

**Callers:** only the Network feature's own frontend (`network-service.ts`). No canonical workflow calls these endpoints. `coverage/resolve` (the natural integration point for Orders/carrier selection) has **no caller**.

## 19. Events / Jobs

- Events `ServiceAreaOpened` / `ServiceAreaClosed` are dispatched by `ServiceAreaService::changeStatus` but have **zero listeners/subscribers** anywhere — a dormant extension point.
- **No jobs, no console commands, no scheduled tasks, no webhooks, no notifications.** `sweepExpired` runs only if an operator hits the endpoint (and the frontend sweep handler has a response-contract bug — `reclaimed` vs `released` — so it always reports failure; noted for the Network owner, not fixed here).

## 20. Permissions

`network.view`, `network.manage`, `network.capacity.commit`, `network.capacity.manage` — seeded by the Network module's own migration and referenced only by its routes/model/tests. **Not reused** by Geography, Shipping Companies, Distribution, Orders, Channels, Loading, Drivers, Vehicles, or Delivery. Retiring the Service Areas UI does not affect any other module's permissions.

## 21. Browser Verification (read-only; no forms submitted)

1. **Service Areas page loads** at `/app/logistics/network`; shows 0 service areas / 0 dispatch regions, empty state "Create a service area to define delivery coverage," and a capacity panel noting "the operator workflow is the capacity reservation in **Operations**." ✓
2. Navigation location: Shipping module → "Network" section → "Service Areas." ✓
3. Displays coverage/service-level/capacity config; **no data**. ✓
4. **No other canonical workspace visibly consumes Service Area data.** ✓
5. **Distribution Planning loads independently** — fully functional (Preparation Wave PREP-202608-000008, 12 eligible orders, 6 zones, 3 groups) with zero service areas. ✓
6. **Distribution Zones loads independently** (`/logistics/geography/distribution-zones`; legacy `/logistics/distribution/zones` still redirects). ✓
7. **Geography loads independently** — 27 governorates, 211 cities, shipping pricing. ✓
8. **Shipping Companies loads independently.** ✓

No Service Areas/Orders/Distribution/Shipping records were created, edited, deleted, or submitted.

## 22. Retirement Impact Matrix

| Area | Retire **UI** only | Retire **backend + tables** |
|---|---|---|
| Orders | SAFE | SAFE |
| Geography | SAFE | SAFE |
| Distribution / Zones / Templates / Groups / Map | SAFE | SAFE |
| Shipping Companies / Carrier integrations | SAFE | SAFE |
| Channels | SAFE | SAFE |
| Loading | SAFE | SAFE |
| Drivers / Vehicles | SAFE | SAFE |
| Trips / Delivery | SAFE | SAFE |
| **`Logistics/Operations`** (ResourcePool, capacity) | SAFE (its own UI unaffected) | **BLOCKED** — FKs `ops_resource_pools.service_area_id`/`dispatch_region_id`, `ops_capacity_reservations.*` → network tables; code `use`s Network classes |
| **`Logistics/Dispatch`** (DispatchBoard, allocation) | SAFE | **BLOCKED** — FK `dispatch_boards.dispatch_region_id`; code `use`s Network CapacitySlot/Commitment/DispatchRegion |

Retiring only the Service Areas **navigation item + page** is SAFE across the board. Removing the **backend/schema** is BLOCKED by the Operations and Dispatch modules (structural FKs + code), independent of the fact that all those tables are currently empty.

## 23. Final Verdict

**C — UI-REDUNDANT BUT BACKEND RELEVANT.**

The Service Areas UI is not required by any canonical Order → Reservation → Preparation → Distribution
Planning → Zone → Template → Group → Loading → Vehicle/Driver → Delivery workflow (proven: zero code
references from those modules, zero data, Distribution Planning fully operational without it, no inbound
FKs from canonical tables). It **may be retired from the operator navigation** with no canonical impact.

The backend and schema, however, are **relevant and must remain**: `Logistics/Operations` and
`Logistics/Dispatch` reference the `network_*` tables by foreign key and use the Network domain classes.
This is why the verdict is C, not D (which treats backend relevance as merely "leave pending"), and not
E (full removal would break Operations/Dispatch schema). Verdict A/B are excluded because no canonical
workflow consumes it. Verdict F is excluded because the impact **is** provable — the dependency is fully
enumerated and confined to two sibling EPIC-LOG-V2 modules.

## 24. Recommended Future Position (advisory only — no action taken)

Service Areas is the coverage/capacity front-end of a **dormant EPIC-LOG-V2 "carrier network" stack**
(Network + Operations-capacity + Dispatch) that is fully built, entirely unpopulated (0 rows across all
its tables), disconnected from the live canonical flow, and partially unfinished (dead events, no
scheduler, unwired create UI, a sweep response bug). Reasonable future options, each needing its own
approved task:
- **Retire the UI** (remove the "Service Areas" nav item / page from the Shipping module) while leaving
  backend + schema intact — safe now, matches verdict C.
- **Keep as-is** if the EPIC-LOG-V2 carrier-network capability is still on the roadmap.
- Only consider **full removal** after a companion audit of `Logistics/Operations` and `Logistics/Dispatch`
  confirms the whole stack is being retired together.

This audit makes **no** recommendation to act; it only establishes that a UI retirement would be safe.

## 25. Exact Files / Objects Inspected (read-only)

**Backend (code):** `Modules/Logistics/Network/{Domain/Models/*, Domain/Services/*, Domain/Events/*, Domain/Enums/*, Presentation/Http/{Controllers/NetworkController.php,Resources/ServiceAreaResource.php}, Infrastructure/{Providers/LogisticsNetworkServiceProvider.php,Database/Migrations/*}}`; `backend/routes/api.php` (2071-2109, 110); the Network permission seeder + IAM restore migration; consumer evidence in `Modules/Logistics/Operations/*` and `Modules/Logistics/Dispatch/*`; PHPDoc-only hits in `Modules/Logistics/Distribution/*`. Confirmed NO hits in `Modules/Commerce/{Orders,OrderImport,Channels}`, `Modules/Logistics/{ShippingCompanies,Vehicles,Drivers,Delivery,Geography}`, `Modules/Operations/{Preparation,Loading}`.
**Frontend (code):** `frontend/src/features/logistics/network/*`; `router/router.ts`, `router/routes.ts`, `config/module-navigation.ts`, `i18n/locales/{en,ar}/*.json`.
**Database (read-only queries):** `information_schema` (tables, columns, key_column_usage) + `COUNT(*)` on `network_*`, `ops_resource_pools`, `ops_capacity_reservations`, `dispatch_boards`.
**Browser (read-only):** `/app/logistics/network`, `/app/logistics/geography`, `/app/logistics/distribution/workspace`, `/app/logistics/geography/distribution-zones`, `/app/logistics/shipping-companies`.

## 26. Data Safety Statement (Part 19)

- No files modified.
- No migrations created.
- No migrations executed.
- No database data changed (read-only `SELECT`/`information_schema` only).
- No routes changed.
- No navigation changed.
- No permissions changed.
- No business logic changed.
- No Distribution logic changed.
- No Shipping Company logic changed.
- No deployment performed.
- No commit created.
- No follow-up implementation started.

**This was an audit only. Service Areas was not removed, hidden, moved, renamed, or replaced, and Distribution Zones / Geography were not modified. STOP.**
