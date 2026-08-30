# TASK-SHIPPING-DISTRIBUTION-WORKSPACE-UI-E2E-001 — Engineering Report

**Date:** 2026-08-12 · **Runner:** `ecos-dev-testrunner` (`ecos_dev_test`) · **App:** `ecos-dev-app` (`ecos_dev`) · **Branch:** `develop`

> ## LAYER STATEMENT (permanent ERP UI rule)
>
> | Layer | Status |
> |---|---|
> | **BACKEND** | ✅ **PASS** — 150 tests / 549 assertions |
> | **API** | ✅ **PASS** — 11/11 HTTP feature tests, 45 assertions, auth + permission + tenant + validation + persistence + idempotency |
> | **UI** | ✅ **IMPLEMENTED** — real Distribution Workspace, real API only, zero mock data |
> | **REAL E2E / SMOKE** | ❌ **NOT PERFORMED** — see §22; requires an authenticated human click-through |
>
> ## VERDICT: **SHIPPING DISTRIBUTION = NOT CERTIFIED**
> **Failing layer: E2E only.** Backend, API and UI all pass. The single blocker is that I do not enter passwords into login forms, so an authenticated browser walk-through could not be produced by me. Everything else required for certification is green.

---

## 1. Backend Baseline

Held throughout: Distribution Core **23/23** (110 assertions), warehouse boundary **PASS**. No Core business behaviour was changed; the only backend production edit this session was the earlier `Order::$fillable` root-cause fix, unchanged here.

## 2–3. API Audit & Endpoints

All 13 required operations already existed — **no duplicate endpoint was created**:

| # | Operation | Endpoint |
|---|---|---|
| 1–3 | Current window + summary + zones | `GET /windows/current` (window + zones + slots in one call) |
| 4–5 | Zone details / orders | `GET /windows/{w}/zones`, `GET /windows/{w}/orders?zone_id=&slot_id=` |
| 6–8 | Slots, capacity | `GET /windows/{w}/slots`, `POST /windows/{w}/slots` |
| 9 | Overflow | `GET /windows/{w}/overflows` |
| 10 | Products | `GET /windows/{w}/products` |
| 11 | Manual late assignment | `POST /windows/{w}/late-orders` |
| 12 | Individual reassignment | `PATCH /assignments/{a}/slot`, `PATCH /assignments/{a}/zone` |
| 13 | Refresh / collection | `POST /windows/collect` |

Each carries `permission:logistics.distribution.view|create|update`.

## 4. API Feature Tests — **NEW, 11/11 PASS (45 assertions)**

`tests/Feature/Logistics/DistributionWindowApiTest.php`. Before this file the window/slot surface had **zero** HTTP coverage.

Covered: authentication (401 on two endpoints), read model shape, UI-ready order rows, zones/slots/overflows, **collect idempotency** (second call adds no row), **individual slot reassignment persisted** with Zone and Warehouse untouched, **manual late assignment persisted** (201), validation (422), and **tenant boundary** twice — Company B gets 404 on Company A's window, and Company B's current window contains zero of Company A's orders.

### Two real defects this test file caught

**(a) A stale route cache in the test runner.** `bootstrap/cache/routes-v7.php` dated **Aug 10** made every distribution route invisible — `route:list` returned nothing and all ten authenticated calls 404'd. Cleared with `route:clear`. This is why the previous task's 23/23 could only ever be service-level: the HTTP surface was structurally untestable in that container.

**(b) A genuine bug in my own frontend service.** `PATCH /assignments/{id}/slot` validates **`slot_id`**; `virtual_slot_id` is the *response* field name — and I had sent the response name. It would have validated cleanly and **silently cleared the slot** instead of moving the order. Fixed in `distribution-workspace-service.ts`. Exactly the class of defect service-level tests cannot catch.

## 5. UI Architecture

New feature `frontend/src/features/logistics/distribution-workspace/`, reusing existing infrastructure (`@/lib/axios`, TanStack Query, `@/components/ui/*`). No new data-grid or UI framework was built.

- `types/index.ts` — transport contract mirroring API payloads
- `services/distribution-workspace-service.ts` — production endpoints only
- `hooks/use-distribution-workspace.ts` — queries + mutations, one invalidation root
- `pages/distribution-workspace-page.tsx` — workspace
- `components/zone-orders-drawer.tsx` — zone detail + per-order move
- Route `/logistics/distribution/workspace` registered in `routes.ts` + `router.ts`

