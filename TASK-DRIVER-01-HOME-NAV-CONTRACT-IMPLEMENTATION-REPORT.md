# TASK-DRIVER-01 — HOME + SHIPPING NAVIGATION + RUNTIME CONTRACT ALIGNMENT — REPORT

**Date:** 2026-08-25
**Mode:** IMPLEMENTATION — focused verification only.
**Final status:** **IMPLEMENTED / FOCUSED VERIFIED.** No STOP condition was reached. Not certified (no full certification run). Nothing committed, nothing deployed, no migration, no backend change.

> **Driver Experience remains under Shipping/Logistics, not Operations.**
>
> **Driver Home does not expose vehicle name, capacity, zones, or governorates.**

---

## 1. Scope

Delivered exactly three things: **Driver Home** (simplified to the approved UI), **Shipping navigation** for the Driver Runtime, and the **Driver Runtime contract alignment audit** (DriverTrip / Stop / phone-address-GPS). Not implemented (later tasks): Loading execution, Vehicle Stock, Orders, Delivery, Partial/Failed Delivery, Expenses, Wallet, Driver Closing, Monthly Settlement. No Distribution business logic, Group/Trip lifecycle, or Order lifecycle was touched, and no new Driver status was invented.

## 2. Driver Ownership

**Driver Experience remains under Shipping/Logistics, not Operations.** The Driver Runtime entry was added to the **`shipping`** module in `config/module-navigation.ts`. Nothing was added to the Operations module, and **تقفيل اليوم was NOT moved out of Operations** — the Operations items are unchanged (`preparation-workspace`, `logistics-distribution-plan`, `loading-drivers`, `driver-day-settlement`), asserted green by `module-navigation.test.ts` (35/35). The Operations default remains Preparation Workspace.

Note: the driver-runtime *source folder* is still `features/operations/driver-mobile/` (a pre-existing path). Only **navigation ownership** was in scope; moving files would be a large, collision-prone rename with no user-visible effect, so it was deliberately not done (see §21).

## 3. Navigation

Added to the **live** registry `config/module-navigation.ts` (not the dead `config/navigation.ts`, which was left untouched), at the end of the Shipping module, following the existing `{ key, path, icon }` convention — **no `label` property**:

```
{ key: 'driver-section', isSection: true },
{ key: 'driver-home', path: ROUTES.driverHome, subtree: '/driver', icon: Smartphone },
```

`subtree: '/driver'` makes every driver-runtime route (loading, stops, …) resolve to the Shipping shell. Only **Home** is listed — the rest of the driver workflow was **not fabricated** because those pages/contracts are not ready (§15). Rendered live as **Driver → Home** (EN) / **السائق → الرئيسية** (AR).

## 4. Authorization

**No new permission was created.** The existing `loading.driver.operate` continues to gate `/api/driver/*` (verified in `config/permissions.php`: the `driver` role holds `logistics.shipping => ['view']` and `loading.driver => ['operate']`, and nothing dispatcher-level).

**Navigation is permission-aware** through `features/authorization/use-navigation.ts` → `isModuleVisible()`. The `shipping` module's fallback gate is the `logistics` domain, and **the driver already satisfies it via its existing `logistics.shipping.view` grant** — so the Driver entry is visible to an authorized Driver **with no change to the authorization layer**. D-02 authorization contracts were not modified or weakened.

## 5. Driver Identity

Identity comes **only from the authenticated user context** (`useAuthStore().user.name`). The Home never reads a driver name from a Trip payload, and the backend `tripSummary()` deliberately does not carry one. No client-supplied `driver_id`/`driver_name`, no URL `driver_id`, and no Trip-supplied driver id is used for ownership — the `/api/driver/*` surface accepts **no driver identifier at all**; ownership is resolved server-side per request (`ownedTrip()`/`ownedStop()`), so there is nothing to spoof. A component test asserts the greeting renders the auth user's name.

## 6. Home UI

Deliberately minimal and action-oriented — three things only: **who the driver is**, **how many orders are assigned today**, and **the one action that starts work**:

```
مرحبًا، أحمد            ← greeting (auth identity) + role line
─────────────────────
   رحلتك اليوم
        12
      12 طلب
 [  ابدأ التحميل  ]
```

**Driver Home does not expose vehicle name, capacity, zones, or governorates** — nor Group/Trip capacity, dispatcher information, internal Distribution planning detail, money, expenses, commission, wallet, settlement, or inventory quantities. A component test asserts the rendered text contains none of `capacity`/`zone`/`governorate`/`vehicle_id`/`dispatch`. It is not a dashboard: no KPI grid, no analytics.

