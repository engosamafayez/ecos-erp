# TASK-DISTRIBUTION-MAP-ORDER-POINTS-AND-DYNAMIC-ZONES-001

**Phase completed:** §16 pre-implementation audit (read-only).
**Phase not started:** implementation — **stopped on two blockers plus a tooling constraint.**
No code, config, schema or business data changed. No commit. No deploy.

---

## 1. Executive Summary

The §16 audit says something better than expected and something worse.

**Better:** roughly half of what this task asks for is already built and already correct.
Each order is *already* an independent pin at its real `latitude`/`longitude`; orders without
coordinates are *already* listed separately and *never* given a substitute position; the backend
*already* returns per-order coordinates with an explicit `has_location` flag; and there is *no* city
polygon anywhere, because there are no polygons at all.

**Worse:** the single most visible thing the task forbids is exactly what the code draws today —
a large circle at a zone centroid, sized by order count. §15 prohibits it in as many words. Removing
it is straightforward.

**Two things stop implementation, and neither is a UI problem:**

**BLOCKER 1 — "Change Zone" cannot leave the Group unchanged.** The canonical service
`ManualAssignmentService::changeOrderZone()` re-derives the order's Group from the destination Zone,
by design and by written contract, and enforces destination-Group capacity. §5 says *"لا تغير
Group"*. §13 forbids building a second engine to avoid it. As written, §5 and §13 cannot both be
satisfied. **Owner decision required.**

**BLOCKER 2 — there is no map.** No map library, no tile provider, no geometry library, no API key.
The "map" is a hand-rolled SVG scatter over the bounding box of the real points, and the existing
code documents that as deliberate. Convex-hull boundaries are achievable without a dependency; a
real basemap is not. **Owner decision required.**

**Constraint — verification tooling is unavailable in this session.** `php`, Pint, PHPStan, `tsc`,
ESLint, `docker exec` and the test gate are all refused by the auto-mode classifier; file reads and
`grep` still work. §18 makes browser verification mandatory for completion, and no authenticated
session exists. Writing this feature now would produce unverifiable code against a mandatory-
verification acceptance list.

---

## 2. What already exists and already satisfies the task

| Requirement | Status | Evidence |
|---|---|---|
| §1 Every order is an independent pin | ✅ **already correct** | `distribution-map-tab.tsx:420-441` — `plottedOrders.map(...)` renders one `<circle r={5}>` per order at `project(o.latitude, o.longitude)` |
| §1 Pin at the real lat/lng | ✅ **already correct** | `DistributionAggregationService::mapData():943-944, 963-965, 981-982` — `orders.google_maps_lat/lng`, cast at the edge because the DECIMAL(10,7) columns arrive as strings |
| §1 No single point representing a group of orders | ⚠️ **true for orders, false for zones** — see §3 below | |
| §7 No invented coordinates | ✅ **already correct** | `mapData()` sets `has_location`; the tab's docblock: *"never dropped and never given a substitute position"* |
| §7 Orders without location listed with a reason | ✅ **partially** — they are listed and counted (`summary.orders_without_location`); the *reason* (missing address vs unresolved) is not distinguished | `mapData():1078-1082` |
| §10 No city polygon used as a zone | ✅ **correct by absence** — no polygon of any kind is drawn | |
| §11 No Trip vocabulary in the map UI | ✅ **correct** | The tab deals in zones, groups and orders only |
| §13 No second zone engine | ✅ **nothing new was built** | |

**The backend `mapData()` payload is already the right shape for this task.** Per order it returns
`order_id, order_number, customer_name, total, city, zone_id, slot_id, latitude, longitude,
has_location`. That is sufficient for pins, for zone membership, and for hull computation. It needs
**no change** for §1, §2, §3 or §7.

---

## 3. What violates the task today

