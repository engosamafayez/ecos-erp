# TASK-DISTRIBUTION-MAP-ORDER-POINTS-AND-DYNAMIC-ZONES-001

**Status:** Owner decisions recorded. Implementation **not started**.
**Verification:** 🛑 **BLOCKED — TOOLING UNAVAILABLE**
No code, configuration, schema, migration or business data changed. No commit. No deploy.

---

## 1. Owner Decisions — ACCEPTED AND LOCKED

### BLOCKER 1 → **Option C accepted**

*"لا تغير Group"* means: do not let the user manage a Group by hand from the Change-Zone screen, do
not add a Keep-Group mode, and do not build a second engine that separates Zone from Group.

**The canonical relationship stands: `Order → Zone → Group → Shipment → Driver/Vehicle`.**

Binding consequences for implementation:

| # | Rule |
|---|---|
| 1 | Use the existing `ManualAssignmentService::changeOrderZone()` — no new service, no new engine |
| 2 | Use the existing Zone → Group mapping (`distribution_slot_zones`) |
| 3 | If the destination Zone maps to a different Group, the Order **follows** that Group — this is correct, not a side effect |
| 4 | If the destination Group is full, **the operation is refused** by the existing capacity contract (`GroupCapacityGuard::assertHasHeadroom`) |
| 5 | **No** Keep-Group mode |
| 6 | **No** Group selector inside the Change-Zone flow |
| 7 | Never force an Order to stay in its old Group — that would put Zone and Group in a state the canonical relationship forbids |

**Net effect: zero backend change is required for Change Zone.** The service and the route already
exist and already behave exactly as this decision requires.

### BLOCKER 2 → **Dependency-free SVG accepted**

No Leaflet, Mapbox, MapLibre, Google Maps, OpenLayers, Turf, d3, tile provider or API key in this
task. The existing SVG projection in `distribution-map-tab.tsx` is the map surface. The convex hull
is hand-rolled (monotone chain), which needs no dependency.

---

## 2. Verification Status — the reason implementation did not start

🛑 **BLOCKED — TOOLING UNAVAILABLE**

The auto-mode permission classifier in this session refuses every executable. Confirmed by direct
attempt on the exact command this task names:

```
npx tsc --noEmit -p tsconfig.app.json     → refused
php -l                                     → refused
Pint / PHPStan / ESLint / docker exec / test-gate.sh → refused
```

File reads and `grep` still work, which is how the §16 audit was completed.

The task instruction is explicit:

> إذا كانت أدوات التحقق ما زالت blocked في هذه الجلسة:
> STOP ولا تدّعي VERIFIED. اكتب BLOCKED — TOOLING UNAVAILABLE ولا تكمل بتغييرات كبيرة غير قابلة للتحقق.

That instruction was followed. **No large unverifiable change was written.**

Additionally, §18/browser verification cannot be satisfied even with tooling: there is no
authenticated session, and `ecos_dev` holds **0 `distribution_delivery_stops`**, so the ten browser
scenarios have no data to exercise.

---

## 3. Audit Findings Carried Forward (verified, source-level)

Full evidence: `TASK-DISTRIBUTION-MAP-ORDER-POINTS-DYNAMIC-ZONES-001-AUDIT-AND-BLOCKERS.md`.

### Already correct — do not rebuild

| Requirement | Evidence |
|---|---|
| Every order is an independent pin | `distribution-map-tab.tsx:420-441` — one `<circle r={5}>` per order |
| Pins use the order's real lat/lng | `mapData():943-944, 963-965, 981-982` from `orders.google_maps_lat/lng`, cast at the edge |
| Orders without coordinates get no fake position | `has_location` flag; tab docblock: *"never dropped and never given a substitute position"* |
| No city/governorate polygon is used as a Zone | No polygon of any kind is currently drawn |
| No Trip vocabulary in this UI | The tab deals only in zones, groups, orders |

**`mapData()` already returns everything the new visual model needs** — per order: `order_id,
order_number, customer_name, total, city, zone_id, slot_id, latitude, longitude, has_location`.
**Do not rebuild it.**

### Must change

| # | Defect | Location |
|---|---|---|
| V1 | Large zone-centroid circle, radius `16 + sqrt(order_count) * 6` — the thing §15 forbids | `distribution-map-tab.tsx:400-410` |
| V2 | Zone position computed as `lat_sum / plotted_count` | `mapData():1027-1029` — stop **consuming** it; leave the field for other callers |
| V3 | City-centroid fallback for zone position | `mapData():1019, 1034-1038` — do not consume (every source column is NULL in this deployment anyway) |
| V4 | No dynamic boundary exists | — |
| V5 | Order pins are not clickable — only zone markers have `onClick`; orders carry a `<title>` only | `:420-441` vs `:388-395` |
| V6 | Tab is read-only by design — *"No mutation is reachable from this tab."* | docblock |
| V7 | Pins are coloured by **Group** (`colorFor(o.slot_id)`), not Zone | `:428` |

---

## 4. Build Specification — ready to execute

Frontend only. No backend change unless a missing field is proven during implementation.

**Step 1 — remove the centroid visualisation (V1, V2, V3).**
Delete the zone `<circle>` block at `:385-418`. Stop reading `zones[].latitude`, `longitude`,
`centroid_source`. Nothing may size or place a Zone from an average of coordinates.

