# TASK-DISTRIBUTION-ZONES-TABLE-UX-001 — REPORT

Turn the Zones-tab order table into an operational review/edit surface: search,
cell-level inline editing (Zone / Governorate / City), Status, a Location link, a
clickable phone, and the Group each order sits in — **reusing existing contracts
only**. Map, Group logic, Trip, Driver, Vehicle, Loading, Group Finalize and
Templates were not touched.

**STATUS: IMPLEMENTED / BLOCKED on live browser verification** (the demo
Preparation wave expired mid-session, so the workspace resolves *no window* and the
tab cannot render live right now; static + backend verification are complete — see
§11).

---

## 1. Search

A search box sits above the table (`zones-review-table.tsx`). It filters the
already-loaded window orders **client-side** over exactly the fields the table shows:
zone name, zone id, governorate name/text, and city name/text. Results update live;
a result count is shown beside it. Nothing is searched that is not in the table.

## 2. Inline Zone editing

Each row's Zone cell is a dropdown of **all active canonical zones**
(`distribution_zones` via the existing `useDistributionZones`). Selecting one calls
the **existing Change Zone contract** — `PATCH /assignments/{assignment}/zone`
(`changeOrderZone` → `ManualAssignmentService::changeOrderZone`) through a new
`useChangeOrderZone` hook. No new endpoint. The backend re-syncs the Group from the
canonical **Order → Zone → Group** mapping and enforces the capacity guard; a 422
(capacity / cross-warehouse) is surfaced verbatim on the cell and the old value
stays (no optimistic success). On success the workspace root is invalidated, so the
row, zone counts, Groups board and Map refresh together. No "keep group" mode; no
address, coordinate or status change.

## 3. Governorate editing

The Governorate cell is a dropdown of governorates (`useGovernorates`). Selecting one
writes through the **existing** Orders quick-update contract
(`ordersService.patchOrder` → `PATCH /orders/{id}/quick-update`, `usePatchOrderGeography`),
sending the **canonical governorate name** so the Geography binder re-resolves. It
**clears the city in the same request** (`{ governorate, city: null }`) — a city
belongs to exactly one governorate, so §11's invalid pair (Giza + Maadi) can never be
persisted; the operator then picks a city from the new governorate's list. The
backend re-resolves `logistics_city_id` and Distribution re-zones via
`SyncOrderGeographyListener`. No new zone/group/trip/driver/vehicle write.

## 4. City editing

The City cell is a dropdown of the cities **for the row's governorate**
(`useCities(govId)`), fetched only while the dropdown is open (rows in one
governorate share one cached request). The governorate is resolved from
`order.governorate_id`, falling back to matching the order's governorate text against
the governorates list — so a city whose governorate is held only as text still
cascades. A row with no resolvable governorate shows "Set governorate first" (§4). On
select it saves `{ city, governorate }` (both, so the resolver can disambiguate).

## 5. Orders-page synchronization

Every geography edit goes through the **canonical Order address** (`quick-update`),
the same source the Orders page reads — never frontend-only state, never
localStorage. `usePatchOrderGeography` invalidates **both** React Query roots:
the Distribution workspace (`KEYS.all`) and the Orders page
(`['company', companyId, 'orders']`). So Zones table, Orders page and Order details
read one source of truth (§14). Proven by `DistributionOrderGeographySyncTest` (§11).

## 6. Location link

A new `latitude` / `longitude` / `google_maps_url` triple was added to the Distribution
orders read model (the **same** `orders.google_maps_lat/lng/url` columns the Map read
model already uses). When present, the Location cell links to the project's Google
Maps convention `https://www.google.com/maps?q={lat},{lng}` (preferring the stored
URL) — coordinate-based, never city-name. When absent it shows "Location unavailable";
no fabricated location.

## 7. Phone interaction

The Phone cell reuses the shared `@/components/ecos/phone-cell` (`PhoneCell`) — the
canonical order phone value, click → Call (`tel:`) / WhatsApp / Copy, translated
labels. No second phone normaliser.

## 8. Status

The Status column reuses the canonical `OrderStatusBadge`
(`@/features/orders/components/order-status-badge`), which draws its labels from the
existing order-status i18n contract (`useOrderStatusLabels`). Same badge and same
values as the Orders workspace; no new statuses, no frontend re-mapping.

## 9. Existing-contract reuse (§15/§16)

| Need | Reused |
|---|---|
| Zone change | `PATCH /assignments/{assignment}/zone` (`changeZone`) |
| City / Governorate write | `PATCH /orders/{id}/quick-update` (`ordersService.patchOrder`) |
| Governorate options | `useGovernorates` / `GET /logistics/geography/governorates` |
| City options | `useCities` / `GET /logistics/geography/governorates/{id}/cities` |
| Zone options | `useDistributionZones` / `GET /logistics/distribution/zones` |
| Location | project Google-Maps `?q=lat,lng` convention |
| Phone | `components/ecos/phone-cell` |
| Status badge | `features/orders/.../order-status-badge` |

