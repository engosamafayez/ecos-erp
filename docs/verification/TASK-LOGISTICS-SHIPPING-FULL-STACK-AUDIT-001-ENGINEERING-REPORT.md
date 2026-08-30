# TASK-LOGISTICS-SHIPPING-FULL-STACK-AUDIT-001 — ENGINEERING REPORT

**Final status: AUDIT COMPLETE — IMPLEMENTATION UNCHANGED**

| | |
|---|---|
| Task | TASK-LOGISTICS-SHIPPING-FULL-STACK-AUDIT-001 |
| Type | Audit only — no production changes |
| Date | 2026-08-17 |
| Branch | `develop` @ `ec43b470` |
| Authority | **Current repository code.** ADRs and prior reports used only to detect contradiction. |
| Scope | Logistics / Shipping / Distribution / Loading / Dispatch / Delivery / Driver Mobile / Returns / Settlement |
| Certification | **NOT CERTIFIED.** Certification was explicitly out of scope. |
| Repo mutation | This report file only. No code, route, migration, fixture, schema, or data change. No commit, no stage, no deploy, no `docker cp`. |

### Evidence method

| Source | How it was obtained |
|---|---|
| Frontend routes | Direct read of `frontend/src/router/router.ts` + `routes.ts` |
| Frontend navigation | Direct read of `frontend/src/config/module-navigation.ts` + `features/authorization/use-navigation.ts` |
| Backend routes | `php artisan route:list --json` in `ecos-dev-app` (1,856 routes), cross-checked against `backend/routes/api.php` |
| Route-list trust | `md5sum` of `routes/api.php` in container == repo (`f54acb75…`). Byte-identical, so the route dump is authoritative. |
| Contract map | Scripted diff of every endpoint literal in 26 shipping service files against the 1,856 registered routes |
| Schema | Read-only `information_schema` + `COUNT(*)` queries against `ecos_dev` / `ecos_dev_test` |
| Permissions | Read-only `SELECT` from `permissions`, diffed against every `RequirePermissionMiddleware:` argument in the route table |

No test suite was run. No mutating query was issued. Per Part 25, only route inspection, static reads, and non-destructive schema/count queries were used.

---

## 1. Executive Summary

The Shipping/Logistics domain is **not one system**. It is **four independently-built stacks** that were never joined. Two of them work, two of them cannot work at all, and the one stack that carries the complete operational workflow is invisible in the navigation menu.

**The four stacks**

| # | Stack | Backend | Frontend | Verdict |
|---|---|---|---|---|
| **A** | `Logistics\*` (14 submodules) | 416 routes, real | 24 pages, wired | **WORKS.** The canonical stack. |
| **B** | `Operations\Loading` (Loading & Allocation OS) | 24 routes, real, **the only inventory-correct dispatch path in the system** | **none** | **ORPHAN BACKEND.** Zero frontend callers. |
| **C** | `operations/distribution-board` (Distribution Board / Loading / Dispatch Gate) | **none** — `/api/distribution/*` has **0 routes** | 5 pages, 43 endpoint calls | **ORPHAN FRONTEND.** Every call 404s. |
| **D** | `operations/driver-mobile` (Driver Mobile OS) | **none** — `/api/driver/*` has **0 routes** | 11 pages, 20 endpoint calls | **ORPHAN FRONTEND.** Every call 404s. |

**The seven findings that matter**

1. **16 registered pages are 100% non-functional** (stacks C + D). They call `/api/distribution/*` and `/api/driver/*`. Neither namespace exists — `api/distribution` returns 0 routes, `api/driver` returns 0 routes. This is not a partial gap; not one endpoint of the 63 they call is registered. Both services also bypass the authenticated Axios client, so they would 401 even if the routes existed.
2. **The only working trip/delivery/settlement UI is not in the navigation.** `/logistics/distribution/trips` (`TripsWorkspacePage`) is fully wired to stack A — trips, stops, POD, returns, custody, exceptions, settlement, payments — and is reachable only by typing the URL.
3. **The only inventory-correct dispatch path has no UI.** `Operations\Loading` → `DispatchVehicleAction` → `LoadVehicleWorkflow` → `ShipOrderInventoryAction` correctly consumes FIFO, supports split shipment, and is idempotent. Nothing in the frontend calls it.
4. **The reachable stack never touches inventory.** The entire `Modules\Logistics` tree — all 14 submodules, 416 routes — contains **zero** references to any inventory model, stock ledger, or restock action. Loading, dispatch, delivery, and returns in stack A mutate no stock.
5. **P0 tenant isolation breach.** `TripController` derives company from **request input**, not from the authenticated user. `loadTrip()`/`resolveTrip()` are bare `where('uuid', $id)` lookups. Any authenticated user can read, modify, add orders to, and **dispatch** any other company's trip. The Delivery OS detail paths (COD collect, POD capture, returns) have the same bare-UUID lookup. `logistics_drivers` has no `company_id` column at all.
6. **62 of 70 shipping domain events have no consumer.** `TripDispatched`, `TripStatusChanged`, `TripSettled`, `DeliveryStopCompleted`, `DeliverySucceeded`, `CodCollected`, `ReturnReceived` — all fire into the void. Completing a delivery changes no order status, moves no stock, posts no journal.
7. **177 shipping routes are permanently 403 in `ecos_dev`.** 17 permissions referenced by live routes are absent from the `permissions` table. The middleware fails closed. This is **environment drift, not a code defect** — `ecos_dev_test` has all 17; `ecos_dev` has none, while `migrations` records the seeding migrations as already run (so a plain `migrate` will not restore them).

**Vehicle inventory reconciliation does not exist.** `vehicle_shift_reconciliations` + `_lines` have models, migrations, relations, and a FormRequest — but no controller, no route, and no writer. `VehicleInventoryService::recordReturn()`, `recordDelivery()`, and `unallocate()` have **zero callers**: the vehicle ledger only ever grows.

**The domain has never been exercised.** Every shipping transactional table in `ecos_dev` has **0 rows** — no trip, no delivery, no loading session, no settlement, no POD, no COD has ever been created. Only master data exists (1 driver, 1 vehicle, 3 zones).

**Critical gaps: 9. Proposed follow-up tasks: 14. User action required: yes** — the P0 tenant breach and the stack C/D decision (build the backend vs. retire the pages vs. redirect to stack A) are business/architecture calls, not engineering defaults.

---

## 2. Current Frontend Route Inventory

Every route below is registered in `frontend/src/router/router.ts`. No route is lazy-loaded — all 40 components are **eager static imports** at the top of `router.ts`. No route carries a permission guard: the only guard in the tree is `ProtectedRoute`, which checks authentication status **only** (`frontend/src/router/guards/protected-route.tsx:11-23`). **Every route below is reachable by direct URL by any authenticated user regardless of permission.**

### 2.1 Logistics OS block — `router.ts:376-399`

| # | URL | ROUTES key | Component | Feature dir | In nav? | Perm at route |
|---|---|---|---|---|---|---|
| 1 | `/logistics/geography` | `logisticsGeography` | `EgyptGeographyPage` | `logistics/geography` | ✅ Shipping | none |
| 2 | `/logistics/distribution/zones` | `logisticsDistributionZones` | `DistributionZonesPage` | `logistics/distribution-zones` | ✅ Shipping | none |
| 3 | `/logistics/distribution/planning` | `logisticsDistributionPlanning` | `DistributionPlanningPage` | `logistics/distribution-planning` | ✅ Shipping | none |
| 4 | `/logistics/distribution/workspace` | `logisticsDistributionWorkspace` | `DistributionWorkspacePage` | `logistics/distribution-workspace` | ❌ **hidden** | none |
| 5 | `/logistics/distribution/trips` | `logisticsTrips` | `TripsWorkspacePage` | `logistics/trips` | ❌ **hidden** | none |
| 6 | `/logistics/carriers` | `logisticsCarrierAccounts` | `CarrierAccountsPage` | `logistics/carriers` | ✅ Shipping | none |
| 7 | `/logistics/automation` | `logisticsAutomation` | `AutomationMonitoringPage` | `logistics/automation` | ✅ Shipping | none |
| 8 | `/logistics/intelligence` | `logisticsIntelligence` | `LogisticsIntelligencePage` | `logistics/intelligence` | ✅ Shipping | none |
| 9 | `/logistics/fleet/fuel-review` | `logisticsFuelReview` | `FuelReviewPage` | `logistics/fleet` | ✅ Shipping | none |
| 10 | `/logistics/dispatch/boards` | `logisticsDispatchBoard` | `DispatchBoardPage` | `logistics/dispatch` | ✅ Shipping | none |
| 11 | `/logistics/shipping-companies` | `logisticsShippingCompanies` | `ShippingCompaniesPage` | `logistics/shipping-companies` | ✅ Shipping | none |
| 12 | `/logistics/drivers` | `logisticsDrivers` | `DriversPage` | `logistics/drivers` | ✅ Shipping | none |
| 13 | `/logistics/vehicles` | `logisticsVehicles` | `VehiclesPage` | `logistics/vehicles` | ✅ Shipping | none |
| 14 | `/logistics/delivery` | `logisticsDelivery` | `DeliveryPage` | `logistics/delivery` | ✅ Shipping | none |
| 15 | `/logistics/fleet` | `logisticsFleet` | `FleetDashboardPage` | `logistics/fleet` | ✅ Shipping | none |
| 16 | `/logistics/network` | `logisticsNetwork` | `ServiceAreasPage` | `logistics/network` | ✅ Shipping | none |
| 17 | `/logistics/dispatch` | `logisticsDispatch` | `DispatchCommandCenterPage` | `logistics/dispatch` | ✅ Shipping | none |
| 18 | `/logistics/operations` | `logisticsOperations` | `OperationsCenterPage` | `logistics/operations` | ✅ Shipping | none |
| 19 | `/logistics/dispatch/execution` | `logisticsDispatchExecution` | `DispatchExecutionPage` | `logistics/dispatch` | ✅ Shipping | none |
| 20 | `/logistics/operations/dashboards` | `logisticsOpsDashboards` | `OperationalDashboardsPage` | `logistics/operations` | ✅ Shipping | none |
| 21 | `/logistics/operations/alerts` | `logisticsOpsAlerts` | `AlertCenterPage` | `logistics/operations` | ✅ Shipping | none |
| 22 | `/logistics/operations/activity` | `logisticsOpsActivity` | `ActivityCenterPage` | `logistics/operations` | ✅ Shipping | none |
| 23 | `/logistics/operations/readiness` | `logisticsOpsReadiness` | `EnterpriseReadinessPage` | `logistics/operations` | ✅ Shipping | none |
| 24 | `/logistics/enterprise` | `logisticsEnterprise` | `EnterpriseWorkspacePage` (aliased `LogisticsEnterpriseWorkspacePage`) | `logistics/operations` | ✅ Shipping | none |

### 2.2 Distribution / Loading / Dispatch Gate block — `router.ts:368-374`

| # | URL | ROUTES key | Component | Feature dir | In nav? |
|---|---|---|---|---|---|
| 25 | `/operations/distribution/board` | `distributionBoard` | `DistributionBoardPage` | `operations/distribution-board` | ❌ **hidden** |
| 26 | `/operations/distribution/loading/:tripId/loading` | `loadingWorkspace` + suffix | `LoadingWorkspacePage` | `operations/distribution-board` | ❌ **hidden** |
| 27 | `/operations/loading/dashboard` | `loadingOsDashboard` | `LoadingDashboardPage` | `operations/distribution-board` | ❌ **hidden** |
| 28 | `/operations/dispatch-gate` | `dispatchGate` | `DispatchGatePage` | `operations/distribution-board` | ❌ **hidden** |
| 29 | `/operations/dispatch-gate/:tripId` | `dispatchGate` + param | `DispatchGateWorkspacePage` | `operations/distribution-board` | ❌ **hidden** |

Note the URL oddity on #26: `loadingWorkspace` is `/operations/distribution/loading`, and the registered path appends `/:tripId/loading`, producing `/operations/distribution/loading/:tripId/loading` — "loading" twice.

### 2.3 Driver Mobile block — `router.ts:499-509`

| # | URL | ROUTES key | Component | In nav? |
|---|---|---|---|---|
| 30 | `/driver/home` | `driverHome` | `DriverHomePage` | ❌ **hidden** |
| 31 | `/driver/trips/:tripId` | `driverTrip` | `DriverTripDashboardPage` | ❌ hidden |
| 32 | `/driver/trips/:tripId/stops` | `driverTripStops` | `DriverStopListPage` | ❌ hidden |
| 33 | `/driver/trips/:tripId/stops/:stopId` | `driverTripStop` | `DriverStopDetailPage` | ❌ hidden |
| 34 | `/driver/trips/:tripId/collections` | `driverTripCollections` | `DriverCollectionsPage` | ❌ hidden |
| 35 | `/driver/trips/:tripId/exceptions` | `driverTripExceptions` | `DriverExceptionsPage` | ❌ hidden |
| 36 | `/driver/trips/:tripId/returns` | `driverTripReturns` | `DriverReturnsPage` | ❌ hidden |
| 37 | `/driver/trips/:tripId/settlement` | `driverTripSettlement` | `DriverSettlementPage` | ❌ hidden |
| 38 | `/driver/trips/:tripId/custody` | `driverTripCustody` | `DriverCustodyReturnPage` | ❌ hidden |
| 39 | `/driver/trips/:tripId/timeline` | `driverTripTimeline` | `DriverTripTimelinePage` | ❌ hidden |
| 40 | `/driver/trips/:tripId/map` | `driverTripMap` | `DriverMapPage` | ❌ hidden |

All 11 driver routes sit **inside** `ProtectedRoute` **and inside `AppShell`** — i.e. a driver on a phone gets the full desktop module rail and context sidebar. There is no driver-specific shell, and no mobile-only layout.

### 2.4 Adjacent (Shipping module nav entry, Commerce-owned)

| # | URL | Component | Backend | Status |
|---|---|---|---|---|
| 41 | `/fulfillments` | `FulfillmentsPage` | `api/fulfillments` (7 routes) | ✅ connected |
| 42 | `/fulfillments/new` | `CreateFulfillmentPage` | `POST api/fulfillments` | ✅ connected |
| 43 | `/fulfillments/:id` | `ViewFulfillmentPage` | `GET api/fulfillments/{id}` | ✅ connected |

**Totals: 40 in-scope shipping routes + 3 adjacent fulfillment routes. 24 in navigation, 16 hidden.**

Every one of these is a real page (a `*-page.tsx` default/named export rendering a full screen), not a bare component. `LoadingWorkspacePage`, `DispatchGateWorkspacePage` and the driver pages are full pages despite being sub-routes.

---

## 3. Current Navigation Inventory

### 3.1 How navigation actually resolves

`frontend/src/config/module-navigation.ts` is the single live source. Structure: **Module Rail → per-module sidebar**. Labels have **no `label` field** — display text is an i18n key typed against `common.json` (`NavItemKey = keyof (typeof enCommon)['nav']['items']`), so a key without a translation is a compile error.

Gating is done by `features/authorization/use-navigation.ts`:

```
isModuleVisible(moduleId, ctx):
  1. org feature flag  (shipping → 'shipping'; logistics → 'shipping')
  2. ctx.isSystem            → allow all
  3. ALWAYS_VISIBLE          → 'dashboard'
  4. ctx.navigation whitelist (Role Template) → AUTHORITATIVE if non-empty
  5. fallback: holds ANY permission in MODULE_DOMAINS[moduleId]
               shipping → ['logistics']; logistics → ['logistics']
```

**This is module-level gating only.** There is no per-item and no per-route permission check. Consequence: a user with any single `logistics.*` permission sees **all 23 Shipping sidebar items**, including Fleet, Network, Dispatch, Intelligence and Operations — and can open all 40 routes by URL.

### 3.2 The `logistics` module rail entry is hidden and empty

`module-navigation.ts:337-341` defines module `logistics` with `items: []`, and `HIDDEN_MODULE_IDS` (line 461-467) hides it. Every Logistics workspace is instead parented under the **`shipping`** module. This is intentional and documented in the file.

### 3.3 Live Shipping module sidebar — `module-navigation.ts:197-246`

| Nav label (i18n key) | Route | Component | Feature | Permission |
|---|---|---|---|---|
| `fulfillments` | `/fulfillments` | `FulfillmentsPage` | fulfillments | module-level only |
| — *`carriers-section`* — | (section header) | — | — | — |
| `logistics-shipping-companies` | `/logistics/shipping-companies` | `ShippingCompaniesPage` | shipping-companies | module-level only |
| `logistics-carriers` | `/logistics/carriers` | `CarrierAccountsPage` | carriers | module-level only |
| `logistics-automation` | `/logistics/automation` | `AutomationMonitoringPage` | automation | module-level only |
| `logistics-intelligence` | `/logistics/intelligence` | `LogisticsIntelligencePage` | intelligence | module-level only |
| `logistics-fuel-review` | `/logistics/fleet/fuel-review` | `FuelReviewPage` | fleet | module-level only |
| `logistics-drivers` | `/logistics/drivers` | `DriversPage` | drivers | module-level only |
| `logistics-vehicles` | `/logistics/vehicles` | `VehiclesPage` | vehicles | module-level only |
| — *`fleet-section`* — | | | | |
| `logistics-fleet` | `/logistics/fleet` | `FleetDashboardPage` | fleet | module-level only |
| — *`network-section`* — | | | | |
| `logistics-network` | `/logistics/network` | `ServiceAreasPage` | network | module-level only |
| — *`dispatch-section`* — | | | | |
| `logistics-dispatch` | `/logistics/dispatch` | `DispatchCommandCenterPage` | dispatch | module-level only |
| `logistics-dispatch-exec` | `/logistics/dispatch/execution` | `DispatchExecutionPage` | dispatch | module-level only |
| `logistics-dispatch-board` | `/logistics/dispatch/boards` | `DispatchBoardPage` | dispatch | module-level only |
| — *`operations-section`* — | | | | |
| `logistics-operations` | `/logistics/operations` | `OperationsCenterPage` | operations | module-level only |
| `logistics-ops-dashboards` | `/logistics/operations/dashboards` | `OperationalDashboardsPage` | operations | module-level only |
| `logistics-ops-alerts` | `/logistics/operations/alerts` | `AlertCenterPage` | operations | module-level only |
| `logistics-ops-activity` | `/logistics/operations/activity` | `ActivityCenterPage` | operations | module-level only |
| `logistics-ops-readiness` | `/logistics/operations/readiness` | `EnterpriseReadinessPage` | operations | module-level only |
| `logistics-enterprise` | `/logistics/enterprise` | `EnterpriseWorkspacePage` | operations | module-level only |
| — *`geo-section`* — | | | | |
| `egypt-geography` | `/logistics/geography` | `EgyptGeographyPage` | geography | module-level only |
| — *`dist-section`* — | | | | |
| `logistics-distribution-zones` | `/logistics/distribution/zones` | `DistributionZonesPage` | distribution-zones | module-level only |
| `logistics-distribution-plan` | `/logistics/distribution/planning` | `DistributionPlanningPage` | distribution-planning | module-level only |
| — *`delivery-section`* — | | | | |
| `logistics-delivery` | `/logistics/delivery` | `DeliveryPage` | delivery | module-level only |