**Step 2 — convex hull helper (V4).** New pure module, no dependency:
- Monotone-chain convex hull over each Zone's **projected** order points.
- Outward padding offset so the boundary clears the pins.
- Point-count cases per the decision: **1 point** → pin + Zone label only, no polygon;
  **2 points** → connecting line/outline; **3+** → `<polygon>` hull.
- Never synthesise area that the order points do not imply.
- Pure and unit-testable — no React, no DOM.

**Step 3 — colour by Zone (V7, §8/§9).** Pin fill from `zones[].color`; boundary the same colour at
low opacity. Neutral colour for unassigned orders, which stay visible.

**Step 4 — clickable pins (V5).** `onClick` per order pin → order panel showing order number,
customer, address, city, current Zone, Group (if any), status. **Reuse** the existing
`DistributionOrderDetail` / `OrderDetailDrawer` — do not build a third order panel.

**Step 5 — Change Zone (§5, Option C).**
- Zone selector sourced from the canonical `zones` payload. No city names, no hardcoding.
- Confirm → `PATCH /api/logistics/distribution/assignments/{assignment}/zone` with `{ zone_id }`.
  **The endpoint already exists** (`routes/api.php:1780`, `permission:logistics.distribution.update`)
  and has no frontend caller today.
- New `useChangeOrderZone` mutation in `use-distribution-workspace.ts`, invalidating the existing
  root key `KEYS.all` — the coarse invalidation all 15 existing mutations already use.
- **No Group selector.** Group membership is re-derived server-side and re-read from the canonical
  refresh.
- Surface the two legitimate refusals honestly: destination Group full (capacity contract), and
  window closed (`assertManualAllowed`).

**Step 6 — Zone panel (§9).** Zone list with order and group counts; selecting a Zone highlights its
boundary and pins and dims the rest. The `Selection` state already exists (`:49-52`) — extend it.

**Step 7 — i18n.** The tab's strings are currently hardcoded. Add EN/AR keys to the existing system
(namespace must be registered in **both** `i18n/namespaces.ts` and `i18n/types.ts`).

### Explicitly not touched
`mapData()`'s order payload · `changeOrderZone()` semantics · Group Finalize · Group capacity ·
Overflow approval · Group → Trip contract · Driver · Vehicle · Loading · Preparation · Templates ·
Zones tab · every D-02 security control.

---

## 5. Legacy Data

No migration. No bulk repair. No automatic zone reassignment. No automatic Group, Trip, Driver or
Vehicle movement. **ORD-00007 and ORD-00017 are not modified.** Orders without coordinates remain in
the existing "no recorded location" list with no fake position.

The map performs mutations **only** as the direct result of a Zone the user selected.

---

## 6. Acceptance Checklist — current state

| # | Criterion | Status |
|---|---|---|
| 1 | Every order has an independent pin | ✅ already true |
| 2 | Pins at real lat/lng | ✅ already true |
| 3 | No centroid representing a group of orders | ❌ zone centroid circle still present (V1) |
| 4 | No city polygon as Zone | ✅ already true |
| 5 | Zone boundary built from order points | ❌ not implemented (V4) |
| 6 | Boundary recomputes on membership change | ❌ not implemented |
| 7 | Order pin opens a drawer | ❌ not implemented (V5) |
| 8 | Drawer contains Change Zone | ❌ not implemented (V6) |
| 9 | Order can move Zone → Zone | ❌ endpoint exists, UI does not |
| 10 | Address/coordinates unchanged by a Zone change | ✅ guaranteed — `changeOrderZone()` writes only `distribution_zone_id`, `virtual_slot_id`, `assignment_source`, `assigned_by`, `assignment_reason` |
| 11 | Unassigned orders visible | ✅ already true |
| 12 | No invented location | ✅ already true |
| 13 | No Trip vocabulary | ✅ already true |
| 14 | Group = Shipment flow preserved | ✅ untouched |
| 15 | No automatic reassignment / Group movement / Trip sync | ✅ none exists, none added |
| 16 | No duplicate Zone engine | ✅ none added |
| 17 | No migration | ✅ none |

**7 of 17 already satisfied. The remaining 6 are the build spec in §4 — all frontend.**

---

## 7. Next Step

Resume in a session with working verification tooling. Open with:

```
Execute TASK-DISTRIBUTION-MAP-ORDER-POINTS-DYNAMIC-ZONES-001 from
TASK-DISTRIBUTION-MAP-ORDER-POINTS-DYNAMIC-ZONES-001-REPORT.md §4.
Option C and dependency-free SVG are already approved. Do not redo the audit.
```

Then run: `npx tsc --noEmit -p tsconfig.app.json` (the `-p` flag is mandatory), ESLint on touched
files, EN/AR i18n parity, the relevant PHPUnit suites, and browser verification on `localhost:5173`.

Browser verification additionally needs an authenticated session and enough real distribution data
to exercise the ten scenarios — `ecos_dev` currently has **0 delivery stops**.

---

# FINAL STATUS

## BLOCKED — TOOLING UNAVAILABLE

Owner decisions are recorded and locked. The build specification is complete and ready to execute.
**No implementation was attempted, and nothing is claimed as verified.**
