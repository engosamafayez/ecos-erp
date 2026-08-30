# TASK-DISTRIBUTION-ZONE-GROUP-CONFIGURATION-VISIBILITY-001 — IMPLEMENTATION REPORT

**Date:** 2026-08-24 · **Branch:** `develop` · No commit. No deploy. No migration. **Read-side only.**

> # IMPLEMENTED / FOCUSED VERIFIED
>
> **BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT.** Not certified.
> No STOP condition fired. No mutation endpoint, no new eligibility predicate, no new
> lifecycle state, no live data changed, no Trip file touched.

---

## 1. Executive Summary

**The root cause is now visible at the level where it can actually be fixed. Three Zones hold live
work and belong to no Group:**

| Zone | Orders waiting | Also need warehouse | Context |
| --- | --- | --- | --- |
| **Giza** (DZ-0003, id 3) | **2** | **2** | Giza |
| **Helwan** (DZ-0008, id 8) | 1 | 0 | Cairo · Main Warehouse |
| **Obour** (DZ-0009, id 9) | 1 | 0 | Cairo · Main Warehouse |

Attaching one Zone to a Group clears every Order behind it — whereas the order-level surface built
in the previous task invited triaging the same problem five separate times. The Zone section is
rendered **above** the order list: cause before symptom.

**Three requirements that needed care, and how each was met:**

- **§5** — DZ-0003's *only* two Orders have no warehouse. The Zone still appears, and carries
  `orders_needing_warehouse: 2` so the UI states both blockers separately instead of collapsing them.
- **§7** — ORD-00001 has no Zone. It produces **no** Zone row: a Zone that does not exist cannot be
  configured, and inventing one would offer an action leading nowhere. It stays visible in the
  order-level list. The arithmetic proves it: **4 zone-level + 1 zone-less = 5** order-level.
- **§6** — ORD-00007 is included, because the canonical predicate includes it. It was **not**
  special-cased out and no legacy status was invented.

---

## 2. Existing Surface

**The Groups board** (`distribution-groups-panel.tsx`) — the same surface the order-level exception
uses. No new module, no new tab, no Trips navigation entry.

**No new endpoint either.** The zone rollup was added to the existing
`GET /windows/{window}/awaiting-group` read, because it is the same question at a different grain
from the same rows. That guarantees the two views cannot disagree: if the order list says 2 Orders in
DZ-0003, the Zone card says 2.

**Why not `zoneSummaries()`**, the obvious candidate: it applies the narrower `constrainToEligible`
predicate and is warehouse-scoped, so it would drop the three `ready_for_dispatch` Orders **and**
every warehouse-null Order — between them the entire population this surface exists to show.

---

## 3. Zone Classification

Decided **server-side** in `DistributionWindowController::zonesWithoutGroup()`. The frontend never
infers state from `group_id === null` or any other raw relationship.

The rollup **folds the array the caller already built** — the classified Orders awaiting a Group — so
it adds no query, cannot drift from the order list, and inherits the same canonical predicate the
Groups board and Finalize use. **No new eligibility predicate and no second aggregation.**

Per Zone it reports: `zone_id`, `zone_name`, `orders_waiting`, `orders_needing_warehouse`,
`governorates[]`, `warehouses[]`.

- Only Zones with **real operational demand** appear (≥1 waiting Order) — never the whole zone table.
- `governorates` is a distinct list, because a Zone spans cities; reporting one address would imply
  otherwise.
- Sorted **busiest first**: the Zone blocking the most work is the one worth configuring next.

---

## 4. Live Zones Found

Verified over real HTTP, GET only, against the live operational window:

```
Giza      waiting=2  needing_warehouse=2  govs=[Giza]   whs=[]
Helwan    waiting=1  needing_warehouse=0  govs=[Cairo]  whs=[Main Warehouse]
Obour     waiting=1  needing_warehouse=0  govs=[Cairo]  whs=[Main Warehouse]

zone totals = 4   ·   order-level total = 5   ·   difference = 1 zone-less order
```

