# TASK-DISTRIBUTION-UNASSIGNED-ZONES-VISIBILITY-001 — IMPLEMENTATION REPORT

**Date:** 2026-08-24 · **Branch:** `develop` · No commit. No deploy. No migration. **Read-side only.**

> # IMPLEMENTED / FOCUSED VERIFIED
>
> **BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT.** Not certified.
> No STOP condition fired. No mutation endpoint, no new lifecycle state, no live data changed.

---

## 1. Executive Summary

**5 live Orders were invisible to every screen. They are now visible, each with its root blocker.**

| Order | Status | Warehouse | Zone | Blocker |
| --- | --- | --- | --- | --- |
| ORD-00013 | `in_progress` | **NULL** | DZ-0003 | **WAREHOUSE UNASSIGNED** |
| ORD-00014 | `in_progress` | **NULL** | DZ-0003 | **WAREHOUSE UNASSIGNED** |
| ORD-00007 | `ready_for_dispatch` | set | DZ-0009 | ZONE NOT IN GROUP |
| ORD-00010 | `ready_for_dispatch` | set | DZ-0008 | ZONE NOT IN GROUP |
| ORD-00001 | `ready_for_dispatch` | set | — | ZONE NOT IN GROUP *(secondary: `address_incomplete`)* |

Two corrections to the brief's premise, both established from live data before building:

1. **It is 5 Orders, not 4.** ORD-00001 has no zone at all — it can never be zoned because its city
   is NULL — and it was equally invisible.
2. **The eligibility predicate named in §2 would have hidden 3 of the 5.** `fulfilmentEligible()` is
   `[in_progress, confirmed]`; ORD-00001, ORD-00007 and ORD-00010 are all `ready_for_dispatch`. I
   used the Distribution read service's own predicate instead — see §3, the single deviation.

**§4 honoured exactly:** ORD-00013/14 are in an uncovered zone **and** have no warehouse, and they
report **WAREHOUSE UNASSIGNED** — the blocker that must be cleared first — appearing in one bucket
only, never duplicated.

---

## 2. Existing Surface Used

**The Groups tab of Distribution Planning.** No new module, no new tab, no Trips page.

The section renders below the Group cards in `distribution-groups-panel.tsx`, so the operator sees
*Group Planning* and *Orders Awaiting Group Assignment* on one board — Group stays the primary
concept and Trip is never surfaced as a step.

**Why not the existing "Unassigned" sub-tab:** it shows orders with `zone_id === null` only. Four of
the five have a zone, so that bucket structurally could not hold them. It answers a narrower
question and was left untouched.

---

## 3. Canonical Eligibility Contract

**No new eligibility predicate was created.** The Order set comes from
`DistributionAggregationService::orders($windowId)` — the same call the Groups board and Finalize
use, carrying the same `constrainToLoadingEligible` predicate.

**The one deviation from the brief, stated plainly.** §2 named `OrderStatus::fulfilmentEligible()`.
That predicate is `[in_progress, confirmed]`, while every Distribution/Group read uses
`constrainToLoadingEligible` = the same plus **`ready_for_dispatch`**. Live counts:

```
window orders with no Group:  in_progress 2 · confirmed 0 · ready_for_dispatch 3
```

Using the narrower predicate would have surfaced **2 of 5** and hidden ORD-00001, ORD-00007 and
ORD-00010 — and would have produced a set that disagrees with the Group counts on the same screen,
which is the inconsistency this workstream exists to remove. I used the existing Distribution read
service, which §2 also names. **Neither choice introduces a predicate; this one keeps the numbers
reconcilable.**

---

## 4. Classification Rules

Decided **server-side**; the frontend never infers a blocker from a missing field. Each Order lands
in exactly one bucket — the most actionable — and anything else true about it travels as
`secondary_reason`.

```
warehouse IS NULL                          -> warehouse_unassigned
zone IS NULL or zone not attached to a
  Group in this Window                     -> zone_not_in_group
warehouse + zone + zone IS in a Group,
  yet still no slot                        -> awaiting_group_assignment   (not expected; reported)
outside the Distribution eligibility set   -> never reaches the classifier
```

`awaiting_group_assignment` exists so an ingestion gap would surface rather than hide. Live count: 0.

