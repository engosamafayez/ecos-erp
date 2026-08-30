# TASK-DRIVER-APP-UX-FOUNDATION-001

## Executive Summary

**No code was changed, and that is the correct outcome for this phase.**

The brief instructs me to inspect before changing. I did, and three of the four scope items
were **already implemented**; the fourth is **blocked on a backend read contract that does not
exist**, which the brief explicitly says to document rather than invent.

The inspection did surface one genuine problem, and it is a security issue rather than a UX
one: **the live DEV `driver` role carries two dispatcher permissions that the canonical
permission catalogue already revoked.** The code is correct and test-guarded; the DEV database
has drifted from it.

| Scope | Finding |
|---|---|
| 1. Driver-only order visibility | **ALREADY SATISFIED** — frontend and backend both |
| 2. Driver navigation | **ALREADY SATISFIED** — driver nav is driver-scoped |
| 3. Driver Home redesign | **ALREADY SATISFIED** — operational dashboard exists with every required field |
| 4. Financial area | **BLOCKED** — no cross-trip read contract exists (§4) |
| — | **NEW FINDING: live DEV role drift** (§5) |

Files changed: **NONE** · Backend: **NONE** · Live data: **UNCHANGED** · Commit/Deploy: **NONE**

Date: 2026-08-26 · Branch: `develop`

---

## Before State

The Driver App ships **16 pages** and a driver-specific navigation context
(`module-navigation.ts` id `driver`, plus `mobile-bottom-nav.tsx`), with 17 routes registered
under `/driver/*` in `router.ts`.

---

## Scope 1 — Driver-only order visibility: **ALREADY SATISFIED**

Verified on both sides rather than assumed.

**Frontend.** `driver-orders-page.tsx` uses `useDriverTrips()` → picks the current trip →
`useDriverStops(currentTrip.id)`. It renders `DeliveryStopCard`s for **that trip's stops
only**. It never touches the enterprise orders list.

**The driver app never calls a non-driver endpoint.** Every request in
`driver-mobile-service.ts` targets `/api/driver/*` (plus `/api/auth/me`):

```
/api/driver/loading            /driver/stops/{id}/deliver      /driver/trips/{id}/returns
/api/driver/trips              /driver/stops/{id}/delivery-proof /driver/trips/{id}/collections
/driver/loading/products/{id}  /driver/stops/{id}/action        /driver/trips/{id}/settlement
…                              …                                …
```

`grep "logistics/distribution" src/features/operations/driver-mobile/` → **no matches**.

**Backend.** The canonical `driver` role (`config/permissions.php:506`) grants exactly two
permissions — `logistics.shipping.view` and `loading.driver.operate`. It holds **no orders
permission at all**, so the enterprise Orders API refuses a driver token regardless of the UI.

**No change was needed, and none was made.** The enterprise Orders module was not touched.

---

## Scope 2 — Driver navigation: **ALREADY SATISFIED**

`mobile-bottom-nav.tsx` swaps the pinned destinations by context:

```
DEFAULT_PINNED  dashboard → ROUTES.dashboard ,  orders → ROUTES.orders        (enterprise)
DRIVER_PINNED   driver-home, driver-loading, driver-orders, driver-vehicle-inventory
```

The driver's `orders` entry points at **`ROUTES.driverOrders`** — the trip-scoped page above —
**not** `ROUTES.orders`. The global search is also hidden in the driver context. The driver
module (`module-navigation.ts:528`) lists the same four driver destinations and owns the whole
`/driver` subtree.

So the brief's conditional — *"Remove/hide the generic Orders navigation entry **if** it
exposes the global order list"* — does not fire: inside the driver experience it does not.

**One nuance worth recording (not a defect, not changed):** `isDriver` is computed from the
**pathname**, not from identity:

```js
const isDriver = pathname === ROUTES.driverHome || pathname.startsWith('/driver/') || pathname === '/driver';
```

A driver who navigates to `/dashboard` would therefore see the enterprise pinned items. That
is cosmetic only — the destinations behind them fail authorization, since the driver role
holds no orders/dashboard permission. Making the nav identity-aware is a UX improvement, not a
security fix, and it is outside this phase's scope.

---

## Scope 3 — Driver Home: **ALREADY SATISFIED (no redesign needed)**

