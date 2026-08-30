# TASK-DRIVER-01 — HANDOFF: DISCOVERY COMPLETE, IMPLEMENTATION BLOCKED

**Status:** Discovery complete and verified. Backend contract work partially done (1 file, additive, **unverified**).
Remaining work blocked on verification tooling. **Not a code problem — a session tooling problem.**

Paste this file into a fresh session to resume without re-auditing.

---

## 0. Why this stopped

The auto-mode permission classifier refuses substantial shell commands in the originating session
(`php -l`, Pint, PHPStan, `tsc`, `docker exec`, `git status`). Its own message states it reacts to
earlier conversation content and will keep firing for the remainder of that conversation. Simple
commands still run; nothing else does.

Writing the remaining frontend work — a type contract change plus six consumer files plus navigation
plus two locale files — without `tsc` would be guesswork. It was not attempted.

**Resume in a fresh session.** The classifier state does not carry over.

---

## 1. Required report facts (§2 / §17 — all confirmed by reading source)

```
DRIVER APP URL:      http://127.0.0.1:5173/driver/home
FRONTEND ROUTE:      ROUTES.driverHome = '/driver/home'      (src/router/routes.ts:226)
PAGE COMPONENT:      DriverHomePage
                     src/features/operations/driver-mobile/pages/driver-home-page.tsx
ROUTE MOUNTING:      src/router/router.ts:521 — child of AppShell (router.ts:242),
                     NOT a full-screen shell like POS (router.ts:240)
NAVIGATION REGISTRY: src/config/module-navigation.ts   ← LIVE (9 importers)
                     src/config/navigation.ts          ← DEAD (0 importers); do not revive
NAVIGATION TARGET:   module id 'shipping' (module-navigation.ts:288-330)
BACKEND BASE ROUTE:  /api/driver/*    (backend/routes/api.php:3116)
CONTROLLER:          Modules\Logistics\Distribution\Presentation\Http\Controllers\DriverRuntimeController
PERMISSION:          loading.driver.operate  (granted to `driver` by D-02, config/permissions.php)
```

**There is exactly one driver route family — no duplicate exists.** 11 routes at
`routes.ts:226-236`, all mounted `router.ts:521-531`. A separate admin CRUD screen lives at
`/logistics/drivers` (`routes.ts:170`, `router.ts:393`) — different concern, leave it alone.

**Navigation conventions (must be followed):**
- `ModuleNavLink = { key: NavItemKey; path: string; icon: LucideIcon }` — **no `label` field**.
- `NavItemKey = keyof (typeof enCommon)['nav']['items']` (`module-navigation.ts:76`).
- Therefore adding a nav entry **requires** a matching key in `src/i18n/locales/en/common.json`
  under `nav.items`, and the Arabic mirror. A missing key is a `tsc` error, by design.

---

## 2. Work already done — KEEP, do not revert

**One file changed:** `backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverRuntimeController.php`

- `stopSummary()` now returns the canonical order payload plus `delivery_type`,
  `collected_amount`, `attempted_at`, `notes`. Previously six scalars and **no order at all** —
  the cause of every blank stop card and of the list search never matching.
- New private `orderPayload(DeliveryStop $stop, bool $withLines): ?array` — **one** order
  representation, shared by list and detail, differing only in whether `lines` is populated.
- `stopDetail()` delegates to it.
- Adds `payment_method` (same precedence as `PaymentFulfillmentGate::methodOf()` — manual wins),
  `items_count`, `product_name`, `unit_price`, `line_total`.
- `withCount('lines')` on the list path; `with('lines.product')` only on detail.

**Safe to leave in place:** every field it adds was already declared in the frontend types, so the
contract is strictly better aligned than before and no regression was introduced.
**It is unlinted and untested.** First action in the new session: `php -l`, Pint, PHPStan on it.

---

## 3. The canonical contract (source of truth for the frontend alignment)

### `tripSummary()` returns EXACTLY these 10 keys — nothing else
```
id, trip_number, status, company_id, driver_id, vehicle_id,
stops_count, exceptions_count, trip_started_at, trip_finished_at
```

The frontend `DriverTrip` type declares **18** fields. **13 are phantom** — the backend has never
sent them, which is why the Home shows `NaN`:

`name`, `type`, `orders_count`, `collection_amount`, `zone_code`, `wave_number`, `driver_name`,
`vehicle_plate`, `departure_at`, `total_cash_collected`, `total_bank_transfers`,
`total_already_paid`, `kpis`

**Resolution — do not add these to the backend:**
- `zone_code`, `vehicle_plate`, `wave_number` → **forbidden on the Home by §3/§11.** Delete from the type.
- the three money totals + `collection_amount` → the driver money endpoints are **403-frozen** by
  design (D-02 baseline). Surfacing them would contradict the freeze. Delete from the type.
- `orders_count` → **use the existing `stops_count`.** One stop = one order
  (`distribution_delivery_stops` is unique on `(trip_id, order_id)`), so `stops_count` already *is*
  the assigned-order count, computed live via `withCount('stops')`. No backend change needed.
