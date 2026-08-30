# TASK-DISTRIBUTION-MAP-LOCATION-UX-003 — REPORT

**Status: IMPLEMENTED / BROWSER VERIFICATION PENDING**
One part stopped on its own, per §28: **address geocoding (PRIORITY 2)** — no provider exists
and adding one needs authorization. Everything else is implemented.

Date: 2026-08-24 · Branch: `develop` · Not committed, not deployed
**Backend changed: NO. Migration: NONE.**

---

## 1. What discovery changed about the scope

Three requirements were **already satisfied** and needed no code — found by inspecting
first, as §27 required:

| Requirement | Already true because |
|---|---|
| AC-11 canonical Drawer pattern | `DistributionOrderDetail` is already a thin adapter to the enterprise `OrderDetailDrawer`; its docblock states Distribution must not own an order-detail implementation |
| AC-12 full address in Order Detail | that drawer already renders governorate, city, street, building, floor, apartment, landmark and address notes, plus a composed one-line address |
| AC-14 phone interactive | `MapOrderPanel` already shows phone, address, city and governorate, and already opens the canonical drawer |

So **§18 resolved to "no backend change"**: every field needed was already in the canonical
payload. No read-model addition, no endpoint, no migration.

---

## 2. Exact behaviour implemented

### §6 — overlap (AC-06, AC-07)

Three **named Leaflet panes** with declared z-index: zones 410, pins 450, selected 460.

This is a real fix, not a tidy-up. Every vector layer on Leaflet's default `overlayPane`
shares one SVG root, so what ended up on top was whatever was appended last — it worked
only because the draw effect happened to add polygons before pins. Any reordering would
have put a zone fill over the pins inside it, which is precisely the reported overlap.
Zone fill opacity also stays light when selected, and pins now live on a pane above it.

Labels are selective: hover shows one tooltip, and only the **selected** order keeps its
label pinned. Nothing else gets a permanent label.

### §7 — collisions (AC-04, AC-05)

`fanOutColocated()` spaces co-located orders evenly around a small circle (~5 m). Only
genuine collisions move — a lone order stays on its exact captured coordinate, so the
ordinary case remains truthful, and the returned `nudged` flag says which pins were
displaced. Deterministic by input order, so pins do not reshuffle under the cursor.

No library was added; this is plain geometry over the existing `circleMarker` rendering.

### §11–13 — compact Zone Orders strip (AC-08, AC-09, AC-10)

`ZoneOrdersStrip`, directly below the map, visible **only** while a zone is selected.
Chips, not a grid: order number + customer, capped at 12 with `+ N more`. Clicking one
selects it, pans the map and highlights the pin — it deliberately does **not** open the
drawer, because locating an order and reading its record are different intentions and a
drawer over the map would fight the first. The drawer is one further click.

Orders in the zone with no coordinates still appear, marked `· no pin`, so the count
agrees with the list and clicking says why nothing moved.

### §14 — no-location panel

Same visual pattern, kept. The chips are now **buttons** that open the canonical drawer —
an order with no pin is the one most worth opening, since the reason lives in its address
fields.

### §15 — selection

`selectedOrderId` is threaded to the map: larger pin, own pane, pinned label. Clearing
the selection removes the zone highlight and the strip together.

### §16 — search

Unchanged and still functional: order number, customer, city, zone name and phone.

---

## 3. Geocoding provider used

**None. This part is stopped.**

- `GoogleMapsUrlResolver` exists, but it parses coordinates out of a **Google Maps URL** —
  it cannot turn "2 Shalaby, Maadi, Cairo" into a point.
- I checked whether it could still help: **0 live orders have a `google_maps_url` with no
  coordinates**, so URL extraction would resolve nothing here.
- No geocoding integration exists anywhere in the repository, and adding one means an
  external dependency plus a key or config entry — which §19 forbids without approval.

So `AddressGeocoder` is a declared seam with `REGISTERED_GEOCODER = null`, and a test
asserts that. The three-state priority order is real and **Priority 2 is visibly
unreachable** rather than hidden behind a stub that silently returns nothing forever.

**To unblock:** authorize a provider (an OSM/Nominatim usage policy, or a Google
Geocoding key), and the resolver's existing seam is where it plugs in. `buildAddressQuery()`
already composes the query the brief specified, narrow → wide, and is tested.

---

## 4. How unresolved addresses are handled (AC-03)

`UNRESOLVED` receives **no coordinates, ever**. An invented pin is indistinguishable from
a real delivery address on a map and someone would drive to it, so the layer refuses even
a half-recorded coordinate rather than guessing the other half.

`buildAddressQuery()` also refuses two shapes the brief called out:

- a locality alone — **"Maadi" is not an address**; it would match a district centroid and
  drop a pin that looks exact and is not;
- a street with no locality — unplaceable on its own.

`isAddressResolvable()` separates *"no address on file"* from *"address on file, nothing to
resolve it with"* — two different operator problems. On live data that is 8 orders with a
usable address awaiting a provider, and 2 with effectively nothing recorded.

---

## 5. §5 location indicator — partial, and why

The three-state model exists in the resolution layer and is asserted by tests, and the UI
distinguishes the two **reachable** states: a pin, versus `· no pin` in the strip and
membership of the no-location panel.