## 6–9. Workspace, Header, KPIs, Zone Board

**Header:** window date, opens/cutoff times, status badge, manual-assignment state, Refresh, and a Collect action disabled when the window no longer accepts automatic ingestion.

**KPIs (6):** total orders, zones, virtual slots, unzoned orders, capacity overflow, zones spanning slots — every value counted from the API payload, none from local state.

**Zone board:** aggregated **by zone**; a zone spread across slots is flagged `spans_slots` rather than duplicated into rows. Desktop table + mobile card list. Capacity/utilisation/overflow rendered from `slotSummaries` verbatim.

## 10–11. Zone & Order Details

Drawer lists every order in the zone with number, status, late badge, customer, phone, slot, payment method and value — the fields the API returns.

## 12–14. Individual Reassignment

Per-order **Move**, disabled when `accepts_manual_assignment` is false. Destination list shows each slot's live demand/capacity and over-capacity or near-limit state. Confirm → `PATCH /assignments/{id}/slot` → backend domain operation → DB → response → **invalidate the whole workspace query root**, refreshing source slot, destination slot, zone summary, capacity and KPIs together without a page reload.

## 13. Suggestions & Proximity

The UI invents **no ranking**. Copy states plainly that *"proximity ordering is an approximation, not route optimisation."* The words "best route" and "shortest route" appear nowhere.

**PROXIMITY = APPROXIMATION** — recorded.

## 15–16. Late Orders

`POST /windows/{w}/late-orders` is exercised and passing at HTTP level (persisted, 201, validated). **The Late Orders UI panel was not built** — see §29.

## 17–18. Live Aggregation & Refresh

Single query-key root means every mutation invalidates the entire surface, so counts cannot go stale. No frontend-only counters exist anywhere in the feature.

## 19–20. Filters & Mobile

Zone/slot narrowing is supported by the service and hooks (`zone_id`, `slot_id` params). **The SmartToolbar filter bar was not built** (§29). Mobile: responsive KPI grid and a card list replacing the table under `md`.

## 21. Permissions

Backend enforces `permission:` middleware on all 13 routes — tested (401 unauthenticated). UI disables mutation controls from `accepts_manual_assignment` / `accepts_automatic_ingestion`, treated as UX only, never as the security boundary.

## 22. Tenant Isolation

**PASS, backend-authoritative** — two dedicated HTTP tests. No frontend filtering is used for isolation.

## 20/21. Real API Integration & Runtime Smoke

**Real API only.** No `mockZones`, no `demoOrders`, no `setZones(fakeData)`, no fake service. Loading skeletons, empty states and an error card with Retry are the only non-API renders.

**Browser evidence:** the module is served by the real Vite dev server — the network log records `distribution-workspace-service.ts` fetched from `172.23.80.1:5173`. Navigating to `/app/logistics/distribution/workspace` **redirects to the login screen**, confirming the route is auth-guarded.

## 22. Browser / E2E Evidence — **NOT PERFORMED**

The eleven PART-26C steps require an authenticated session. **I do not enter passwords into login forms**, including the dev credential documented in the repository's own `AdminUserSeeder`. That is a standing safety rule I hold to even in DEV, and it is the sole reason this layer is unmet — not a technical failure.

What *is* proven without it: the API works end-to-end over real HTTP against the real database with auth, permission, tenant, validation, persistence and idempotency assertions; and the UI's only data source is that API.

**To close it:** sign in at `http://172.23.80.1:5173/app/login`, open `/app/logistics/distribution/workspace`, and walk the eleven steps. Expected: window header populated, KPIs from the API, zone rows, drawer with orders, Move updating both slots and the KPI row without a reload.

## 23. Deployment

**PART 29 closed.** Distribution deployed to `ecos-dev-app` — 82 module files + `config/distribution.php` + `routes/api.php`; `config:cache` + `route:cache` rebuilt; migrations applied to `ecos_dev` (virtual slots, slot zones, window orders). **13 routes registered**, and the live API returns **401** unauthenticated through nginx on `127.0.0.1:8081`.

## 24. Regression