The brief describes replacing a "weak/legacy Home". The current Home is **not** legacy — it is
already the operational dashboard being asked for, built from lifecycle state:

```
ON_THE_ROAD        = ['dispatched', 'out_for_delivery', 'in_progress']
COMPLETED_STATES   = ['completed', 'settlement_pending', 'closed']
BLOCKED_STATES     = ['dispatch_blocked', 'cancelled']
UNRESOLVED_LOADING = ['pending_loading', 'awaiting_driver_confirmation',
                      'awaiting_driver_reconfirmation']
```

Every field the brief lists is present, and each has a real backend source:

| Required | Where | Source |
|---|---|---|
| current trip | header — `currentTrip.trip_number` | `GET /driver/trips` |
| vehicle | header — `currentTrip.vehicle_plate` | same |
| trip status | block 1, `currentWork[workKey]` | derived from `trip.status` |
| total stops/orders | block 3, `ordersTotal` | `GET /driver/trips/{id}/stops` |
| delivered | block 3 `MiniStat` | stop rows, `status === 'delivered'` |
| remaining | block 3 `MiniStat` (`pending`) | stop rows, pending/in_progress |
| failed / exception | block 3 `MiniStat` (`failed`) | stop rows, `status === 'failed'` |
| loading status | block 1 detail — `awaitingConfirmation` / `productsCount` | `GET /driver/loading` |
| primary next action | block 2 — single `h-14` CTA | derived `actionKey` / `actionRoute` |

The layout is already mobile-first in the way the brief asks: one dominant "current work"
block, **one** large primary action, then scannable stat cards.

It also satisfies the subtle requirement from the previous closure: when the trip is complete
the derived state returns `actionKey: null`, so **no button renders at all** rather than a
disabled one.

**No metric on this screen lacks a backend source.** Nothing was invented, and nothing was
changed.

---

## Scope 4 — Financial area: **BLOCKED — missing read contract**

The brief authorises adding the Wallet destination *"only if the required read data already
exists"*. It does not.

**What exists** — all of it **trip-scoped**:

```
GET  /api/driver/trips/{tripId}/settlement        per-trip settlement
GET  /api/driver/trips/{tripId}/collections       per-trip collections
POST /api/driver/trips/{tripId}/settlement/submit driver submits their count
```

`DriverSettlementPage` is routed at `ROUTES.driverTripSettlement` and reachable from the trip
dashboard. So *per-trip* financial visibility already works.

**What does not exist:** any **driver-level, cross-trip** financial read. Enumerated from the
live route table — no `/api/driver/wallet`, `/api/driver/financial*`, `/api/driver/earnings`
or `/api/driver/settlements`. Every financial read is keyed by `{tripId}`.

A "Driver Wallet / Financial Report" is by definition cross-trip (balance carried, collections
across trips, outstanding vs settled). Building the shell now would mean either inventing
values or aggregating client-side across trips — a second financial computation inside the
Driver App, which the brief and the prior closure both forbid.

### The exact missing read contract

```
GET /api/driver/wallet
    auth: auth:sanctum + permission:loading.driver.operate
    scope: the authenticated driver, fail-closed (logistics_drivers.user_id)

    {
      "outstanding_cash":        number,   // collected, not yet handed over
      "settled_to_date":         number,   // approved settlements
      "pending_settlements":     number,   // submitted, awaiting operator approval
      "open_trips":              number,
      "last_settlement_at":      string|null,
      "recent": [ { trip_id, trip_number, date, collected, settled, status } ]
    }
```

Every field must come from the existing canonical settlement/collection tables — this is a
**read projection**, not a new authority, and it must not recompute money the Settlement
service already owns.

**Nothing was added to the navigation for this**, because a destination pointing at a page
that cannot be truthfully populated is worse than its absence.

---

## Scope 5 — NEW FINDING: live DEV role drift (security)

This is the one real problem the inspection found, and it is not a UX issue.

**Canonical** (`config/permissions.php:506`) — the driver role grants **2** permissions:

```php
'driver' => [
    'logistics.shipping' => ['view'],
    'loading.driver'     => ['operate'],
],
```

immediately preceded by a comment recording exactly why more were removed:

> *"REMOVED `logistics.distribution` view+update. That is the DISPATCHER surface
> (`/api/logistics/distribution/*`), and granting it to drivers is what allowed a driver to
> reach the cash ledger: record a payment and verify it, on any company's trip.
> TASK-SHIPPING-DRIVER-CLOSURE-001 §158-161 recorded this risk and recommended exactly this
> revocation."*