**Change of note:** the previous Home (TASK-DRIVER-WAVE-2-PHASE-1 Part A) carried a Loading-progress card and a Delivery-progress card (stops/delivered/remaining/failed). Both were **removed** here to satisfy §7/§18 ("intentionally simple", "avoid dashboards with unnecessary KPIs") and the explicit "Do NOT implement Delivery" scope rule. Delivery progress belongs to the upcoming Delivery/Orders tasks. The obsolete `home.cards.*` / `home.shipmentLabel` i18n keys were removed (EN+AR together, parity preserved). Flagging this explicitly because it reverses part of a previously approved slice — if the owner wants the Loading-progress line back on Home, it is a one-line re-add.

## 7. Home States

| State | Condition | Rendered |
|---|---|---|
| **A** | Trip assigned, loadable | `رحلتك اليوم` + count + **[ابدأ التحميل]** |
| **B** | No trip assigned | **`لم يتم تعيين رحلة لك بعد`** — no fake trip, no fake count, no fallback to company-wide orders, no CTA |
| **C** | Trip assigned but blocked | count + **`لا يمكن بدء التحميل حاليًا`**, CTA withheld |

State C is derived from the **existing canonical `Trip.status`** already present in the driver payload — `dispatch_blocked` and `cancelled` (from `TripStatus`). **No new Driver/Trip status was invented**, and no internal exception or class name is surfaced. Loading state shows a skeleton, never zeros.

## 8. Order Count

The count is the sum of the backend's own `stops_count` across **the driver's own trips** (`GET /api/driver/trips`, server-scoped to the authenticated driver). One delivery stop is one order (`distribution_delivery_stops` is unique on `(trip_id, order_id)`), so `stops_count` **is** the canonical assigned-order count — not a second aggregation, and not all-company / all-Distribution / all-Group / all-vehicle / all-Wave orders. Ownership is **never** computed on the frontend. `?? 0` guards on both sides mean the value is always a number — a test asserts **no `NaN`** when a trip omits `stops_count`.

## 9. DriverTrip Contract

**Already aligned (D-01) — verified, no change needed.** Backend `DriverRuntimeController::tripSummary()` returns exactly 10 keys: `id, trip_number, status, company_id, driver_id, vehicle_id, stops_count, exceptions_count, trip_started_at, trip_finished_at`. The frontend `DriverTrip` type declares exactly those 10. A repo-wide grep for consumers of `trip.capacity` / `trip.zones` / `trip.governorate` / `trip.driver_name` / `trip.vehicle_name` / `trip.total_*` returned **zero matches** — no phantom fields are declared or consumed, and the backend is not being asked to pad the payload.

## 10. Stop Contract

**Already aligned (D-01) — verified.** `stopSummary()` includes `order` (from `orderPayload()`) plus `delivery_type`, `collected_amount`, `attempted_at`, `notes`. **List and detail derive from the same representation** — `orderPayload($stop, withLines: false)` for the list and `withLines: true` for the detail — so there is exactly **one** Order representation, not two. `orderPayload()` provides `order_number, customer_name, phone, address, governorate, city, area, gps{lat,lng}, payment_method, grand_total, deposit_paid, remaining_balance, items_count, delivery_notes` and, with lines, `product_id, product_name, ordered_qty, unit_price, line_total, loaded_qty, delivered_qty, returned_qty, remaining_qty`.

## 11. Phone / Address / GPS

**Already aligned (D-01) — verified.** `delivery-stop-card.tsx` reads `stop.order?.phone`, `stop.order?.order_number`, `stop.order?.customer_name`, `stop.order?.address` — the original blank-stop-card defect (no `order` in the payload) is fixed at the source. `driver-stop-detail-page.tsx` reads `order.phone` (tel: link), `order.address/area/city/governorate` (joined), and `order.gps.lat/lng` (maps deep-link). There is **no** competing `billing_phone`/`shipping_address` representation on the driver surface, and **no invented GPS field** — `gps` is consumed exactly as the backend emits it.

## 12. Loading CTA

`[ابدأ التحميل]` routes to the **existing** `ROUTES.driverLoading` = `/driver/loading`, registered in `router.ts` to the existing `DriverLoadingPage` (Wave-1). No Loading logic was implemented and no placeholder page was created — the CTA links to a real, working route. **No routing gap; no STOP.**

## 13. i18n