Identical with and without `warehouse_id`, confirming that warehouse-null Orders survive a
warehouse-scoped read — the point of §5.

---

## 5. Warehouse Blockers

`orders_needing_warehouse` counts the waiting Orders in that Zone whose **root** blocker is the
missing warehouse — reusing the order-level classification, not recomputing it.

**Two blockers, never merged.** "No Group" and "these Orders also have no warehouse" are different
problems with different fixes, so the card states both:

```
Giza                    [No group assigned]
Orders waiting: 2
Giza
⚠ 2 of these orders also require warehouse assignment.
Attach this zone from a group's "Manage zones and orders" panel above.
```

DZ-0003 is the case that would previously have vanished twice over — once for having no warehouse,
once for its Zone belonging to no Group. **No warehouse was assigned automatically.**

---

## 6. ORD-00001

Has no Zone (its city is NULL, so it is unzoneable). It contributes **no Zone row** — no fake Zone,
no synthetic bucket — and remains visible in the order-level surface from the previous task with
`secondary_reason: address_incomplete`. Asserted by `test_a_zoneless_order_creates_no_zone_row` and by
the reconciliation test.

## 7. ORD-00007

**Not mutated.** `virtual_slot_id` still NULL, still on TRP-001's manifest, `updated_at` unchanged.

It is counted under **Obour (DZ-0009)**, which is truthful: its Zone really is attached to no Group.
Per §6 the canonical Distribution predicate includes it (`ready_for_dispatch`), so it was **not**
filtered out and **no legacy status was invented** to hide it. Its separate Trip-drift exception
remains where TASK-1-B put it.

## 8. ORD-00017

**Not mutated** — `awaiting_payment`, `updated_at` still 2026-08-22 21:50:05. It has no distribution
assignment at all, so it correctly appears in neither the Zone list nor the order list.

---

## 9. UI Changes

A single amber card at the foot of the Groups board, **above** the order-level section:

```
⚠ Zones without a group (3)
These zones hold orders but belong to no group, so nothing is planning them.
Attaching a zone to a group clears every order behind it.

[All 3] [Zones without group 2] [Warehouse assignment required 1]

┌─────────────────────────────┬─────────────────────────────┐
│ Giza          [No group]    │ Helwan        [No group]    │
│ Orders waiting: 2           │ Orders waiting: 1           │
│ Giza                        │ Cairo · Main Warehouse      │
│ ⚠ 2 also require warehouse  │ Attach this zone from a     │
│ Attach this zone from …     │ group's "Manage zones…"     │
└─────────────────────────────┴─────────────────────────────┘
```

**All five §15 states implemented:** (A) `"All active zones are currently covered by groups."` said
explicitly rather than rendering nothing, so the operator knows the check ran; (B) the list;
(C) the warehouse blocker as its own destructive line, distinct from "No group"; (D) existing
`Skeleton` loading pattern; (E) existing destructive-`Card` error pattern.

Existing components only (`Card`, `Badge`, `Button`, `Skeleton`) — no new visual framework. **Cards in
a 2-column responsive grid, not a table**, so it stays readable on mobile inside an already dense
board.

**Zero Trip vocabulary**, verified by inspection: 0 occurrences of "trip" in the backend rollup, 0 in
the `ZonesWithoutGroup` component, 0 in the new i18n block.

---

## 10. Filters

Three, per §12, each with its count, as existing `Button` variants:

| Filter | Meaning |
| --- | --- |
| **All** | every uncovered Zone |
| **Zones without group** | Zones whose *only* blocker is the missing Group (`orders_needing_warehouse === 0`) |
| **Warehouse assignment required** | Zones with at least one Order also lacking a warehouse |

Made mutually meaningful deliberately: every Zone in the list is "without group" by construction, so
that filter would have equalled *All* if taken literally. Empty buckets are hidden. **No new filter
framework** — the server returns every row plus counts in one response, so narrowing is a local view
of one fetch.

---

## 11. Operator Actions

