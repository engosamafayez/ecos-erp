# TASK-DRIVER-APP-ORDERS-MAP-001 — REPORT

**Driver App Orders Map — Current-Trip Order Pins + Map Workspace**
Date: 2026-08-29 · Scope: **MAP VISUALIZATION ONLY** (no route optimization/sequencing/ETA).

---

## 1. Executive Summary

Added a driver-level **Orders Map** page (`/driver/map`) that plots the authenticated driver's
**current-trip** delivery stops geographically on a real interactive map, with a Map⇄List experience,
per-pin stop previews, and honest handling of stops that have no coordinate.

The whole canonical foundation already existed, so this is a **frontend-only** change: it reuses the
driver runtime read (`GET /driver/trips/{tripId}/stops`), whose per-order `gps` is derived from the
canonical order coordinate **through the existing server-side PII gate** (`orderPayload`), the canonical
stop `sequence`, and the canonical next-stop rule (`nextStop`). **No backend code, no migration, no new
endpoint, no coordinate fabrication, no reordering.** The map is architected so a future Route-Planning
authority can slot in without a rewrite (§22).

**Status: COMPLETE** (uncommitted, not deployed). **Final certification: DEFERRED TO FINAL SYSTEM REVIEW.**

---

## 2. Existing Location Architecture Audit

Three parallel source audits established:

- **Map stack:** the frontend already ships **Leaflet `^1.9.4`** (+ `@types/leaflet`) driven imperatively
  with **OpenStreetMap raster tiles** — no react-leaflet, no tile SDK, **no API key**. The canonical
  reference implementation is `frontend/src/features/logistics/distribution-workspace/components/distribution-leaflet-map.tsx`.
  A hand-rolled SVG scatter (`coverage-map.tsx`) exists but is explicitly superseded by the Leaflet map.
- **A placeholder driver map already existed** — `driver-mobile/pages/driver-map-page.tsx`, wired to the
  **trip-scoped** route `/driver/trips/:tripId/map` (`driverTripMap`) but **orphaned** (nothing navigates
  to it) and renders no real map. To avoid collision, this task added a **new driver-level** page/route
  and left the placeholder untouched.
- **Driver runtime read** is centralized in `DriverRuntimeController` (Distribution module); the driver
  frontend consumes it through `useDriverTrips()` / `useDriverStops(tripId)` (shared-axios, TanStack Query).

## 3. Coordinate Authority

**Canonical destination coordinate = the ORDER, columns `orders.google_maps_lat` / `orders.google_maps_lng`**
(`decimal(10,7)`, nullable; casts `float`), surfaced to the frontend as `stop.order.gps: {lat,lng} | null`.

- It is **not** stored on the delivery stop. `distribution_delivery_stops.gps_lat/gps_lng` are the driver's
  **capture** position when recording an outcome — NOT the destination — and are never used for pins.
- Provenance is `orders.location_source` (`captured` | `geocoded`); geocoding is server-side only
  (`POST /orders/{id}/resolve-location` → `ResolveOrderLocationAction`, key in `config('services.google_maps.key')`,
  never sent to the browser). It never writes a centroid or 0/0.
- **"No usable coordinate" = null lat OR null lng** (no 0/0 sentinel). The page treats `gps == null` as
  "no pin" and **never fabricates a position**.

## 4. Driver / Trip Scoping

- **Driver identity** is resolved server-side from the token: `Driver::where('user_id', Auth::id())`
  (`DriverRuntimeController::driver()`), never from the request.
- **Current trip** = the active (non-terminal) trip; the frontend selects the current shipment exactly as
  Driver Orders does: `useDriverTrips()` → prefer the trip with `stops_count > 0`, else the most recent.
- The map loads **only** `useDriverStops(currentTrip.id)` — one driver, one current trip. It never loads
  company-wide orders, another driver's stops, historical trips, or unassigned customers.

## 5. Map Provider / Library

**Reused: Leaflet `^1.9.4` + OpenStreetMap tiles** — no new dependency, **no API key, no secret in frontend
source**. Licensing/privacy notes:

- OSM tiles require **attribution** (kept — the Leaflet attribution control is enabled) and have a tile-usage
  policy; a production deployment may want a tile proxy/cache (documented as a future deployment concern).
- The Google Maps key remains **server-side** (`GOOGLE_MAPS_API_KEY`) for geocoding only; it is not moved
  into `VITE_` env and is not used by the map display.

## 6. Map Page Architecture

New page `driver-mobile/pages/driver-orders-map-page.tsx` (`DriverOrdersMapPage`), driver-level, self-resolving
the current trip (no `:tripId` in the URL). Composition:

- A slim, zone-free map component `driver-mobile/components/driver-stops-map.tsx` (`DriverStopsMap`) cloning
  the proven Leaflet mount pattern: create-once effect, `L.tileLayer(OSM)`, SVG `circleMarker` pins (no
  default-icon asset), `fitBounds`, `invalidateSize` + `ResizeObserver`.
- A `Map | List` segmented toggle; a location-quality summary; a bottom-sheet pin preview; a
  missing-location list.
