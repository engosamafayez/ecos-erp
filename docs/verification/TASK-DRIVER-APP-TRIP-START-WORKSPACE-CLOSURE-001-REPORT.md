# TASK-DRIVER-APP-TRIP-START-WORKSPACE-CLOSURE-001 — Engineering Report

**Driver App — Trip Detail + Start Trip Workspace Closure**

Date: 2026-08-29
Scope: DEV only. Frontend-only change. No commit / no push / no deploy outside DEV. No DEV business-data mutation. TRP-003 not started.

---

## 0. Executive summary

The Driver **Trip Detail** page (`DriverTripDashboardPage`, route `/driver/trips/:tripId`) rendered a header and an **empty body** for any assigned trip that was not `out_for_delivery` / `in_progress` / `completed` — i.e. for every pre-departure state, including `loading` (TRP-003). It also gated *Start Trip* on `out_for_delivery`, the inverse of the canonical departure seam. The page is now a full mobile-first workspace that renders an operational state for **every** trip status, derives loading/custody readiness from the canonical driver reads, and exposes *Start Trip* only at the canonical seam (`loading_completed`, nothing awaiting). Driver **Home** was aligned to the same lifecycle truth so its "Start Trip" promise and the Trip Detail workspace cannot disagree. No backend change: the existing Start Trip authority is reused as-is.

---

## 1. Root cause of the blank Trip page

`DriverTripDashboardPage` rendered action branches for exactly three statuses:

```
out_for_delivery → Start Trip button
in_progress      → View Stops / tools / Finish
completed        → Settlement / Returns / Custody
```

For **any other status** the body was an empty `<div>`. An assigned trip in `loading`, `loading_completed`, `driver_accepted`, `ready_for_dispatch`, `settlement_pending`, `closed`, `dispatch_blocked`, or `cancelled` showed only the header (trip reference + status) with **no readiness information and no action** — exactly the reported symptom for TRP-003 (`loading`).

Second, a lifecycle **drift**: *Start Trip* was gated on `trip.status === 'out_for_delivery'` — an already-on-the-road state. The canonical Start Trip authority departs from `loading_completed`, so the button appeared at the wrong time and never at the right one.

## 2. Trip Detail read trace

`route /driver/trips/:tripId` → `DriverTripDashboardPage` → `useDriverTrip(tripId)` → `GET /api/driver/trips/{uuid}` → `DriverRuntimeController::trip` → `ownedTrip()` (fail-closed) → `tripSummary()`.

`tripSummary` returns 12 scalars (id, trip_number, **status**, company_id, driver_id, vehicle_id, vehicle_plate, vehicle_name, stops_count, exceptions_count, trip_started_at, trip_finished_at). It carries **no loading/custody readiness** — so the old page had nothing to render for a loading trip, and the new page additionally reads the canonical loading manifest (`useDriverLoading` → `GET /api/driver/loading` → `DriverLoadingController::manifest`) for readiness detail.

**TRP-003 live payload (read-only):** trip `status=loading`, `stops_count=1`, `vehicle_plate=1336`; manifest `loading_complete=true`, `orders_count=1`, items = [loaded=1 `driver_confirmed`, loaded=0 `awaiting_driver_confirmation`] ⇒ loadedItems=1, pendingConfirmations=0.

## 3. Canonical lifecycle trace

`TripStatus` (backend enum) flow: `planning → loading → loading_completed → driver_accepted → ready_for_dispatch → dispatched → (in_progress / out_for_delivery) → completed → settlement_pending → closed` (+ `dispatch_blocked`, `cancelled`). The frontend consumes this single vocabulary through the shared `lib/trip-lifecycle.ts` (`ON_THE_ROAD`, `COMPLETED_STATES`, `BLOCKED_STATES`, `UNRESOLVED_LOADING`). **No second lifecycle was created.**

## 4. Start Trip authority

`useStartTrip` → `POST /api/driver/trips/{id}/start` → `DriverRuntimeController::startTrip`:
- `advanceToDispatched($trip)` acts **only** if `trip.status === LoadingCompleted`, re-checks `allLoadedProductsConfirmed` (custody), records driver acceptance, and walks `DriverAccepted → ReadyForDispatch → Dispatched` — then `→ InProgress`. All in ONE transaction; a dispatch blocker (no orders / inactive assignment / unfit driver/vehicle / unconfirmed custody) throws `422` and rolls back.

This authority is **reused unchanged**. The backend remains the sole judge of whether a trip may start.

## 5. Loading readiness

Derived from the canonical manifest, never fabricated:
- `loadedItems` = manifest items with `quantity_loaded > 0`
- `pendingConfirmations` = loaded items whose `workflow_state ∈ UNRESOLVED_LOADING` (Phase-2 per-item authority)
- `confirmedCount = loadedItems − pendingConfirmations`
- `loadingCompleteFlag = manifest.shipment.loading_complete`