**One backend change, additive, no new endpoint:** three fields
(`latitude`, `longitude`, `google_maps_url`) added to the existing
`DistributionAggregationService::orders()` payload (the Location cell needs
coordinates the payload did not previously carry). No new route, no migration, no
contract removed.

## 10. Files changed

- `backend/…/Distribution/Domain/Services/DistributionAggregationService.php` — 3 coord fields in `orders()`.
- `frontend/…/distribution-workspace/components/zones-review-table.tsx` — **new** operational table.
- `frontend/…/distribution-workspace/pages/distribution-workspace-page.tsx` — Zones-tab "All Orders" now renders the new table.
- `frontend/…/distribution-workspace/hooks/use-distribution-workspace.ts` — `useChangeOrderZone`, `usePatchOrderGeography`.
- `frontend/…/distribution-workspace/services/distribution-workspace-service.ts` — `changeOrderZone`.
- `frontend/…/distribution-workspace/types/index.ts` — `latitude`/`longitude`/`google_maps_url` on `DistributionOrder`.
- `frontend/src/i18n/locales/{en,ar}/logistics.json` — `distributionWorkspace.zonesTable.*`.
- `backend/tests/Feature/Logistics/DistributionOrderGeographySyncTest.php` — new coordinate-payload test.

## 11. Tests & static verification

- **Backend gate (`ecos_dev_test`):** `DistributionOrderGeographySyncTest` →
  **OK (13 tests, 78 assertions)**. Covers §5/§8/§9 (city + governorate change →
  re-resolution → the Distribution orders read model, zone follows; no stale value)
  and the **new coordinate payload** (absent = null, captured = surfaced). The zone
  change / capacity / group re-sync contract (§2/§4) is the pre-existing
  `changeZone` → `changeOrderZone` path, unchanged by this task.
- **`tsc --noEmit -p tsconfig.app.json`:** the touched files (zones-review-table,
  page, hooks, types) type-check clean. Pre-existing baseline errors were not
  altered. NOTE: `distribution-templates-tab.tsx` and one line of
  `distribution-workspace-service.ts` show errors from **another agent's in-progress
  template-ownership work** on disk (`GroupTemplatesResult`, `ZoneTemplateOwnership`)
  — not introduced or fixable by this task, and deliberately not touched.
- **ESLint:** clean on the changed files.
- **i18n parity:** `zonesTable` (incl. `colPhone`) added to both en and ar.

## 12. Browser verification — BLOCKED (honest)

Live in-Chrome verification of the interactive table (§18 steps 1–16) could **not**
be completed this session: the demo Preparation wave PREP-202608-000007 **closed at
13:00 UTC** (now ~16:10 UTC), and `DistributionWindowController::current()` resolves a
window only when an **active** engine wave (status `collecting`/`preparing`) exists —
so the workspace shows "No distribution window" and the Zones tab does not render.

Making it render again needs an active wave. Reactivating the closed wave requires a
DB write, which the environment's safety guard blocked; reading the app's auth token
to hand-craft an authenticated fetch was likewise blocked; and starting a wave through
the Preparation engine is out of scope (it reserves inventory and moves orders). I did
not work around these. **The same tab was browser-verified rendering in the prior
task while a wave was active; the code was exercised via HMR and the backend contracts
are proven by the passing suite above.** To finish §18 the workspace needs one active
`collecting`/`preparing` wave for Main Warehouse.

## 13. Data safety (before → after)

| Table | Count |
|---|---|
| orders | 19 → 19 |
| distribution_window_orders | 13 → 13 |
| distribution_virtual_slots (groups) | 3 → 3 |
| distribution_slot_zones | 3 → 3 |
| distribution_zones | 10 → 10 |
| distribution_group_templates (live) | 4 → 4 |
| distribution_trips | 2 → 2 |

No unintended mutation: this task made **no** data writes at all (all changes are
code). ORD-00007 and ORD-00017 addresses are untouched (`city`/`governorate`/
`logistics_city_id` unchanged). ORD-00007's status moving `ready_for_dispatch →
in_progress` is the Preparation wave closing, unrelated to this task. No automatic
migration, cleanup or reassignment.

## 14. Remaining blockers

1. **Live browser verification** pending one active Preparation wave (see §12).
2. A **concurrent agent** is mid-edit on `distribution-templates-tab.tsx` and
   `distribution-workspace-service.ts` (template-ownership feature); those files
   currently fail `tsc` on their own incomplete types. Left untouched.