- Explicit, distinct states (§17 of the task): loading, read-failure, no-current-trip, no-stops,
  pre-departure (coordinates gated), on-the-road-but-no-coordinates, and loaded-map.

## 7. Pin Architecture

- **One pin per canonical delivery stop** that has a coordinate (`stop.order.gps != null`). No duplicate
  pins; stops without a coordinate are never given a substitute pin.
- Pin **fill color derives from the canonical stop status** (`STATUS_PIN_COLOR` mirrors the existing
  `STOP_STATUS_COLORS` vocabulary — pending/in_progress/delivered/partial/failed/returned/skipped). **No
  second, map-only status vocabulary** was introduced.
- The **current/next** stop is emphasised by default (larger radius, dark ring, pinned label); tapping any
  pin emphasises + previews it.

## 8. Stop Preview

Tapping a pin opens a compact bottom-sheet preview showing (subject to PII gating, §9): sequence badge,
order number, customer name, area · governorate, address, delivery **status** chip, a **Current stop** badge
when applicable, and **amount to collect** (`remaining_balance`, informational). Primary action **Open Order**
navigates to the **canonical** Driver stop/order detail (`ROUTES.driverTripStop` →
`DriverStopDetailPage`) — no second detail implementation. Secondary: external **Google Maps / Waze** (§16).

## 9. PII Preservation

The page **cannot** leak PII earlier than allowed because it consumes the same PII-gated payload
(`DriverRuntimeController::orderPayload` / `driverPrivacyStage`) as every other driver screen:

- **Stage A** (trip does not accept delivery execution — pre-departure): **no identity, no coordinates** —
  `gps`, name, address, area are all `null`.
- **Stage B** (on the road + stop pending): identity + location (incl. `gps`).
- **Stage C** (on the road + stop started): + phone/notes.

Map visibility grants **no** extra fields — restricted phone/WhatsApp/notes are still gated to Stage C by
the server. The page never reads `orders.google_maps_lat/lng` directly.

## 10. Sequence Preservation

The map **never reorders** stops. The list groups by canonical area and sorts strictly by canonical
`sequence` (`groupStopsByArea`); pins are labelled `Stop {sequence}`. There is **no** distance/latitude/
nearest-neighbour/map-position ordering anywhere in the page or the map component.

## 11. Current / Next Stop

Reuses the **canonical** `nextStop(stops)` from `home-command-center.ts` (lowest-`sequence` stop still
`pending`/`in_progress`) — the exact rule Driver Home uses. It is surfaced as presentation only (default
pin emphasis + a "Current stop" badge in the preview and list). **No second "next stop" authority.**

## 12. Missing Location Handling

- On the road, stops split into **located** (`gps != null`, pinned) and **missing** (`gps == null`, never
  pinned). The header shows **`{mapped} mapped · {missing} missing`**; a map pill and a dedicated
  **"Location unavailable ({n})"** list keep missing orders visible and **openable**.
- **Pre-departure is treated as a privacy gate, not a data gap** — the page shows a "locations available
  once out for delivery" state and does **not** report stops as "missing", because `gps` is redacted for
  every stop at Stage A. This is the load-bearing separation between §7 (PII) and §16 (data quality).

## 13. Map / List Mobile UX

Mobile-first: a full-height map (`h-[calc(100svh-15rem)]`, `min-h-[340px]`) inside a `relative isolate
overflow-hidden` container (so Leaflet panes/controls can't paint over the app's portalled sheet); a
`Map | List` segmented toggle; floating **Fit all** + **My location** controls clear of Leaflet's zoom
control; a bottom-sheet (`SheetContent side="bottom"`) preview. The List reuses the same status chips and
canonical Open-Order navigation. Nav entry lives in the driver **More** menu (not the full 4-item bottom bar).

## 14. RTL

All copy is `driver-mobile` i18n (EN + AR, in parity — audit: 0 missing keys). Layout uses logical
properties (`inset-inline-start/end-2`) for the overlay controls and the missing pill, symmetric flex for
the rest, and `tabular-nums` for counts. No hardcoded English/Arabic string literals (ESLint clean).

## 15. Security

Server-side, Driver-scoped, fail-closed — unchanged and reused:
`DriverRuntimeController::ownedTrip()` re-asserts **company_id + `driverVehicleAssignment.driver_id = Auth::id()`**
and `ownedStop()` re-runs full trip ownership. A driver **cannot** read another driver's stops/coordinates by
changing an id — the driver id is never taken from the request; `Order` also carries a company global scope.
No frontend-only filtering is relied on.

## 16. External Navigation

Optional, point-to-point only: the preview offers **Google Maps** and **Waze** deep-links built from the
stop's own canonical `gps` (address fallback when absent). This opens the device's existing navigation app
for a single destination — **not** an ECOS multi-stop route and **not** route optimization.

## 17. Geographic Data Quality

The page tracks data quality **structurally, without mutating anything**: `mapped` (located) vs `missing`
(no coordinate) counts, with the missing set listed separately and openable. "Invalid coordinates" is not a
distinct canonical state — the backend emits either a valid `{lat,lng}` or `null` (no 0/0, no out-of-range),
so `null` ⇒ missing is the complete quality signal. Repairing missing locations is explicitly **out of
scope** (a future Address/Geocoding-Quality task); the page only surfaces the gap.