- `driver_name` → **do not put it on every trip card.** The Home needs the driver's name once; take
  it from the existing authenticated-user context, not from the trip payload.
- `kpis` → not sent. `driver-trip-dashboard-page.tsx:174` falls back to
  `trip.kpis?.remaining_stops ?? 1`, which permanently disables its Finish Trip button. Out of D-01
  scope to populate; guard it and note it.

### `stopSummary()` / `stopDetail()` — canonical field names
```
order.phone      (NOT billing_phone)
order.address    (NOT shipping_address)
order.gps        { lat, lng }  — currently read by nothing
order.payment_method, order.items_count, order.grand_total,
order.deposit_paid, order.remaining_balance, order.delivery_notes
lines[]: product_id, product_name, ordered_qty, unit_price, line_total,
         loaded_qty, delivered_qty, returned_qty, remaining_qty
```

Per §4: **align the frontend to these names. Do not add compatibility aliases.**

---

## 4. Exact frontend consumers to fix (grep-confirmed)

| File | What to change |
|---|---|
| `types/driver-mobile.ts:9-28` | `DriverTrip` → the 10 canonical keys only |
| `types/driver-mobile.ts:58-59` | `billing_phone` → `phone`; `shipping_address` → `address`; add `gps`, `items_count` |
| `pages/driver-home-page.tsx` | Rewrite per §3/§7 — driver name, assigned orders, Start Loading / No trip. Remove the `NaN` reducers at :12-16 |
| `components/driver-trip-card.tsx:28-31, 50-55, 67-71` | Remove money total, `zone_code`, `driver_name`, `vehicle_plate` (§11) |
| `components/delivery-stop-card.tsx:35-36, 65-67` | Drop the `as unknown as Record` phone hack; read `order.phone`, `order.address` |
| `pages/driver-stop-detail-page.tsx:96-136` | `billing_phone`→`phone`, `shipping_address`→`address`; consume `gps`; `line.quantity`→`ordered_qty` |
| `pages/driver-map-page.tsx:34, 42, 99` | `shipping_address` → `address`; prefer `order.gps` over the text query |
| `pages/driver-trip-dashboard-page.tsx:110-135, 157, 174` | Remove `wave_number`/`zone_code`/`driver_name`/`vehicle_plate`; guard `kpis` |
| `pages/driver-collections-page.tsx:27-29` | Reads the three frozen money totals — mark unavailable, do not fabricate (§5) |

**i18n:** the entire `features/operations/driver-mobile/` tree has **zero** `useTranslation` calls.
Every string is hardcoded English. Minimum keys required by §6/§10: Driver, Home, Assigned orders,
Start Loading, No trip assigned, Orders today, Delivered, In progress, Failed delivery, Available,
Unavailable, Vacation, Loading. Two registrations per memory: the namespace must be added to
`i18n/namespaces.ts` **and** `i18n/types.ts`, or every `t()` is compile-red.

---

## 5. Known STOP already established (do not re-litigate)

**Driver availability** — `Driver.php:28-34` has only `active | inactive | archived`.
"Available for Work / Unavailable / Vacation" cannot be represented. §4 of the D-01 brief says
report the gap and do not invent a lifecycle. **Report it; add the i18n keys only.**

---

## 6. Verification the new session must run (§11)

```
php -l                          — the changed controller
vendor/bin/pint                 — changed backend files
vendor/bin/phpstan analyse      — changed backend files
npx tsc --noEmit -p tsconfig.app.json     (the -p flag is mandatory; bare tsc checks zero files)
npx eslint                      — touched frontend files
i18n parity                     — en vs ar key sets must match exactly
docker cp → ecos-dev-testrunner (no hot mount; every edit needs an explicit copy)
GATE_WAIT=2400 ./scripts/test-gate.sh tests/Feature/Security/DriverRbacTenancySecurityTest.php
```

Baseline to protect: **`DriverRbacTenancySecurityTest` was `OK (21 tests, 42 assertions)`** at the
end of D-02. It must stay green — it is the D-02 security baseline (§9).

Known adjacent failure, **not D-01's and control-proven**:
`PaymentProofLifecycleTest::test_10_verification_never_writes_order_status_itself`, caused by another
workstream's `PaymentFulfillmentGate::permitsAdvance()`. Do not touch it.

---

## 7. Environment facts

- Frontend dev server: `http://127.0.0.1:5173` (Vite). `127.0.0.1:8081` is the dev nginx and serves
  the Laravel welcome page — **not** the SPA.
- Test DB is `ecos_dev_test`, pinned in `tests/TestCase.php`; always go through `scripts/test-gate.sh`.
- Containers are **not** hot-mounted — `docker cp` after every edit.
- Live driver-domain data: **0 drivers, 0 vehicles, 0 stops, 2 trips**. Browser verification of the
  Home with real assigned work is impossible without fabricating fleet data, which is out of bounds.
  Expect **BROWSER NOT VERIFIED** unless an authenticated session plus legitimate data both exist.

---

## 8. Data safety at handoff

No business data was created or modified. No migration. No schema change. No frontend file, nav
entry, locale file, route or D-02 file was touched. The only change in the working tree from this
task is the single additive controller edit in §2.