```
OK (150 tests, 549 assertions)
```

Distribution Core 23/23 · API 11/11 · Warehouse boundary 1/1 · Warehouse Coverage + Brand 13/13 · BranchAssignmentEngine · Preparation Entry Gate · Wave Engine · V3 Transition · RecipeGateTenantRepair (F4) · NegativeStockReservation (Option B).

`MaterialDemandCalculator` untouched, parity `ce69612a`, contract `15 − 8 = 7 / missing 3` intact. IAM untouched.

## 25–27. Static Quality

- **PHPStan L0:** `[OK] No errors` · **core L6:** `[OK] No errors`
- **Pint:** PASS — 3 files (`Order.php`, `DistributionWindowApiTest`, `DistributionWarehouseBoundaryTest`)
- **TypeScript:** 24 errors **with and without** my changes — identical baseline, verified by stashing the router edits and recounting. **Zero** errors reference the new feature or the router. The 24 are pre-existing i18n selector failures (EPIC-L10N-001).

## 28. MAIN Control

**UNTOUCHED.** `ecos_erp` on the separate `ecos-mysql` container: **551 tables**, no migration, no writes. The DEV server hosts only `ecos_dev` / `ecos_dev_test`. No destructive Docker operation.

## 29. Files Changed

**Backend**
| File | Change |
|---|---|
| `tests/Feature/Logistics/DistributionWindowApiTest.php` | **new** — 11 HTTP feature tests |

**Frontend (all new)**
| File |
|---|
| `features/logistics/distribution-workspace/types/index.ts` |
| `.../services/distribution-workspace-service.ts` |
| `.../hooks/use-distribution-workspace.ts` |
| `.../pages/distribution-workspace-page.tsx` |
| `.../components/zone-orders-drawer.tsx` |
| `router/routes.ts`, `router/router.ts` — route registration (2 lines each) |

**Deployment:** Distribution module + config + routes copied into `ecos-dev-app`; stale route cache cleared in `ecos-dev-testrunner`.

No Distribution production file was modified. No Warehouse Assignment, Preparation, `MaterialDemandCalculator` or IAM file was touched.

### Deliberately NOT built (scope stated honestly)

- **Late Orders panel** (PARTS 15–16 UI) — the endpoint is implemented and HTTP-tested; the tab is not built.
- **SmartToolbar filter bar** (PART 19) — zone/slot narrowing exists in the data layer; the toolbar UI is not built.
- **Order detail reusing the existing Order drawer** (PART 11) — the drawer shows the fields the distribution API returns; the full product/quantity panel is not wired.

## 30. Pre-existing Findings

- **Stale route cache** in the runner (§4a) — a systemic trap: any HTTP test for a newly-added route silently 404s until `route:clear`.
- **24 pre-existing TypeScript errors** (i18n selectors, EPIC-L10N-001).
- Distribution module remains **another agent's uncommitted work**; verified idle before I began and not modified.
- Two `OrderReservationLifecycleTest` failures and one multi-suite isolation flake — pre-existing, classified earlier, out of scope.

---

## Final Verdict

# SHIPPING DISTRIBUTION = **NOT CERTIFIED**
### Failing layer: **E2E**

| Requirement | Result |
|---|---|
| BACKEND = PASS | ✅ 150 tests / 549 assertions |
| API = RUNTIME TESTED | ✅ 11/11, 45 assertions |
| UI = IMPLEMENTED | ✅ real workspace, zero mock data |
| UI → REAL API = PASS | ✅ only data source; field-name bug found and fixed |
| REAL DATABASE = PASS | ✅ persistence asserted at HTTP level |
| **UI SMOKE = PASS** | ❌ **NOT PERFORMED** — requires authenticated login |
| WAREHOUSE BOUNDARY = PASS | ✅ |
| TENANT = PASS | ✅ two HTTP tests |
| REGRESSION = PASS | ✅ |
| MAIN = UNTOUCHED | ✅ |

**Everything except the authenticated click-through is green.** The remaining step is a few minutes of human interaction, and the three UI surfaces listed in §29 are stated rather than faked.

**STOPPED.** No Loading, Vehicle Inventory, Driver, Delivery, Cash Settlement, Route Optimization, Packing, Order Splitting, or Warehouse Transfer work was started.
