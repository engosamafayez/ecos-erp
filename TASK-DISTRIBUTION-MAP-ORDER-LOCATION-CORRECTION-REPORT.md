# DISTRIBUTION MAP / ORDER LOCATION — CORRECTION — REPORT

**Server-side Google Geocoding of the complete delivery address for orders with no
stored coordinates, plus complete-address display and truthful location states in
Order Details. Approved owner rules followed.**

**STATUS: IMPLEMENTED / VERIFIED (mock + graceful-degradation live).** Real Google
geocoding is prepared and unit-verified with a mocked provider; it was NOT exercised
against live Google because no `GOOGLE_GEOCODING_API_KEY` is configured in this
environment (see §11).

## 1. Diagnosis (why the complete address / location wasn't reaching the UI)

- The location layer from the prior task (`distribution-workspace/lib/order-location.ts`)
  already had the correct 3-priority model (coordinates → address-geocoded → unresolved)
  but **`REGISTERED_GEOCODER = null`** — no provider — so any order without captured
  coordinates fell to `UNRESOLVED` and showed "No recorded location," even with a full
  address.
- The map Order panel (`map-order-panel.tsx`) rendered `detail.address`
  (= `orders.billing_address_1`, NULL on manual orders) instead of the complete
  `detail.shipping_address` the payload already carries.
- Drawer dimensions were already the standard fixed sizes (no full-screen surface).

## 2. Provider configuration

`config/services.php` → `services.google.geocoding_key`, read from
`env('GOOGLE_GEOCODING_API_KEY')`. **No key is hardcoded**; the key lives only in the
environment and is used **server-side only** (never sent to the browser). To enable in
any environment, set `GOOGLE_GEOCODING_API_KEY` in `.env`. Absent key ⇒ the API reports
`not_configured` and the UI shows an honest "Geocoding is not configured" state.

## 3. Exact geocoding flow

`OrderDetailDrawer` Location tab → (only when the order has **no** captured point AND a
resolvable complete address) `useResolveOrderLocation(orderId, enabled)` (a cached
React-Query) → `POST /api/orders/{order}/resolve-location`
(`OrderController::resolveLocation`, `permission:sales.orders.update`) →
`ResolveOrderLocationAction`:
1. captured `google_maps_lat/lng` present → **`available`** (no request);
2. else compose the COMPLETE address; if insufficient → **`address_unavailable`**;
3. else if no key → **`not_configured`**;
4. else `OrderGeocodingService::geocode()` (Google) → point → persist + **`resolved_from_address`**,
   or null → **`geocoding_failed`**.
The browser never sees the key; only the resolved `{status, lat, lng, source}` returns.

## 4. Address source (rule §5/§6)

The geocoded query is the customer's COMPLETE delivery address, composed narrow→wide
from the order's own fields: `shipping_address` (street) + `Bldg <building>` +
`Apt <apartment>`, then `area, city, governorate`, then `Egypt`. A **specific line
(street/building) AND a locality are both required** — a locality alone (city/
governorate/zone/area) is treated as `address_unavailable` and is **never** geocoded to
a centroid. Only the address string and the key are sent to Google — no name, phone,
order id, or other order data.

## 5. Coordinate persistence / use (rule §8)

Reuses the EXISTING location architecture — a successful geocode writes
`orders.google_maps_lat/lng` and stamps `location_source='geocoded'` (the same
provenance column `GoogleMapsUrlResolver` already uses). No new table, no duplicate
location system. Captured coordinates are never overwritten (Priority 1 returns first),
so an operator-recorded pin stays authoritative and `location_source` keeps geocoded
points distinguishable.

## 6. Failure handling (rule §4/§12)

Every non-success is a truthful state, never a substitute point: **available /
resolved_from_address / geocoding_failed / address_unavailable / not_configured**, plus a
transient "Resolving location from address…" while the request is in flight. A missing
key, a network error, a non-OK Google status, or zero results all degrade gracefully and
persist nothing.