### 3.4 Operations module sidebar — `module-navigation.ts:191-196`

One item only:

| Nav label | Route | Component |
|---|---|---|
| `wave-workspace` | `/operations/preparation/wave-workspace` | `WaveWorkspaceLayout` |

**Distribution Board, Loading Dashboard, Loading Workspace, Dispatch Gate and all Driver Mobile pages are absent from this module — and from every module.**

### 3.5 Pages in code but absent from navigation (16)

| Route | Component | Why it matters |
|---|---|---|
| `/logistics/distribution/trips` | `TripsWorkspacePage` | **The only working trip → delivery → settlement UI in the product.** Fully wired to stack A. |
| `/logistics/distribution/workspace` | `DistributionWorkspacePage` | Wired to the live distribution-window read model. |
| `/operations/distribution/board` | `DistributionBoardPage` | Backend does not exist. |
| `/operations/loading/dashboard` | `LoadingDashboardPage` | Backend does not exist. |
| `/operations/distribution/loading/:tripId/loading` | `LoadingWorkspacePage` | Backend does not exist. |
| `/operations/dispatch-gate` (+`/:tripId`) | `DispatchGatePage`, `DispatchGateWorkspacePage` | Backend does not exist. |
| `/driver/home` + 10 `/driver/trips/*` | 11 driver pages | Backend does not exist. |

### 3.6 Navigation items pointing to missing routes

**None.** Every path in every `APP_MODULES` entry resolves to a registered route. Navigation has no dead links.

### 3.7 Dead navigation file

`frontend/src/config/navigation.ts` (180 lines) exports `NAV_GROUPS`, `NAV_ITEMS`, `findNavItemByPath` — and has **zero importers** anywhere in `frontend/src`. `module-navigation.ts:485` states it is the "Single source of truth for label lookups — **replaces the removed navigation.ts**", but the file was never removed.

The dead file still defines four shipping nav groups that no longer exist in the live nav: `Distribution OS`, `Loading OS`, `Shipping — Geography & Distribution`, `Driver Mobile`. It also uses a plain `label: string` field, which the live standard explicitly forbids. **Reading this file gives a materially wrong picture of the product's navigation.**

---

## 4. Page-by-Page Frontend Audit

**Mock-data scan result: no shipping page contains mock or static seeded data.** A scan of all 41 shipping pages for `const MOCK|SAMPLE|DUMMY|FAKE|STUB`, `mockData`, `// mock` produced only matches on JSX `placeholder=` attributes (input hints) — verified individually. Every page is React-Query-hook driven. **The failure mode in stacks C and D is a missing backend, not fake data.**

### 4.A Stack A pages (24) — wired, real data

Data flow is uniform: **page → `use-*` React Query hook → `*-service.ts` → `@/lib/axios` `api` instance (`baseURL = env.apiUrl`, Bearer token via request interceptor) → `api/logistics/*`.**

| Page | Purpose | Actions (verified handlers) | Backend | State source |
|---|---|---|---|---|
| `EgyptGeographyPage` | Governorate/city/alias master data | create, update, delete, toggle status, reorder, alias CRUD | `api/logistics/geography/*` (18) | backend |
| `DistributionZonesPage` | Distribution zone master data | create, update, delete, toggle status | `api/logistics/distribution/zones*` | backend |
| `DistributionPlanningPage` | Zone planning board (1,005 lines) | start planning, mark planned, view zone detail | `api/logistics/distribution/planning/*` (5) | backend |
| `DistributionWorkspacePage` | Live distribution-window read model | collect window, add slot, assign zone→slot, change zone/slot, assign late order | `api/logistics/distribution/windows/*` (13) | backend |
| **`TripsWorkspacePage`** | **Trip lifecycle workspace** | create/update trip, set status, assign driver+vehicle, record driver acceptance, dispatch-readiness probe, order add/remove/move, custody add/remove/confirm, stops generate/start/complete, capture proof, exceptions raise/resolve, returns record/confirm, settlement open/submit-cash/reconcile/finalize/dispute, payment record/verify/reject | `api/logistics/distribution/trips/*` (43) | backend |
| `ShippingCompaniesPage` | Carrier master data + contracts | create, update, set status, company mappings, contract CRUD + activate | `api/logistics/shipping-companies/*` (15) | backend |
| `CarrierAccountsPage` | Carrier account integration | create, test connection, status mappings upsert | `api/logistics/carriers/*` (8) | backend |
| `DriversPage` | Driver master data | create, update, set status, documents, assign/release vehicle | `api/logistics/drivers/*` (14) | backend |
| `VehiclesPage` | Vehicle master data | create, update, set status, documents, maintenance records | `api/logistics/vehicles/*` (17) | backend |
| `FleetDashboardPage` | Fleet units, health, fitness | register unit, lifecycle, move group, record odometer | `api/logistics/fleet/*` (43) | backend |
| `FuelReviewPage` | Fuel transaction reconciliation | reconcile, dispute, reject, write-off | `api/logistics/fleet/fuel-transactions/*` | backend |
| `ServiceAreasPage` | Network service areas + capacity | create area, attach/detach member, set status, service levels, capacity reserve/commit/release | `api/logistics/network/*` (18) | backend |
| `DispatchCommandCenterPage` | Dispatch board overview | create board, set status, propose, accept/reject proposal, release | `api/logistics/dispatch/*` | backend |
| `DispatchBoardPage` | Board list | open board, view | `api/logistics/dispatch/boards` | backend |
| `DispatchExecutionPage` | Dispatch ops execution | build queue, claim next/item, defer, prioritise, allocate, confirm/release allocation, resolve/override conflict, break lock, review approve/reject, open/close session | `api/logistics/dispatch/ops/*` (31) | backend |
| `OperationsCenterPage` | Ops landing | navigate | `api/logistics/operations/summary/*` | backend |
| `OperationalDashboardsPage` | KPI / capacity / dispatch / drivers / fleet | read-only | `api/logistics/operations/dashboards/*` (5) | backend |
| `AlertCenterPage` | Exception + alert registry | acknowledge, resolve, escalate, suppress, add note, create alert rule | `api/logistics/operations/exceptions/*` (17) | backend |
| `ActivityCenterPage` | Audit + history timeline | read-only | `api/logistics/operations/activity/*` (6) | backend |
| `EnterpriseReadinessPage` | Module readiness validation | validate all, validate module | `api/logistics/operations/readiness/*` (6) | backend |
| `EnterpriseWorkspacePage` | Executive/operations rollup | read-only | `api/logistics/intelligence/dashboard/*` (2) | backend |
| `LogisticsIntelligencePage` | Decisions/forecast/insight/optimization | read-only | `api/logistics/intelligence/*` (17) | backend |
| `AutomationMonitoringPage` | Automation policy monitoring | read-only | `api/logistics/automation/*` (3) | backend |
| `DeliveryPage` | Delivery OS list + drawer | create delivery, set status, cancel, escalate, retry, attempts advance/succeed/fail/abort, POD capture/validate/reject/artifact, COD open/collect/verify/dispute/write-off, returns store/in-transit/receive/verify/discrepancy | `api/logistics/delivery/*` (38) | backend |

**Stack A missing behaviour (gaps only, not fixed):**
- No page anywhere surfaces `api/logistics/routing/*` as a top-level workspace — routing is reachable only as a tab inside `trip-drawer.tsx` → `TripRoutingTab`, and the trips page that hosts it is not in navigation.
- `TripsWorkspacePage` gates its "New Trip" button on `usePermission().can(...)`, and passes `activeCompanyId` from `useOrganizationContext()` as the `company_id` **request filter** — i.e. the client chooses the tenant (see §12).
- No UI consumes `api/logistics/operations/pools/*` (13 routes), `api/logistics/operations/capacity/*` (11), or `api/logistics/operations/diagnostics/*` (7) beyond summary tiles.

### 4.C Stack C pages (5) — **BROKEN, no backend**

All five route through `operations/distribution-board/services/distribution-board-service.ts`, which defines `const BASE = '/api/distribution'` and uses **raw `fetch()` with `credentials: 'include'`** — not the shared `api` instance. Two independent defects:

1. `/api/distribution/*` → **0 registered routes**. Verified: filtering all 1,856 routes for prefix `api/distribution` returns zero.
2. Raw `fetch` sets no `Authorization` header. Auth is Bearer-token via the `api` interceptor (`lib/axios.ts:27-33`), so these calls carry no credential and would 401 even if the routes existed.

| Page | Purpose | Actions attempted | Backend | Verdict |
|---|---|---|---|---|
| `DistributionBoardPage` | Zone/trip planning board | validate board, finalize board, create/update/delete trip, auto-fill, add/remove/move order, assign driver/vehicle/carrier, custody add/remove, approve trip, return order to wave | `GET/POST /api/distribution/board*`, `/trips*` | **404 — every call** |
| `LoadingDashboardPage` | Loading progress per trip | open workspace | `GET /api/distribution/loading-trips` | **404** |
| `LoadingWorkspacePage` | Per-trip loading manifest | manifest start/complete, item confirm, resolve shortage, view breakdown, driver-confirm, accept discrepancy | `/api/distribution/manifests/*` | **404 — every call** |
| `DispatchGatePage` | Dispatch gate dashboard | list gate trips | `GET /api/distribution/dispatch-gate` | **404** |
| `DispatchGateWorkspacePage` | Per-trip dispatch gate | driver-accept, dispatch, dispatch-vehicle, handover status, audit trail | `/api/distribution/trips/{id}/dispatch*` | **404 — every call** |

Complete list of the **43** non-existent endpoints this service calls:

```
GET    /api/distribution/board
GET    /api/distribution/board/zones/{zoneId}/orders
GET    /api/distribution/board/trips/{tripId}/orders
POST   /api/distribution/board/validate
POST   /api/distribution/board/finalize
GET    /api/distribution/board/exceptions
POST   /api/distribution/trips
PUT    /api/distribution/trips/{id}
DELETE /api/distribution/trips/{id}
POST   /api/distribution/trips/{id}/auto-fill
POST   /api/distribution/trips/{tripId}/orders
DELETE /api/distribution/trips/{tripId}/orders/{orderId}
POST   /api/distribution/trips/{fromTripId}/orders/move
POST   /api/distribution/trips/{tripId}/orders/{orderId}/return-to-wave
POST   /api/distribution/trips/{tripId}/driver
POST   /api/distribution/trips/{tripId}/vehicle
POST   /api/distribution/trips/{tripId}/carrier
POST   /api/distribution/trips/{tripId}/custody
DELETE /api/distribution/trips/{tripId}/custody/{custodyId}
POST   /api/distribution/trips/{tripId}/custody/{custodyId}/driver-confirm
POST   /api/distribution/trips/{tripId}/approve
GET    /api/distribution/trips/{tripId}/coverage
GET    /api/distribution/trips/{tripId}/manifest
GET    /api/distribution/trips/{tripId}/handover-status
POST   /api/distribution/trips/{tripId}/dispatch
POST   /api/distribution/trips/{tripId}/driver-accept
POST   /api/distribution/trips/{tripId}/dispatch-vehicle
GET    /api/distribution/trips/{tripId}/audit-trail
GET    /api/distribution/manifests/{id}
POST   /api/distribution/manifests/{id}/start
POST   /api/distribution/manifests/{id}/complete
POST   /api/distribution/manifests/{mId}/items/{iId}/confirm
POST   /api/distribution/manifests/{mId}/items/{iId}/resolve-shortage
GET    /api/distribution/manifests/{mId}/items/{iId}/breakdown
POST   /api/distribution/manifests/{mId}/items/{iId}/driver-confirm
POST   /api/distribution/manifests/{mId}/items/{iId}/accept-discrepancy
GET    /api/distribution/loading-trips
GET    /api/distribution/dispatch-gate
GET    /api/distribution/dispatch-gate/{tripId}
GET    /api/distribution/fleet/vehicles
GET    /api/distribution/fleet/drivers
GET    /api/distribution/fleet/carriers
```

### 4.D Stack D pages (11) — **BROKEN, no backend**

`operations/driver-mobile/services/driver-mobile-service.ts` creates its **own** Axios instance: `axios.create({ baseURL: '/api' })` — no token interceptor, no 401 handler. Same two defects: routes absent **and** no credential.

| Page | Purpose | Actions attempted | Verdict |
|---|---|---|---|
| `DriverHomePage` | Active trip list | open trip | **404** |
| `DriverTripDashboardPage` | Trip KPIs | start trip, finish trip, close trip | **404** |
| `DriverStopListPage` | Stop list | open stop | **404** |
| `DriverStopDetailPage` | Stop execution | record action | **404** |
| `DriverCollectionsPage` | Cash collection | record payment | **404** |
| `DriverExceptionsPage` | Raise exception | record exception | **404** |
| `DriverReturnsPage` | Record return | record return, confirm return | **404** |
| `DriverSettlementPage` | Settlement submit | submit settlement | **404** |
| `DriverCustodyReturnPage` | Custody return | record custody return | **404** |
| `DriverTripTimelinePage` | Timeline | read | **404** |
| `DriverMapPage` | GPS + map | record GPS | **404** |

Complete list of the **20** non-existent endpoints:

```
GET  /api/driver/trips
GET  /api/driver/trips/{tripId}
POST /api/driver/trips/{tripId}/start
POST /api/driver/trips/{tripId}/finish
POST /api/driver/trips/{tripId}/close
POST /api/driver/trips/{tripId}/gps
GET  /api/driver/trips/{tripId}/stops
GET  /api/driver/trips/{tripId}/stops/{stopId}
POST /api/driver/stops/{stopId}/action
POST /api/driver/stops/{stopId}/proof
POST /api/driver/stops/{stopId}/payment
POST /api/driver/stops/{stopId}/exception
GET  /api/driver/trips/{tripId}/collections
GET  /api/driver/trips/{tripId}/exceptions
GET  /api/driver/trips/{tripId}/returns
POST /api/driver/trips/{tripId}/returns
POST /api/driver/returns/{returnId}/confirm
GET  /api/driver/trips/{tripId}/custody-returns
GET  /api/driver/trips/{tripId}/settlement
POST /api/driver/trips/{tripId}/settlement/submit
GET  /api/driver/trips/{tripId}/timeline
```

---

## 5. Backend Route Inventory

**416 in-scope routes.** All are registered in `backend/routes/api.php` (3,942 lines — there are no per-module route files; `find backend -name "routes*.php"` returns only `routes/api.php` and `routes/web.php`).

Common middleware on every in-scope route: `api`, `App\Http\Middleware\Authenticate:sanctum`. Permission enforcement, where present, is `Modules\IAM\Infrastructure\Middleware\RequirePermissionMiddleware:<permission>`.

### 5.1 Route groups

| Prefix | Routes | Owner module | Permission coverage | Tenant scope | Active? |
|---|---|---|---|---|---|
| `api/loading/*` | **24** | `Operations\Loading` | **NONE — 0/24 routes carry any permission middleware** | ✅ auth-derived (`user()->company_id`) | **registered, but 0 frontend callers** |
| `api/logistics/geography/*` | 18 | `Logistics\Geography` | writes only (`logistics.geography.*`); all GETs open | ❌ none (global master data) | active |
| `api/logistics/distribution/zones,areas,stats,next-code` | 8 | `Logistics\Distribution` | writes only (`logistics.distribution.*`) | ❌ **none** | active |
| `api/logistics/distribution/planning/*` | 5 | `Logistics\Distribution` | 2 of 5 | ❌ **none** | active |
| `api/logistics/distribution/windows/*` | 13 | `Logistics\Distribution` | all 13 | ✅ auth-derived | active |
| `api/logistics/distribution/trips/*` | **43** | `Logistics\Distribution` | writes only; **all GETs unprotected** | ❌ **REQUEST-SUPPLIED / none** | active |
| `api/logistics/delivery/*` | 38 | `Logistics\Delivery` | all 38 | ⚠️ index/stats/store scoped; **detail paths not** | active |
| `api/logistics/dispatch/*` | 42 | `Logistics\Dispatch` | all 42 | ✅ auth-derived | active |
| `api/logistics/drivers/*` | 14 | `Logistics\Drivers` | writes only | ❌ **none — no `company_id` column** | active |
| `api/logistics/vehicles/*` | 17 | `Logistics\Vehicles` | writes only | ❌ **none** | active |
| `api/logistics/shipping-companies/*` | 15 | `Logistics\ShippingCompanies` | writes only | ❌ **none** | active |
| `api/logistics/carriers/*` | 8 | `Logistics\Carriers` | all 8 | ✅ auth-derived | active |
| `api/logistics/fleet/*` | 43 | `Logistics\Fleet` | all 43 | ✅ auth-derived | active |
| `api/logistics/network/*` | 18 | `Logistics\Network` | all 18 | ✅ auth-derived | active |
| `api/logistics/operations/*` | 75 | `Logistics\Operations` | all 75 | ✅ auth-derived | active |
| `api/logistics/intelligence/*` | 17 | `Logistics\Intelligence` | all 17 | ✅ auth-derived | active |
| `api/logistics/automation/*` | 3 | `Logistics\Automation` | all 3 | ✅ auth-derived | active |
| `api/logistics/routing/*` | 9 | `Logistics\Routing` | all 9 | ❌ **none** | active |
| `api/shipping/quote` | 1 | `Commerce\Shipping` | `logistics.shipping.quote` + throttle 120/1 | n/a (stateless quote) | active |
| `api/brands/{brand}/shipping/*` | 6 | `Organization\Brands` | writes only | brand-scoped | active |
| `api/brands/{brand}/delivery-*` | 9 | `Organization\Brands` | writes only | brand-scoped | active |
| `api/operations/demand-analysis` | 1 | `Operations\DemandAnalysis` | none + throttle 120/1 | — | active |
| **`api/distribution/*`** | **0** | — | — | — | **DOES NOT EXIST** |
| **`api/driver/*`** | **0** | — | — | — | **DOES NOT EXIST** |

### 5.2 The empty route group

`backend/routes/api.php:952-957`:

```php
/*
|--------------------------------------------------------------------------
| Operations — Distribution Board OS (protected)
| ADR-DIST-004: Wave is the single operational container
|--------------------------------------------------------------------------
*/
```

The banner is followed immediately by the next section (`Admin — Configuration OS`). **The Distribution Board OS route group is an empty comment block.** This is the direct cause of stack C being 100% broken.

### 5.3 Permission-middleware gaps within registered routes

- **`api/loading/*` — 24 routes, zero permission middleware.** Includes `POST .../assignments/{id}/dispatch`, which is the **only inventory-consuming dispatch endpoint in the product**. Any authenticated user could invoke it. Mitigating factor only: no UI calls it and there are no `loading_sessions` rows.
- **`api/logistics/distribution/trips/*` — all 20 GET routes unprotected**, including `/custody`, `/orders`, `/settlement`, `/financial-summary`, `/payments`, `/returns`, `/stops`. Only writes require `logistics.distribution.update`.
- `api/logistics/drivers`, `/vehicles`, `/shipping-companies`, `/geography` — all list/show/stats/documents-download GETs unprotected.

---

## 6. Backend Ownership Map

