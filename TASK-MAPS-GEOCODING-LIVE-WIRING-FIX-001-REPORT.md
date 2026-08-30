# TASK-MAPS-GEOCODING-LIVE-WIRING-FIX-001 — REPORT

**Wire the already-implemented Order Location / Distribution Map geocoding to the
configured Google Maps key and prove one live end-to-end resolution.**

**STATUS: IMPLEMENTED / VERIFIED (live ECOS → Google → coordinates → persisted →
browser).** The geocoding feature was not rebuilt; only the config contract was
corrected and one controlled live resolution was executed and verified. Not committed,
not pushed, not deployed. The API key is not shown anywhere in this report.

---

## 1. Previous implementation (unchanged)

The server-side geocoding feature from the prior CORRECTION task was left intact:

- `OrderGeocodingService::geocode()` — Google Geocoding, server-side only, sends **only**
  the address string + key, returns `null` (never a guess) on missing key / network error
  / non-OK status / zero results / out-of-bounds.
- `ResolveOrderLocationAction` — priority 1 captured coords → `available`; else compose
  the COMPLETE address (specific line **and** locality both required, else
  `address_unavailable`, never a centroid); else no key → `not_configured`; else geocode
  → persist `google_maps_lat/lng` + `location_source='geocoded'` → `resolved_from_address`,
  or `null` → `geocoding_failed`.
- `POST /orders/{order}/resolve-location` (`OrderController::resolveLocation`,
  `permission:sales.orders.update`); frontend `useResolveOrderLocation` (cached query,
  enabled only when no coords + resolvable address); `OrderDetailDrawer` Location tab.

No architecture, coordinate columns, action, controller, route, map UI, Order Details UI,
address composition, or frontend resolution flow was changed — the live test exposed no
defect requiring it.

## 2. Configuration mismatch (the defect fixed)

The implementation read the key from **`services.google.geocoding_key`** (backed by env
`GOOGLE_GEOCODING_API_KEY`). The environment's configured key is **`GOOGLE_MAPS_API_KEY`**.
The two never met, so every resolution returned `not_configured` even though a valid key
existed. A second, independent defect: the corrected `config/services.php` had **not been
copied into the running dev container** (it still held the old `google` block), so even
after the source fix the runtime resolved empty.

## 3. Configuration fix (contract + wiring)

- **`config/services.php`** — replaced the old block with the canonical contract:
  ```php
  'google_maps' => [
      'key' => env('GOOGLE_MAPS_API_KEY'),
  ],
  ```
  (No key hardcoded; `env()` is used only inside the config file, per Laravel convention.)
- **`OrderGeocodingService.php`** — `isConfigured()` and `geocode()` both read
  `config('services.google_maps.key', '')`. No `env()` in runtime business logic.
- **`OrderLocationGeocodingTest.php`** — 4× `config()->set('services.google_maps.key', …)`.
- No stale reference to `geocoding_key` / `GOOGLE_GEOCODING_API_KEY` /
  `services.google.geocoding` remains anywhere under `backend/` (grep: no files found).
- Runtime: the corrected `config/services.php` + service were copied into `ecos-dev-app`;
  the dev container's environment carries `GOOGLE_MAPS_API_KEY` (runtime `.env`, not a
  committed source file); `php artisan config:clear` was run (no cached config present).
- **Resolution verified without displaying the key:** a read-only bootstrap probe reported
  `config('services.google_maps.key')` length **39** (and `env('GOOGLE_MAPS_API_KEY')`
  length **39**). The value itself was never printed, logged, or written to any file.

## 4. Live Google request

`ORD-00010` (complete New Cairo address, **no** stored coordinates) → the Location tab
fired exactly one `POST /api/orders/{ORD-00010}/resolve-location`. The server composed the
COMPLETE delivery address from the order's own fields
(`التجمع الخامس … كمبوند جراند ريزدينس - فيلا B 33, Bldg …, New Cairo, Cairo, Egypt`) and
sent **only** that address + the key to Google. No customer name, phone, order id, or other
order data was transmitted.

## 5. Live geocoding result

Google returned `status: OK` → the endpoint returned:

| field  | value |
|--------|-------|
| status | `resolved_from_address` |
| latitude | `30.0194554` |
| longitude | `31.4838207` |
| source | `geocoded` |

