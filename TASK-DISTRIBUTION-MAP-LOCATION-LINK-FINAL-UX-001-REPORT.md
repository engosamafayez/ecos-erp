# TASK-DISTRIBUTION-MAP-LOCATION-LINK-FINAL-UX-001 — REPORT

**Make the Location coordinates in the Map Order Panel a clickable Google Maps link. UI-only,
on top of the existing implementation.**

**STATUS: IMPLEMENTED / STATICALLY VERIFIED (inspection only).** No browser, Vitest, PHPUnit,
tsc, ESLint, regression, Google API call, DB write, migration, docker cp, or docker restart.
Not committed, not pushed, not deployed.

Read first (in context): the reports for TASK-DISTRIBUTION-MAP-ORDER-PANEL-UX-POLISH-001,
TASK-DISTRIBUTION-MAP-EXPLICIT-GEOCODING-GATE-001, and
TASK-DISTRIBUTION-WORKSPACE-KPI-HEADER-CLEANUP-001. (No
TASK-DISTRIBUTION-PHASE1-GROUP-DETAIL-MAP-FINAL-UX-001 report exists in the current context.)

---

## 1. Files modified

1. `frontend/src/features/logistics/distribution-workspace/components/map-order-panel.tsx`
2. `frontend/src/i18n/locales/en/logistics.json` + `frontend/src/i18n/locales/ar/logistics.json`
   (one accessible-label key)

No other files. No backend, API, database, migration, route, geocoding-service, or map-
architecture file touched.

## 2. Coordinates are now clickable

The Location section's coordinates render as a semantic `<a>` anchor (not an `onClick` div),
keeping the exact coordinate typography (`font-mono tabular-nums`, `dir="ltr"`) and adding a
subtle link treatment (`text-primary hover:underline`) plus a small `ExternalLink` (↗) icon for
affordance — the "best form" from the brief:

```
Location
[30.013056, 31.208853 ↗]
```

## 3. The link uses the order's own latitude/longitude

The `href` is built dynamically from THIS order's `detail.latitude` / `detail.longitude`:

```
https://www.google.com/maps/search/?api=1&query=${lat},${lng}
```

where `lat`/`lng` are `Number(detail.latitude|longitude).toFixed(6)`. No coordinate is hardcoded;
the displayed text and the query use the same values.

## 4. Opens in a new tab/window

`target="_blank"` on the anchor.

## 5. `noopener` / `noreferrer` used

`rel="noopener noreferrer"` on the anchor, so the external Google Maps page cannot reach back
into the ERP tab.

## 6. No API key in the link

The URL contains only `query=lat,lng`. No `GOOGLE_MAPS_API_KEY`, no `services.google_maps.key`,
no token, no secret, and no extra customer data. (`api=1` is Google's public URL-scheme version
flag, not a key.)

## 7. No-coordinate states unchanged

The link renders only when a real point exists (`coords != null`, i.e. both lat and lng
present). When there is no point, the existing states are untouched: **Resolve location** (when
the address is resolvable) or **Address unavailable** (when it is not). No coordinate is
invented from zone / city / governorate / locality / centroid, and no fallback location is used.

## 8. Explicit geocoding gate preserved

Nothing in the geocoding path changed. The link is a plain hyperlink to the public Google Maps
site opened by a user click — it does **not** call our backend, `OrderGeocodingService`, the
`resolve-location` endpoint, or the Google Geocoding API. Opening the map or the order panel
still triggers no geocoding; geocoding still happens only via the existing explicit "Resolve
location" action. `GOOGLE_MAPS_API_KEY`, `services.google_maps.key`, `OrderGeocodingService`,
the `resolve-location` endpoint, geocoding persistence, and the location architecture are all
unchanged.

## 9. Map / Group / Zone / Clustering behavior unchanged

Only the Location coordinate presentation in `map-order-panel.tsx` changed. Group/zone
filtering, selected-group scoping, `clusterByCoordinate` / `coordinateKey`, `MapClusterPanel`,
markers, and order isolation are untouched. The canonical ECOS order drawer from the polish task
(SheetContent, width, header, order number, status Badge, scroll body, RTL, View full details,
Change zone) is unchanged apart from the coordinates becoming a link.

## 10. Five KPIs preserved

The KPI header remains the approved five (Eligible orders, Assigned orders, Unassigned orders,
Active Groups, Need attention). "Remaining capacity" was not reintroduced (this task did not
touch the page or the metrics row).

## 11. Map header cleanup preserved

The map description sentence and the "N of N orders have a recorded location / N of N zones
placed" statistics remain deleted (not reintroduced).

## 12. Backend / API / Database unchanged

No PHP, route, endpoint, schema, migration, or data change.

## 13. Browser / tests not run

Verification was static/code inspection + diff review only, confirming: the URL is built
dynamically from the order's lat/lng; `target="_blank"`; `rel="noopener noreferrer"`;
coordinates keep `dir="ltr"` + `font-mono tabular-nums`; no API key/secret in the URL; no auto-
geocoding introduced (plain anchor, no app-side request); and EN/AR i18n parity for the new
`orderPanel.location.openInMaps` accessible label.

## 14. No data mutation

Nothing was written or persisted.

## i18n

Added `distributionWorkspace.map.orderPanel.location.openInMaps` — EN "Open customer location in
Google Maps", AR "فتح موقع العميل في خرائط جوجل" — used as the anchor's `aria-label`. The visible
link text is the coordinates themselves, so no other i18n string was added.

## Accessibility

Semantic `<a>` (keyboard-focusable, activatable by Enter), with an `aria-label` describing the
action and the `ExternalLink` icon marked `aria-hidden`. No `onClick`-on-`div` pattern.

**Final status:**
IMPLEMENTED / STATICALLY VERIFIED
Browser: NOT RUN · Tests: NOT RUN · Google API: NOT CALLED · Data mutation: NONE ·
Backend: UNCHANGED · API: UNCHANGED · Database: UNCHANGED · Commit: NONE · Push: NONE · Deploy: NONE
