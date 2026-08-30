# TASK-DISTRIBUTION-MAP-ORDER-PANEL-UX-POLISH-001 — REPORT

**Three UI/UX fixes on the Distribution Map only: (1) the map's Order Details panel now
matches the canonical ECOS drawer, (2) Location shows the actual coordinates, (3) the Zone
panel shows Group names instead of a group count. UI-only; no behavior changed.**

**STATUS: IMPLEMENTED / STATICALLY VERIFIED (inspection only).** No browser, no Vitest /
PHPUnit / tsc / ESLint / regression run. No data mutation. Not committed, not pushed, not
deployed.

---

## Files modified

1. `frontend/src/features/logistics/distribution-workspace/components/map-order-panel.tsx`
2. `frontend/src/features/logistics/distribution-workspace/components/distribution-map-tab.tsx`
3. `frontend/src/i18n/locales/en/logistics.json` + `frontend/src/i18n/locales/ar/logistics.json`

No other files were touched. No new component was created (the existing `MapOrderPanel`,
`ZoneList`, and the canonical `Sheet` primitives were reused).

## What changed in each file

### 1) `map-order-panel.tsx` — Problems 1 & 2

**Problem 1 — canonical drawer.** The panel's shell now mirrors the canonical ECOS
`OrderDetailDrawer` exactly, reusing the same `Sheet`/`SheetContent`/`SheetHeader` primitives:
- **Width:** the identical class the canonical drawer uses —
  `sm:w-[48vw] sm:min-w-[480px] sm:max-w-[820px]` (was an ad-hoc `sm:max-w-md`). Same width at
  every breakpoint as every other ECOS drawer.
- **Container / behavior:** `flex flex-col gap-0 p-0` — a fixed, bordered header plus a single
  scrollable body, the standard side-drawer overlay behavior (built-in `Sheet` close, backdrop,
  z-index and RTL side handled by the primitive — unchanged).
- **Header structure & typography:** `SheetHeader` with `border-b px-4 py-3`, the order number
  in `font-mono text-base` with the status **Badge** beside it, and the customer as a muted
  `text-xs` description — matching the canonical header. The status row was **moved out of the
  body** into the header (removing the duplicate "Status" field).
- **Padding / spacing:** the body is `flex-1 overflow-y-auto p-4` with `space-y-4` sections, so
  content no longer touches the drawer edges.
- The map stays behind the overlay; nothing resizes the map, and no map layout is broken (the
  map container's own `isolate` stacking context from the prior task is untouched).

**Problem 2 — Location shows coordinates.** In the Location section, when coordinates exist it
now renders the **actual values** (`{lat.toFixed(6)}, {lng.toFixed(6)}` in `font-mono
tabular-nums`, `dir="ltr"`) instead of the bare "Location available" label. No coordinates are
invented, no geocoding is triggered, and the resolvable / address-unavailable / explicit
"Resolve location" states from the geocoding-gate task are preserved unchanged.

### 2) `distribution-map-tab.tsx` — Problem 3

`ZoneList` now shows the zone's **Group names** instead of "N groups". Added a `groupLabelBySlot`
memo (`slot_id → name ?? code`) built from the map payload's own `groups`, passed to `ZoneList`
(both the desktop panel and the mobile sheet). Each row is now two lines — zone name + order
count on the first, the group labels joined by " · " on the second (or "No group" when the zone
has none) — truncated with a full-text `title` tooltip so long or multiple names never break the
fixed-width panel. The order count is unchanged.

### 3) i18n

Added `distributionWorkspace.map.noGroup` to `en` ("No group") and `ar` ("بدون مجموعة"). No
other keys were added or removed; existing keys (now unused, e.g. `orderPanel.title`,
`orderPanel.status`, `zoneGroups`, `orderPanel.location.available`) were left in place.

## How Group names were obtained from existing data

From the SAME map response already loaded by `useDistributionMap`: `map.groups` (`MapGroup[]`)
carries `slot_id`, `code`, and `name`, and each zone carries `slot_ids: string[]`. The zone's
group labels are `zone.slot_ids.map(slotId → groupLabelBySlot.get(slotId))` using
`name ?? code` (the canonical group identifier, same convention as the map legend and the order
panel's "Group" field). No new endpoint, no new query, no payload change, and names are never
guessed — an unresolved slot id is simply skipped.

## How Location now shows coordinates

From the existing `DistributionOrder` fields `latitude` / `longitude` (the same data the map
plots from). When both are present the panel prints them; otherwise it keeps the existing state
(Resolve location / Address unavailable). The distribution payload carries **no**
`location_source` field, so — per "show source if available" — only the coordinates are shown;
adding a source would require a backend payload change, which is out of scope. The storage/fetch
of coordinates is unchanged.

## How the Order Details drawer was unified with the canonical ECOS drawer

By adopting the canonical `OrderDetailDrawer`'s exact `SheetContent` contract — same width
classes, same `flex flex-col gap-0 p-0` shell, same bordered `px-4 py-3` header with a mono
order number + status badge + muted description, and a single `flex-1 overflow-y-auto p-4`
scroll body. Both surfaces use the same `Sheet` primitive, so drawer behavior, z-index,
backdrop, and RTL side placement are identical by construction. No new drawer pattern was
introduced.

## Preserved functionality (§4)

Map rendering, zone/group filtering, order markers, coordinate clustering (one marker + count),
multiple-orders-at-one-coordinate cluster panel, order isolation, order details, "View full
order details", the explicit "Resolve location" action, the existing geocoding gate, EN/AR i18n,
and RTL are all unchanged — only presentation was adjusted. Coordinates use `dir="ltr"`; header
and group padding use logical properties (`pe-*`, `ps-*`) so LTR/RTL and desktop/mobile all
behave correctly.

## Explicit confirmations

- **Backend unchanged.** No PHP/controller/service/model change.
- **Database unchanged.** No schema, migration, or column change.
- **API unchanged.** No route, endpoint, or payload change.
- **Geocoding logic unchanged.** `OrderGeocodingService`, `ResolveOrderLocationAction`,
  `POST /orders/{order}/resolve-location`, and the `services.google_maps.key` contract are
  untouched; clustering/aggregation untouched.
- **No Google API call** was made.
- **No data mutation** of any kind.
- **No commit, no push, no deploy.**
- **Verification:** static/code inspection only — no browser, Vitest, PHPUnit, tsc, ESLint, or
  regression suite was run (per the task's instruction).

**Final status: TASK-DISTRIBUTION-MAP-ORDER-PANEL-UX-POLISH-001 — IMPLEMENTED / STATICALLY
VERIFIED.**