| Capability | Current owner (verified in code) | Notes |
|---|---|---|
| **Geography** | `Logistics\Geography` — `GovernorateController`, `CityController`, `CityAliasController` | Global (no `company_id`). Also a second, separate authority: `Admin\Configuration\MasterGeographyController` at `api/master-geography` (see §9). |
| **Service Areas / Network capacity** | `Logistics\Network\NetworkController` | Own capacity ledger (`network_capacity_*`). |
| **Shipping companies (carriers as vendors)** | `Logistics\ShippingCompanies\ShippingCompanyController` | Contracts + company mappings. |
| **Carrier accounts (integration)** | `Logistics\Carriers\CarrierController` | Separate from ShippingCompanies. Two carrier concepts coexist. |
| **Shipping price / quote** | `Commerce\Shipping\ShippingQuoteService` + `Organization\Brands\BrandShippingController` | **Not owned by Logistics.** Consumed by `orders-service.ts` (`POST /shipping/quote`) and `brand-shipping-service.ts` (`GET /brands/{id}/shipping/calculate`). Connected and working. |
| **Vehicles (master data)** | `Logistics\Vehicles\VehicleController` | |
| **Fleet (units, fuel, inspections, maintenance)** | `Logistics\Fleet` — 4 controllers | `fleet_units` is a **second** vehicle representation alongside `logistics_vehicles`. |
| **Drivers** | `Logistics\Drivers\DriverController` | |
| **Distribution planning (zones/windows/slots)** | `Logistics\Distribution` — `DistributionZoneController`, `DistributionWindowController`, `DistributionPlanningController`, `DistributionWindowService`, `DistributionCollectionService`, `DistributionAggregationService`, `OrderZoneResolver`, `ManualAssignmentService`, `RedistributionSuggestionService` | Planning only. |
| **Trips** | `Logistics\Distribution\TripService` (+ `TripController`) | **Canonical.** `TripStatus` = 13-state machine with a transition table. `update()` strips `status`; status moves only through `changeStatus()`. |
| **Loading** | **TWO owners.** (1) `Operations\Loading` — `LoadingSessionController`, `VehicleAssignmentController`, `AllocationController`, `LoadProductAction`, `AutoAllocationService`, `VehicleInventoryService`. (2) `Logistics\Distribution\TripStatus::Loading` / `LoadingCompleted` — status labels only, no loading logic. | Stack B does the real work and has no UI; stack A has the labels and the UI. |
| **Dispatch** | **THREE owners.** (1) `Logistics\Distribution\TripService::changeStatus(→Dispatched)` — gate-enforced, no inventory. (2) `Operations\Loading\DispatchVehicleAction` → `LoadVehicleWorkflow` → `ShipOrderInventoryAction` — inventory-correct, no UI. (3) `Logistics\Dispatch` (`DispatchController`, `DispatchOperationsController`) — board/proposal/queue/allocation lifecycle, a **planning** engine that never transitions a Trip. | See §9 and §12. |
| **Delivery** | **TWO owners.** (1) `Logistics\Distribution\DeliveryService` — trip stops, actions, proofs, exceptions, returns (used by `TripsWorkspacePage`). (2) `Logistics\Delivery` — a full Delivery OS: `DeliveryService`, `DeliveryAttemptService`, `CodCompletionService`, `PodService`, `DeliveryReturnService` (used by `DeliveryPage`). | Two parallel delivery models over the same physical act. |
| **Returns (delivery)** | **TWO owners.** (1) `Logistics\Distribution\DeliveryService::recordReturn/confirmReturn` → `distribution_trip_returns`. (2) `Logistics\Delivery\DeliveryReturnService` (initiate→in_transit→received→verified) → `delivery_returns` + `delivery_return_lines`. | Neither restocks. |
| **Settlement** | `Logistics\Distribution\SettlementService` — sole owner. Documented in `DeliveryCodController` as "DISTRIBUTION IS THE SINGLE CASH AUTHORITY". | `Logistics\Delivery` COD records the doorstep event and publishes `CodCollected`; it runs no reconciliation. Contract respected. |
| **Vehicle inventory** | `Operations\Loading\VehicleInventoryService` — sole owner. | Parallel ledger (`vehicle_inventory_items` / `_movements`), never joined to canonical stock. 3 of its 5 methods have no callers. |
| **Vehicle end-of-trip reconciliation** | **NOBODY.** Models + migrations + FormRequest exist; no controller, no route, no writer. | See §11. |
| **Routing / ETA** | `Logistics\Routing\RoutingController` | Reachable only via `TripRoutingTab` inside `trip-drawer.tsx`. |
| **Order → shipping inventory consumption** | `Commerce\Orders\ShipOrderInventoryAction`, called by `Operations\Fulfillment\LoadVehicleWorkflow` and `DispatchOrderWorkflow` | The single inventory authority. Reached from stack B only. |

---

## 7. Frontend → Backend Contract Map

Scripted diff of every endpoint literal in all 26 shipping service files against the 1,856 registered routes: **374 resolved, 63 unresolved** (excluding false positives where the matched literal was a `BASE` constant rather than a call).

### 7.1 Complete chains (stack A) — representative

```
TripsWorkspacePage
 → useTrips() / useTripStats()                          [logistics/trips/hooks]
 → tripService.list()                                    [trip-service.ts, BASE='/logistics/distribution/trips']
 → GET api/logistics/distribution/trips                   ✅ registered
 → Logistics\Distribution\TripController@index
 → Trip::query() (no service layer for reads)
 → distribution_trips                                     ✅ table exists (0 rows)
 → events: none on read
```

```
TripsWorkspacePage → "Dispatch"
 → tripService.setStatus(id,'dispatched')
 → PATCH api/logistics/distribution/trips/{id}/status      ✅ registered, perm logistics.distribution.update
 → TripController@setStatus
 → TripService::changeStatus()
     · TripStatus::canTransitionTo() enforced
     · Trip::dispatchBlockers() enforced  ← BACKEND GATE
 → UPDATE distribution_trips SET status,dispatched_at
 → events: TripStatusChanged, TripDispatched
 → ⚠️ BOTH EVENTS HAVE ZERO LISTENERS
 → ⚠️ NO INVENTORY MUTATION
```

```
TripsWorkspacePage → "Finalize settlement"
 → tripSettlementService.finalize()
 → PATCH api/logistics/distribution/trips/{tripId}/settlement/finalize   ✅ registered
 → SettlementController@finalize → SettlementService
 → distribution_trip_settlements
 → event: TripSettled  → ⚠️ ZERO LISTENERS → no finance posting
```

```
DeliveryPage → "Collect COD"
 → deliveryService.collectCod()
 → PATCH api/logistics/delivery/{id}/cod/collect           ✅ registered, perm delivery.cod.collect
 → DeliveryCodController@collect → CodCompletionService
 → delivery_cod_records
 → event: CodCollected → ⚠️ ZERO LISTENERS
 → ⚠️ delivery resolved by bare findByUuidOrFail() — NO COMPANY SCOPE
```

### 7.2 FRONTEND → BACKEND MISMATCH (63 endpoints)

| Service | Endpoints | Status |
|---|---|---|
| `operations/distribution-board/services/distribution-board-service.ts` | **43** | **MISMATCH — `/api/distribution/*` namespace has 0 routes** |
| `operations/driver-mobile/services/driver-mobile-service.ts` | **20** | **MISMATCH — `/api/driver/*` namespace has 0 routes** |

Both are **BROKEN LINK** at the *route* layer — the chain terminates immediately after the service call. Full endpoint lists in §4.C and §4.D.

Additionally, both services are **BROKEN LINK at the auth layer**, independently:

| Service | HTTP client | Bearer token | Verdict |
|---|---|---|---|
| all 24 stack A services | `@/lib/axios` `api` | ✅ request interceptor attaches `Authorization: Bearer` | correct |
| `distribution-board-service.ts` | raw `fetch(..., {credentials:'include'})` | ❌ none | would 401 |
| `driver-mobile-service.ts` | own `axios.create({baseURL:'/api'})` | ❌ none | would 401 |

### 7.3 Adjacent mismatch found in passing (Preparation OS, out of primary scope)

`features/operations/services/preparation-service.ts` calls 4 endpoints that do not exist. Reported for completeness; **not** a shipping finding:

| Frontend call | Registered route | Verdict |
|---|---|---|
| `GET /preparation/assignment-policies` | `api/preparation/warehouse-assignment-policies` | name mismatch |
| `POST/PUT/DELETE /preparation/assignment-policies/{id}` | `api/preparation/warehouse-assignment-policies/{id}` | name mismatch |
| `POST /preparation/orders/{orderId}/override-warehouse` | — | missing |
| `GET /preparation/today` | `api/preparation/sessions/today` | path mismatch |

This corroborates the standing CR-PREP-001 note that its API controllers/routes were never written.

### 7.4 UNUSED BACKEND CAPABILITY

| Backend | Routes | Frontend callers | Significance |
|---|---|---|---|
| **`Operations\Loading` (`api/loading/*`)** | **24** | **ZERO** — verified: grep for `/loading/sessions`, `api/loading`, `'/loading` across all of `frontend/src` returns **no matches** | **This is the only path in the product that consumes inventory on dispatch.** Entirely unreachable. |
| `api/logistics/routing/*` | 9 | only `TripRoutingTab` inside a drawer on a non-navigable page | effectively unreachable |
| `api/logistics/operations/pools/*` | 13 | none | unused |
| `api/logistics/operations/capacity/*` | 11 | none | unused |
| `api/logistics/operations/diagnostics/*` | 7 | none | unused |
| `api/logistics/distribution/trips/{id}/dispatch-readiness` | 1 | `tripService` — but page not in nav | reachable by URL only |

---

## 8. Duplicate Implementations

| Capability | Impl | File / class | Caller | Route | Active | Reachable | Legacy? | Canonical? |
|---|---|---|---|---|---|---|---|---|
| **Distribution board** | A | `Logistics\Distribution\DistributionWindowController` + `DistributionWorkspacePage` | frontend | `api/logistics/distribution/windows/*` (13) | ✅ | URL only (not in nav) | no | **✅ canonical** |
| | B | `operations/distribution-board/DistributionBoardPage` | frontend | `/api/distribution/board` | ❌ **no backend** | page loads, all data 404 | **yes — orphan** | no |
| **Loading** | A | `Operations\Loading` (24 routes, `LoadProductAction`, `VehicleInventoryService`, `DispatchVehicleAction`) | **none** | `api/loading/*` | ✅ backend live | ❌ **no UI** | no | **✅ canonical backend** |
| | B | `operations/distribution-board/LoadingWorkspacePage` + `LoadingDashboardPage` | frontend | `/api/distribution/manifests/*` | ❌ **no backend** | 404 | **yes — orphan** | no |
| | C | `Logistics\Distribution\TripStatus::Loading/LoadingCompleted` | `TripService` | via `trips/{id}/status` | ✅ | ✅ | no | status labels only, no loading logic |
| **Dispatch** | A | `Logistics\Distribution\TripService::changeStatus(→Dispatched)` + `Trip::dispatchBlockers()` | `TripController` | `PATCH trips/{id}/status` | ✅ | URL only | no | **✅ canonical trip transition** |
| | B | `Operations\Loading\DispatchVehicleAction` → `LoadVehicleWorkflow` → `ShipOrderInventoryAction` | `VehicleAssignmentController@dispatch` | `POST api/loading/.../dispatch` | ✅ | ❌ no UI | no | **✅ canonical inventory path** |
| | C | `Logistics\Dispatch` (`DispatchController`, `DispatchOperationsController`, 42 routes) | frontend (3 pages) | `api/logistics/dispatch/*` | ✅ | ✅ in nav | no | planning/allocation engine — **never transitions a Trip** |
| | D | `operations/distribution-board/DispatchGateWorkspacePage` | frontend | `/api/distribution/trips/{id}/dispatch` | ❌ | 404 | **yes — orphan** | no |
| **Delivery** | A | `Logistics\Distribution\DeliveryService` (stops/actions/proofs/exceptions) | `DeliveryController` (Distribution) | `trips/{tripId}/stops/*` | ✅ | URL only | no | trip-centric model |
| | B | `Logistics\Delivery` (Delivery OS: attempts, POD, COD, returns, retry, SLA — 38 routes) | `DeliveryPage` | `api/logistics/delivery/*` | ✅ | ✅ in nav | no | **richer; order-centric model** |
| | C | `operations/driver-mobile` (11 pages) | frontend | `/api/driver/*` | ❌ | 404 | **yes — orphan** | no |
| **Return** | A | `Logistics\Distribution\DeliveryService::recordReturn/confirmReturn` → `distribution_trip_returns` | Distribution `DeliveryController` | `trips/{tripId}/returns` | ✅ | URL only | no | 2-state |
| | B | `Logistics\Delivery\DeliveryReturnService` → `delivery_returns` + `_lines` | `DeliveryReturnController` | `delivery/{id}/returns/*` | ✅ | ✅ | no | **✅ richer: 5-state + line-level discrepancy** |
| | C | `operations/driver-mobile/DriverReturnsPage` | frontend | `/api/driver/trips/{id}/returns` | ❌ | 404 | **yes — orphan** | no |
| **Vehicle representation** | A | `Logistics\Vehicles\Vehicle` → `logistics_vehicles` (17 routes) | `VehiclesPage` | `api/logistics/vehicles/*` | ✅ | ✅ | no | master data |
| | B | `Logistics\Fleet\FleetUnit` → `fleet_units` (43 routes) | `FleetDashboardPage` | `api/logistics/fleet/*` | ✅ | ✅ | no | ops/lifecycle/cost |
| **Carrier representation** | A | `Logistics\ShippingCompanies\ShippingCompany` → `logistics_shipping_companies` | `ShippingCompaniesPage` | 15 routes | ✅ | ✅ | no | vendor/contract |
| | B | `Logistics\Carriers\CarrierAccount` | `CarrierAccountsPage` | 8 routes | ✅ | ✅ | no | integration/credentials |
| **Geography** | A | `Logistics\Geography` (18 routes) | `EgyptGeographyPage` | `api/logistics/geography/*` | ✅ | ✅ | no | **✅ canonical for shipping** |
| | B | `Admin\Configuration\MasterGeographyController` → `master_governorates` | Configuration OS | `api/master-geography/*` (6) | ✅ | ✅ | no | separate table; the pair is the known source of the Arabic-governorate matching defect |
| **Navigation config** | A | `config/module-navigation.ts` | 8 importers | — | ✅ | ✅ | no | **✅ canonical** |
| | B | `config/navigation.ts` | **0 importers** | — | ❌ | ❌ | **yes — dead** | no |

Nothing was deleted or merged.

---

## 9. State Machine Map

### 9.1 The intended chain vs. what the code does

| Step | Actual trigger | Actual owner | Status mutation | Inventory mutation | Event | Event consumed? | UI |
|---|---|---|---|---|---|---|---|
| Order ready for shipping | — | **NO LINK.** `TripService::assignOrder()` validates only `exists:orders,id`, not-on-another-trip, and trip capacity. It does **not** check order status, `inventory_reserved_at`, preparation state, or company match. | none | none | none | — | trips page (not in nav) |
| Distribution (window/zone) | `POST windows/collect` | `DistributionCollectionService` | `distribution_window_orders` | none | `OrderAddedToDistributionWindow` | ❌ **no** | `DistributionWorkspacePage` (not in nav) |
| Trip created | `POST trips` | `TripService::create` | `status=planning` | none | none | — | trips page (not in nav) |
| Trip → loading | `PATCH trips/{id}/status` | `TripService::changeStatus` | `planning→loading` | **none** | `TripStatusChanged` | ❌ **no** | trips page |
| **Loading (physical)** | `POST api/loading/.../load-product` | `Operations\Loading\LoadProductAction` → `VehicleInventoryService::recordLoad` | `loading_tasks.status` | **vehicle ledger only** (`vehicle_inventory_items`) — canonical stock untouched | `VehicleLoaded` | ❌ no | **NO UI** |
| Loading complete | `PATCH trips/{id}/status` | `TripService` | `loading→loading_completed` | none | `TripStatusChanged` | ❌ no | trips page |
| Driver acceptance | `PATCH trips/{id}/driver-acceptance` | `TripController@recordDriverAcceptance` | acceptance flags on trip | none | none | — | trips page |
| Ready for dispatch | `PATCH trips/{id}/status` | `TripService` | `driver_accepted→ready_for_dispatch` | none | `TripStatusChanged` | ❌ no | trips page |
| **Dispatch (A)** | `PATCH trips/{id}/status` → `dispatched` | `TripService::changeStatus` — **gate enforced** | `→dispatched`, `dispatched_at` | **NONE** | `TripStatusChanged`, `TripDispatched` | ❌ **no** | trips page |
| **Dispatch (B)** | `POST api/loading/.../dispatch` | `DispatchVehicleAction` → `LoadVehicleWorkflow` → **`ShipOrderInventoryAction`** | `VehicleAssignment→dispatched`; **`Order→out_for_delivery`** | ✅ **FIFO consumed, `inventory_shipped_at` stamped, COGS on order** | `VehicleReleased`, `OrderDispatchedEvent` | ✅ `HandleOrderDispatched` | **NO UI** |
| Stop created | `POST trips/{tripId}/stops/generate` | `DeliveryService::generateStops` | `distribution_delivery_stops` | none | none | — | trips page |
| Driver arrives / attempt | `PATCH stops/{stopId}/start` | `DeliveryService` | `→in_progress` | none | none | — | trips page |
| POD | `POST stops/{stopId}/proof` | `DeliveryService::captureProof` | `distribution_delivery_proofs` | none | none | — | trips page |
| COD | `POST stops/{stopId}/payments` | `SettlementService::recordPayment` | `distribution_payment_collections` | none | none | — | trips page |
| **Delivered** | `PATCH stops/{stopId}/complete` | `DeliveryService::completeStop` | stop `→delivered` | **NONE** | `DeliveryStopCompleted` | ❌ **no** | trips page |
| Failed / retry | Delivery OS `attempts/{id}/fail`, `{id}/retry` | `Logistics\Delivery` | `delivery_attempts`, `delivery_failures` | none | `DeliveryFailed`, `DeliveryRetryScheduled` | ❌ no | `DeliveryPage` |
| Return (driver→vehicle) | `POST trips/{tripId}/returns` | `DeliveryService::recordReturn` | `distribution_trip_returns` | **none** | none | — | trips page |
| Return (warehouse receipt) | `PATCH delivery/{id}/returns/{rid}/receive` | `DeliveryReturnService::receive` | counted qty + derived discrepancy | **NONE — no restock** | `ReturnReceived` | ❌ **no** | `DeliveryPage` |
| Settlement | `POST/PATCH trips/{tripId}/settlement/*` | `SettlementService` | `distribution_trip_settlements` | none | `TripSettled` | ❌ **no** | trips page |
| **Vehicle→Warehouse reconciliation** | — | **NOBODY** | — | — | — | — | **NONE** |

### 9.2 Transitions with defects