**No new lifecycle state.** The three blockers are per-request transport discriminators derived from
existing state — the Order's warehouse, its Zone, and this Window's `distribution_slot_zones` links.
Nothing is persisted; no Order status, Group status or column was added.

**The existing zone-level classifier is reused, not replaced.**
`DistributionAggregationService::unassignedReason()` answers *"why is there no Zone"* — a different
question, and it returns null whenever a zone exists (which is exactly why ORD-00013/14 looked fine
before). It is carried through unchanged as `secondary_reason`.

---

## 5. Warehouse-Unassigned

**ORD-00013, ORD-00014** — `in_progress`, zone DZ-0003, `assigned_warehouse_id = NULL`.

They were invisible for a structural reason: a Group is warehouse-scoped
(`distribution_virtual_slots.warehouse_id` NOT NULL) and every Group aggregate filters on
`orders.assigned_warehouse_id = <warehouse>`, which a NULL never matches. That is a certified
invariant (`DistributionGroupWarehouseOwnershipTest::test_orders_with_no_warehouse_never_join_a_group`),
so they could not enter a Group even if DZ-0003 were attached to one.

**They report WAREHOUSE UNASSIGNED, not ZONE NOT IN GROUP** — §4's explicit requirement, asserted by
`test_a_warehouse_less_order_in_an_uncovered_zone_reads_as_warehouse_unassigned`.

**Deliberate design point:** warehouse-NULL Orders are returned **even when a warehouse is supplied**.
They belong to no warehouse, so a warehouse filter would drop precisely the rows needing attention —
the defect itself. Warehouse-*set* Orders of another warehouse are excluded. Both asserted.

---

## 6. Zone-Not-in-Group

**ORD-00007 (DZ-0009), ORD-00010 (DZ-0008), ORD-00001 (no zone).**

Live zone → Group coverage in the operational window:

| Zone | Group | Window orders |
| --- | --- | --- |
| DZ-0002 | DG-001 | 5 |
| DZ-0007 | DG-001 | 2 |
| DZ-0003 | **NONE** | 2 |
| DZ-0008 | **NONE** | 1 |
| DZ-0009 | **NONE** | 1 |

Three of five zones holding live work belong to no Group. Nothing said so before.

ORD-00001 has no zone at all and carries `secondary_reason: address_incomplete` from the existing
classifier — its city is NULL, so it is unzoneable until the address is fixed.

---

## 7. Eligible-Awaiting-Group

`awaiting_group_assignment` — warehouse present, zone present, zone attached to a Group, yet no slot.
**Live count: 0.** Kept because its appearance would mean an ingestion gap, and silence would be
worse than a bucket that is usually empty.

---

## 8. UI Changes

A single amber card at the foot of the Groups board, only rendered when the count is non-zero:

```
⚠ Orders awaiting group assignment (5)
These orders are eligible for distribution but no group covers them, so nothing is planning them.

[All 5] [Warehouse unassigned 2] [Zone not in a group 3]

ORD-00013  in_progress  unpaid   [Warehouse assignment required]
           Zone: DZ-0003 · Warehouse: Unassigned · 199.11
ORD-00007  ready_for_dispatch    [Zone not assigned to a group]
           Obour City · Cairo · Zone: DZ-0009 · Warehouse: … 
ORD-00001  ready_for_dispatch    [Zone not assigned to a group]
           Zone: Unassigned · Also: address_incomplete
```

Existing components only (`Card`, `Badge`, `Button`) — no new visual framework. **Cards, not a table:**
nine fields per row is unreadable at mobile width, and this sits inside an already dense board.

**Trip is not mentioned anywhere in this surface.** No Trips page was created.

i18n: 11 new keys in both locales, **parity 2155/2155**, every Arabic value verified as Arabic script,
no hardcoded strings.

---

## 9. Filters

Four, per §6: **All · Warehouse unassigned · Zone not in a group · Awaiting group**, each with its
count, rendered as existing `Button` variants. Empty buckets are hidden so the operator is not
offered a filter that yields nothing.

**No new filter framework.** The server returns every row plus the counts in one response, so
narrowing is a local view of one fetch rather than a second query.

---

## 10. API / Read Model

**`GET /logistics/distribution/windows/{window}/awaiting-group`** — a READ, guarded by the existing
`permission:logistics.distribution.view`. **No new permission. No mutation endpoint. No new source of
truth.**