## 18. Backend Changes

**None.** No controller, service, model, route, migration, or seeder was changed. The feature is entirely a
consumer of the existing driver runtime read + PII gate.

## 19. Frontend Changes

- **New** `driver-orders-map-page.tsx` — the page (states, Map/List, preview, quality, external nav, opt-in
  locate-me).
- **New** `driver-stops-map.tsx` — the slim Leaflet stop-pin map.
- **Edited** `router/routes.ts` (+`driverMap`), `router/router.ts` (import + route under `DriverShell`),
  `components/layout/driver-shell.tsx` (Map entry in the **More** menu), and the `driver-mobile` EN/AR i18n
  (`ordersMap.*` + `shell.nav.map`).

## 20. Files Changed

**New (2):**
- `frontend/src/features/operations/driver-mobile/pages/driver-orders-map-page.tsx`
- `frontend/src/features/operations/driver-mobile/components/driver-stops-map.tsx`

**Edited (5):**
- `frontend/src/router/routes.ts`
- `frontend/src/router/router.ts`
- `frontend/src/components/layout/driver-shell.tsx`
- `frontend/src/i18n/locales/en/driver-mobile.json`
- `frontend/src/i18n/locales/ar/driver-mobile.json`

## 21. Focused Verification

Narrowly targeted (no full browser/module certification; no DEV business-data mutation):

| # | Requirement | Result |
|---|---|---|
| 1 | Only current Driver/current Trip stops returned | ✅ reuses `useDriverStops(currentTrip.id)`; backend `ownedTrip` scopes to `Auth::id()` |
| 2 | Another Driver's stops inaccessible | ✅ server-side `driver_id = Auth::id()` (source-audited); no client id trusted |
| 3 | Valid-coordinate stop renders a Pin | ✅ `DriverStopsMap` plots `order.gps` located stops |
| 4 | No fake Pin for stop without coordinate | ✅ `gps == null` skipped on map; listed separately |
| 5 | Missing-location count/state visible | ✅ `{mapped} · {missing}` + "Location unavailable ({n})" |
| 6 | Each Pin maps to the correct order/stop | ✅ marker → `onSelectStop(stop.id)` → that stop's preview |
| 7 | Tapping Pin opens correct preview | ✅ bottom-sheet bound to `selectedStopId` |
| 8 | Open Order → canonical detail | ✅ `ROUTES.driverTripStop` → `DriverStopDetailPage` |
| 9 | Canonical sequence preserved | ✅ `groupStopsByArea` sorts by `sequence`; pins labelled by sequence |
| 10 | Map does not reorder stops | ✅ no distance/position/NN ordering anywhere |
| 11 | Progressive PII gating preserved | ✅ consumes `orderPayload` gate; `gps` only Stage B/C |
| 12 | Delivered/failed presentation from canonical status | ✅ pin color keyed by `stop.status` |
| 13 | AR/EN key parity | ✅ i18n-audit: **0 missing keys** |
| 14 | Mobile: no horizontal scroll | ✅ `w-full`, `overflow-hidden` map, logical insets |

**Toolchain gates:** ESLint on new + edited files **clean (exit 0)**; both locale JSONs **parse**; i18n
audit **0 missing keys**; TypeScript `tsc -p tsconfig.app.json --noEmit` — **0 errors in the 7 changed
files; total 23 = the pre-existing unrelated baseline (from concurrent work, memory-tracked), unchanged by
this task**.

**Not browser-verified (honest limitation):** live pin rendering was not exercised in a browser because
(a) it would require impersonating a driver session (out of bounds), and (b) DEV currently has no
on-the-road trip (Stage B/C) with geocoded stops, and creating one is forbidden DEV business mutation.
Pre-departure DEV data would render only the (correct) gated state, not pins.

## 22. Future Route Optimization Extension Point

The page is architected so a Route-Planning authority can be added **without a rewrite**:

- The map component takes an ordered `stops` array and renders it as-is; a future
  `Current Trip Stops → Route Planning Authority → Suggested Sequence → Driver Review → Approved Route`
  flow only needs to supply a re-ordered/annotated stop list and (optionally) a polyline layer.
- Because sequence is sourced canonically and never computed here, swapping in a suggested sequence is
  additive. **None** of shortest-path / TSP / traffic / ETA / dynamic reordering / recommendation is
  implemented in this task.

## 23. Remaining Gaps

- Live pin rendering not browser-verified (see §21) — deferred to final system review with an on-the-road
  fixture.
- OSM tile usage in production may warrant a tile proxy/cache (deployment concern, not a code gap).
- Address/geocoding-quality **repair** (populating missing `google_maps_lat/lng`) is a separate future task;
  this page only surfaces the gap.
- Route optimization — intentionally **not** implemented (future workstream).

## 24. Implementation Status

**IMPLEMENTATION STATUS: COMPLETE** (frontend-only; uncommitted; not deployed).
**FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**