The readiness card shows **Loading `{confirmed}/{loaded}`**, **Orders `{stops_count | orders_count}`**, **Vehicle `{plate}`**, each with a done/pending marker.

## 6. Custody readiness

Goods custody is surfaced through the same manifest confirmations (the driver's per-item custody acknowledgement is the loading confirmation, per `advanceToDispatched`'s own definition). No separate equipment-custody metric is invented where the driver read model does not expose one (§4 "do not fabricate unavailable metrics").

## 7. Start eligibility

```
readyToStart = trip.status === 'loading_completed' && pendingConfirmations === 0
```

This is the exact precondition of `advanceToDispatched` (departs only from `loading_completed`) plus the Phase-2 rule that a pending confirmation always takes precedence (§8). *Start Trip* is exposed **only** when `readyToStart`; otherwise the page shows the real blocker. This cannot present a button the backend would reject or silently no-op (§6). On rejection the `useStartTrip` hook surfaces the message and the page re-reads canonical truth — no faked success (§5); on success the trip + stops reads are invalidated (§7).

## 8. Blocker presentation

When not ready, the pre-departure branch shows a precise, canonical reason + one CTA (Go to Loading):

| Condition | Blocker |
|-----------|---------|
| `pendingConfirmations > 0` | "{n} loaded item(s) still awaiting your confirmation." |
| complete flag set, trip not advanced (**stranded**, e.g. TRP-003) | "Loading is complete, but this trip has not been released for dispatch yet." |
| items loaded, not complete | "Finish loading and confirm every item to start the trip." |
| nothing loaded | "Start loading this shipment to begin." |

For TRP-003 the page therefore renders the **awaiting-dispatch** blocker (proven against live data), never a blank body and never a Start Trip button the backend cannot honor.

## 9. Home integration

Driver Home's `deriveState` routed `actionKey:'startTrip'` to `/driver/trips/:id` whenever the **manifest** `loading_complete` flag was set. For a stranded trip (flag set, but `trip.status` still `loading` — exactly TRP-003) Home promised "Start Trip" and landed the driver on the (previously blank) Trip Detail, where the backend could not actually start it.

Home now triggers `startTrip` on **`trip.status === 'loading_completed'`** (the same canonical truth Trip Detail uses). A stranded trip falls through to the loading actions instead. Home ↔ Trip Detail are now consistent by construction: when Home offers Start Trip, the trip is genuinely at the seam and Trip Detail shows the Start Trip CTA; when it is not, both read it as loading. (The Phase-2 precedence branch — pending confirmations → Confirm Received — is unchanged and still fires first.)

## 10. Delivery gating preservation

`acceptsDeliveryExecution()` and the backend `assertTripOnTheRoad` guard (from TASK-DRIVER-APP-RUNTIME-GATING-…-001) are **untouched**. A `loading`/`ready` trip exposes no delivery-execution controls (Trip Detail shows readiness/Go-to-Loading; the stop-detail delivery controls remain gated on `acceptsDeliveryExecution`). Start Trip progression must occur (→ on the road) before delivery execution becomes available. On-road trips continue to route to stops where delivery execution is allowed by the existing predicate. Verified green by the existing stop-detail gating suite.

## 11. Mobile UX

Mobile-first hierarchy, no desktop tables: **Trip header** (number · vehicle · status badge) → **Readiness card** (Loading / Orders / Vehicle) → **Primary action** — either `[ Start Trip ]` (with the existing confirm dialog + GPS) or a **Trip-not-ready** blocker with the real reason and a single `[ Go to Loading ]`. On-road, completed, settlement-pending, closed, and blocked each render their own compact state. Loading / Error / Not-found / Loaded-but-not-ready / Loaded-and-ready are all distinct (§11) — a read error now shows Error + Retry instead of the previous silent fall-through.

## 12. Security

`ownedTrip()` (used by both the trip read and `startTrip`) resolves the driver from `Driver::user_id = Auth::id()` and scopes the trip by `company_id` **and** `driverVehicleAssignment.driver_id`, with `firstOrFail()` (404 otherwise). A driver can read/start only their own trip; tenant isolation and assignment guards are intact. The frontend change reads through the same guarded endpoint and broadens nothing. Unchanged.

## 13. Backend changes

**None.** The Start Trip authority, ownership guards, manifest, and lifecycle already existed and are correct; they are reused as-is. No new endpoint, no second lifecycle, no `Trip.status` write from the client.

## 14. Frontend changes