| Defect | Evidence |
|---|---|
| **Multiple owners — dispatch** | 3 independent dispatch implementations (§8). A trip can reach `dispatched` in stack A with no inventory movement, while the inventory-correct stack B path is unreachable. |
| **Multiple owners — loading** | `Operations\Loading` owns loading logic; `Logistics\Distribution` owns the loading *status*. Nothing synchronises them. |
| **Multiple owners — delivery/returns** | 2 backends each (§8), over the same physical act, with separate tables. |
| **Missing inventory mutation** | Loading, dispatch (A), delivery, and return in the reachable stack mutate no stock. |
| **Missing event consumers** | 62 of 70 events (§17). |
| **UI/backend disagreement** | Stack C's `DispatchGateWorkspacePage` posts `/api/distribution/trips/{id}/dispatch` (nonexistent) while the enforced gate lives at `PATCH api/logistics/distribution/trips/{id}/status`. The UI gate and the real gate are not the same gate. |
| **No direct status mutation found** | ✅ Positive: `TripService::update()` explicitly `unset($attributes['status'])` (line 39), and `changeStatus()` is the only writer. The transition table is honoured. |
| **Unguarded entry** | `assignOrder` accepts an order in any state, from any company. |

---

## 10. Inventory Boundary Audit

**Headline: the entire `Modules\Logistics` tree contains zero inventory coupling.** A grep for `Inventory|StockLedger|stock_ledger|InventoryItem|restock|ShipOrder` across all of `backend/Modules/Logistics` returns **7 matches, all of them comments or enum labels** (e.g. `'Received at Warehouse'`, `// Warehouse counts what actually came back`). Not one line of executable inventory code.

### Loading

| Question | Current behaviour |
|---|---|
| Reduce On Hand? | **No.** |
| Reduce Reserved? | **No.** |
| Consume FIFO? | **No.** |
| Create ledger entries? | **No** canonical entries. It appends to a **separate** ledger: `vehicle_inventory_movements` (`movement_type='loaded'`), via `VehicleInventoryService::recordLoad()`. |
| Calculate COGS? | **No.** |

`LoadProductAction` (`Operations/Loading/Application/Actions/LoadProductAction.php:74-83`) creates a `LoadingTask`, calls `recordLoad`, and increments `loading_weight_kg`. `VehicleInventoryService::recordLoad` touches only `vehicle_inventory_items` and `vehicle_inventory_movements`. **Warehouse stock is unaffected by loading.**

Whether that is correct depends on an approved contract; the audit records only that loading is inventory-neutral and that the offsetting decrement happens later, at dispatch, in stack B only.

### Dispatch

**Two answers, because there are two dispatch paths.**

**Stack B — `Operations\Loading` (correct, unreachable).** `DispatchVehicleAction:59` calls `LoadVehicleWorkflow::execute()`, which:
- resolves order ids from `AllocationRecord` for the assignment;
- builds **per-order, per-line allocation quantity maps** so each vehicle ships only its share (`LoadVehicleWorkflow.php:59-62`);
- runs inside `OrderStatusGuard::withAuthorization()` and a savepoint;
- is **idempotent** — `if ($order->inventory_shipped_at !== null) continue;`
- calls `ShipOrderInventoryAction::execute($order, $lineQuantities)` → FIFO consumption, ledger entries, COGS;
- sets `Order->status = OutForDelivery`;
- logs `OrderEvent` and fires `OrderDispatchedEvent` (which **does** have a listener: `HandleOrderDispatched`).

This is a well-built path. Its file docblock states it "Closes GAP-02 … and GAP-03". **Historical issue #2 is genuinely fixed here.**

**Stack A — `Logistics\Distribution` (reachable, inventory-blind).** `TripService::changeStatus(→Dispatched)` enforces `dispatchBlockers()`, writes `status` + `dispatched_at`, fires `TripStatusChanged` + `TripDispatched`, and **performs no inventory operation whatsoever**. Both events have no listeners. So a trip dispatched through the only reachable UI ships nothing from stock and leaves every order's status untouched.

### Delivery

**Does not mutate inventory.** Neither implementation:
- `Logistics\Distribution\DeliveryService::completeStop()` sets stop status, fires `DeliveryStopCompleted` (no listeners). No stock, no order status.
- `Logistics\Delivery` attempt success → `DeliverySucceeded` (no listeners).
- `VehicleInventoryService::recordDelivery()` — the method that would decrement the vehicle ledger on delivery — has **zero callers**.

Consequence: even inside stack B's own parallel ledger, delivery never reduces `quantity_on_hand`. The vehicle ledger is append-only-upward.

### Return

**Does not mutate inventory, immediately or after inspection.**

`DeliveryReturnService::receive()` (`DeliveryReturnService.php:102-136`) writes `warehouse_confirmed_qty`, derives `discrepancy_qty` per line (never trusting caller input — good), sets `status=received`, and fires `ReturnReceived` (no listeners). `verify()` sets `status=verified` and moves the parent delivery to `Returned`. **At no point is any stock restored.**

There is a warehouse-inspection *step* (`received` → `verified`, with derived discrepancy), but no restock at either end, and no sellable/damaged disposition drives a stock decision.

`VehicleInventoryService::recordReturn()` — zero callers.

**Comparison against approved contracts:** `Modules\Commerce\Orders\ShipOrderInventoryAction` is the approved single inventory authority for shipping, and `Operations\Fulfillment\ResumeToConfirmedWorkflow:32` documents the invariant that `inventory_shipped_at` must not be cleared without restoring stock. Stack A neither calls the authority nor stamps the marker, so it does not violate the invariant — it sits entirely outside it. That is the boundary defect: the reachable workflow is not connected to the approved authority.

---

## 11. Vehicle Inventory Audit

### Do vehicles function as inventory locations?

**Partially, in one orphan subsystem only.** `Operations\Loading` models a vehicle as an inventory location via:

| Table | Purpose | Rows in `ecos_dev` |
|---|---|---|
| `vehicle_inventory_items` | per-assignment, per-product balance: `quantity_loaded`, `quantity_allocated`, `quantity_unallocated`, `quantity_on_hand`, `quantity_delivered`, `quantity_returned`, `status` | **0** |
| `vehicle_inventory_movements` | append-only movement log: `loaded` / `allocated` / `unallocated` / `delivered` / `returned` | **0** |

Vehicles are **not** represented as warehouses/locations in the canonical inventory system. There is no warehouse record for a vehicle, and no canonical ledger entry ever references a vehicle.

### Method-by-method liveness

| `VehicleInventoryService` method | Effect | Callers | Live? |
|---|---|---|---|
| `recordLoad()` | +loaded, +on_hand, +unallocated | `LoadProductAction:75` | ✅ |
| `allocate()` | +allocated, −unallocated | `AutoAllocationService:213` | ✅ |
| `unallocate()` | −allocated, +unallocated | **none** | ❌ dead |
| `recordDelivery()` | +delivered, recompute on_hand, `Depleted` when 0 | **none** | ❌ dead |
| `recordReturn()` | +returned, recompute on_hand, `Returned` | **none** | ❌ dead |

**The vehicle ledger can only increase.** Nothing ever records a delivery or a return against it.

### The audit's specific question

> Vehicle starts with 100 units AND delivers 70. Where are the remaining 30 represented?

**Answer, from current code:**

- **In the reachable stack (A):** nowhere. There is no vehicle inventory at all. `distribution_trip_returns` records a counted quantity as a document, with no stock meaning and no vehicle balance.
- **In the orphan stack (B):** `vehicle_inventory_items` would read `quantity_loaded=100`, `quantity_delivered=0`, `quantity_on_hand=100` — because `recordDelivery()` is never called. The 70 delivered units are invisible to the vehicle ledger, and the 30 remaining are indistinguishable from the original 100.

### Is there an explicit Vehicle → Warehouse reconciliation?

**No.** The scaffolding exists and is complete except for the part that runs:

| Artefact | Exists? | Evidence |
|---|---|---|
| `VehicleShiftReconciliation` model | ✅ | `Domain/Models/VehicleShiftReconciliation.php` |
| `VehicleShiftReconciliationLine` model | ✅ | `Domain/Models/VehicleShiftReconciliationLine.php` |
| Migrations (both tables) | ✅ | `2026_07_05_121800`, `2026_07_05_121900` |
| Tables in DB | ✅ | `vehicle_shift_reconciliations`, `vehicle_shift_reconciliation_lines` (0 rows) |
| Relations wired | ✅ | `VehicleAssignment::hasOne`, `VehicleInventoryItem::hasMany` |
| `ReconciliationLineRequest` FormRequest | ✅ | `Presentation/Http/Requests/ReconciliationLineRequest.php` |
| **Controller** | ❌ | none |
| **Route** | ❌ | zero routes reference reconciliation |
| **Action / Service that writes it** | ❌ | the only writer-shaped method, `recordReturn()`, has no callers |
| **UI** | ❌ | none |

**Gap recorded. Not implemented.** Nothing was added.

---

## 12. Multi-Warehouse Audit

Determined from schema + code, not from the existence of warehouses.

| Entity | `warehouse_id`? | Evidence |
|---|---|---|
| `distribution_trips` | **NO COLUMN** | `information_schema` returns no `warehouse_id`; grep for `warehouse` in `Trip.php` and its migration returns nothing |
| `distribution_trip_orders` | no | schema |
| `distribution_delivery_stops` | no | schema |
| `loading_sessions` | **YES — exactly one** | `LoadingSession.php:16,59` + schema |
| `dispatch_boards` | yes (one) | schema |
| `network_dispatch_regions` | yes (one) | schema |
| `delivery_deliveries` | no | schema |

**Findings:**

1. **The reachable trip model has no warehouse dimension at all.** A `Trip` cannot state which warehouse it loads from. So multi-warehouse-per-trip is not "unsupported" — the concept is absent from the model.
2. **The orphan Loading OS supports exactly one warehouse per loading session.** Split fulfilment across warehouses would require multiple sessions, and nothing coordinates sessions.
3. **Split fulfilment across *vehicles* IS supported — in stack B only.** `LoadVehicleWorkflow.php:56-62` builds per-order, per-line allocation maps explicitly so "one order across multiple vehicles" works, and `ShipOrderInventoryAction` accepts a `$lineQuantities` map. Its comment states this supports split-shipment. This is split-by-vehicle, **not** split-by-warehouse.
4. **One order can be on at most one trip.** `TripService::assignOrder()` throws `orderAlreadyOnAnotherTrip` on any existing `TripOrder` for that order id, backed by a unique index. So in stack A, split fulfilment of a single order is structurally prohibited.

**Recorded explicitly: the reachable system supports one trip per order, no warehouse dimension on the trip, and no split fulfilment. The orphan Loading OS supports one warehouse per session and split-by-vehicle within it.** Nothing was implemented.

---

## 13. Dispatch Gate Audit

**Verdict: BOTH — but the enforced gate and the UI gate are different gates, and the UI one is broken.**

### The backend-enforced gate (stack A)

`TripService::changeStatus()` (`TripService.php:64-70`):

```php
// Dispatch is gated on readiness, not just on the transition table.
if ($target === TripStatus::Dispatched) {
    $blockers = $trip->dispatchBlockers();
    if ($blockers !== []) {
        throw DistributionException::dispatchBlocked($blockers);
    }
}
```

Plus the transition table: only `ReadyForDispatch` may move to `Dispatched` (`TripStatus.php:63`). Plus `update()` strips `status`, so there is no bypass through the update endpoint.

**What `Trip::dispatchBlockers()` checks** (`Trip.php:190-224`):

| Check | Present |
|---|---|
| Trip has ≥1 order | ✅ |
| Full driver acceptance (products, custody, equipment) | ✅ |
| Driver/vehicle assignment exists and is active | ✅ (when `type->requiresDriverVehicleAssignment()`) |
| Driver may start deliveries (licence/status) — delegated to Drivers module | ✅ |
| Vehicle may be dispatched (status/licence/insurance) — delegated to Vehicles module | ✅ |
| External-carrier trip references a shipping company | ✅ |
| **Loading completion** | ❌ **NOT CHECKED** |
| **Inventory shipped state (`inventory_shipped_at`)** | ❌ **NOT CHECKED** |
| **Vehicle capacity / manifest completeness** | ❌ not checked |

So the gate is real and cannot be bypassed **for what it checks** — but it does not check loading completion or inventory state. A trip can be dispatched with orders whose stock was never shipped. (Note: `LoadingCompleted → DriverAccepted → ReadyForDispatch` is the only route to `Dispatched` in the transition table, so a *status* of loading-completed is implied; the *substance* of loading — that products were physically loaded and inventory shipped — is not verified.)

### The read-only probe

`GET trips/{id}/dispatch-readiness` → `TripController@dispatchReadiness` returns `{is_ready, blockers, has_full_driver_acceptance}`. Its own docblock calls it "Read-only readiness probe used by the dispatch gate UI." It is advisory; enforcement is in `changeStatus`. **Correct design.**

### The stack B gate

`DispatchVehicleAction:27-42` enforces:
- assignment status must be `loading_complete` (so **this** path does check loading completion);
- an active `DriverAssignment` must exist.

Then it ships inventory. **Backend-enforced. No UI.**

### The stack C "Dispatch Gate" pages

`/operations/dispatch-gate` and `/operations/dispatch-gate/:tripId` call `GET /api/distribution/dispatch-gate` and `POST /api/distribution/trips/{id}/dispatch` — **neither exists**. These pages render and then fail to load any data. They are **not** a UI over the enforced gate; they are a UI over a backend that was never built.

### Can dispatch bypass the gate?

| Path | Bypass possible? |
|---|---|
| `PATCH trips/{id}/status` → dispatched | ❌ no — `dispatchBlockers()` enforced |
| `PUT trips/{id}` (update) | ❌ no — `status` unset |
| `POST api/loading/.../dispatch` | ❌ gate enforced (`loading_complete` + driver) — **but 0 permission middleware on the route**, so any authenticated user may attempt it |
| `POST /api/distribution/trips/{id}/dispatch` | n/a — route does not exist |

---

## 14. Delivery Audit

Assessed against code only. Where two implementations exist, the better one is scored and both are named.

| # | Capability | Status | Evidence |
|---|---|---|---|
| 1 | Trip starts | **PARTIAL** | Stack A: `PATCH trips/{id}/status` → `dispatched`/`in_progress` works. No `start` semantics (odometer, GPS start, actual departure) in the reachable stack. Stack D's `POST /driver/trips/{id}/start` (with `lat`,`lng`,`odo_start`) **MISSING** — no backend. |
| 2 | Driver receives trip | **IMPLEMENTED** (backend) | `PATCH trips/{id}/driver-acceptance` → `recordDriverAcceptance`; `Trip::hasFullDriverAcceptance()` feeds the dispatch gate. UI on the non-navigable trips page. |
| 3 | Stop is created | **IMPLEMENTED** | `POST trips/{tripId}/stops/generate` → `DeliveryService::generateStops` → `distribution_delivery_stops`. |
| 4 | Driver arrives | **PARTIAL** | `PATCH stops/{stopId}/start` sets `in_progress` + `attempted_at`. No geofence/arrival verification; no GPS. |
| 5 | Delivery attempt starts | **IMPLEMENTED** (Delivery OS) | `POST delivery/{id}/attempts`, `PATCH .../advance`. Full `AttemptStatus` enum. Stack A has only stop start. |
| 6 | Customer confirmation | **PARTIAL** | Captured as part of POD (signature/artifact). No distinct customer-confirmation step or OTP. |
| 7 | POD | **IMPLEMENTED** (Delivery OS) | `PodStatus` + `PodArtifactKind` enums; `POST .../pod`, `POST .../pod/artifacts`, `PATCH .../pod/validate`, `PATCH .../pod/reject`. Permissions `delivery.pod.capture` / `delivery.pod.validate`. Stack A has a simpler `POST stops/{stopId}/proof`. |
| 8 | COD / payment collection | **IMPLEMENTED** | Delivery OS: open/collect/verify/dispute/write-off (`CodStatus`). Distribution: `POST stops/{stopId}/payments` + verify/reject. Cash authority correctly reserved to Distribution's `SettlementService` (documented contract, respected in code). |
| 9 | Delivered | **IMPLEMENTED (status only)** | Stack A `completeStop`; Delivery OS `attempts/{id}/succeed`. **No inventory, no order status, no finance** — both events unconsumed. |
| 10 | Failed | **IMPLEMENTED** | `attempts/{id}/fail`, `FailureCategory` + `FailureReason` enums, `delivery_failures` table. |
| 11 | Retry | **IMPLEMENTED** | `POST delivery/{id}/retry`, `GET .../retry-eligibility`, `PATCH .../address-corrected`, `DeliveryRetryScheduled` / `DeliveryRetryExhausted` events, `AwaitingRetry` status. |
| 12 | Return | **IMPLEMENTED (document only)** | Both impls (§8). No stock effect. |
| 13 | Warehouse return | **PARTIAL** | `DeliveryReturnService`: `initiated → in_transit → received → verified`, with per-line `warehouse_confirmed_qty` and **derived** `discrepancy_qty`. **No restock at any state.** |
| 14 | Settlement | **IMPLEMENTED (backend)** | `SettlementService`: open, submit-cash, reconcile, finalize, dispute; payment verify/reject; `financial-summary`. `TripSettled` unconsumed → **no finance posting**. UI on the non-navigable trips page. |

Additional Delivery OS capability present and routed: `SlaBreached` event, `GET delivery/{id}/timeline`, `GET delivery/{id}/public-timeline`, `PATCH delivery/{id}/escalate`, `PATCH delivery/{id}/cancel`, `GET delivery/stats`.

**Summary: 8 IMPLEMENTED, 5 PARTIAL, 1 MISSING (trip start with GPS/odometer), 0 UNKNOWN.** The Delivery OS backend is the most complete subsystem in the whole domain. Its two structural problems are that nothing consumes its events and its detail endpoints are not tenant-scoped.

---

## 15. Driver Mobile Audit

**Verdict: 11 pages, 0 working. Verified by implementation, not by component name.**

| Screen | Route | Component | API it calls | Backend | Actions implemented in the component |
|---|---|---|---|---|---|
| Driver Home | `/driver/home` | `DriverHomePage` | `GET /api/driver/trips` | ❌ 404 | list active trips, navigate |
| Trip Dashboard | `/driver/trips/:tripId` | `DriverTripDashboardPage` | `GET /api/driver/trips/{id}`, `POST .../start`, `.../finish`, `.../close` | ❌ 404 | KPI grid, start/finish/close trip |
| Stop List | `.../stops` | `DriverStopListPage` | `GET .../stops` | ❌ 404 | list, open stop |
| Stop Detail | `.../stops/:stopId` | `DriverStopDetailPage` | `GET .../stops/{id}`, `POST /driver/stops/{id}/action` | ❌ 404 | record delivery action |
| Collections | `.../collections` | `DriverCollectionsPage` | `GET .../collections`, `POST /driver/stops/{id}/payment` | ❌ 404 | payment-collection form, bank-transfer form |
| Exceptions | `.../exceptions` | `DriverExceptionsPage` | `GET .../exceptions`, `POST /driver/stops/{id}/exception` | ❌ 404 | exception form |
| Returns | `.../returns` | `DriverReturnsPage` | `GET/POST .../returns`, `POST /driver/returns/{id}/confirm` | ❌ 404 | return form, confirm |
| Settlement | `.../settlement` | `DriverSettlementPage` | `GET .../settlement`, `POST .../settlement/submit` | ❌ 404 | settlement summary, submit with amount + discrepancy notes |
| Custody Return | `.../custody` | `DriverCustodyReturnPage` | `GET .../custody-returns` | ❌ 404 | custody return list + form |
| Timeline | `.../timeline` | `DriverTripTimelinePage` | `GET .../timeline` | ❌ 404 | timeline render |
| Map | `.../map` | `DriverMapPage` | `POST .../gps` | ❌ 404 | record GPS |

