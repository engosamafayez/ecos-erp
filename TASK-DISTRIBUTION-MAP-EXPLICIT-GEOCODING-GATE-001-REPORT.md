# TASK-DISTRIBUTION-MAP-EXPLICIT-GEOCODING-GATE-001 — REPORT

**Remove auto-geocoding when the Distribution Map opens; make geocoding an explicit,
per-order action only. Opening the map is now READ-ONLY for order data.**

**STATUS: IMPLEMENTED / STATICALLY VERIFIED.** No browser, tests, Google API calls, or DB
mutations were run. Not committed, not pushed, not deployed.

---

## 1. Files modified

**Distribution workspace (frontend):**
- `features/logistics/distribution-workspace/components/distribution-map-tab.tsx` — removed
  the auto-resolution on map mount; the map now renders only already-stored coordinates.
- `features/logistics/distribution-workspace/hooks/use-distribution-workspace.ts` — removed
  the auto hook `useResolveMapOrderLocations`; added the explicit mutation
  `useResolveOrderLocationAction`. Dropped the now-unused `useQueries` / `useMemo` imports.
- `features/logistics/distribution-workspace/components/map-order-panel.tsx` — added an
  explicit "Resolve location" action + honest location state (opened from a marker / a
  "No recorded location" order).
- `features/logistics/distribution-workspace/components/distribution-order-detail.tsx` —
  opens the canonical drawer with `autoResolveLocation={false}`.

**Orders (frontend) — backward-compatible gate only:**
- `features/orders/components/order-detail-drawer.tsx` — added an optional
  `autoResolveLocation` prop (default `true`, so the Orders workspace is unchanged); when
  `false`, the Location tab shows an explicit "Resolve location" button and resolves nothing
  on open.

**i18n:** `en/orders.json` + `ar/orders.json` (`drawer.location.notResolved`, `.resolve`);
`en/logistics.json` + `ar/logistics.json` (`distributionWorkspace.map.orderPanel.location.*`).

## 2. What changed

Auto-geocoding on map mount is gone. The Distribution Map is now a pure projection of
stored coordinates. Geocoding happens only when an operator clicks "Resolve location" for a
single order. The map/drawer containment (`isolate`) and the coordinate aggregation
(`clusterByCoordinate` / `coordinateKey`, one marker + count + cluster panel) from
`TASK-DISTRIBUTION-MAP-COMPLETE-UX-CORRECTION-001` are preserved unchanged.

## 3. How auto-geocoding at map mount was prevented

The map tab previously computed `eligibleOrderIds` and called
`useResolveMapOrderLocations(...)` on mount, which fired `POST /orders/{id}/resolve-location`
for every eligible unlocated order (Google geocode + persist). **All of that was removed.**
The tab now derives `effectiveOrders = map?.orders ?? []` — no eligibility scan, no
resolution hook, no client-side merge, no effect. Mounting or re-rendering the tab issues no
resolve request and triggers no persistence. The auto hook itself
(`useResolveMapOrderLocations`, the only thing that batched resolutions) was deleted, so it
cannot be reintroduced by accident; a repo-wide search confirms zero remaining references.

The second, deeper auto path — the canonical `OrderDetailDrawer` Location tab, reachable via
"View full details" — was gated too: its geocoding query is now `enabled` only after an
explicit request. The distribution adapter passes `autoResolveLocation={false}`, so opening
an order from the map never auto-resolves; the Orders workspace keeps the default (`true`)
and is behaviourally unchanged.

## 4. How Resolve became explicit

- **`useResolveOrderLocationAction`** — a mutation (not a query with `enabled`): it runs only
  when called from a click, once per press. On `resolved_from_address` it invalidates the
  workspace root so the map refetches and the new pin appears; every other outcome mutates
  nothing.
- **`MapOrderPanel`** (opened from a marker or a "No recorded location" order) shows an
  honest location state: **Location available** when coordinates exist; a **Resolve
  location** button when the address is complete-and-resolvable; **Address unavailable**
  otherwise. Only the button calls the endpoint; the result is reported (resolved / failed /
  not configured / address unavailable).
- **`OrderDetailDrawer` Location tab** (gated mode): shows "Location not resolved yet." with a
  **Resolve location** button; clicking it is the only trigger.

Address eligibility reuses the EXISTING rule (`isAddressResolvable` — a specific
street/building line AND a locality); a zone/city name alone is never resolvable and never
becomes a centroid.

## 5. Google API not called during this task

No real Google Geocoding request was made. The change is frontend wiring only; I ran no
`resolve-location` call, no browser session, and no script that would reach Google.

## 6. No DB mutation

Nothing was persisted. `google_maps_lat/lng` and `location_source` were not written; no order
was modified. The whole point of the change is that opening the map performs no writes, and I
performed none while implementing it.

## 7. Browser not run

No browser/Playwright/Cypress verification was performed (per the task's verification policy).

## 8. Regression / test suites not run

No Vitest, PHPUnit, Pest, tsc, ESLint, `migrate:fresh`, or DB reset was executed. Verification
was static/code inspection + `diff` review only: confirmed the map mount no longer wires any
resolution, the explicit action still calls the existing endpoint, no auto-resolve effect
remains, and no dangling references to the removed hook exist.

## 9. No commit / push / deploy

No commit, push, PR, or deployment. No schema, migration, location columns, Order/Distribution
architecture, geocoding-service contract, API route, or `GOOGLE_MAPS_API_KEY` /
`services.google_maps.key` contract was changed. `GOOGLE_GEOCODING_API_KEY` was not
reintroduced.

## Expected behavior (§12) — how it now maps to the code

- **A** Open map → read `map.orders` only → no Google request, no DB write. ✔
- **B** Order with coordinates → pin. ✔ (unchanged aggregation)
- **C** Several orders at one coordinate → one marker with a count → click → cluster panel
  lists all. ✔ (preserved from the prior task)
- **D** Order without coordinates → no pin → stays in "No recorded location". ✔
- **E** Unlocated + complete address → NOT auto-geocoded; shows an explicit "Resolve
  location". ✔
- **F** Click "Resolve location" → `POST /orders/{id}/resolve-location` → persist → workspace
  invalidated → map shows the pin. ✔
- **G** Insufficient address → "Address unavailable", no Google request. ✔

**Final status: TASK-DISTRIBUTION-MAP-EXPLICIT-GEOCODING-GATE-001 — IMPLEMENTED / STATICALLY
VERIFIED.** Not browser-verified, not live-verified. Not committed, not pushed, not deployed.