| # | Violation | Location | Fix |
|---|---|---|---|
| V1 | **A large circle at a zone centroid, radius scaled by order count** — precisely what §15 forbids (*"large circle = zone"*, *"one point = all orders"*) | `distribution-map-tab.tsx:400-410` — `<circle r={16 + Math.sqrt(z.order_count) * 6}>` | Delete. Replace with a hull over that zone's order pins (§3) |
| V2 | **Zone position is a mean of its orders' coordinates** — a centroid standing in for a cluster | `mapData():1027-1029` — `lat_sum / plotted_count` | Stop consuming `zones[].latitude/longitude` in the UI. The field can stay (other callers may exist) but must not drive the drawing |
| V3 | **City-centroid fallback for zone position** — §2/§10 forbid city as the basis of zone geometry | `mapData():1019, 1034-1038`, `zoneCityCentroids()` | Do not consume. *Note: the code itself records that every one of those columns is NULL in this deployment, so the fallback currently yields nothing* |
| V4 | **No dynamic boundary at all** | — | Implement convex hull + padding over each zone's projected order points (§3) |
| V5 | **Order pins are not clickable** — only zone markers carry `onClick`; order circles have a `<title>` tooltip only | `:420-441` vs `:388-395` | Add click → Order Drawer (§4) |
| V6 | **The tab is read-only by design** — no Change Zone exists | Docblock: *"READ ONLY. Selecting a zone or a group filters what is shown. No mutation is reachable from this tab."* | §5 — see BLOCKER 1 |
| V7 | **Pins are coloured by Group, not Zone** — §8 gives each zone its own colour | `:428` — `fill={colorFor(o.slot_id)}` | Colour by `zone_id`; `zones[].color` is already returned |

---

## 4. 🛑 BLOCKER 1 — Change Zone necessarily changes the Group