**Live DEV database** — the `driver` role holds **4**:

```
logistics.shipping.view
loading.driver.operate
logistics.distribution.view      ← revoked in the catalogue
logistics.distribution.update    ← revoked in the catalogue
```

**Exposure.** `logistics.distribution.update` guards **43 routes**, including
`PATCH /trips/{id}/status`, `/trips/{id}/driver-acceptance`, `/trips/{id}/assignment`,
`POST /trips/{id}/orders`, `orders/move`, `/trips/{id}/custody`, `PUT /zones/{id}`, and
`PATCH /trips/{tripId}/returns/{returnId}/confirm`.

That last one matters most: it would let a driver **confirm their own return as the
warehouse**, collapsing the ADR-015 §6.4 driver/warehouse separation certified in
TASK-DRIVER-APP-FINAL-CLOSURE-002. The UI never offers it, but a driver *token* can call it
directly — the UI is not the boundary.

**The code is correct and already guarded.**
`DriverRbacTenancySecurityTest::test_a1_the_driver_role_holds_the_runtime_permission_and_no_dispatcher_authority`
builds its role from the real catalogue and **passes**. The drift is in DEV data only, which is
why no test caught it.

**Not fixed here, deliberately.** The remedy is re-running the canonical RbacSeeder (documented
as idempotent) to bring the live role back to the catalogue. That is a **live permission
mutation** — it removes access from 2 live users — and this brief says not to modify live data.
**Requires your authorization.** I did not run it.

---

## Exact Gaps Found

1. **Driver Wallet / cross-trip financial read contract — MISSING.** Specified in §4.
2. **Live DEV `driver` role drift — 2 excess dispatcher permissions.** §5. Needs the seeder.
3. *(cosmetic, not acted on)* `isDriver` is pathname-derived, so a driver outside `/driver/*`
   sees enterprise pinned nav items whose destinations then fail authorization.

---

## Files Changed

**NONE.** Three of four scope items were already implemented; the fourth is blocked on a
backend contract; the security finding is live data, not code.

---

## Verification

Because no code changed, this is a confirmation of the current state:

| Check | Result |
|---|---|
| Driver frontend tests | **29 / 29 green** (3 files) |
| TypeScript (`tsc -p tsconfig.app.json`) | **23 errors = documented baseline**, 0 in driver-mobile |
| ESLint (driver-mobile changed files) | **exit 0** |
| Backend driver regression (9 suites) | **126 tests, 892 assertions, 0 failures** |
| No global order list exposed | **PROVEN** — driver app calls only `/api/driver/*`; canonical driver role holds no orders permission |
| Certified delivery / loading intact | **PROVEN** — `DriverStopDeliveryTest`, `LoadingCustodyWorkflowTest`, `DriverLoadingCustodyHandoffTest`, `TripDepartureLifecycleTest` all green |

No backend was touched, so no new backend test was required. No existing test was weakened.

---

## Preserved (Scope 5 of the brief)

Driver authentication · trip ownership (`ownedTrip`/`ownedStop`/`ownedTask` fail-closed) ·
custody architecture · delivery lifecycle · the canonical delivery writer
(`RecordProductDeliveryAction`) · secure POD · loading custody transfer — **all untouched.**

No duplicate authority, service, lifecycle writer, inventory system, delivery writer or
payment authority was created.

---

## Blocked / Requires a Separate Task

1. **Driver Wallet** — needs `GET /api/driver/wallet` (contract in §4) before any UI. Backend
   task.
2. **Live DEV role drift** — needs your authorization to run the RbacSeeder against DEV. It
   removes live permissions from 2 users, so I did not do it unprompted.
3. *(optional)* Identity-aware `isDriver` in the bottom nav — cosmetic.

---

## Status

**PHASE 1 COMPLETE — no implementation required; two items escalated.**

Scopes 1–3 were verified as already satisfied against the real code and the live permission
catalogue rather than assumed. Scope 4 is correctly blocked and its missing contract is
specified. The material outcome of this task is the **live DEV role drift**, which is a
security exposure that no test could catch because the code is already right.

---

**STOP.** Task 1 only. No other Driver App task started.
