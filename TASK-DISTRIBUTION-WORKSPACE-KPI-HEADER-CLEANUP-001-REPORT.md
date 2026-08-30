# TASK-DISTRIBUTION-WORKSPACE-KPI-HEADER-CLEANUP-001 — REPORT

**Distribution Planning UI cleanup: reduce the KPI header to 5 balanced cards (remove
"Remaining capacity") and delete the map description + statistics text. UI-only.**

**STATUS: IMPLEMENTED / STATICALLY VERIFIED (inspection only).** No browser, Vitest,
PHPUnit, tsc, ESLint, regression, DB mutation, Google API call, docker cp, or docker restart.
Not committed, not pushed, not deployed.

---

## Files modified

1. `frontend/src/features/logistics/distribution-workspace/pages/distribution-workspace-page.tsx`
2. `frontend/src/components/workspace/metrics/workspace-metrics-row.tsx`
3. `frontend/src/features/logistics/distribution-workspace/components/distribution-map-tab.tsx`

No new components; no i18n keys added or removed; no backend/API/data files touched.

## 1) "Remaining capacity" removed — KPI header is now 5 cards

In `distribution-workspace-page.tsx`:
- Deleted the `{ id: 'remaining', icon: Gauge, label: …phase1.kpiRemaining, value: remainingTotal }`
  metric object from the `metrics` array.
- Deleted its now-unused computation `const remainingTotal = slots.reduce(...)`.
- Removed the now-unused `Gauge` import from `lucide-react`.

The `metrics` array now contains exactly the five required cards, in order:
**Eligible orders → Assigned orders → Unassigned orders → Active Groups → Need attention.**
Their data sources, calculations, click behaviors (Need attention still opens the Exceptions
drawer), colors, and the canonical `WorkspaceMetricCard`/`WorkspaceHeader` rendering are
unchanged — only the sixth card was dropped.

## The five remaining KPIs are balanced in one row on desktop

`WorkspaceHeader` renders metrics via the shared `WorkspaceMetricsRow`, whose grid previously
capped at four columns (`lg:grid-cols-4`), so five cards wrapped 4 + 1. Added a single,
**backward-compatible** entry so exactly-five layouts fill one desktop row:
- `GRID_COLS[5] = 'sm:grid-cols-2 lg:grid-cols-5'`
- the column lookup now keys on `metrics.length` directly; 1/2/3 keep their explicit columns,
  and **4, 6, and 7+ still fall through to the same `sm:grid-cols-2 lg:grid-cols-4` fallback as
  before** — so no other workspace's KPI layout changes. Only a five-metric row is affected.

The same `WorkspaceMetricCard` component and design tokens are used (no new card, no custom
grid). On mobile the row keeps its existing horizontal-scroll behavior; at `sm` it is two
columns; at `lg` it is five in one balanced row.

## 2) Map description + statistics text removed

In `distribution-map-tab.tsx`, above the map, deleted:
- the description paragraph ("Zones, groups and orders placed from the locations recorded on the
  orders themselves. Zones have no stored geometry: each is positioned from its own orders."), and
- the statistics line ("… of … orders have a recorded location  … of … zones placed" — the two
  `map-summary-orders` / `map-summary-zones` spans).

Nothing replaces them. The map card's heading and the toolbar/filter controls (search, All
zones, Fit all, mobile Zones) are unchanged, so the map area now begins directly after the
toolbar — cleaner, with no description or statistics text. No i18n key was deleted (the now-unused
`subtitle` / `summaryOrders` / `summaryZones` strings remain in the locale files, harmless).

## 3) TASK-DISTRIBUTION-MAP-ORDER-PANEL-UX-POLISH-001 behavior preserved

None of that task's code was touched. Still intact and unchanged:
- Canonical ECOS order drawer shell + width + `SheetContent` contract (`MapOrderPanel`).
- Coordinates shown instead of "Location available".
- Group names inside the Zone panel instead of "N groups".
- Coordinate clustering, the cluster panel, zone/group filtering, "View full details".
- Explicit "Resolve location" action and the no-auto-geocoding-on-map-open gate.
- EN/AR i18n and RTL behavior.

## 4–5) Map and everything else unchanged

No change to the map, markers, clustering, zones, groups, order location, geocoding, backend,
database, API, routes, or architecture. The Leaflet map, its `isolate` containment, the data
hooks, and all business logic are untouched. The KPI data (`orders`, `assigned`, `unassigned`,
`slots`, `exceptionsCount`) and their sources/endpoints are unchanged — only the sixth card's
presentation was removed.

## Explicit confirmations

- **Map functionality unchanged.**
- **No backend changes.** **No API changes.** **No database changes.** **No routes/architecture changes.**
- **No data mutation.** **No Google API call.**
- **No tests or browser run** — verification was code/static inspection + diff review only
  (per the task's verification rule). Confirmed via a repo search that no dangling references to
  `remainingTotal`, `Gauge`, `map.summary`, `summaryOrders`, or `summaryZones` remain in the
  distribution workspace.

**Final status:**
IMPLEMENTED / STATICALLY VERIFIED
Browser: NOT RUN · Tests: NOT RUN · Data mutation: NONE · Backend: UNCHANGED · API: UNCHANGED ·
Commit: NONE · Push: NONE · Deploy: NONE