**Change Zone IS supported** (so §16.7's STOP does not apply): the service and route both exist.

- `ManualAssignmentService::changeOrderZone(DistributionWindowOrder $assignment, ?int $zoneId, ?int $actorId, ?string $reason)` — `:215-265`
- `PATCH /api/logistics/distribution/assignments/{assignment}/zone` — `routes/api.php:1780`, `permission:logistics.distribution.update`
- **Zero frontend callers** — the endpoint has never been wired to a UI

**But it cannot satisfy §5.** Verbatim from its docblock (`:200-203`):

> *"Its Slot follows the destination Zone's mapping, because that mapping is the source of truth for Slot membership. Passing a Zone with no Slot legitimately leaves the Order slotless."*

And the implementation (`:227-252`):

```php
$slotId = $zoneId === null || $orderWarehouseId === null
    ? null
    : ($this->collection->slotMapForWindow($window->id, (string) $orderWarehouseId)[$zoneId] ?? null);
…
if ($slotId !== null && $slotId !== $previousSlot) {
    $destination = VirtualCapacitySlot::query()->find($slotId);
    if ($destination !== null) {
        $this->capacity->assertHasHeadroom($destination, 1);
    }
}
```

`virtual_slot_id` **is** Group membership. So a zone change:

1. **changes the Group** — directly contradicting §5's *"لا تغير Group"*;
2. **can be refused** when the destination Group is at capacity — an outcome §5 does not anticipate;
3. **can leave the order slotless** if the destination Zone maps to no Group in that warehouse;
4. **is refused entirely** when the window is Closed (`assertManualAllowed`, `:222`).

This is not incidental — it is the stated contract, and it is coherent: Zone→Group is a mapping
(`distribution_slot_zones`), so an order's Group is *derived* from its Zone. §13 forbids building a
second engine to sidestep it.

**Decision required.** Options, with my read of each:

| | Option | Consequence |
|---|---|---|
| **A** *(recommended)* | Accept that changing Zone re-derives Group, and amend §5 | Zero backend change. Matches the existing certified contract and the canonical flow `Zone → Group`. The UI must then surface the Group change and handle a capacity refusal honestly |
| **B** | Add a "keep current Group" mode to `changeOrderZone()` | Changes a certified service. Produces orders whose Zone and Group disagree — the Zone→Group mapping stops being a source of truth. I would not recommend this without a strong business reason |
| **C** | Treat §5's *"لا تغير Group"* as meaning *"do not move the order to a different Group **manually**"* | If that is the intent, **there is no blocker** and Option A is simply the correct reading. §5 lists *"لا تنشئ Group"* separately, which is why I read *"لا تغير Group"* literally rather than assuming |

**If the answer is A or C, this blocker clears and implementation can proceed.**

---

## 5. 🛑 BLOCKER 2 — there is no map

`frontend/package.json` contains **no** Leaflet, Mapbox, MapLibre, Google Maps, OpenLayers, deck.gl,
Turf, hull or d3 dependency. The existing tab documents this as a deliberate constraint
(`distribution-map-tab.tsx:33-37`):

> *"NO MAP LIBRARY AND NO TILE PROVIDER. This follows the projection already used by `coverage-map.tsx`: an SVG scatter over the bounding box of the real points. That needs no Leaflet, no Mapbox, no Google Maps SDK and no API key — none of which this project has."*

What that means for this task:

- **§3 hull boundaries are achievable with no dependency.** A monotone-chain convex hull is ~30 lines
  over the already-projected points, and §3 lists convex hull as the first acceptable option. The 1-point
  and 2-point special cases §3 specifies are straightforward. **Not a blocker.**
- **A real basemap is not achievable without a dependency and a key.** §14's sketch and the word
  "خريطة" imply streets and geography. Today the pins float on a blank SVG whose extent is the
  bounding box of the points themselves — geometrically faithful *relative to each other*, but with
  no map underneath.

**Decision required:** (a) proceed on the existing dependency-free SVG projection, adding hulls and
interaction — deliverable now, no new dependency; or (b) approve a real map library plus a tile
provider and key, which is a materially larger change and a new external dependency.

---

## 6. Constraint — verification tooling unavailable

The auto-mode permission classifier refuses every executable in this session (`php -l`, Pint,
PHPStan, `npx tsc`, ESLint, `docker exec`, the test gate). File reads and `grep` still work, which is
why this audit was possible. The refusal states it reacts to accumulated conversation content and
will persist for the remainder of this conversation.

§18 requires backend focused tests, `tsc`, ESLint, EN/AR i18n parity **and** browser verification,
and states the task is not complete without the latter. Additionally, no authenticated browser
session exists, and the driver/distribution data needed to exercise the ten browser scenarios does
not exist in `ecos_dev` (0 delivery stops; 2 trips).

Implementing now would mean writing a substantial typed frontend feature with no type-check against
an acceptance list that mandates verification. That is not a trade I should make silently.

---

## 7. Implementation plan (ready to execute once unblocked)

Ordered, with the decisions each step depends on.

**Backend — no change required for the map itself.** `mapData()` already returns everything §1–§3
need. The only optional change is to stop emitting `zones[].latitude/longitude/centroid_source`, and
that should wait until every consumer is known.

**Frontend, in `distribution-map-tab.tsx` and siblings:**

1. **Remove the zone centroid circle** (V1) and stop reading `zones[].latitude/longitude` (V2, V3).
2. **Add a convex-hull helper** — monotone chain over each zone's projected order points, plus a
   padding offset. Special cases per §3: 1 point → pin + label only, no polygon; 2 points → a
   connecting capsule; 3+ → hull. Pure function, unit-testable, no dependency.
3. **Colour pins by `zone_id`** using `zones[].color` (V7); neutral colour for unassigned (§6).
4. **Make order pins clickable** (V5) → open the Order Drawer with the §4 field list. Reuse the
   existing `DistributionOrderDetail` / `OrderDetailDrawer` rather than building a third order panel.
5. **Add Change Zone** (§5) → `PATCH /assignments/{assignment}/zone` via a new
   `useChangeOrderZone` mutation in `use-distribution-workspace.ts`, invalidating the existing
   root key `KEYS.all` (the coarse invalidation already used by all 15 mutations). **Depends on
   BLOCKER 1.**
6. **Right panel** (§9) — zone list with order and group counts, selection highlighting. Selection
   state already exists (`Selection` type, `:49-52`); extend rather than replace.
7. **i18n** — the tab currently has hardcoded strings; add EN/AR keys to the existing system.

**Explicitly not touched:** `mapData()`'s order payload, `changeOrderZone()`'s semantics, Group
lifecycle, Preparation/Loading contracts, Trip (which correctly does not appear in this UI at all),
and every D-02 security control.

---

## 8. Data safety

Nothing was read into or written out of any database in this phase — the audit was entirely
source-level. **No automatic repair, reassignment, zone migration, order movement or group movement
was performed or proposed** (§12). ORD-00007 and the other legacy rows were not touched and are not
assumed to be wrong.

The only file created by this task is this report.

---

## 9. Status

# STOPPED — OWNER DECISION REQUIRED

**Blocking:**
1. §5 *"لا تغير Group"* vs `changeOrderZone()`'s certified Zone→Group derivation — options A/B/C in §4.
2. Basemap: dependency-free SVG projection, or approve a map library + tile provider — §5.

**Also required before this can be called done:** a session with working verification tooling, and a
legitimately authenticated browser with enough real distribution data to exercise §18's ten
scenarios. Neither exists now.

**Good news worth repeating:** §1 and §7 — the parts that looked like the hardest requirements — are
already implemented correctly. The bulk of the remaining work is deleting one circle, adding a hull
function, and wiring an endpoint that already exists.