All new visible strings exist in **both** locales with real Arabic translations, referenced via `t()` — no hardcoded UI labels. New keys: `driver-mobile` → `home.greeting` ("Hello, {{name}}" / "مرحبًا، {{name}}"), `home.tripToday` ("Your trip today" / "رحلتك اليوم"), `home.ordersCount` ("{{count}} orders" / "{{count}} طلب"), `home.blocked` ("Loading can't be started right now" / "لا يمكن بدء التحميل حاليًا"); `common` → `nav.items.driver-section` ("Driver" / "السائق"), `nav.items.driver-home` ("Home" / "الرئيسية"). Nav labels are typed against `common.json`, so a missing key is a compile error. **Parity: driver-mobile 122 = 122, common 378 = 378** (no EN-only/AR-only keys). Arabic was verified **rendered**, not just present (§18).

## 14. Responsive UI

Mobile-first, using existing ECOS components and tokens only (`Button`, `Skeleton`, DS spacing/typography) — no new visual system. Single-column layout with a sticky header, a full-width 48px-tall primary CTA, `truncate` on the identity line, and `min-h-screen` — works on mobile, tablet and desktop with mobile prioritized. RTL confirmed live (`dir="rtl"`).

## 15. Security Tests

**No backend file was changed in this task**, so the D-02 contracts are untouched; the canonical suite was run as regression evidence:

**`DriverRbacTenancySecurityTest` → `OK (21 tests, 42 assertions)`** (via `GATE_WAIT=2400 ./scripts/test-gate.sh`, real-role/unprivileged pattern — not permission-granting `actingAs`).

Coverage against the six required properties:

| # | Requirement | Evidence |
|---|---|---|
| 1 | Driver with `loading.driver.operate` → allowed | `test_a2_a_real_driver_role_reaches_the_driver_runtime` |
| 2 | User without permission → blocked | `test_a3…is_refused` (+ `a4`: permitted non-driver still refused) |
| 3 | Driver A cannot see Driver B's runtime | `test_b2_a_driver_cannot_reach_another_drivers_trip_in_the_same_company` |
| 4 | Driver cannot access another company's Trip | `test_b1_a_driver_cannot_reach_another_companys_trip` (+ `b3` unassigned trip) |
| 5 | Driver cannot supply another `driver_id` | **Structural**: the `/api/driver/*` surface accepts no driver identifier; identity is resolved from the token. `a1` asserts the role's grant; `b1`–`b3` close uuid-probing. |
| 6 | Navigation is permission-aware | `isModuleVisible()` module gate; driver passes via existing `logistics.shipping.view` (see §21 for the item-level limitation) |

## 16. Frontend Tests

New `driver-home-page.test.tsx` — **7/7 passing**: (1) identity renders from the **auth** user, not a Trip field; (2) assigned count from backend `stops_count`; (3) count sums across the driver's own trips; (4) **no `NaN`** when `stops_count` is absent; (5) State B shows `home.empty.title` with no count and no CTA; (6) State C shows `home.blocked` and withholds the CTA; (7) the rendered text exposes no vehicle/capacity/zone/governorate/dispatch detail. Stop-detail and stop-card behaviour is unchanged (no code touched) and type-checks clean.

## 17. Static Analysis

- **`tsc -p tsconfig.app.json`** (never bare `tsc`): **23 errors total = the existing baseline; 0 in any file this task touched** (driver-mobile, module-navigation, routes, router). Baseline is pre-existing and unrelated — **not claimed clean**.
- **ESLint**: exit **0** on all changed files (one Arabic-literal finding in the test fixture was fixed properly by using a Latin test name, not suppressed).
- **`module-navigation.test.ts`**: 35/35.
- **Pint / PHPStan / `php -l`**: **not applicable — no backend file was changed.** (The backend contract items in §9–§11 were already correct from D-01 and needed no edit.)

## 18. Browser Verification

Verified live at `localhost:5173` with the existing authenticated session — **no fabricated data, no live mutation**:

- **Scenario B (authorized user, no Trip) — VERIFIED.** `/app/driver/home` rendered `Hello, Administrator` + role line + **`No shipment assigned yet`**, with no order count, no CTA, and no vehicle/zone/capacity text. The session user has 0 assigned trips, so State B is the truthful state.
- **Navigation — VERIFIED.** While on `/driver/home`, the **Shipping** sidebar rendered (proving `subtree: '/driver'`), containing section **Driver** → link **Home** → `/app/driver/home`.
- **Arabic / RTL — VERIFIED.** With the locale switched to `ar`: `dir="rtl"`, Home rendered **`مرحبًا، …`**, **`سائق`**, **`لم يتم تعيين رحلة لك بعد`**, and the nav rendered **`السائق` → `الرئيسية`**. The locale (a browser UI preference, not business data) was **restored to `en`/`ltr`** exactly as found.
- **Scenario A (authorized Driver *with* an active Trip) — NOT VERIFIED:** would require assigning a driver to a trip. `NOT VERIFIED — DATA SAFETY CONSTRAINT` (fabrication forbidden). States A and C are covered by component tests instead.
- **Scenarios C/D (unauthorized user; Driver A sees only their own data) — NOT VERIFIED IN BROWSER:** would require authenticating as another user. `NOT VERIFIED — AUTHENTICATION CONSTRAINT` (entering credentials is not permitted). Both are covered by the automated security suite (21/21, §15).
- Screenshots were unavailable (the browser pane was not compositing); the DOM was read directly instead.

## 19. Data Safety

No live business data was created, modified or deleted. No driver assigned, no Trip created, no Order moved, no Loading started, no Order status/payment change, no inventory movement, no expense, no wallet, no settlement. No migration, no DB write, no backend change, no commit, no deploy. The only browser-side write was the UI locale preference, restored to its original value. Verification used component tests plus the isolated-fixture security suite.

## 20. Files Changed

| File | Change |
|---|---|
| `…/driver-mobile/pages/driver-home-page.tsx` | Rewritten to the approved minimal Home (States A/B/C); dropped the Wave-2 progress cards and the now-unused `useDriverLoading`/`useDriverStops` calls |
| `…/driver-mobile/pages/driver-home-page.test.tsx` | **new** — 7 component tests |
| `config/module-navigation.ts` | `+ driver-section` + `driver-home` under **Shipping**; `+ Smartphone` icon import |
| `i18n/locales/{en,ar}/common.json` | `+ nav.items.driver-section`, `+ nav.items.driver-home` |
| `i18n/locales/{en,ar}/driver-mobile.json` | `+ home.greeting/tripToday/ordersCount/blocked`; `− home.shipmentLabel`, `− home.cards.*` |

**Unchanged on purpose:** all backend files, `config/navigation.ts` (dead registry), `router.ts` / `routes.ts` (the `/driver/home` route already existed — no second Home route created), `use-navigation.ts` / `permissions.php` (no new permission, no widened gate), the Operations module, and every D-02 authorization contract.

## 21. Remaining Gaps

1. **Item-level nav permission gating does not exist** (pre-existing): `ModuleNavLink` has no `permission` field and `isModuleVisible()` gates per **module**. A driver therefore sees the other Shipping links too (Shipping Companies, Fleet, Dispatch…), whose APIs correctly refuse them. Adding per-item gating is an architecture change and was out of scope. **Owner decision if driver-only sidebars are wanted.**
2. **State C has no granular reason**: the driver runtime exposes `Trip.status` but no readiness/blocker detail (`dispatchReadiness` is dispatcher-gated, `logistics.distribution.*`). The UI shows a generic actionable message rather than the specific reason. Surfacing the reason to drivers would need a canonical read — not invented here.
3. **Driver source folder still under `features/operations/driver-mobile/`** — navigation ownership is Shipping; the physical path is legacy. A rename is cosmetic and collision-prone across concurrent tasks.
4. **Home no longer shows loading progress** (see §6) — deliberate per §7/§18; trivially reversible if the owner prefers the richer card.
5. `home.kpis.*` and `home.empty.subtitle` remain as unused pre-existing keys (not pruned — outside this task's change).

## 22. Next Task

**Driver Loading** — the next step in the approved workflow (`Driver Home → Loading`). The route and page already exist (Wave-1 Group-as-Shipment manifest); the next task should cover Loading execution UX, then Vehicle Stock, Orders, Started Delivery, Delivery, Failed Delivery, Expenses, Wallet, and Driver Closing in order. The nav entries for those must be added only as each page/contract becomes ready (they were deliberately not fabricated here).

---

## Final status

**IMPLEMENTED / FOCUSED VERIFIED** — Driver Home simplified to the approved UI with States A/B/C, the Driver Runtime entry added to **Shipping** navigation with EN/AR labels, and the DriverTrip / Stop / phone-address-GPS contracts audited and confirmed already aligned (no phantom fields, one Order representation). Verified: tsc 23-baseline/0-mine, ESLint 0, i18n parity 122+378, 7 component tests, nav 35/35, `DriverRbacTenancySecurityTest` **21/21**, and live browser render of State B + the Shipping nav entry in **both** English and Arabic/RTL. No STOP condition, no new permission, no new status, no migration, no backend change, nothing committed or deployed.