Supporting components that exist and are wired into the pages (so the UI work is real, only the backend is absent): `proof-of-delivery-form`, `payment-collection-form`, `bank-transfer-form`, `return-form`, `exception-form`, `delivery-action-form`, `custody-return-list`, `settlement-summary`, `driver-trip-timeline`, `trip-kpi-grid`, `delivery-stop-card`, `stop-status-badge`, `driver-trip-card`.

| Aspect | Finding |
|---|---|
| **Offline behaviour** | **NONE.** No service worker, no IndexedDB/localStorage queue, no optimistic mutation queue, no retry-on-reconnect. Plain Axios calls. A driver losing signal loses the action. |
| **Payment collection** | UI complete (`payment-collection-form`, `bank-transfer-form`); backend absent. |
| **POD** | UI complete (`proof-of-delivery-form`); backend absent. |
| **Delivery confirmation** | UI complete (`delivery-action-form`); backend absent. |
| **Failure reasons** | UI complete (`exception-form`); backend absent. |
| **Return handling** | UI complete (`return-form`, `custody-return-list`); backend absent. |
| **Auth** | Own Axios instance with **no token interceptor** → every call unauthenticated. |
| **Mobile shell** | None. All 11 routes render inside `AppShell` (desktop module rail + context sidebar). |
| **Driver identity** | No driver-role concept in `use-navigation.ts`; `MODULE_DOMAINS` has no driver entry. There is no way to log in *as a driver* with a driver-appropriate surface. |

---

## 16. Returns Audit (delivery returns only — Supplier Returns untouched)

### Chain as implemented

```
Customer refuses / partial
   ↓
Driver Return        stack A: POST trips/{tripId}/returns → distribution_trip_returns   (no stock)
                     stack D: POST /api/driver/trips/{id}/returns → 404
   ↓
Vehicle              VehicleInventoryService::recordReturn()  → ZERO CALLERS. Vehicle balance unchanged.
   ↓
Warehouse            stack A: PATCH trips/{tripId}/returns/{rid}/confirm  (2-state, no stock)
                     Delivery OS: PATCH delivery/{id}/returns/{rid}/in-transit → /receive
   ↓
Inspection           Delivery OS receive(): writes warehouse_confirmed_qty per line,
                     DERIVES discrepancy_qty (never trusts caller)  ✅ good
                     → verify(): status=verified, parent delivery → Returned
   ↓
Restock / Damage     ❌ DOES NOT EXIST — no stock mutation, no sellable/damaged disposition
```

| Question | Answer |
|---|---|
| **Stock mutation timing** | Never. Not on driver return, not on warehouse receipt, not on verification. |
| **FIFO behaviour** | None. No layer is re-created, no cost is restored. |
| **Damaged items** | No disposition field drives stock. `has_discrepancy` + `discrepancy_qty` are recorded as document facts only. |
| **Sellable items** | Not distinguished from damaged for stock purposes. |
| **Return status** | Well-modelled in the Delivery OS: `DeliveryReturnStatus` = initiated → in_transit → received → verified (+ discrepancy flag), with `assertTransition()` guarding moves. Stack A's `distribution_trip_returns` is a flat 2-state document. |
| **Duplicate processing** | Guarded within each implementation by `assertTransition()`. **Not guarded across implementations** — a return could be recorded once in `distribution_trip_returns` and again in `delivery_returns` with no cross-check. |
| **Tenant scope** | `DeliveryReturnController` has no auth-derived company scope; the parent delivery is resolved by bare `findByUuidOrFail()`. |

**Positive finding:** deriving `discrepancy_qty` from the counted quantity rather than trusting the caller (`DeliveryReturnService.php:116-117`) is the correct pattern and is implemented correctly.

---

## 17. Security / Tenant Isolation Audit

### 17.1 There is no tenant middleware and no global scope

- Middleware on every in-scope route is `api` + `Authenticate:sanctum` (+ optional permission). **No tenant/company middleware exists in the stack.**
- `Trip`, `Delivery`, and `Vehicle` all define `protected static function booted()` — and each contains **only** a UUID generator. **No `addGlobalScope`, no `BelongsToCompany` trait.**

So company isolation depends entirely on each controller filtering explicitly.

### 17.2 Scoping matrix (all 51 shipping controllers)

`authCompany` = references `user()->company_id`. `reqCompany` = takes `company_id` from request input. `bareLookup` = `::findOrFail` / `where('uuid',$id)` with no company predicate.

| Module | Controller | authCompany | reqCompany | bareLookup | Verdict |
|---|---|---|---|---|---|
| Logistics/Distribution | **TripController** | 0 | **2** | 1 | 🔴 **P0** |
| Logistics/Distribution | **DeliveryController** | 0 | 0 | 1 | 🔴 **P0** |
| Logistics/Distribution | **SettlementController** | 0 | 0 | 1 | 🔴 **P0 (cash)** |
| Logistics/Distribution | DistributionZoneController | 0 | 0 | 2 | 🟠 |
| Logistics/Distribution | DistributionPlanningController | 0 | 0 | 0 | 🟠 |
| Logistics/Distribution | DistributionWindowController | 1 | 0 | 0 | ✅ |
| Logistics/Drivers | **DriverController** | 0 | 0 | **10** | 🔴 **P0** |
| Logistics/Vehicles | VehicleController | 0 | 0 | 0 | 🟠 |
| Logistics/Vehicles | VehicleMaintenanceController | 0 | 0 | 0 | 🟠 |
| Logistics/ShippingCompanies | ShippingCompanyController | 0 | 0 | **9** | 🟠 |
| Logistics/Delivery | DeliveryController | 1 | 0 | 0 | ⚠️ index/stats/store only |
| Logistics/Delivery | **DeliveryCodController** | 0 | 0 | 0 | 🔴 **P0 (cash)** |
| Logistics/Delivery | **DeliveryPodController** | 0 | 0 | 0 | 🔴 **P0 (legal proof)** |
| Logistics/Delivery | **DeliveryAttemptController** | 0 | 0 | 0 | 🔴 **P0** |
| Logistics/Delivery | **DeliveryReturnController** | 0 | 0 | 0 | 🔴 **P0** |
| Logistics/Routing | RoutingController | 0 | 0 | 2 | 🟠 |
| Logistics/Geography | Governorate / City / CityAlias | 0 | 0 | 3/5/2 | ⚪ global master data |
| Logistics/Carriers | CarrierController | 1 | 0 | 1 | ✅ |
| Logistics/Dispatch | DispatchController | 1 | 0 | 4 | ✅ |
| Logistics/Dispatch | DispatchOperationsController | 1 | 0 | 11 | ✅ |
| Logistics/Fleet | FleetUnit / Fuel / Inspection / Maintenance | 1/1/2/1 | 0 | 2/0/1/1 | ✅ |
| Logistics/Network | NetworkController | 1 | 0 | 4 | ✅ |
| Logistics/Operations | all 9 controllers | 1 each | 0 | 0 | ✅ |
| Logistics/Intelligence | all 5 controllers | 1 each | 0 | 0 | ✅ |
| Logistics/Automation | AutomationController | 1 | 0 | 0 | ✅ |
| Operations/Loading | all 7 controllers | 1–8 each | 0 | 0 | ✅ scoping — ⚠️ **0 permission middleware** |

**Two clear generations.** The newer LOG-00x modules (Dispatch, Fleet, Network, Operations, Intelligence, Automation, Carriers, DistributionWindow) and all of `Operations\Loading` derive company from the authenticated user. The older TASK-DIST-00x era (Distribution trips/delivery/settlement, Drivers, Vehicles, ShippingCompanies, Routing) do not.

### 17.3 Concrete cross-tenant exposures (evidence)

**`TripController` — the worst case.**

```php
// stats(), line 41-42 — company from REQUEST, and only when supplied
$base = fn () => Trip::query()
    ->when($request->filled('company_id'),
           fn ($q) => $q->where('company_id', $request->input('company_id')));

// index(), line 93-97 — company_id is one OPTIONAL filter among four
foreach (['company_id','preparation_wave_id','distribution_zone_id','shipping_company_id'] as $filter) {
    if ($request->filled($filter)) { $query->where($filter, $request->input($filter)); }
}

// loadTrip(), line 325-338  &  resolveTrip(), line 343-346 — no company predicate
return Trip::where('uuid', $id)->firstOrFail();
```

| Another company could… | Via | Verified |
|---|---|---|
| **view** all trips of all companies | `GET trips` with no `company_id` | ✅ no scope in `index()` |
| **view** a specific foreign trip + orders, custody, stops, settlement, payments, returns | `GET trips/{uuid}` and children | ✅ `loadTrip` unscoped, and all GETs carry no permission middleware either |
| **create** a trip inside a foreign company | `POST trips` with `company_id` of the victim (`rules()`: `'company_id' => [$required,'uuid','exists:companies,id']` — existence only) | ✅ |
| **modify** a foreign trip | `PUT trips/{uuid}` → `resolveTrip` | ✅ |
| **assign** foreign orders onto a trip | `POST trips/{uuid}/orders` — `order_id` validated as `exists:orders,id` only | ✅ |
| **dispatch** a foreign trip | `PATCH trips/{uuid}/status` → `loadTrip` | ✅ |
| **deliver / complete stops / capture proof** on a foreign trip | Distribution `DeliveryController` | ✅ |
| **record / verify / reject payments; finalize settlement** on a foreign trip | `SettlementController` | ✅ |
| **collect COD, capture/validate POD, receive returns** on a foreign delivery | Delivery OS sub-controllers via `findByUuidOrFail()` | ✅ |
| **view / modify / assign vehicles to** any driver | `DriverController` — 10 bare `Driver::findOrFail($id)` | ✅ |

**The frontend confirms the model is client-driven:** `TripsWorkspacePage` reads `activeCompanyId` from `useOrganizationContext()` and passes it as the `company_id` **request filter**. The tenant boundary is chosen by the client and trusted by the server.

**Schema-level impossibility:** `logistics_drivers` has no `company_id` column (columns: `id, driver_code, full_name, mobile, national_id, date_of_birth, address, employment_date, shipping_company_id, license_*, status, notes, timestamps`). Drivers **cannot** be company-isolated without a migration. `logistics_shipping_companies`, `logistics_governorates`, `logistics_cities` likewise. All `distribution_trip_*` child tables (orders, custody, returns, settlements, stops, proofs, actions, exceptions, payments) have no `company_id` and scope only through the unscoped parent.

### 17.4 Warehouse / vehicle / driver scope

- **Warehouse scope:** none anywhere in shipping. `distribution_trips` has no `warehouse_id`; no endpoint filters by warehouse.
- **Vehicle scope:** `logistics_vehicles.company_id` exists but `VehicleController` never filters on it.
- **Driver scope:** impossible at schema level (no column).

### 17.5 Permission enforcement gaps

- **`api/loading/*` — 24 routes, 0 permission middleware.** Includes the inventory-consuming dispatch.
- **All 20 GET routes under `api/logistics/distribution/trips/*` are unprotected**, including `/settlement`, `/financial-summary`, `/payments`, `/custody`.
- `api/logistics/drivers|vehicles|shipping-companies|geography` — list/show/stats/document-download GETs unprotected. `GET drivers/{id}/documents/{documentId}/download` and the vehicle equivalent serve document files with **no permission and no company check**.

### 17.6 The 403 wall (17 permissions missing in `ecos_dev`)

`RequirePermissionMiddleware` **fails closed** (`abort(403, "Permission denied: {$permission}")`); only `is_system` roles bypass.

Diffing every `RequirePermissionMiddleware:` argument against the `permissions` table: **65 in-scope permissions referenced, 17 absent, gating 177 routes.**

| Missing permission | Routes gated |
|---|---|
| `operations.view` | **76** |
| `fleet.view` | 17 |
| `delivery.view` | 12 |
| `dispatch.view` | 11 |
| `network.view` | 8 |
| `delivery.execute` | 8 |
| `dispatch.propose` | 7 |
| `fleet.manage` | 7 |
| `network.manage` | 6 |
| `carrier.view` | 5 |
| `routing.view` | 5 |
| `carrier.manage` | 3 |
| `dispatch.release` | 3 |
| `routing.optimize` | 4 |
| `dispatch.manage` | 2 |
| `delivery.retry` | 2 |
| `delivery.cancel` | 1 |
| **Total** | **177** |

`operations.view` alone gates the Operations Center, Operational Dashboards, Alert Center, Activity Center, Enterprise Readiness, Logistics Intelligence and Automation Monitoring — **7 of the 24 navigable shipping pages**.

**Root cause — environment drift, not a code defect:**

| Check | Result |
|---|---|
| Do the seeding migrations define these? | ✅ yes — e.g. `Logistics/Operations/.../2026_08_05_100003_seed_phase4_permissions.php:22` inserts `operations.view` |
| Did they run in `ecos_dev`? | ✅ recorded in `migrations` |
| Present in `ecos_dev`? | ❌ **0 of 7** sampled two-part permissions |
| Present in `ecos_dev_test`? | ✅ **7 of 7** |
| Dot-depth distribution in `ecos_dev.permissions` | 2 dots: 559 · 3 dots: 19 · **1 dot: 0** |

**Not a single two-part (`namespace.action`) permission exists in `ecos_dev`, while `ecos_dev_test` has them.** The migrations are recorded as run, so a plain `migrate` will not restore them. The code is correct; the `ecos_dev` permission table has drifted. **Every environment must be verified independently** — a deployment provisioned the same way inherits the same 403 wall.

Corroborating drift: `2026_12_20_000000_seed_enterprise_permission_matrix.php:26` asserts that "`operations.view` and `routing.optimize` already do" exist. In `ecos_dev`, neither does.

Nothing was repaired.

---

## 18. Database Model Map

96 shipping tables in `ecos_dev`. Row counts are from read-only `COUNT(*)`. **Soft delete is present on exactly one table (`distribution_zones`); the other 95 have no `deleted_at`.**

### GEOGRAPHY
| Table | Owner | company_id | Rows | Notes |
|---|---|---|---|---|
| `logistics_governorates` | Logistics\Geography | ❌ | — | global |
| `logistics_cities` | Logistics\Geography | ❌ | — | + `distribution_zone_id` |
| `logistics_city_aliases` | Logistics\Geography | ❌ | — | alias matching |
| `master_governorates` | Admin\Configuration | ❌ | — | **second geography authority** |

### DISTRIBUTION
| Table | company_id | soft del | Rows | Key relationships / status |
|---|---|---|---|---|
| `distribution_zones` | ❌ | **✅** | 3 | only soft-deleted table in the domain |
| `distribution_zone_plans` | ❌ | ❌ | — | |
| `distribution_windows` | ✅ | ❌ | — | `status` (`DistributionWindowStatus`) |
| `distribution_virtual_slots` | ✅ | ❌ | — | capacity slots |
| `distribution_slot_zones` | ❌ | ❌ | — | slot↔zone |
| `distribution_window_orders` | ✅ | ❌ | — | `assignment_source` |

### TRIPS
| Table | company_id | Rows | Notes |
|---|---|---|---|
| `distribution_trips` | ✅ | **0** | `status` = `TripStatus` (13 states); `uuid`; `shipping_company_id`, `driver_vehicle_assignment_id`, `preparation_wave_id`, `distribution_zone_id`; **NO `warehouse_id`** |
| `distribution_trip_orders` | ❌ | **0** | unique on `order_id` → one trip per order |
| `distribution_trip_custody` | ❌ | **0** | `CustodyItemType` |
| `distribution_trip_returns` | ❌ | **0** | `TripReturnKind` |
| `distribution_trip_settlements` | ❌ | **0** | `SettlementStatus` |
| `distribution_payment_collections` | ❌ | **0** | `PaymentType` |

### VEHICLES
| Table | company_id | vehicle_id | Rows |
|---|---|---|---|
| `logistics_vehicles` | ✅ (unused by controller) | — | 1 |
| `logistics_vehicle_documents` | ❌ | ✅ | — |
| `logistics_vehicle_maintenance_records` | ❌ | ✅ | — |
| `fleet_units` | ✅ | ✅ | — | **second vehicle representation** |
| `fleet_groups`, `fleet_fleets`, `fleet_unit_group_history` | ✅/✅/❌ | | — |
| `fleet_inspections`, `_templates`, `_template_items`, `_results` | ✅/✅/❌/❌ | | — |
| `fleet_defects` | ✅ | | — |
| `fleet_maintenance_plans`, `_schedule_rules`, `fleet_work_orders` | ✅/❌/✅ | | — |
| `fleet_fuel_transactions`, `fleet_fuel_cards`, `fleet_odometer_readings`, `fleet_cost_entries` | ✅ | | — |

### DRIVERS
| Table | company_id | driver_id | Rows |
|---|---|---|---|
| `logistics_drivers` | ❌ **NO COLUMN** | — | 1 |
| `logistics_driver_documents` | ❌ | ✅ | — |
| `logistics_driver_vehicle_assignments` | ❌ | ✅ (+`vehicle_id`) | — |
| `driver_assignments` (Loading OS) | ✅ | ✅ (+`vehicle_id`) | — |

### LOADING
| Table | company_id | warehouse_id | Rows |
|---|---|---|---|
| `loading_sessions` | ✅ | **✅ (exactly one)** | **0** |
| `loading_tasks` | ✅ | ❌ | 0 |
| `loading_exceptions` | ✅ | ❌ | 0 |
| `vehicle_assignments` | ✅ | ❌ (+`vehicle_id`) | **0** |
| `vehicle_plans`, `vehicle_plan_slots`, `vehicle_plan_slot_orders`, `vehicle_plan_adjustment_log` | ✅ | ❌ | 0 |
| `vehicle_capacity_snapshots` | ✅ | ❌ | 0 |
| `allocation_records`, `allocation_decisions` | ✅ | ❌ | 0 |
| `shipment_groups`, `shipment_group_items` | ✅ | ❌ | 0 |
| `route_plans`, `route_plan_stops` | ✅ | ❌ | 0 |

### DISPATCH
`dispatch_boards` (✅, +`warehouse_id`), `dispatch_sessions` (✅), `dispatch_queue_items` (✅), `dispatch_proposals` (✅), `dispatch_proposed_assignments` (❌, +vehicle+driver), `dispatch_resource_allocations` (✅, +vehicle+driver), `dispatch_assignment_locks` (✅), `dispatch_assignment_reviews` (✅), `dispatch_assignment_blockers` (❌), `dispatch_conflicts` (✅), `dispatch_policies` (✅), `dispatch_audit_entries` (✅), `dispatch_timeline_events` (✅), `dispatch_releases` (❌). All **0 rows**.

### DELIVERY
| Table | company_id | Rows |
|---|---|---|
| `delivery_deliveries` | ✅ | **0** |
| `delivery_attempts` | ❌ | 0 |
| `delivery_pods`, `delivery_pod_artifacts` | ❌ | 0 |
| `delivery_cod_records` | ❌ | 0 |
| `delivery_failures` | ❌ | 0 |
| `delivery_tracking_events` | ❌ | 0 |
| `distribution_delivery_stops` / `_actions` / `_proofs` / `_exceptions` | ❌ | 0 |