**Why a dedicated read rather than an extension** (§14): `GET /windows/current` is consumed by five
tabs and shares its Trip presenter with `finalizeGroup`; the per-slot reconciliation read answers a
different question (one Group vs its Trip). This asks a Window-wide question, so extending either
would have overloaded a certified payload.

Response: `{ summary: {total, warehouse_unassigned, zone_not_in_group, awaiting_group_assignment},
orders: [...] }` with order number, status, customer, value, payment state and method, products
count, city, governorate, zone, warehouse (id + name), `blocker`, `secondary_reason`.

---

## 11. Performance

**Two queries total, no N+1.**

1. `DistributionAggregationService::orders($windowId)` — the existing aggregate, already eager-joined
   for customer, city, governorate, warehouse name and product counts.
2. One `pluck` of this Window's `distribution_slot_zones`, `flip()`ped into a set so the
   zone-coverage test is an O(1) lookup per Order rather than a query per Order.

No `orders` table scan and no PHP-side filtering of unbounded data: the aggregate is already
window-scoped and eligibility-filtered server-side. The payload also needed **no** change to the
aggregation — `warehouse_id` and `warehouse_name` were already exposed.

---

## 12. Tenancy

Company scoping is inherited, not reinvented: the Window is resolved through the controller's
existing tenant helper, so a foreign company reading the same window id gets **404**, asserted by
`test_another_companys_orders_never_appear`. Warehouse narrowing is applied on top for warehouse-set
Orders. **No new tenancy mechanism.**

---

## 13. Tests

`OrdersAwaitingGroupVisibilityTest` — **11 tests, 105 assertions, all green.**

| # | Required | Test |
| --- | --- | --- |
| 1 | Warehouse-unassigned Order appears | `test_a_warehouse_less_order_in_an_uncovered_zone_reads_as_warehouse_unassigned` |
| 2 | Zone-not-in-Group Order appears | `test_an_order_in_an_uncovered_zone_is_visible_as_zone_not_in_group` |
| 3 | Eligible Order awaiting Group appears | bucket implemented + counted; live 0, partition asserted in `test_the_summary_counts_each_order_in_exactly_one_bucket` |
| 4 | Ineligible not misclassified | `test_an_ineligible_order_is_not_classified_as_awaiting_a_group` |
| 5 | ORD-00013/14 shape → Warehouse Unassigned | test 1 above reproduces it exactly; live payload confirms (§15) |
| 6 | DZ-0008 / DZ-0009 orders visible with correct reason | test 2 reproduces the shape; live payload confirms both |
| 7 | Orders already in a Group do not appear | `test_an_order_already_in_a_group_does_not_appear` |
| 8 | Another company's Orders do not appear | `test_another_companys_orders_never_appear` (404) |
| 9 | No Group mutated | `test_the_read_mutates_nothing` |
| 10 | No Trip mutated | same test — trips and manifest counts |
| 11 | ORD-00007 unchanged | §16 live check |
| 12 | ORD-00017 unchanged | §16 live check |
| 13 | No Window created by the read | `test_the_read_mutates_nothing` |
| 14 | Existing contracts unchanged | no certified test modified; no existing source behaviour altered |

Extra rows: an unzoneable Order is visible with the existing secondary reason · the view permission is
enforced (403) · a warehouse-scoped read still includes warehouse-null Orders · a warehouse-scoped
read excludes another warehouse's Order.

**No existing certified test was modified.**

---

## 14. Static Checks

`php -l` clean · **Pint PASS** · **PHPStan `[OK] No errors`** · **ESLint clean** on all 4 frontend
files · `tsc -p tsconfig.app.json` **23 errors — identical to baseline, none in any file I touched** ·
**i18n parity 2155/2155** with all new Arabic values in Arabic script.

Full regression deliberately not run.

---

## 15. Browser Verification

> ### BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT

The UI requires an interactive login; authentication was not bypassed.

Verified through the real HTTP stack against the live operational window, **GET only** — the exact
payload the section renders:

```
summary: total 5 · warehouse_unassigned 2 · zone_not_in_group 3 · awaiting_group_assignment 0

ORD-00001  ready_for_dispatch  zone=None  wh=set   blocker=zone_not_in_group     secondary=address_incomplete
ORD-00007  ready_for_dispatch  zone=9     wh=set   blocker=zone_not_in_group     secondary=None
ORD-00010  ready_for_dispatch  zone=8     wh=set   blocker=zone_not_in_group     secondary=None
ORD-00013  in_progress         zone=3     wh=NULL  blocker=warehouse_unassigned  secondary=None
ORD-00014  in_progress         zone=3     wh=NULL  blocker=warehouse_unassigned  secondary=None
```

**ORD-00013 and ORD-00014 classify as `warehouse_unassigned`** — not silently displayed as ordinary
Group orders, and not mislabelled as a zone problem. Identical output with and without
`warehouse_id`, confirming warehouse-null Orders survive a warehouse-scoped read. Neither was
mutated.

---

## 16. Data Safety

Before/after identical, `max(updated_at)` unchanged on every table this task reads:

| Table | rows | `max(updated_at)` |
| --- | --- | --- |
| orders | 19 | 2026-08-24 05:00:01 |
| distribution_windows | 4 | 2026-08-23 02:37:21 |
| distribution_virtual_slots | 3 | 2026-08-23 01:07:17 |
| distribution_slot_zones | 3 | 2026-08-23 01:07:18 |
| distribution_window_orders | 13 | 2026-08-23 02:56:54 |
| distribution_trips | 2 | 2026-08-23 01:07:36 |
| distribution_trip_orders | 4 | 2026-08-23 01:07:36 |

Overflow approvals: **0**. No warehouse assigned, no zone assigned, no Group created or changed, no
Trip created or changed, no Order status changed, no Loading session opened, no driver or vehicle
assigned. Tests ran against `ecos_dev_test` under `RefreshDatabase`. The one live write was a Sanctum
token for read-only verification, **revoked**.

---

## 17. Legacy Records

- **ORD-00007** — untouched: `virtual_slot_id` still NULL, still on TRP-001's manifest. It now appears
  in this surface as `zone_not_in_group`, which is truthful — its zone really is in no Group. **No
  automatic repair**, and its Trip-drift exception remains separately surfaced by TASK-1-B.
- **ORD-00017** — untouched: `awaiting_payment`, `updated_at` still 2026-08-22 21:50:05. It has no
  distribution assignment at all, so it correctly does **not** appear here.
- **The 2026-08-21 window remains operational** — no window created, none deleted; it is still the
  window the active wave anchors to.
- **Group → Trip snapshot contract untouched** — nothing re-synced.

---

## 18. Remaining Gaps

1. **No resolution action from this surface, by design.** §7 makes it read-only, so there is no
   "assign warehouse" or "attach zone" control. The blockers have existing manual workflows
   (`BranchAssignmentEngine` for warehouse; zone attach on the Groups board), but a deep-link was not
   added because the warehouse path has no operator-facing surface at all — the Warehouse-Unassigned
   bucket is still an open item from an earlier Q4 ruling.
2. **The zone-coverage gap is shown per Order, not per Zone.** An operator sees "3 orders in
   uncovered zones" but not "DZ-0008 has work and no Group" as a zone-level statement. A Zones-tab
   badge would complete it.
3. **`awaiting_group_assignment` is unexercised** — live count 0, and no fixture produces it because
   it would require an ingestion gap. Implemented and counted, not demonstrated.
4. **Pre-existing, untouched:** ORD-00001's NULL city and ORD-00017's unbound `logistics_city_id`
   remain data gaps; neither is caused by this task.

---

## 19. Recommended Next Task

**Surface the gap at the Zone level** — a badge on the Zones tab reading "no Group" for DZ-0003,
DZ-0008 and DZ-0009. It closes gap 2 with data this task already returns, is read-side only, and lets
the operator fix the cause (attach the zone) rather than triaging the symptom order by order.

Secondary, and needing an owner decision: give the **Warehouse-Unassigned** blocker a resolution
path, since that bucket still has no operator surface anywhere.

---

> ## STATUS: IMPLEMENTED / FOCUSED VERIFIED
>
> 5 previously invisible live Orders are now surfaced with their root blocker, on the Groups board,
> with Trip never exposed as a step. Read-side only: no mutation endpoint, no new eligibility
> predicate, no new lifecycle state, no migration, no live data mutated, ORD-00007 and ORD-00017
> untouched. 11 tests / 105 assertions green. Browser not verified — authentication constraint.
> Not certified. No commit. No deploy.