A literal "GPS / Address matched" badge was **not** added, because the second value is
unreachable until a provider is authorized — a badge that always reads "GPS" is noise on a
surface this task exists to de-clutter. It belongs with the provider work.

---

## 6. Files changed

**Backend: none.**

| File | Change |
|---|---|
| `lib/order-location.ts` | **New** — resolution layer, `AddressGeocoder` seam, `buildAddressQuery`, `fanOutColocated` |
| `lib/order-location.test.ts` | **New** — 15 tests |
| `components/distribution-leaflet-map.tsx` | panes, `selectedOrderId`, selective labels, collision fan-out via the shared helper |
| `components/distribution-map-tab.tsx` | `ZoneOrdersStrip`, clickable no-location chips, `selectedOrderId` wiring |
| `i18n/locales/en/logistics.json`, `ar/logistics.json` | `map.zoneOrdersPanel` (4 keys each) |

The existing `map.zoneOrders` string was left intact — the new keys went under a distinct
`zoneOrdersPanel` object rather than shadowing it.

---

## 7. Test results

**Vitest: 15 / 15 passed** (`lib/order-location.test.ts`).

| §21 case | Covered |
|---|---|
| 1 Order with coordinates uses them | `uses captured coordinates when present` |
| 2 Full address composed for the resolver | `composes the full address a geocoder would need` |
| 3 Resolution succeeds → position | structurally unreachable — no provider (§3) |
| 4 Resolution fails → unresolved | `has no geocoding provider registered…` |
| 5 No address → no fake location | `never invents coordinates…`, `treats a half-recorded coordinate as unresolved…` |
| 6 Two orders same coordinates independently selectable | `separates two orders sharing a coordinate…` + 4 more |
| 11 Full address in Order Detail | pre-existing canonical drawer (§1) |
| 12 Existing GPS link works | untouched |
| 7–10, 13 | component/interaction level — see §11 |

**Static:** `tsc --noEmit -p tsconfig.app.json` → **23 errors, the pre-existing baseline,
NONE in `distribution-workspace`**. ESLint over the whole feature directory → clean.
i18n parity **2276 / 2276**, both new key sets translated.

`php -l` / Pint / PHPStan: **not applicable** — no backend file was touched.

**A correction worth recording:** mid-task the type-check read 41 then 45 errors and I
attributed the jump to another agent's concurrent edits. It returned to **23** on its own
with no action from me, which confirms that attribution — the extra errors were in
`operations` and `orders` files this task never opened.

---

## 8. Browser verification

**BROWSER VERIFICATION PENDING.**

Not performed, and not claimed. Exercising the zone strip and pin selection needs an
authenticated session; producing new pin arrangements would mean writing live order
coordinates, which §23 and §24 forbid. No live data was fabricated, and no Distribution
Window was reactivated or mutated.

Note for whoever runs it: **9 of 19 live orders have coordinates**, so the map has real
pins to exercise, and 10 sit in the no-location panel — enough to verify both surfaces
without creating anything.

---

## 9. Data safety snapshot (§24)

Identical before and after. Every `ecos_dev` query was a `SELECT`; the only writes in this
task were Vitest runs, which touch no database.

| Fact | Before | After |
|---|---|---|
| orders | 19 | 19 |
| distribution_windows | 4 | 4 |
| groups | 3 | 3 |
| zones | 10 | 10 |
| templates | 5 | 5 |
| trips | 2 | 2 |
| window_orders | 13 | 13 |
| orders with coordinates | 9 | 9 |
| orders without coordinates | 10 | 10 |

---

## 10. Untouched (§25)

Wave 1, Wave 2, Wave 3, Driver Home, Driver Loading, Driver Delivery, Driver Wallet,
Driver Reports, Templates, Template Driver Recommendations, Group assignment, Trip
assignment, Vehicle assignment, Loading, Finalize, Preparation, Reservation, Order
lifecycle, Zone → Group contract, Template → Group contract, and the Change-Zone backend
contract (AC-15).

Leaflet was not replaced, the zone geometry implementation was not replaced, and the Map
architecture was not rewritten. **No new engine and no duplicate Order source** were
introduced (AC-17, AC-18) — the strip, the panel and the drawer all read the canonical
window-order aggregate that every other Distribution surface already uses.

---

## 11. Remaining gaps

1. **Address geocoding (PRIORITY 2 / AC-02)** — stopped, needs a provider decision (§3).
2. **§5 source badge** — deferred with the provider work (§5).
3. **§21 cases 7–10 and 13** as component tests. The logic they cover is implemented and
   the pure parts are tested; asserting them end-to-end means rendering Leaflet under
   jsdom, which needs a map mock. `@testing-library/react` and jsdom are already
   configured, so this is straightforward to add — it was scoped out rather than faked.
4. **Browser walkthrough** (§8).

---

## Final Status

**IMPLEMENTED / BROWSER VERIFICATION PENDING**

Pin/zone stacking is now declared rather than accidental, co-located orders are each
independently clickable, the compact zone strip and clickable no-location chips exist, the
canonical drawer was reused rather than duplicated, and no order is ever given a
coordinate it does not have. No backend change, no migration, no new dependency, no live
data touched.

Address geocoding is the one stopped part and needs your provider decision. No other task
was started.