**No new mutation endpoint, and no new action.** Attaching a Zone to a Group is an existing operator
workflow on this very board — each Group's *"Manage zones and orders"* panel
(`POST /windows/{w}/slots/{s}/zones` → `ManualAssignmentService::assignZoneToSlot`) — so the card
points at it rather than adding a second way to do the same thing.

Explicitly **not** implemented: no automatic Group creation, no automatic Zone attachment, no
automatic Order movement, no automatic warehouse assignment, no automatic defer. Capacity is
untouched — no capacity raised, nulled, or overflow approved.

---

## 12. API / Read Model

Extended the existing `GET /logistics/distribution/windows/{window}/awaiting-group`
(`permission:logistics.distribution.view`) with an additive `zones` key. **No new endpoint, no new
permission, no new source of truth, no mutation.** The existing `summary` and `orders` keys are
unchanged, so the previous task's consumer is unaffected.

---

## 13. Performance

**No additional query.** The rollup is an in-memory fold of the order rows the endpoint already
built, so the endpoint remains at **two queries total**: the existing eager-joined aggregate, plus one
`pluck` of this Window's `distribution_slot_zones` flipped into a set for O(1) coverage lookups.

Nothing loads the whole `orders` or `distribution_zones` table: the aggregate is window-scoped and
eligibility-filtered server-side.

---

## 14. Tenancy

Inherited, not reinvented — the Window is resolved through the controller's existing tenant helper,
so a foreign company reading the same window id gets **404**. Asserted at the zone grain by
`test_the_zone_rollup_is_company_scoped`. Warehouse narrowing applies on top for warehouse-set
Orders. **No new tenancy mechanism.**

---

## 15. i18n

11 new keys under `distributionWorkspace.zonesWithoutGroup`, in **both** locales.
**Parity exact: 2166 / 2166.** Every new Arabic value verified as Arabic script. No hardcoded
user-facing strings. Existing `logistics` namespace reused — no new namespace registered.

---

## 16. Tests

`OrdersAwaitingGroupVisibilityTest` — **20 tests, 181 assertions, all green** (11 from the previous
task, 9 added here).

| # | Required | Test |
| --- | --- | --- |
| 1 | Zone with relevant Orders and no Group appears | `test_a_zone_with_uncovered_orders_appears_with_its_order_count` |
| 2 | Zone with no relevant Orders does not appear | `test_a_zone_with_no_relevant_orders_does_not_appear`, `test_a_covered_zone_does_not_appear` |
| 3 | DZ-0003 shape appears | `test_a_zone_whose_orders_all_lack_a_warehouse_still_appears` + live §4 |
| 4 | DZ-0008 appears | live §4; shape covered by test 1 |
| 5 | DZ-0009 appears under the canonical predicate | live §4 — it does, and was not special-cased |
| 6 | Warehouse-null Orders do not hide the Zone | `test_a_zone_whose_orders_all_lack_a_warehouse_still_appears` |
| 7 | Warehouse-null shown as a secondary blocker | same, plus `test_a_zone_reports_partial_warehouse_blockers` |
| 8 | ORD-00001 creates no fake Zone | `test_a_zoneless_order_creates_no_zone_row` |
| 9 | ORD-00007 not mutated | §18 live check |
| 10 | ORD-00017 not mutated | §18 live check |
| 11 | Group counts unchanged | `test_the_read_mutates_nothing` |
| 12 | Trip counts unchanged | same test |
| 13 | No Trip created | same test |
| 14 | No Group mutated | same test (slot + slot_zones counts, membership map) |
| 15 | No Order moved | same test (membership and zone maps compared) |
| 16 | Company tenancy enforced | `test_the_zone_rollup_is_company_scoped` (404) |
| 17 | No Window created by a read | `test_the_read_mutates_nothing` |
| 18 | Existing contracts unchanged | no certified test modified; no Trip file touched |

Extra: `test_the_zone_rollup_reconciles_with_the_order_list` proves the two grains partition the set
exactly (zone totals + zone-less == order total), and
`test_zones_are_ordered_by_how_much_work_they_block` pins busiest-first.