### RETURNS
`delivery_returns` (❌ company), `delivery_return_lines` (❌), `distribution_trip_returns` (❌). All **0 rows**.

### SETTLEMENT
`distribution_trip_settlements` (❌), `distribution_payment_collections` (❌). **0 rows**.

### VEHICLE INVENTORY
| Table | company_id | vehicle_id | Rows | Notes |
|---|---|---|---|---|
| `vehicle_inventory_items` | ✅ | ✅ | **0** | `VehicleInventoryItemStatus`; the parallel ledger |
| `vehicle_inventory_movements` | ✅ | ✅ | **0** | append-only, `MovementType` |
| `vehicle_shift_reconciliations` | ✅ | ✅ | **0** | **no writer, no route, no controller** |
| `vehicle_shift_reconciliation_lines` | ✅ | ❌ | **0** | **no writer** |

### NETWORK
`network_service_areas` (✅), `network_service_area_members` (❌), `network_service_levels` (✅), `network_dispatch_regions` (✅, +`warehouse_id`), `network_capacity_plans` (✅), `network_capacity_slots` (❌), `network_capacity_commitments` (✅), `network_coverage_rules` (❌). **0 rows**.

**Schema-level observations:** (1) only 1 of 96 tables has soft delete; (2) child tables almost universally lack `company_id`, so isolation depends entirely on scoped parent lookups that §17 shows are frequently missing; (3) `warehouse_id` appears on only 3 tables, none of them the trip; (4) every transactional table is empty.

---

## 19. Event Map

**70 domain events in `Modules\Logistics`. 8 have a consumer. 62 have none.**

The only listener registration in the entire tree is `LogisticsAutomationServiceProvider::LISTENERS` (lines 47-56), which maps 8 events to 8 `AbstractAutomationListener` subclasses — all `ShouldQueue`, all feeding the **Automation monitoring surface** (observability), none producing a business effect.

### Consumed (8) — all → Automation monitoring
| Event | Producer | Listener | Side effect |
|---|---|---|---|
| `ReadinessValidated` | Operations\ReadinessController | `ReadinessValidatedListener` | monitoring record |
| `LogisticsHealthCalculated` | Operations\OperationalHealthController | `LogisticsHealthCalculatedListener` | monitoring record |
| `DiagnosticsGenerated` | Operations\DiagnosticsController | `DiagnosticsGeneratedListener` | monitoring record |
| `ExecutiveSummaryGenerated` | Operations\SummaryController | `ExecutiveSummaryGeneratedListener` | monitoring record |
| `OperationalExceptionRaised` | Operations\ExceptionController | `OperationalExceptionRaisedListener` | monitoring record |
| `OperationalExceptionResolved` | Operations\ExceptionController | `OperationalExceptionResolvedListener` | monitoring record |
| `DispatchConflictDetected` | Dispatch\DispatchOperationsController | `DispatchConflictDetectedListener` | monitoring record |
| `DispatchConflictResolved` | Dispatch\DispatchOperationsController | `DispatchConflictResolvedListener` | monitoring record |

### Events with NO consumers (62) — business-critical subset
| Event | Producer | What is lost |
|---|---|---|
| **`TripDispatched`** | `TripService::changeStatus` | no inventory ship, no order status, no notification |
| **`TripStatusChanged`** | `TripService::changeStatus` | no downstream sync |
| **`TripSettled`** | `SettlementService::finalize` | **no finance posting** |
| **`DeliveryStopCompleted`** | `DeliveryService::completeStop` | **no order completion, no stock** |
| `DeliverySucceeded` | Delivery OS attempt succeed | no order completion |
| `DeliveryFailed` | Delivery OS attempt fail | no order/exception routing |
| `DeliveryPartiallySucceeded` | Delivery OS | — |
| `CodCollected` | `CodCompletionService` | **no cash/finance link** |
| `PodValidated` / `PodRejected` | `PodService` | — |
| `ReturnInitiated` / `ReturnReceived` | `DeliveryReturnService` | **no restock** |
| `DeliveryRetryScheduled` / `DeliveryRetryExhausted` | Delivery OS | no escalation |
| `SlaBreached` | Delivery OS | no alert |
| `DeliveryCreated` / `DeliveryAttemptStarted` | Delivery OS | — |
| `DispatchReleased`, `DispatchProposalAccepted/Generated/Rejected`, `DispatchBoardOpened`, `DispatchBlocked` | Dispatch | no trip transition |
| `OrderAddedToDistributionWindow`, `DistributionAssignmentChanged`, `LateOrderManuallyAssigned` | Distribution | — |
| `CapacityCommitted/Released/Exhausted/ThresholdBreached`, `CapacityPlanPublished`, `ServiceAreaOpened/Closed` | Network | — |
| all 17 Fleet events | Fleet | no maintenance/cost automation |
| all 5 Routing events | Routing | no ETA propagation |
| all 4 Vehicles events | Vehicles | — |
| `CarrierAccountConnected/Disabled` | Carriers | — |

### Loading OS events (`Operations\Loading`) — 11 events
`VehicleLoaded`, `VehicleAssigned`, `VehicleReleased`, `VehiclePlanned`, `VehiclePlanRecalculated`, `DriverAssigned`, `AllocationCompleted`, `AllocationAdjusted`, `LoadingSessionCreated/Closed/Cancelled` — **no listeners registered**.

**The one working chain:** `LoadVehicleWorkflow` → `OrderDispatchedEvent` → `HandleOrderDispatched` (registered in `FulfillmentServiceProvider:67`). This is the sole event-driven business effect in the entire shipping domain — and it is reachable only through the UI-less stack B.

### Coupling analysis
- **Duplicate listeners:** none.
- **Synchronous coupling:** `DispatchVehicleAction` calls `LoadVehicleWorkflow` **directly** (not via an event) inside its transaction — a deliberate, documented cross-domain call (`Operations\Loading` → `Operations\Fulfillment` → `Commerce\Orders`). It is the only cross-domain business call in shipping, and it is correct in that it keeps dispatch and inventory atomic.
- **Direct cross-domain calls:** the above; plus `Trip::dispatchBlockers()` reading `driver->canStartDeliveries()` and `vehicle->canBeDispatched()` — a documented BR delegation to the Drivers/Vehicles modules.
- **Consumers with no producer:** none found.
- **Events with no consumers:** 62 + 11 = **73**.

**Caveat:** the platform's `EnterpriseEventBus` overrides `DomainEventBus`, and `Event::listen()` is known not to observe inventory events routed through that bus. The Automation listeners register via `Event::listen()` and consume Logistics' own events dispatched with `event()`/`::dispatch()`, so they do fire. Any future attempt to bridge shipping events into the inventory domain must account for the bus override.

---

## 20. Legacy / Dead Code

Nothing was deleted. Evidence given per item.

| # | Artefact | Evidence of deadness | Verdict |
|---|---|---|---|
| 1 | `frontend/src/config/navigation.ts` (180 lines) | **0 importers** in `frontend/src`; `module-navigation.ts:485` says it "replaces the removed navigation.ts"; uses the forbidden `label: string` field; defines 4 nav groups that no longer exist | **DEAD — superseded, actively misleading** |
| 2 | `api.php:952-957` "Operations — Distribution Board OS" comment banner | Empty group; **0 routes**; references `ADR-DIST-004` | **DEAD banner over a missing implementation** |
| 3 | `operations/distribution-board/` — 5 pages, 22 components, 3 hooks, 1 service, 1 types file | Backend namespace `/api/distribution/*` has **0 routes**; no nav entry; no test | **UNREACHABLE (functionally)** |
| 4 | `operations/driver-mobile/` — 11 pages, 13 components, 1 hook, 1 service, 1 types file | Backend `/api/driver/*` has **0 routes**; no nav entry; no token interceptor | **UNREACHABLE (functionally)** |
| 5 | `VehicleShiftReconciliation` + `VehicleShiftReconciliationLine` + 2 migrations + `ReconciliationLineRequest` | No controller, no route, no writer, 0 rows | **UNUSED SCAFFOLDING** |
| 6 | `VehicleInventoryService::recordReturn()` | 0 callers | **DEAD METHOD** |
| 7 | `VehicleInventoryService::recordDelivery()` | 0 callers | **DEAD METHOD** |
| 8 | `VehicleInventoryService::unallocate()` | 0 callers | **DEAD METHOD** |
| 9 | `Operations\Loading` — 24 routes, 15 Actions, 2 Services, 21 Models, 7 Controllers, 3 Policies, 12 Requests, 11 Resources | **0 frontend callers** (grep across all of `frontend/src`); 0 rows in all its tables | **ORPHANED SUBSYSTEM — functional but unreachable. Do NOT delete: it is the only inventory-correct dispatch path.** |
| 10 | 62 Logistics events + 11 Loading events | no listeners | **PRODUCED, NEVER CONSUMED** |
| 11 | `api/logistics/operations/pools/*` (13), `capacity/*` (11), `diagnostics/*` (7) | no frontend caller | **UNUSED BACKEND** |
| 12 | `logistics` module entry in `module-navigation.ts:337-341` | `items: []` and listed in `HIDDEN_MODULE_IDS` | intentionally inert (documented) — **not a defect** |
| 13 | `ROUTES.loadingWorkspace` path shape | registered as `${loadingWorkspace}/:tripId/loading` → `/operations/distribution/loading/:tripId/loading` | **malformed URL (duplicated segment)** |

---

## 21. Dashboard / Data Source Audit

| Dashboard | Source | Data | Refresh | Empty state | Error state |
|---|---|---|---|---|---|
| `OperationalDashboardsPage` | `GET api/logistics/operations/dashboards/{kpi,capacity,dispatch,drivers,fleet}` → `Logistics\Operations\DashboardController` | **REAL** — ⚠️ blocked by missing `operations.view` in `ecos_dev` | React Query default | present | present |
| `LogisticsIntelligencePage` | `api/logistics/intelligence/*` (17) → Decision/Forecast/Insight/Optimization controllers | **REAL** — ⚠️ blocked by `operations.view` | React Query | present | present |
| `EnterpriseWorkspacePage` | `api/logistics/intelligence/dashboard/{executive,operations}` | **REAL** — ⚠️ blocked by `operations.view` | React Query | present | present |
| `EnterpriseReadinessPage` | `api/logistics/operations/readiness/*` (6) | **REAL** — ⚠️ blocked by `operations.view` | React Query | present | present |
| `AlertCenterPage` | `api/logistics/operations/exceptions/*` (17) | **REAL** — ⚠️ partly blocked | React Query | present | present |
| `ActivityCenterPage` | `api/logistics/operations/activity/*` (6) | **REAL** — ⚠️ blocked by `operations.view` | React Query | present | present |
| `AutomationMonitoringPage` | `api/logistics/automation/{metrics,monitoring,policies}` | **REAL** — ⚠️ blocked by `operations.view` | React Query | present | present |
| `FleetDashboardPage` | `api/logistics/fleet/{stats,units,options}` | **REAL** — ⚠️ blocked by `fleet.view` | React Query | present | present |
| `DispatchCommandCenterPage` | `api/logistics/dispatch/*` | **REAL** — ⚠️ blocked by `dispatch.view` | React Query | present | present |
| `DispatchBoardPage` | `api/logistics/dispatch/boards` | **REAL** — ⚠️ blocked by `dispatch.view` | React Query | present | present |
| `DispatchExecutionPage` | `api/logistics/dispatch/ops/monitoring/*` + queue | **REAL** — ⚠️ blocked by `dispatch.view` | React Query | present | present |
| `OperationsCenterPage` | `api/logistics/operations/summary/*` | **REAL** — ⚠️ blocked by `operations.view` | React Query | present | present |
| `DeliveryPage` | `api/logistics/delivery/{stats,options}` + list | **REAL** — ⚠️ blocked by `delivery.view` | React Query | present | present |
| `DistributionPlanningPage` | `api/logistics/distribution/planning/*` | **REAL** — no permission on GETs, so it loads | React Query | present | present |
| `DistributionWorkspacePage` | `api/logistics/distribution/windows/*` | **REAL** | React Query | present | present |
| `TripsWorkspacePage` | `api/logistics/distribution/trips/{stats,options}` + list | **REAL** | React Query | present | present |
| **`LoadingDashboardPage`** | `GET /api/distribution/loading-trips` | **NEITHER — endpoint does not exist** | n/a | shown permanently | **404 on every load** |
| **`DistributionBoardPage`** | `GET /api/distribution/board` | **NEITHER** | n/a | permanent | **404** |
| **`DispatchGatePage`** | `GET /api/distribution/dispatch-gate` | **NEITHER** | n/a | permanent | **404** |
| **`DriverTripDashboardPage`** | `GET /api/driver/trips/{id}` | **NEITHER** | n/a | permanent | **404** |

**Summary: 16 dashboards are real-data (13 of them currently unreachable in `ecos_dev` behind the 403 wall); 4+ are permanently broken. Zero are mock.** Every stack A page has both an empty state and an error state — so the broken pages fail visibly (error state), not silently.

---

## 22. Historical Drift

Documentation was used only to detect contradiction with current code, never to infer missing functionality.

| # | Documented / previously reported | Current code | Verdict |
|---|---|---|---|
| 1 | `module-navigation.ts:485` — "Single source of truth … **replaces the removed navigation.ts**" | `navigation.ts` still exists with 180 lines and 4 obsolete shipping nav groups | **HISTORICAL DRIFT** — the removal never happened |
| 2 | `api.php:955` — "ADR-DIST-004: Wave is the single operational container" heading a Distribution Board OS route group | The group is empty; 0 routes | **HISTORICAL DRIFT** — banner outlived the implementation |
| 3 | `2026_12_20_000000_seed_enterprise_permission_matrix.php:26` — "`operations.view` and `routing.optimize` **already do**" exist | Neither exists in `ecos_dev` (present in `ecos_dev_test`) | **HISTORICAL DRIFT (environment)** |
| 4 | `LoadVehicleWorkflow.php:21-22` — "Closes GAP-02 … and GAP-03 (VehicleInventoryService disconnected from main InventoryItem system)" | GAP-02 genuinely closed. GAP-03 closed **only at dispatch**: `VehicleInventoryService` remains a separate ledger, and 3 of its 5 methods are dead. And the whole path has no UI. | **PARTIALLY ACCURATE** — the claim is true of dispatch, overstated as a general reconnection |
| 5 | ADR-015 Enterprise Fulfillment: `Orders→Reservation→Preparation→Pool→Loading→Vehicle→Delivery` | The chain is broken at **Pool→Loading** (no UI for `Operations\Loading`) and again at **Vehicle→Delivery** (stack A delivery has no vehicle inventory, no reconciliation) | **DRIFT — contract not met end-to-end** |
| 6 | ADR-011 Event-Driven: "everything is an event … immutable + actor-stamped" | Events are correctly shaped and actor-stamped, but **73 of 81** shipping events have no consumer. Emission without consumption is not event-driven integration. | **DRIFT — form satisfied, function not** |
| 7 | ADR-027 §16/§17 reservation chain shipped as a self-contained unit (`ec43b470`) | `ShipOrderInventoryAction` is correct and correctly called by stack B. Stack A never reaches it. | **NOT contradicted** — the reservation chain is sound; shipping simply fails to invoke it |
| 8 | TASK-DIST-005 "Driver Mobile OS" | 11 pages exist; **0 backend routes** | **DRIFT — frontend-only delivery reported as an OS** |
| 9 | Logistics OS ownership: "Logistics owns carriers/drivers/vehicles; never reuse Operations duplicates" | Respected at the master-data layer. But **two** vehicle representations (`logistics_vehicles` + `fleet_units`) and **two** carrier representations (`logistics_shipping_companies` + carrier accounts) now exist *within* Logistics. | **PARTIAL DRIFT — duplication moved inside the module** |
| 10 | EPIC-LOGISTICS-UI-001 **CERTIFIED** 2026-08-07, "96% endpoint coverage" | Accurate **for stack A**, which is what that EPIC covered: 24 pages wired to 416 routes. It did **not** cover stacks C/D, and 16 pages remain backendless. The certification is not contradicted; its scope was narrower than "Logistics/Shipping". | **NOT contradicted — scope clarification.** Per the standing note, its accepted backend dependencies are not re-raised here. |
| 11 | CR-PREP-001 "API controllers + routes NOT written" | Confirmed — 4 `preparation-service.ts` endpoints unresolved (§7.3) | **CONSISTENT** |

No code was changed in response to any drift.

---

## 23. Master Matrix

Legend: 🟢 GREEN working · 🟡 YELLOW partial · 🔴 RED broken/absent · ⚪ GRAY unknown-or-N/A