## 7. Order Details fix (rule §3/§9/§11)

- The Location tab now always shows the **complete delivery address** (street, building,
  floor, apartment, area · city · governorate, landmark) — not just city/governorate.
- It shows the **map point + "Location available" + source** when coordinates exist (or
  are resolved), and otherwise the honest status above.
- The map Order panel (`map-order-panel.tsx`) now renders the complete shipping address
  via the existing `OrderAddressCell`, replacing the billing-only line; the redundant
  separate City/Governorate rows were removed.

## 8. Map layout fix (rule §2/§10)

No change was required — the Order Details drawer is the standard fixed
`sm:w-[48vw] max-w-[820px]`, the map panel `sm:max-w-md`, and the approved Leaflet map
lives in the tab. No oversized/full-screen detail surface exists; the approved map visual
design is untouched.

## 9. Tests

- **Backend** `tests/Feature/Commerce/OrderLocationGeocodingTest.php` (Google HTTP faked)
  → **OK (5 tests, 21 assertions)**: captured coords returned without calling Google;
  missing key → `not_configured`, no persist; complete address → geocodes and persists as
  `location_source='geocoded'`; zero results → `geocoding_failed`, no persist; a
  locality-only address is **never** geocoded (`address_unavailable`, no Google call).
- `tsc --noEmit -p tsconfig.app.json` + ESLint: clean on all touched files.
- i18n EN/AR parity: `drawer.location.*` added to both.

## 10. Browser verification (Chrome, read-only outcome — no data mutated)

- **ORD-00010** (no coords, full address): Location tab shows the complete address
  (التجمع الخامس … فيلا B 33, New Cairo · Cairo) and — with no key in dev — the settled
  state **"Geocoding is not configured."** The server built the correct complete-address
  query (verified in the response). No perpetual spinner (fixed by the query model).
- **ORD-00008** (coords): complete address + **"Location available"** + coordinates
  `30.017610, 31.434569`.
- **ORD-00003** (no complete address): **"Address unavailable — no complete delivery
  address to resolve"**, and **no** resolve request fired.
- The resolve endpoint fired **only** for the resolvable-no-coords order — never for a
  coords order or a no-address order (no wasted geocoding calls).

## 11. Was real geocoding verified, or only prepared?

**Only configuration was prepared + verified with a mocked provider.** The full
geocode→persist path is proven by the faked-HTTP backend test, and the graceful
`not_configured` path is proven live. A **real** Google Geocoding call was **not**
executed because this environment has no `GOOGLE_GEOCODING_API_KEY` (I did not add a
key). Once the key is set in `.env`, the same seam resolves and persists live points with
no further code change.

## 12. Data safety

Zero data mutations: 0 orders have `location_source='geocoded'` (every dev resolve
returned `not_configured` → no write); ORD-00010 remains NULL, ORD-00008's captured
coordinates are unchanged. No migration created; the existing location columns are reused.

## 13. Files changed

**Backend:** `config/services.php`; `…/Orders/Application/Services/OrderGeocodingService.php`
(new); `…/Orders/Application/Actions/ResolveOrderLocationAction.php` (new);
`…/Orders/Presentation/Http/Controllers/OrderController.php` (`resolveLocation` + import);
`routes/api.php` (`POST orders/{order}/resolve-location`, update-gated);
`tests/Feature/Commerce/OrderLocationGeocodingTest.php` (new).
**Frontend:** `features/orders/services/orders-service.ts`;
`features/orders/hooks/use-orders.ts` (`useResolveOrderLocation`);
`features/orders/types/order.ts` (`OrderLocationStatus`, `ResolvedOrderLocation`);
`features/orders/components/order-detail-drawer.tsx` (Location tab);
`features/logistics/distribution-workspace/components/map-order-panel.tsx` (complete address);
`i18n/locales/{en,ar}/orders.json` (`drawer.location.*`).

**Not committed, not deployed.** No canonical map design, order lifecycle, reservation,
inventory, distribution, loading, or driver code was changed.