- **`driver-trip-dashboard-page.tsx`** — full workspace rework: read `useDriverLoading`, derive readiness, render an operational state for every status, error/retry state, and Start Trip gated on the canonical seam.
- **`driver-home-page.tsx`** — `deriveState` Start Trip trigger changed from the manifest `loading_complete` flag to `trip.status === 'loading_completed'` (§9 consistency); stranded shipments fall through to the loading actions.
- **i18n** (`en` + `ar` `driver-mobile.json`) — new `dashboard.*` keys (readiness / ready / blocker / closed / blocked / error / retry / goToLoading / settlementPendingHint) and the full `status.*` label set.

## 15. Files changed

| File | Type | Change |
|------|------|--------|
| `frontend/src/features/operations/driver-mobile/pages/driver-trip-dashboard-page.tsx` | FE src | Full Trip Detail workspace rework |
| `frontend/src/features/operations/driver-mobile/pages/driver-home-page.tsx` | FE src | Start Trip gated on `trip.status === 'loading_completed'` |
| `frontend/src/i18n/locales/en/driver-mobile.json` | FE i18n | `dashboard.*` + `status.*` keys |
| `frontend/src/i18n/locales/ar/driver-mobile.json` | FE i18n | Arabic mirror |
| `frontend/src/features/operations/driver-mobile/pages/driver-trip-dashboard-gating.test.tsx` | FE test | New (9 cases) |
| `frontend/src/features/operations/driver-mobile/pages/driver-home-page.test.tsx` | FE test | +1 stranded-trip case |

## 16. Focused verification

- **New Trip Detail test** `driver-trip-dashboard-gating.test.tsx` — **9/9** (real `trip-lifecycle` predicates):
  1. loading + pending confirmation → non-empty workspace, blocker, **no Start Trip**, Go to Loading.
  2. loading + all confirmed but stranded (flag set) → **awaiting-dispatch** blocker, no Start Trip.
  3. `loading_completed` + nothing awaiting → **Start Trip** + ready hint, no blocker.
  4. §8: `loading_completed` but an item awaits → **no Start Trip**, awaiting blocker.
  5. on-road `in_progress` → View Stops + Finish, no Start Trip.
  6. on-road `dispatched` → View Stops exposed (extends beyond `in_progress`).
  7. `closed` → read-only closed summary, no action.
  8. `dispatch_blocked` → blocked message, no Start Trip.
  9. read error → Error + Retry (never blank).
- **Home** `driver-home-page.test.tsx` — **+1** stranded-trip case (flag set, trip `loading` → NOT ready, no Start Trip); full suite green.
- **Suite:** driver-mobile **10 files / 85 tests pass** (no regressions in Home, command-center, stop-detail gating, reports, loading).
- **tsc** `-p tsconfig.app.json`: **0 new errors** in touched files (23 pre-existing errors are all unrelated features — ratchet held).
- **Live read-only** (§15.1/§15.2): TRP-003's actual trip + manifest payloads drive the page into the non-blank **awaiting-dispatch** workspace (`readyToStart=false`), matching test #2.
- **Security (§15.10):** `ownedTrip()` fail-closed guard confirmed (driver + company scoped) — unchanged.
- **Delivery gating (§15.8/§15.9):** `acceptsDeliveryExecution` + backend guard untouched; stop-detail gating suite green.

*Not run (per task): full Driver-App certification.*

## 17. DEV runtime status

Frontend-only task. The DEV SPA is served by **Vite :5173** (HMR, base `/app/`), which proxies `/api → :8081 → ecos-dev-app`. Vite confirmed serving the updated Trip Detail module (`readyToStart` + `isPreDeparture` present). Both `driver-mobile.json` locales validate as JSON. No backend deploy needed (no backend change). **CURRENT.**

## 18. Remaining gaps (out of scope, documented)

- **TRP-003 is a stranded data record**: its vehicle assignment is `loading_complete` and its items are confirmed, but the trip never advanced past `loading` (it predates the loading-completion → lifecycle bridge). The page now presents this honestly as "loading complete, awaiting dispatch"; **the underlying data is not repaired** (per §14 — no DEV business-data mutation, TRP-003 not started). Releasing/rebuilding such legacy trips is an operator/data-remediation task.
- Pre-existing Driver-App gaps (partial delivered-qty writer, driver-liability/advances authority) are unchanged and out of scope.
- 23 pre-existing `tsc` errors in unrelated features remain the baseline.

## 19. Implementation status

```
IMPLEMENTATION STATUS:  COMPLETE
DEV RUNTIME:            CURRENT
FINAL CERTIFICATION:    DEFERRED TO FINAL SYSTEM REVIEW
```

Constraints honoured: no redesign beyond the blank-page closure · no new business authority · no second lifecycle · no `Trip.status` write from React · backend Start Trip authority reused unchanged · delivery gating preserved · driver isolation preserved · no commit / push / deploy outside DEV · no DEV business-data mutation · TRP-003 not started.