| ID | Page / Capability | Real route | FE component | BE endpoint | BE owner | Database | Current | UI | API | Integration | Tenant | Inventory impact | Events | Dup path? | Legacy? | Gap | Sev | Next task |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| S-01 | Egypt Geography | `/logistics/geography` | `EgyptGeographyPage` | `api/logistics/geography/*` (18) | Logistics\Geography | `logistics_governorates/cities/city_aliases` | 🟢 | 🟢 | 🟢 | 🟢 | ⚪ global | none | none | ✅ `master_governorates` | no | 2nd geography authority; GETs unprotected | P2 | T-11 |
| S-02 | Distribution Zones | `/logistics/distribution/zones` | `DistributionZonesPage` | `.../distribution/zones*` (8) | Logistics\Distribution | `distribution_zones` (3 rows) | 🟢 | 🟢 | 🟢 | 🟢 | 🔴 none | none | none | no | no | no company scope | P0 | T-01 |
| S-03 | Distribution Planning | `/logistics/distribution/planning` | `DistributionPlanningPage` | `.../planning/*` (5) | Logistics\Distribution | `distribution_zone_plans` | 🟢 | 🟢 | 🟢 | 🟢 | 🔴 none | none | none | no | no | no company scope | P0 | T-01 |
| S-04 | Distribution Workspace | `/logistics/distribution/workspace` | `DistributionWorkspacePage` | `.../windows/*` (13) | Logistics\Distribution | `distribution_windows`, `_virtual_slots`, `_slot_zones`, `_window_orders` | 🟡 | 🟢 | 🟢 | 🟢 | 🟢 auth | none | 🔴 3 unconsumed | ✅ Distribution Board (C) | no | **not in nav** | P1 | T-04 |
| S-05 | **Trips Workspace** | `/logistics/distribution/trips` | `TripsWorkspacePage` | `.../trips/*` (43) | Logistics\Distribution\TripService | `distribution_trips` + 5 children (0 rows) | 🟡 | 🟢 | 🟢 | 🟢 | 🔴 **request-supplied** | 🔴 **none** | 🔴 `TripDispatched`/`TripStatusChanged` unconsumed | ✅ Distribution Board (C) | no | **not in nav; P0 tenant; no inventory** | **P0** | T-01, T-04, T-05 |
| S-06 | Shipping Companies | `/logistics/shipping-companies` | `ShippingCompaniesPage` | `.../shipping-companies/*` (15) | Logistics\ShippingCompanies | `logistics_shipping_companies`, `_contracts`, `_mappings` | 🟢 | 🟢 | 🟢 | 🟢 | 🔴 none (no col) | none | none | ✅ carrier accounts | no | no company scope | P0 | T-02 |
| S-07 | Carrier Accounts | `/logistics/carriers` | `CarrierAccountsPage` | `.../carriers/*` (8) | Logistics\Carriers | carrier account tables | 🟡 | 🟢 | 🔴 403 | 🟢 | 🟢 auth | none | 🔴 2 unconsumed | ✅ shipping companies | no | `carrier.view/manage` unseeded | **P0** | T-03 |
| S-08 | Drivers | `/logistics/drivers` | `DriversPage` | `.../drivers/*` (14) | Logistics\Drivers | `logistics_drivers` (1 row) | 🟢 | 🟢 | 🟢 | 🟢 | 🔴 **no `company_id` column** | none | none | no | no | schema-level; 10 bare lookups; doc download open | **P0** | T-02 |
| S-09 | Vehicles | `/logistics/vehicles` | `VehiclesPage` | `.../vehicles/*` (17) | Logistics\Vehicles | `logistics_vehicles` (1 row) | 🟢 | 🟢 | 🟢 | 🟢 | 🔴 col exists, unused | none | 🔴 4 unconsumed | ✅ `fleet_units` | no | no scope; doc download open | **P0** | T-02 |
| S-10 | Fleet Dashboard | `/logistics/fleet` | `FleetDashboardPage` | `.../fleet/*` (43) | Logistics\Fleet | 15 `fleet_*` tables | 🟡 | 🟢 | 🔴 403 | 🟢 | 🟢 auth | none | 🔴 17 unconsumed | ✅ vehicles | no | `fleet.view/manage` unseeded | **P0** | T-03 |
| S-11 | Fuel Review | `/logistics/fleet/fuel-review` | `FuelReviewPage` | `.../fuel-transactions/*` | Logistics\Fleet | `fleet_fuel_*` | 🟡 | 🟢 | 🔴 403 | 🟢 | 🟢 auth | none | 🔴 unconsumed | no | no | `fleet.view` unseeded | P1 | T-03 |
| S-12 | Service Areas / Network | `/logistics/network` | `ServiceAreasPage` | `.../network/*` (18) | Logistics\Network | 8 `network_*` | 🟡 | 🟢 | 🔴 403 | 🟢 | 🟢 auth | none | 🔴 7 unconsumed | no | no | `network.view/manage` unseeded | **P0** | T-03 |
| S-13 | Dispatch Command Center | `/logistics/dispatch` | `DispatchCommandCenterPage` | `.../dispatch/*` (42) | Logistics\Dispatch | 14 `dispatch_*` | 🟡 | 🟢 | 🔴 403 | 🟡 never transitions a Trip | 🟢 auth | none | 🟡 2 of 8 consumed | ✅ 3 dispatch owners | no | `dispatch.*` unseeded; not wired to Trip | **P0** | T-03, T-05 |
| S-14 | Dispatch Board | `/logistics/dispatch/boards` | `DispatchBoardPage` | `.../dispatch/boards` | Logistics\Dispatch | `dispatch_boards` | 🟡 | 🟢 | 🔴 403 | 🟡 | 🟢 auth | none | 🟡 | ✅ | no | `dispatch.view` unseeded | P1 | T-03 |
| S-15 | Dispatch Execution | `/logistics/dispatch/execution` | `DispatchExecutionPage` | `.../dispatch/ops/*` (31) | Logistics\Dispatch | dispatch queue/session/alloc | 🟡 | 🟢 | 🔴 403 | 🟡 | 🟢 auth | none | 🟡 | ✅ | no | `dispatch.*` unseeded | **P0** | T-03 |
| S-16 | Operations Center | `/logistics/operations` | `OperationsCenterPage` | `.../operations/summary/*` | Logistics\Operations | — | 🟡 | 🟢 | 🔴 403 | 🟢 | 🟢 auth | none | 🟢 consumed | no | no | `operations.view` unseeded | **P0** | T-03 |
| S-17 | Operational Dashboards | `/logistics/operations/dashboards` | `OperationalDashboardsPage` | `.../dashboards/*` (5) | Logistics\Operations | — | 🟡 | 🟢 | 🔴 403 | 🟢 | 🟢 auth | none | 🟢 | no | no | `operations.view` unseeded | **P0** | T-03 |
| S-18 | Alert Center | `/logistics/operations/alerts` | `AlertCenterPage` | `.../exceptions/*` (17) | Logistics\Operations | — | 🟡 | 🟢 | 🔴 403 | 🟢 | 🟢 auth | none | 🟢 | no | no | `operations.view` unseeded | **P0** | T-03 |
| S-19 | Activity Center | `/logistics/operations/activity` | `ActivityCenterPage` | `.../activity/*` (6) | Logistics\Operations | — | 🟡 | 🟢 | 🔴 403 | 🟢 | 🟢 auth | none | 🟢 | no | no | `operations.view` unseeded | P1 | T-03 |
| S-20 | Enterprise Readiness | `/logistics/operations/readiness` | `EnterpriseReadinessPage` | `.../readiness/*` (6) | Logistics\Operations | — | 🟡 | 🟢 | 🔴 403 | 🟢 | 🟢 auth | none | 🟢 | no | no | `operations.view` unseeded | P1 | T-03 |
| S-21 | Logistics Enterprise | `/logistics/enterprise` | `EnterpriseWorkspacePage` | `.../intelligence/dashboard/*` (2) | Logistics\Intelligence | — | 🟡 | 🟢 | 🔴 403 | 🟢 | 🟢 auth | none | none | no | no | `operations.view` unseeded | P1 | T-03 |
| S-22 | Logistics Intelligence | `/logistics/intelligence` | `LogisticsIntelligencePage` | `.../intelligence/*` (17) | Logistics\Intelligence | read-only | 🟡 | 🟢 | 🔴 403 | 🟢 | 🟢 auth | none | none | no | no | `operations.view` unseeded | P1 | T-03 |
| S-23 | Automation Monitoring | `/logistics/automation` | `AutomationMonitoringPage` | `.../automation/*` (3) | Logistics\Automation | — | 🟡 | 🟢 | 🔴 403 | 🟢 | 🟢 auth | none | 🟢 8 consumed | no | no | `operations.view` unseeded | P1 | T-03 |
| S-24 | Delivery OS | `/logistics/delivery` | `DeliveryPage` | `.../delivery/*` (38) | Logistics\Delivery | `delivery_*` (7 tables, 0 rows) | 🟡 | 🟢 | 🔴 403 | 🟡 | 🔴 **detail unscoped** | 🔴 **none** | 🔴 13 unconsumed | ✅ Distribution delivery | no | `delivery.*` unseeded; P0 tenant on COD/POD/returns; no restock | **P0** | T-01, T-03, T-06 |
| S-25 | Routing (tab) | via `trip-drawer` | `TripRoutingTab` | `.../routing/*` (9) | Logistics\Routing | `route_plans`, `_stops` | 🟡 | 🟡 tab only | 🔴 403 | 🟡 | 🔴 none | none | 🔴 5 unconsumed | no | no | `routing.*` unseeded; host page not in nav | P2 | T-03, T-04 |
| S-26 | **Distribution Board** | `/operations/distribution/board` | `DistributionBoardPage` | **`/api/distribution/board` — 0 routes** | **NONE** | — | 🔴 | 🟢 built | 🔴 **absent** | 🔴 | ⚪ | ⚪ | ⚪ | ✅ S-04/S-05 | **yes** | **no backend; raw fetch, no auth; not in nav** | **P1** | T-04 |
| S-27 | **Loading Dashboard** | `/operations/loading/dashboard` | `LoadingDashboardPage` | **`/api/distribution/loading-trips` — 0 routes** | **NONE** | — | 🔴 | 🟢 built | 🔴 | 🔴 | ⚪ | ⚪ | ⚪ | ✅ Loading OS | **yes** | no backend | **P1** | T-04 |
| S-28 | **Loading Workspace** | `/operations/distribution/loading/:tripId/loading` | `LoadingWorkspacePage` | **`/api/distribution/manifests/*` — 0 routes** | **NONE** | — | 🔴 | 🟢 built | 🔴 | 🔴 | ⚪ | ⚪ | ⚪ | ✅ Loading OS | **yes** | no backend; malformed URL | **P1** | T-04 |
| S-29 | **Dispatch Gate** | `/operations/dispatch-gate` | `DispatchGatePage` | **`/api/distribution/dispatch-gate` — 0 routes** | **NONE** | — | 🔴 | 🟢 built | 🔴 | 🔴 | ⚪ | ⚪ | ⚪ | ✅ real gate in TripService | **yes** | no backend | **P1** | T-04 |
| S-30 | **Dispatch Gate Workspace** | `/operations/dispatch-gate/:tripId` | `DispatchGateWorkspacePage` | **`/api/distribution/trips/{id}/dispatch` — 0 routes** | **NONE** | — | 🔴 | 🟢 built | 🔴 | 🔴 | ⚪ | ⚪ | ⚪ | ✅ | **yes** | no backend | **P1** | T-04 |
| S-31 | **Driver Mobile (11 pages)** | `/driver/home`, `/driver/trips/*` | 11 pages + 13 components | **`/api/driver/*` — 0 routes** | **NONE** | — | 🔴 | 🟢 built | 🔴 **absent** | 🔴 | ⚪ | ⚪ | ⚪ | ✅ Distribution stops | **yes** | **no backend; own axios, no token; no offline; desktop shell; not in nav** | **P1** | T-07 |
| S-32 | **Loading & Allocation OS** | — (no route) | **NONE** | `api/loading/*` (24) | Operations\Loading | 15 tables, 0 rows | 🟡 | 🔴 **none** | 🟢 | 🔴 | 🟢 auth | 🟢 **FIFO+COGS via ShipOrderInventoryAction** | 🔴 11 unconsumed | ✅ S-26/27/28 | no | **no UI; 0 permission middleware on 24 routes** | **P0** | T-04, T-08 |
| S-33 | **Vehicle Inventory** | — | none | `.../assignments/{id}/inventory` (1 GET) | Operations\Loading\VehicleInventoryService | `vehicle_inventory_items`, `_movements` | 🔴 | 🔴 | 🟡 read only | 🔴 | 🟢 auth | 🔴 **parallel ledger; only grows** | none | no | no | `recordDelivery`/`recordReturn`/`unallocate` dead | **P0** | T-09 |
| S-34 | **Vehicle→WH Reconciliation** | — | none | **none** | **NOBODY** | `vehicle_shift_reconciliations` (+lines), 0 rows | 🔴 | 🔴 | 🔴 | 🔴 | ⚪ | 🔴 absent | none | no | scaffolding | **no controller, route, or writer** | **P0** | T-09 |
| S-35 | Settlement | via S-05 | trips workspace tabs | `.../trips/{id}/settlement/*` + payments (13) | Logistics\Distribution\SettlementService | `distribution_trip_settlements`, `_payment_collections` | 🟡 | 🟢 | 🟢 | 🟡 | 🔴 **none — cash** | none | 🔴 `TripSettled` unconsumed | no | no | P0 tenant; no finance posting; not in nav | **P0** | T-01, T-10 |
| S-36 | Delivery Returns | via S-05 + S-24 | trips workspace + delivery drawer | `.../trips/{id}/returns` + `delivery/{id}/returns/*` | 2 owners | `distribution_trip_returns`, `delivery_returns`, `_lines` | 🟡 | 🟢 | 🟡 | 🔴 | 🔴 none | 🔴 **no restock ever** | 🔴 `ReturnReceived` unconsumed | ✅ 2 impls | no | no restock; no disposition; duplicate path | **P0** | T-06 |
| S-37 | Shipping Quote / Prices | via Orders + Brand config | `orders-service`, `brand-shipping-service` | `api/shipping/quote`, `api/brands/{b}/shipping/*` | Commerce\Shipping + Organization\Brands | brand shipping settings | 🟢 | 🟢 | 🟢 | 🟢 | brand-scoped | none | none | no | no | none | — | — |
| S-38 | Fulfillments | `/fulfillments` (+2) | `FulfillmentsPage`, Create, View | `api/fulfillments` (7) | Commerce | fulfillment tables | 🟢 | 🟢 | 🟢 | 🟢 | ⚪ | ⚪ | ⚪ | no | no | none | — | — |
| S-39 | Navigation config | — | `config/navigation.ts` | — | — | — | 🔴 | 🔴 | ⚪ | ⚪ | ⚪ | ⚪ | ⚪ | ✅ `module-navigation.ts` | **yes — 0 importers** | dead file, misleading | P4 | T-13 |
| S-40 | Route-level authorization | all 40 routes | `ProtectedRoute` | — | — | — | 🟡 | 🟡 auth only | ⚪ | ⚪ | 🔴 | ⚪ | ⚪ | no | no | **no route or nav-item permission gating** | P1 | T-12 |

---

## 24. Open Gaps

**Critical (9)**

| # | Gap | Evidence |
|---|---|---|
| G-01 | Cross-tenant read/write/dispatch on trips, stops, settlements, payments, custody, returns | `TripController` §17.3; no global scope |
| G-02 | Cross-tenant COD collection, POD capture/validation, return receipt | `findByUuidOrFail()` unscoped |
| G-03 | Drivers cannot be tenant-isolated — no `company_id` column | schema §18 |
| G-04 | 177 routes permanently 403 in `ecos_dev` (17 permissions absent) | §17.6 |
| G-05 | 16 pages have no backend at all (43 + 20 endpoints absent) | §4.C, §4.D |
| G-06 | The only inventory-correct dispatch path has no UI; the only reachable dispatch path performs no inventory movement | §10 |
| G-07 | Vehicle→Warehouse reconciliation does not exist; vehicle ledger only grows (3 dead methods) | §11 |
| G-08 | Returns never restock, at any state | §16 |
| G-09 | 73 shipping events have no consumer — including `TripDispatched`, `DeliveryStopCompleted`, `TripSettled`, `CodCollected`, `ReturnReceived` | §19 |

**High (7)**

| # | Gap |
|---|---|
| G-10 | The only working trip/delivery/settlement UI (`/logistics/distribution/trips`) is not in navigation; 16 routes total are nav-orphaned |
| G-11 | `api/loading/*` — 24 routes with zero permission middleware, including inventory-consuming dispatch |
| G-12 | All 20 GET routes on `trips/*` unprotected — settlement, payments, financial-summary, custody readable by any authenticated user |
| G-13 | `Logistics\Dispatch` (42 routes) never transitions a `Trip` — the dispatch planning engine and the dispatch state machine are unconnected |
| G-14 | Order→trip assignment has no guard on order status, reservation state, or company |
| G-15 | Dispatch gate does not check loading completion or `inventory_shipped_at` |
| G-16 | Driver Mobile has no offline support, no token interceptor, no mobile shell, and no driver role/surface |

**Medium (6)**

| # | Gap |
|---|---|
| G-17 | Two vehicle representations (`logistics_vehicles` / `fleet_units`); two carrier representations |
| G-18 | Two delivery models and two return models over the same physical act, with no cross-check against duplicate processing |
| G-19 | Two geography authorities (`logistics_governorates` / `master_governorates`) |
| G-20 | No route-level or nav-item-level permission gating anywhere in the frontend |
| G-21 | Trip has no warehouse dimension; one order cannot span trips; no split fulfilment in the reachable stack |
| G-22 | Soft delete on 1 of 96 tables — trips, deliveries, settlements are hard-deleted |

**Low (3)**

| # | Gap |
|---|---|
| G-23 | `config/navigation.ts` dead and misleading (0 importers) |
| G-24 | `api.php` Distribution Board OS banner over an empty group |
| G-25 | Malformed URL `/operations/distribution/loading/:tripId/loading` |

---

## 25. Prioritized Task Roadmap

Proposed only. **None implemented.**

### P0 — Security / Data Integrity

**T-01 · TASK-LOGISTICS-TENANT-ISOLATION-001**
- **Why:** Any authenticated user can read, modify and **dispatch** another company's trips, and finalize their settlements.
- **Gap:** G-01, G-02. `TripController` takes `company_id` from request input; `loadTrip`/`resolveTrip`/`findByUuidOrFail` are unscoped; no global scope on `Trip`/`Delivery`/`Vehicle`.
- **Files:** `Logistics/Distribution/Presentation/Http/Controllers/{Trip,Delivery,Settlement,DistributionZone,DistributionPlanning}Controller.php`; `Logistics/Delivery/Presentation/Http/Controllers/{DeliveryCod,DeliveryPod,DeliveryAttempt,DeliveryReturn}Controller.php`; `Logistics/Delivery/Infrastructure/Repositories/EloquentDeliveryRepository.php`; `Logistics/{Routing,Vehicles,ShippingCompanies}` controllers; `Domain/Models/{Trip,Delivery,Vehicle}.php`.
- **Dependencies:** none. Ship first.
- **Must NOT touch:** `TripStatus` transition table; `dispatchBlockers()`; `SettlementService` arithmetic; the correctly-scoped modules (Dispatch, Fleet, Network, Operations, Intelligence, Carriers, Loading, DistributionWindow) — verify only.
- **Expected outcome:** company derived from the authenticated actor on every shipping endpoint; request-supplied `company_id` rejected or ignored; cross-company access returns 404. Verified across ≥2 companies per the standing UAT rule.

**T-02 · TASK-LOGISTICS-DRIVER-VEHICLE-TENANCY-001**
- **Why:** `logistics_drivers` has no `company_id`, so drivers cannot be isolated at all; `logistics_vehicles.company_id` exists but is never used.
- **Gap:** G-03. Also: `GET drivers/{id}/documents/{docId}/download` and the vehicle equivalent serve files with no permission and no company check.
- **Files:** new migration for `logistics_drivers.company_id` (+ backfill decision); `Logistics/Drivers/DriverController.php` (10 bare lookups); `Logistics/Vehicles/{Vehicle,VehicleMaintenance}Controller.php`; `Logistics/ShippingCompanies/ShippingCompanyController.php` (9 bare lookups).
- **Dependencies:** T-01 (shared scoping helper). **Requires a business decision:** are drivers company-owned, shipping-company-owned, or shared?
- **Must NOT touch:** `fleet_units` (already scoped); geography tables (intentionally global).
- **Expected outcome:** drivers/vehicles/carriers tenant-isolated; document downloads permission-gated and company-checked.

**T-03 · TASK-LOGISTICS-PERMISSION-RESTORE-001**
- **Why:** 177 shipping routes are hard-403 in `ecos_dev`; 13 of 24 navigable pages cannot load.
- **Gap:** G-04. 17 permissions absent; **zero** two-part permissions exist in `ecos_dev` while `ecos_dev_test` has them; the seeding migrations are recorded as run, so `migrate` will not restore them.
- **Files:** investigate `Logistics/{Operations,Fleet,Network,Dispatch,Delivery}/Infrastructure/Database/Migrations/seed_*_permissions.php`; `Modules/IAM/Infrastructure/Database/Seeders/RbacSeeder.php`; `Modules/IAM/Domain/Catalog/RoleTemplateCatalog.php`; `2026_12_20_000000_seed_enterprise_permission_matrix.php`.
- **Dependencies:** none, but **must precede any UAT of Shipping** — otherwise every finding is masked by 403.
- **Must NOT touch:** `RequirePermissionMiddleware` fail-closed behaviour (it is correct); do not weaken to fail-open.
- **Expected outcome:** root cause of the two-part-name loss identified; an **idempotent, re-runnable** repair path (not a one-shot migration already marked as run); **every environment audited independently**, staging and production included; role templates granting the restored permissions.