**One fixture of mine was wrong and I fixed the fixture, not the implementation:** the ordering test
created its second uncovered Zone and City mid-test, which the collector had not yet bound, so only
one Zone appeared. Both uncovered Zones now come from `setUp`, removing the timing dependency.

**No certified test was modified.**

---

## 17. Browser Verification

> ### BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT

The UI requires an interactive login; authentication was not bypassed and no live data was modified
to demonstrate the UI.

Verified through the real HTTP stack instead — the exact payload the section renders (§4), both
company-wide and warehouse-scoped, with identical results. **No Trip field appears in the zone
payload** (keys: `zone_id`, `zone_name`, `orders_waiting`, `orders_needing_warehouse`,
`governorates`, `warehouses`) and no automatic Order movement occurred (§18).

---

## 18. Data Safety

Before/after identical, `max(updated_at)` unchanged on every business table:

| Table | rows | `max(updated_at)` |
| --- | --- | --- |
| orders | 19 | 2026-08-24 05:00:01 |
| distribution_windows | 4 | 2026-08-23 02:37:21 |
| distribution_virtual_slots | 3 | 2026-08-23 01:07:17 |
| distribution_slot_zones | 3 | 2026-08-23 01:07:18 |
| distribution_window_orders | 13 | 2026-08-23 02:56:54 |
| distribution_trips | 2 | 2026-08-23 01:07:36 |
| distribution_trip_orders | 4 | 2026-08-23 01:07:36 |

**ORD-00007** `virtual_slot_id = NULL`, unchanged · **ORD-00017** `awaiting_payment`, `updated_at`
2026-08-22 21:50:05, unchanged · **2026-08-21 window** untouched and still operational.

Zero Order, Group, Trip, Window, capacity or overflow-approval mutations. Tests ran against
`ecos_dev_test` under `RefreshDatabase`. The one live write was a Sanctum token for read-only
verification, **revoked**.

**§17 verified by mtime:** `TripService.php` (08-21 19:41) and `Trip.php` (08-21 19:39) untouched.
`GroupFinalizationService.php` (09:58) and `GroupLoadingContextService.php` (10:57) carry today's
timestamps but were written by the *previous* two tasks — this task began ~12:40 and touched neither.

**Static checks:** `php -l` clean · **Pint PASS** · **PHPStan `[OK] No errors`** · **ESLint clean** ·
`tsc -p tsconfig.app.json` **23 errors, identical to baseline, none in any file I touched** ·
**i18n parity 2166/2166**.

---

## 19. Remaining Gaps

1. **No resolution action from the Zone card, by design.** §8/§9 make this visibility-only, so the
   card points at the existing on-board *"Manage zones and orders"* workflow in prose rather than
   deep-linking into a specific Group's panel — there is no per-Group anchor to link to, and adding
   one would be a UI change beyond this scope.
2. **The warehouse blocker still has no resolution path anywhere.** DZ-0003's two Orders need a
   warehouse, and the Warehouse-Unassigned bucket remains without an operator surface — an open item
   from the earlier Q4 ruling, unchanged by this task.
3. **`governorates` and `warehouses` are derived from the waiting Orders**, not from the Zone's own
   configuration. For a Zone with no waiting Orders that would be empty — which cannot occur here,
   since such a Zone is not listed at all.
4. **Zone coverage is reported only for the resolved planning window.** A Zone uncovered in a
   different window is not surfaced; the operator works one cycle at a time, so that matches the
   board, but it is a scope limit rather than a total view.
5. **Pre-existing, untouched:** ORD-00001's NULL city and ORD-00017's unbound `logistics_city_id`.

---

> ## STATUS: IMPLEMENTED / FOCUSED VERIFIED
>
> Three Zones holding live work and belonging to no Group are now visible on the Groups board, with
> the warehouse blocker stated separately, busiest first, and no Trip vocabulary anywhere.
> Read-side only: no mutation endpoint, no new eligibility predicate, no new lifecycle state, no
> migration, no automatic movement, no capacity change, no live data mutated, ORD-00007 and ORD-00017
> untouched. 20 tests / 181 assertions green. Browser not verified — authentication constraint.
> Not certified. No commit. No deploy.