The coordinates fall in New Cairo — consistent with the التجمع الخامس / New Cairo address
(a real result, not a centroid or a guess).

## 6. Persistence verification (existing architecture)

Read-only DB check via the app's own connection:

```
ORD-00010 | lat=30.0194554 | lng=31.4838207 | src=geocoded
ORD-00008 | lat=30.0176104 | lng=31.4345694 | src=google_maps   (unchanged)
geocoded_count=1
```

The resolved point was written into the **existing** columns `orders.google_maps_lat/lng`
with `location_source='geocoded'` — no new table, no duplicate location system. Exactly one
order carries `location_source='geocoded'` (the authorized controlled mutation).

## 7. Browser verification

- **ORD-00010** (Location tab, after the live resolve): shows the **complete delivery
  address**, **"LOCATION AVAILABLE"** with the point **`30.019455, 31.483821`**, the
  Open in Maps / Waze / Copy Location Link actions, and **"Source: geocoded"** — replacing
  the previous "Geocoding is not configured" state.
- The Order Details Location tab presents the resolved point as a GPS card + external map
  links (the drawer has no embedded map widget by design; the interactive dot-map is the
  Distribution workspace surface). This is the approved design, unchanged.

## 8. Existing-coordinate path (no wasted Google call)

- **ORD-00008** already has captured coordinates (`location_source='google_maps'`). Its
  Location tab shows those coordinates unchanged; the frontend **disables** the resolve
  query when a point exists, so **no** resolve request fired for it.
- Across the entire session there was exactly **one** `resolve-location` request — for
  ORD-00010. An order with existing coordinates never reaches Google (and, defensively,
  `ResolveOrderLocationAction` priority 1 would return `available` without a Google call).
- No bulk geocode was performed.

## 9. Mock tests (kept)

`tests/Feature/Commerce/OrderLocationGeocodingTest.php` (Google HTTP faked) via the test
gate → **OK (5 tests, 21 assertions)** on the new `services.google_maps.key` contract:
captured coords return without calling Google; missing key → `not_configured`, no persist;
complete address → geocodes and persists as `location_source='geocoded'`; zero results →
`geocoding_failed`, no persist; locality-only → `address_unavailable`, no Google call.

## 10. Regression / static checks

- **PHPStan**: `OrderGeocodingService.php` + `ResolveOrderLocationAction.php` → **No errors**.
- **Contract sweep**: no stale `geocoding_key` / `GOOGLE_GEOCODING_API_KEY` under `backend/`.
- **Frontend**: no frontend file changed this task (backend-only contract fix), so the
  prior task's clean `tsc -p tsconfig.app.json` + ESLint + i18n parity remain valid.

## 11. Data safety

- Exactly **one** order mutated: ORD-00010 (the authorized controlled dev resolution).
- ORD-00008's captured coordinates are unchanged; `geocoded_count=1`.
- No migration, no new location table, no bulk update. The existing location columns and
  `location_source` provenance were reused.

## 12. Security (key handling)

- The key is **never** hardcoded, displayed, logged, returned in an API response, or
  written to any source file or this report. `config/services.php` uses `env()`; runtime
  business logic reads `config('services.google_maps.key')`.
- Geocoding is server-side; the browser receives only `{status, latitude, longitude,
  source, address}` — never the key. Key presence/length was confirmed via a read-only
  probe that printed only the length (39), never the value.

## 13. Remaining gaps

- **Runtime environment must carry `GOOGLE_MAPS_API_KEY`.** The dev container's key lives
  in its runtime `.env` (not a committed source file). On a container rebuild the key must
  be supplied again via the container environment / compose secret — no code change is
  needed; the config contract already resolves it.
- The Order Details Location tab intentionally renders coordinates + external map links
  rather than an embedded map; the interactive dot-map remains the Distribution workspace.

## 14. Files changed (this task)

**Backend only:**
- `config/services.php` — canonical `services.google_maps.key` ← `env('GOOGLE_MAPS_API_KEY')`.
- `Modules/Commerce/Orders/Application/Services/OrderGeocodingService.php` — reads
  `config('services.google_maps.key')` in `isConfigured()` + `geocode()`.
- `tests/Feature/Commerce/OrderLocationGeocodingTest.php` — asserts the new contract.

**Not committed, not pushed, not deployed.** No map design, order lifecycle, reservation,
inventory, distribution, loading, driver, or navigation code was changed.