**T-08 · TASK-LOADING-OS-AUTHORIZATION-001**
- **Why:** 24 `api/loading/*` routes have no permission middleware, including the only inventory-consuming dispatch endpoint.
- **Gap:** G-11.
- **Files:** `backend/routes/api.php:912-950`; `Operations/Loading/Policies/{LoadingSession,VehicleAssignment,AllocationRecord}Policy.php` (policies exist and are unused by the routes).
- **Dependencies:** T-03 (permission names must exist first).
- **Must NOT touch:** `DispatchVehicleAction` gate logic; `LoadVehicleWorkflow`.
- **Expected outcome:** every loading route permission-gated; existing policies wired.

**T-09 · TASK-VEHICLE-INVENTORY-RECONCILIATION-001**
- **Why:** loaded stock that is neither delivered nor returned is unaccounted for. The vehicle ledger only grows.
- **Gap:** G-07. `recordDelivery()`, `recordReturn()`, `unallocate()` have zero callers; `vehicle_shift_reconciliations` has no controller, route, or writer.
- **Files:** `Operations/Loading/Domain/Services/VehicleInventoryService.php`; `Domain/Models/VehicleShiftReconciliation{,Line}.php`; `Presentation/Http/Requests/ReconciliationLineRequest.php`; new controller + routes; `Operations/Fulfillment/Application/Workflows/`.
- **Dependencies:** T-04 (needs a decided UI owner), T-05 (needs the delivery→vehicle link).
- **Must NOT touch:** `ShipOrderInventoryAction`; the canonical stock ledger; the inventory Architecture Freeze — this task defines the *vehicle* side and its handoff, and must be reviewed against ADR-027 before any canonical write.
- **Expected outcome:** an explicit Vehicle→Warehouse reconciliation closing each shift, with the vehicle ledger reconciled and a decided (ADR-backed) canonical-stock effect.

**T-06 · TASK-DELIVERY-RETURN-RESTOCK-001**
- **Why:** returned goods never re-enter stock. Inventory understates on-hand permanently after any return.
- **Gap:** G-08, G-18.
- **Files:** `Logistics/Delivery/Domain/Services/DeliveryReturnService.php`; `Logistics/Distribution/Domain/Services/DeliveryService.php` (`recordReturn`/`confirmReturn`); return line models (needs a sellable/damaged disposition); a listener for `ReturnReceived`.
- **Dependencies:** T-01, T-05. **Requires a business decision:** restock on receipt or on verification; who owns damaged disposition.
- **Must NOT touch:** Supplier Returns (Procurement, certified 2026-08-15) — delivery returns only. Do not change the derived-discrepancy rule; it is correct.
- **Expected outcome:** one canonical delivery-return path with a decided restock point and disposition, and a guard against duplicate processing across the two implementations.

**T-10 · TASK-SHIPPING-FINANCE-BRIDGE-001**
- **Why:** `TripSettled` and `CodCollected` have no consumers, so cash collected at the door and finalized settlements never reach Finance.
- **Gap:** G-09 (finance subset).
- **Files:** `Logistics/Distribution/Domain/Events/TripSettled.php`; `Logistics/Delivery/Domain/Events/CodCollected.php`; Finance integration rules (F3 event→rule→journal).
- **Dependencies:** T-01 (settlement must be tenant-safe before it posts), T-03.
- **Must NOT touch:** the Finance PostingCoordinator contract (post only via it); `auto_subscribe` defaults; Distribution's status as sole cash authority.
- **Expected outcome:** settlement finalization and COD collection post through the approved Finance pipeline.

### P1 — Broken Core Workflow

**T-04 · TASK-DISTRIBUTION-LOADING-CONVERGENCE-001** *(architecture-first — ADR before code)*
- **Why:** 16 pages call two nonexistent API namespaces while a complete, inventory-correct Loading OS sits behind no UI, and the one working trip UI is hidden from the menu. This is the central defect of the domain.
- **Gap:** G-05, G-06, G-10.
- **Decision required (user):** for each of Distribution Board, Loading, Dispatch Gate — (a) build `/api/distribution/*` as a facade over `Operations\Loading` + `Logistics\Distribution`; (b) rewrite the 5 pages against the existing `api/loading/*` and `api/logistics/distribution/*`; or (c) retire the pages and surface `TripsWorkspacePage` + `DistributionWorkspacePage` in navigation. **(b) or (c) reuse working backends; (a) builds a third distribution stack.**
- **Files:** `frontend/src/features/operations/distribution-board/**`; `frontend/src/config/module-navigation.ts`; `backend/routes/api.php:952-957`; `Operations/Loading/**` (read-only reference).
- **Dependencies:** none — but this decision blocks T-05, T-07, T-09.
- **Must NOT touch:** `Operations\Loading` domain logic; `LoadVehicleWorkflow`; `ShipOrderInventoryAction`; the `TripStatus` transition table.
- **Expected outcome:** one canonical Distribution/Loading/Dispatch-Gate path, reachable from navigation, backed by a real API.

**T-05 · TASK-SHIPPING-STATE-MACHINE-UNIFICATION-001** *(architecture-first)*
- **Why:** three dispatch owners, two loading owners, two delivery owners. Trip dispatch in the reachable stack ships no stock and changes no order status; `Logistics\Dispatch` never transitions a Trip; completing a delivery does nothing downstream.
- **Gap:** G-06, G-09, G-13, G-14, G-15.
- **Files:** `Logistics/Distribution/Domain/Services/{TripService,DeliveryService}.php`; `Logistics/Distribution/Domain/Models/Trip.php` (`dispatchBlockers`); `Operations/Loading/Application/Actions/DispatchVehicleAction.php`; `Operations/Fulfillment/Application/Workflows/LoadVehicleWorkflow.php`; `Logistics/Dispatch/**`; listeners for `TripDispatched` / `DeliveryStopCompleted`.
- **Dependencies:** T-04.
- **Must NOT touch:** `ShipOrderInventoryAction` internals; ADR-027 reservation ownership (Orders reserve FG only; Manufacturing owns RM); the inventory Architecture Freeze; `OrderStatusGuard` authorization model.
- **Expected outcome:** one dispatch authority that both enforces the gate **and** consumes inventory; delivery completion propagating to order status; the dispatch gate additionally checking loading completion and `inventory_shipped_at`; order→trip assignment guarded on order state and company.

**T-07 · TASK-DRIVER-MOBILE-BACKEND-001**
- **Why:** 11 driver pages and 13 components exist with zero backend. Field execution is impossible.
- **Gap:** G-05, G-16.
- **Files:** `frontend/src/features/operations/driver-mobile/services/driver-mobile-service.ts` (must use `@/lib/axios`); new `Modules/Logistics/DriverApp/**` or a mapping onto `Logistics\Distribution` stops + `Logistics\Delivery` attempts; `router.ts` (driver shell outside `AppShell`); `use-navigation.ts` (driver role).
- **Dependencies:** T-04, T-05 (the stop/delivery model must be settled first).
- **Must NOT touch:** desktop `AppShell` for non-driver routes; the Delivery OS state machine.
- **Expected outcome:** driver routes backed by real endpoints, authenticated through the shared client, on a mobile shell, with a defined offline strategy and a driver role.

### P2 — Missing Business Capability

**T-11 · TASK-SHIPPING-GEOGRAPHY-CONSOLIDATION-001**
- **Why:** two geography authorities (`logistics_governorates` vs `master_governorates`); this pair is the known source of the Arabic-governorate matching failure that leaves orders without a warehouse.
- **Files:** `Logistics/Geography/**`; `Admin/Configuration/.../MasterGeographyController.php`; `logistics_city_aliases`.
- **Dependencies:** the open TASK-ORDER-AWAITING-STOCK diagnostic decisions.
- **Must NOT touch:** the alias-derivation rule; certified warehouse brand-coverage logic.
- **Expected outcome:** one geography SSOT for shipping with a documented alias strategy.

**T-14 · TASK-SHIPPING-MULTI-WAREHOUSE-ASSESSMENT-001** *(assessment first, no implementation)*
- **Why:** the reachable trip model has no warehouse dimension and one order cannot span trips; the Loading OS supports one warehouse per session and split-by-vehicle only.
- **Gap:** G-21.
- **Files:** assessment across `distribution_trips` schema, `TripService::assignOrder`, `LoadVehicleWorkflow`, `loading_sessions`.
- **Dependencies:** T-04, T-05.
- **Must NOT touch:** anything — assessment only. **Do not implement split fulfilment.**
- **Expected outcome:** a written decision on whether split fulfilment is required, and what it would cost.

### P3 — UI/UX

**T-12 · TASK-SHIPPING-UI-AUTHORIZATION-001**
- **Why:** navigation gating is module-level only. One `logistics.*` permission reveals all 23 Shipping items, and all 40 routes are URL-reachable by any authenticated user.
- **Gap:** G-20, S-40.
- **Files:** `frontend/src/config/module-navigation.ts` (per-item permission); `frontend/src/router/router.ts` + a new permission guard; `features/authorization/use-navigation.ts`.
- **Dependencies:** T-03 (permissions must exist).
- **Must NOT touch:** the typed-i18n-key nav contract — **no `label` field may be introduced**; the `/auth/me` single-context pattern.
- **Expected outcome:** per-item nav gating and per-route permission guards, consistent with the IAM-005 adaptive-frontend model.

### P4 — Technical Debt

**T-13 · TASK-SHIPPING-DEAD-CODE-CLEANUP-001**
- **Why:** `config/navigation.ts` (0 importers) describes a navigation that no longer exists and will mislead the next engineer; the `api.php` Distribution Board banner labels an empty group; the loading-workspace URL duplicates a segment.
- **Gap:** G-23, G-24, G-25.
- **Files:** `frontend/src/config/navigation.ts`; `backend/routes/api.php:952-957`; `frontend/src/router/routes.ts` (`loadingWorkspace`).
- **Dependencies:** **T-04 must decide the fate of stacks C/D first** — do not remove anything those pages depend on before the decision.
- **Must NOT touch:** `Operations\Loading` (functional, and the only inventory-correct dispatch path — explicitly **not** dead code); the 3 dead `VehicleInventoryService` methods (T-09 will use them).
- **Expected outcome:** dead navigation file removed, banner corrected or the group implemented, URL normalized.

---

## 26. Evidence / File References

**Frontend routing & navigation**
- `frontend/src/router/router.ts` — 522 lines; all 40 shipping routes eager-imported; §2
- `frontend/src/router/routes.ts` — 247 lines; ROUTES constants
- `frontend/src/router/guards/protected-route.tsx:11-23` — auth-only guard, no permission
- `frontend/src/config/module-navigation.ts:197-246` — Shipping module sidebar; `:191-196` Operations; `:337-341` empty logistics module; `:461-467` HIDDEN_MODULE_IDS; `:485` "replaces the removed navigation.ts"
- `frontend/src/config/navigation.ts` — 180 lines, 0 importers (dead)
- `frontend/src/features/authorization/use-navigation.ts:12-20,27-52,64-83` — module-level gating

**Frontend services**
- `frontend/src/lib/axios.ts:12-33` — Bearer interceptor (the correct client)
- `frontend/src/features/operations/distribution-board/services/distribution-board-service.ts:29` — `BASE='/api/distribution'`; `:31-44` raw fetch, no auth; 43 endpoints
- `frontend/src/features/operations/driver-mobile/services/driver-mobile-service.ts:13` — own axios, no interceptor; 20 endpoints
- `frontend/src/features/logistics/trips/services/{trip-service,trip-execution-service,trip-settlement-service}.ts` — 43 endpoints, all resolving
- `frontend/src/features/operations/services/preparation-service.ts:54` — 4 unresolved (adjacent)

**Backend routes**
- `backend/routes/api.php` — 3,942 lines, the only API route file
- `:912-950` — `api/loading/*`, 24 routes, **zero permission middleware**
- `:952-957` — **empty Distribution Board OS group**
- `:1593+` — Logistics OS blocks
- `:377` — `api/shipping/quote`

**Backend — Loading OS (the inventory-correct path)**
- `Operations/Loading/Application/Actions/LoadProductAction.php:74-83`
- `Operations/Loading/Application/Actions/DispatchVehicleAction.php:27-42,59`
- `Operations/Fulfillment/Application/Workflows/LoadVehicleWorkflow.php:21-22,56-62,74-100,115-125`
- `Operations/Loading/Domain/Services/VehicleInventoryService.php:21-68` (live), `:73-98` (live), `:103-128` `:133-167` `:172-201` (**dead**)
- `Operations/Loading/Domain/Models/VehicleShiftReconciliation{,Line}.php` — no writer

**Backend — Distribution (the reachable path)**
- `Logistics/Distribution/Domain/Enums/TripStatus.php:19-116` — 13 states + transition table
- `Logistics/Distribution/Domain/Services/TripService.php:38-39` (status stripped), `:48-91` (`changeStatus` + gate), `:101-138` (`assignOrder`, unguarded)
- `Logistics/Distribution/Domain/Models/Trip.php:94-101` (booted = UUID only), `:190-224` (`dispatchBlockers`)
- `Logistics/Distribution/Presentation/Http/Controllers/TripController.php:41-42`, `:72-97`, `:131-153`, `:155-165`, `:325-346`, `:354`
- `Logistics/Distribution/Domain/Services/DeliveryService.php:74-106` (`completeStop`), `:150+` (`recordReturn`)

**Backend — Delivery OS**
- `Logistics/Delivery/Domain/Enums/DeliveryStatus.php:15-26` — 12 states
- `Logistics/Delivery/Domain/Services/DeliveryReturnService.php:102-136` (derived discrepancy, no restock), `:138-156`
- `Logistics/Delivery/Presentation/Http/Controllers/DeliveryCodController.php:18-27` (cash-authority contract), `:144-152` (unscoped lookup)
- `Logistics/Delivery/Infrastructure/Repositories/EloquentDeliveryRepository.php:22-25` — unscoped `findByUuidOrFail`

**Events & authorization**
- `Logistics/Automation/Infrastructure/Providers/LogisticsAutomationServiceProvider.php:47-56,64-70` — the only 8 registrations
- `Operations/Fulfillment/Infrastructure/Providers/FulfillmentServiceProvider.php:67` — `OrderDispatchedEvent` → `HandleOrderDispatched`
- `Modules/IAM/Infrastructure/Middleware/RequirePermissionMiddleware.php:50-63` — fail-closed
- `Modules/IAM/Domain/ValueObjects/PermissionName.php:13-14,57-62` — two-part names explicitly legal
- `Logistics/Operations/Infrastructure/Database/Migrations/2026_08_05_100003_seed_phase4_permissions.php:22` — seeds `operations.view`
- `Modules/IAM/Infrastructure/Database/Migrations/2026_12_20_000000_seed_enterprise_permission_matrix.php:26` — asserts it already exists

**Verification artefacts (scratchpad, not committed)**
- `routes.json` — 1,856 routes from `artisan route:list --json`
- `scoped-routes.txt` — 416 in-scope routes with middleware + permission
- `contract.txt` — 374 resolved / 63 unresolved endpoint diff
- `perms_db.txt` — 578 seeded permissions
- md5 parity: `routes/api.php` container == repo (`f54acb750530c8dcc3bde38d1ce07bc4`)

---

## 27. Final Recommendation

**Status: AUDIT COMPLETE — IMPLEMENTATION UNCHANGED.** The repository contains exactly one new file: this report. No code, route, migration, schema, fixture, test, or data was modified. Nothing was committed, staged, or deployed.

**Do not certify Shipping.** Certification was out of scope, and on the evidence it would fail on the P0 tenant breach alone.

**The domain's core problem is not missing features — it is that four stacks were built in parallel and never joined.** There is far more working backend than the UI can reach, and far more UI than the backend can serve. The Delivery OS (38 routes), Dispatch (42), Fleet (43), Operations (75) and the Loading & Allocation OS (24) are substantial, coherent subsystems. The failure is integration and reachability, not absence of engineering.

**Recommended sequence:**

1. **T-03 first — restore the 17 permissions.** While 177 routes return 403, no UAT of Shipping can produce a trustworthy result; every other defect hides behind the 403 wall. Note this is environment drift: `ecos_dev_test` is correct, `ecos_dev` is not, and the seeding migrations are already marked run — so verify **every** environment independently, staging and production included, and use an idempotent repair rather than a new one-shot migration.
2. **T-01 and T-02 immediately after — close the tenant breach.** Cross-company dispatch and settlement finalization are the most severe findings in this audit and are independent of the architectural decisions below.
3. **Then T-04 — decide the Distribution/Loading/Dispatch-Gate convergence.** This is the pivotal decision and it is the user's to make. Options (b) rewrite the 5 pages onto existing backends and (c) retire them and surface the already-working `TripsWorkspacePage`/`DistributionWorkspacePage` in navigation both reuse working code; option (a) builds a third distribution stack. Nothing in T-05, T-07 or T-09 can be scoped until this is settled. **Architecture-first: ADR before code.**
4. **Then T-05 — unify the dispatch/delivery state machine and connect it to inventory.** The inventory-correct path already exists in `LoadVehicleWorkflow`; the work is to route the reachable workflow through it, not to write new inventory logic.
5. **T-06, T-09, T-10 follow** once the state machine has one owner.

**Two quick, high-value wins available independently of all of the above:** surfacing `/logistics/distribution/trips` in navigation makes the only complete trip→delivery→settlement workflow reachable (blocked today only by a missing nav entry, and gated behind T-01 for safety); and deleting `frontend/src/config/navigation.ts` removes a file with zero importers that actively misdescribes the product's navigation.

**Decisions required from the user before implementation can be authorized:**

| # | Decision |
|---|---|
| D-1 | T-04: build `/api/distribution/*`, rewrite the 16 pages onto existing APIs, or retire them? |
| D-2 | Are drivers company-owned, shipping-company-owned, or shared? (blocks T-02) |
| D-3 | Should loading decrement warehouse stock, or remain neutral until dispatch? (blocks T-05, T-09) |
| D-4 | Do returns restock on warehouse receipt or on verification, and who owns damaged disposition? (blocks T-06) |
| D-5 | Is split fulfilment (one order, multiple warehouses/vehicles) a required capability? (blocks T-14) |
| D-6 | Which of the two delivery models and which of the two return models is canonical? (blocks T-05, T-06) |

**Counts:** 40 in-scope frontend routes (24 in nav, 16 orphaned) · 416 in-scope backend routes · 63 frontend→backend mismatches · 177 routes behind the 403 wall · 96 database tables (all transactional ones empty) · 81 domain events, 8 consumed · **9 critical gaps** · **14 proposed follow-up tasks** · **6 decisions required**.

**STOP.** No recommended task has been implemented. No repair commit was created. Nothing was deployed. Shipping is not certified. The next step is user review of this audit, followed by authorization of specific modification tasks.
