# TASK-1-B-FINAL — GROUP OVERFLOW DECISION + MANUAL REALLOCATION + TRIP INTEGRITY

**Date:** 2026-08-24 · **Branch:** `develop` · No commit. No deploy. **No migration.**

> # IMPLEMENTED / BLOCKED — OWNER DECISION REQUIRED
>
> **No automatic Group → Trip synchronization was implemented.**
> **Orders are not automatically moved or deferred when Group capacity is exceeded.**
>
> Everything unblocked is built, tested and live-verified. **Two of your own STOP conditions
> fired** and I stopped on them rather than working around them: **Approve Overflow** (§5) and
> **atomic multi-order move** (§6). Not certified.

---

## 1. Business Contract

```
ORDER → DISTRIBUTION GROUP → CAPACITY DECISION → TRIP → VEHICLE + DRIVER → LOADING → DELIVERY
```

Group capacity is a **planning** capacity. Orders may enter a Group that temporarily exceeds it.
Over capacity, the system presents the overflow and the operator decides — **A. Approve Overflow**
or **B. Review / manually move**. Nothing is moved, deferred, or auto-created. Until the decision is
made the excess must not reach Trip / Vehicle / Loading.

---

## 2. Previous TASK-1-B Correction

The previous UI showed one label — *"Orders not assigned to a trip"* — which presented a diagnostic
difference as a normal operational destination. **That framing is gone.** The same raw difference now
resolves to one of four server-decided states:

| State | Meaning |
| --- | --- |
| `capacity_decision_required` | Over planning capacity — Finalize will refuse |
| `awaiting_finalization` | Within capacity, no Trip yet — Finalize is next |
| `added_after_finalization` | Finalized, but members joined afterwards — **action required** |
| `resolved` | Every member is on a Trip |

Plus a separate **Trip integrity** section for a manifest row that left its Group.

`state` is derived **server-side** per request from membership, capacity and the Trip list.
`distribution_virtual_slots` gains **no status column** — nothing is persisted, so no second state
machine exists.

---

## 3. Files Changed

**Backend (2)**

| File | Change |
| --- | --- |
| `Distribution/Presentation/Http/Controllers/DistributionWindowController.php` | `state` + `capacity` block on the reconciliation read; 4 state constants |
| `Distribution/Domain/Services/GroupLoadingContextService.php` | **new fail-closed guard** `assertManifestStillBelongsToGroup()`, called from `open()` |

**Frontend (4)** — `types/index.ts` (`GroupTripState`, `capacity` block) ·
`components/group-trip-panel.tsx` (state-driven panel, per-order move, integrity removal) ·
`components/distribution-groups-panel.tsx` (passes sibling Groups) ·
`en/logistics.json` + `ar/logistics.json` (16 new keys).

**Tests** — `GroupTripReconciliationVisibilityTest.php` extended to **19 tests**.

**Not changed:** `GroupFinalizationService`, `TripService`, `GroupCapacityGuard`,
`ManualAssignmentService`, any migration, any capacity value, `DistributionGroupTripTest`.

---

## 4. Overflow Detection

Derived, never stored:

```php
$overflow = ($capacity === null || count($members) <= $capacity) ? 0 : count($members) - $capacity;
```

`capacity_orders` is read exactly as `GroupCapacityGuard` and Finalize read it — **NULL means
unconstrained, never zero**. The payload carries `{maximum, current, remaining, overflow}` so the
client renders rather than recomputes.

Overflow is reachable because `GroupCapacityGuard` deliberately does **not** police automatic
ingestion — in its own words, refusing there *"would start silently dropping work out of
Distribution"* because the global unique on `order_id` makes a refused assignment unretryable. That
behaviour is load-bearing and was not touched.

Live DG-001: `maximum 20, current 7, overflow 0` → **`added_after_finalization`, not an overflow
case.** Its 5 unassigned orders joined 63 minutes after Finalize; treating that as a capacity problem
would misdiagnose it.

---

## 5. Overflow Approval — 🛑 **BLOCKED, STOP CONDITION FIRED**

**"Approve Overflow requires a new schema field not covered by an existing contract."**

Every candidate was audited and none can express *"capacity stays 20, 25 accepted"*:

| Candidate | Verdict |
| --- | --- |
| A status/approval column on the Group | **Does not exist.** `distribution_virtual_slots` has 12 columns, none of them. The read model returns the literal `'draft'`, commented as deliberate |
| An approval event | **Does not exist.** All 7 Distribution events are assignment-change, late-order, or trip-lifecycle |
| `capacity_orders = 25` | Would work mechanically — and **§2 forbids it**, correctly: it changes the planning limit instead of approving an exception against it |
| `capacity_orders = NULL` | Same objection, worse — removes the limit entirely |
| `capacity_stops` / `_weight_kg` / `_volume_m3` | Contractually excluded (order-count only, D4-C); reusing one would smuggle in a new business rule |

**So the "Approve Overflow & Finalize" button was not built.** Rendering a button that cannot work,
or wiring it to a capacity mutation, would both violate §2. The overflow UI therefore offers the one
resolution that exists — move orders out — and states the situation honestly.

**Minimum owner decision required.** Four shapes, none implemented:

- **A1 — derived-only.** No approval concept; Finalize keeps refusing; resolutions stay *raise the
  maximum* or *move orders out*. Zero new schema. Loses "approved exception" as a concept.
- **A2 — new nullable approval columns** (e.g. `overflow_approved_at` / `_by` / `_count`) +
  `assertFinalizable` consulting them. Expresses the contract exactly. **Requires a migration**, which
  is a declared STOP for this task.
- **A3 — event-only approval**, no column; `assertFinalizable` consults event history. No schema
  change, but makes Finalize's decision depend on events, which no current guard does.
- **A4 — raise the limit and record why** in an existing free-text field. Cheapest; still changes the
  planning capacity, so it contradicts your §2.

**My recommendation: A2 if the approval must be auditable and durable — it is the only shape that
matches the contract as written — otherwise A1.** A2 needs your explicit authorisation because it
requires a migration.

---

## 6. Manual Move — implemented single-order; 🛑 **batch atomicity BLOCKED**

**Reused, not duplicated:** `PATCH /logistics/distribution/assignments/{assignment}/slot` →
`DistributionWindowController::changeSlot` → `ManualAssignmentService::changeOrderSlot()`. The
frontend hook `useMoveOrderToSlot()` already existed and is reused as-is.

Already enforced by that service, unchanged: same operational window (`:285`) · destination capacity
under a row lock (`:303` `assertHasHeadroom`) · company/tenant scope via the controller's resolvers ·
warehouse ownership through the Group's own NOT-NULL `warehouse_id` · audit via
`DistributionAssignmentChanged` carrying the previous slot (`:313`).

**What is built:** each order needing a decision shows the window's other Groups as explicit
destination buttons, each displaying its own occupancy (`demand_orders / capacity_orders`). One click
= one order = one atomic call. **The destination is never chosen automatically.** Groups already at
their maximum are still listed with their occupancy visible, so the operator sees *why* one is
unusable rather than finding it absent.

**What is NOT built, and why — your §3 STOP:** *"If atomic multi-order movement is required but the
current API cannot guarantee it: STOP and report the gap instead of creating unsafe partial
behaviour."* `changeOrderSlot` is atomic **per order**; there is no batch endpoint and no transaction
spanning several orders. A five-order multi-select could therefore half-succeed with no rollback. I
did not build the multi-select drawer.

**Minimum owner decision:** accept **sequential single-order moves with per-order feedback** (built,
safe, no new API), or authorise a **batch endpoint** wrapping N moves in one transaction (a new
mutation, which §5 of the previous task forbade without authorisation).

---

## 7. Group → Trip Invariant

**Before Finalize** — a Group may hold orders awaiting finalization → `awaiting_finalization`.

**Over capacity** — Finalize is refused by `GroupFinalizationService::assertFinalizable()`, so **no
Trip exists**, so nothing reaches Vehicle, Driver or Loading. Test:
`test_finalize_is_refused_while_the_group_is_over_capacity` asserts 0 Trips and 0 manifest rows.
**The §7 safety principle holds today, through the existing gate.**

**After Finalize** — an order arriving later is **not** silently added. It surfaces as
*"Added after finalization — action required"* with the existing operator routes. Test:
`test_a_member_joining_after_finalize_is_added_after_finalization`.

**Honest limitation:** *"after Finalize, no accepted Group order may remain outside the Trip"* is not
yet a hard invariant. Making it one requires a controlled synchronisation point, and Finalize must not
re-sync (§11). What is delivered is that the state is now an **action item with actions**, never a
resting label.

---

## 8. Trip Capacity

Unchanged and independent. Live: trips `60,60`, groups `20,20,20` — identical before and after. The
panel shows each Trip's `capacity` and `remaining_capacity`, both server-computed
(`Trip::remainingCapacity()`). `test_trip_capacity_is_independent_of_group_capacity` pins it; the
existing split test passes unmodified. 25 approved orders would fit one Trip (25 ≤ 60); beyond 60 the
existing fill-then-overflow split applies, and no order is ever duplicated
(`orderAlreadyOnAnotherTrip` under `lockForUpdate`).

---

## 9. Trip Integrity

A manifest row whose order left its Group is surfaced as **Trip integrity**, with the **existing**
removal action only — `DELETE /trips/{trip}/orders/{orderId}` via the pre-existing
`useRemoveTripOrder` hook. No new correction endpoint. Nothing is removed, moved or repaired
automatically. The server refuses removal once the Trip leaves `Loading`, which is the existing
editability contract.

---

## 10. Loading Guard — **implemented, fail-closed**

`GroupLoadingContextService::assertManifestStillBelongsToGroup()`, called from `open()` **before any
row is created**.

**Why the existing add-time guard was insufficient:** `TripService::assignOrder()` already refuses a
non-member — **on add only**. The manifest is a snapshot, so an order can leave afterwards and the row
stays. Nothing then stopped it travelling into Loading.

**The check uses the same predicate as the add-time guard** —
`distribution_window_orders.virtual_slot_id = trip.virtual_slot_id` — so add-time and load-time can
never disagree about membership. **No new source of truth and no new eligibility engine:** it asks
about Group membership, not order status.

**Fails closed:** any offending row refuses the whole open, naming the order numbers. It repairs
nothing. `DistributionException extends RuntimeException`, so the controller's existing
`FleetAssignmentException|RuntimeException` catch renders it as **422**.

Verified by `test_loading_is_refused_when_the_trip_carries_a_non_member`: 422, message contains
*"no longer members"*, manifest count unchanged, `loading_sessions` and `vehicle_assignments` both 0.

---

## 11. Finalize Contract

`GroupFinalizationService` was **not modified**. Finalize did not need to change: the overflow gate it
already has *is* the mechanism that keeps the excess out of Trip/Vehicle/Loading.
`DistributionGroupTripTest` passes with **12 tests / 140 assertions**, unmodified — including
`test_finalize_creates_the_canonical_trip_and_is_idempotent` and the split test. No hidden re-sync
was added; `test_finalize_remains_idempotent_across_a_reconciliation_read` proves reading the state
does not disturb it.

---

## 12. ORD-00007 Findings *(not modified)*

| | |
| --- | --- |
| **Current state** | `status = ready_for_dispatch`, `city = Obour City`, `virtual_slot_id = NULL`, `distribution_zone_id = 9`, on TRP-001's manifest as `assignment_type = auto` |
| **Why it is an integrity violation** | It is a manifest member of a Trip whose Group it does not belong to. It entered legitimately at Finalize (`assigned_at` = TRP-001's `finalized_at`), then a city correction re-resolved its zone to `DZ-0009`, which no Group holds |
| **Existing safe correction** | `DELETE /trips/{trip}/orders/{orderId}` → `TripService::removeOrder()`, `logistics.distribution.update`, gated on `isEditable()`. TRP-001 is `loading`, so it is available. **Now surfaced in the UI as the only offered action** |
| **Is Loading protected?** | **It was not. It is now** (§10). Before this task the row would have travelled into Loading unguarded |

No live correction was performed.

---

## 13. UI Changes

```
Group capacity
Current: 7      Maximum: 20     Remaining: 13
Added after finalization — action required

TRP-001   3/60 orders · Remaining 57 · loading · Vehicle — · Driver —

Added after finalization — action required (5)
These orders joined the group after its trip was finalized…
  ORD-00009  ready_for_dispatch  unpaid   Nasr City · Cairo   718.55
     Move to  [DG-003 1/20]  [DG-TPL-VERIFY 0/20]
  …4 more

⚠ Trip integrity — 1 order(s) require attention
  ORD-00007 · TRP-001
  Assigned to this trip but no longer a member of the group.
  [Remove from trip]
```

For an over-capacity Group the third capacity cell becomes **Overflow +N** and the status reads
**"Capacity decision required"** in destructive styling, with the heading *"Overflow orders (N)"*.

Existing components only (`Card`, `Badge`, `Button`, the panel's `Field`) — no new visual framework.
Unassigned orders are **cards, not a table**: this sits two levels inside a nested panel where a
multi-column grid is unreadable at mobile width. i18n: 16 new keys, **parity 2138/2138**, every Arabic
value verified as Arabic script, no hardcoded strings.

---

## 14. API / Service Reuse

| Need | Reused | New? |
| --- | --- | --- |
| Group ↔ Trip state | `GET .../slots/{slot}/reconciliation` (added in the previous task) | extended, not added |
| Move an order between Groups | `PATCH /assignments/{assignment}/slot` + `useMoveOrderToSlot()` | **none** |
| Remove a stray from a Trip | `DELETE /trips/{trip}/orders/{orderId}` + `useRemoveTripOrder()` | **none** |
| Destination Groups + occupancy | `slotSummaries` already held by the workspace, passed down as `siblings` | **none** — no second read that could disagree with the board |
| Finalize | `POST .../finalize` | unchanged |

**No new mutation endpoint. No new permission** — every route reused carries
`logistics.distribution.update`; the read carries `logistics.distribution.view`.

---

## 15. Focused Verification

`GroupTripReconciliationVisibilityTest` — **19 tests, 201 assertions, all green.**
With `DistributionGroupTripTest`: **31 tests, 341 assertions, all green.**

| # | Required check | Test |
| --- | --- | --- |
| 1 | Within capacity → Finalize creates Trip with all accepted orders | `test_a_finalized_group_reports_matching_counts_and_no_difference`, `..._is_resolved` |
| 2 | Over capacity → Finalize blocked until decision | `test_finalize_is_refused_while_the_group_is_over_capacity` (0 Trips, 0 manifest rows) |
| 3 | Approve Overflow → Finalize succeeds | **NOT TESTABLE — blocked (§5).** No approval representation exists |
| 4 | Capacity unchanged after approval | Partially: `test_a_group_over_capacity_requires_a_capacity_decision` asserts `capacity_orders` is still 1 after detection |
| 5 | Manual move → selected order moves | `test_an_operator_can_move_one_overflow_order_to_a_chosen_group` |
| 6 | Destination capacity enforced | `test_a_move_into_a_full_destination_is_refused` (422, order stays put) |
| 7 | No automatic destination selection | Destinations are explicit buttons; no default. `test_overflow_moves_defers_and_creates_nothing_automatically` |
| 8 | No automatic move | same test — membership map identical before/after two reads |
| 9 | No automatic defer | same test |
| 10 | Post-Finalize order not silently added | `test_an_order_joining_after_finalize_is_reported_unassigned_and_never_added`, `..._is_added_after_finalization` |
| 11 | Trip cannot retain an order outside its Group | `test_an_order_leaving_the_group_becomes_an_exception_and_is_never_removed` (surfaced) + §10 (blocked at Loading) |
| 12 | Loading rejects membership violation | `test_loading_is_refused_when_the_trip_carries_a_non_member` |
| 13 | Finalize idempotent | `test_finalize_remains_idempotent_across_a_reconciliation_read` + `DistributionGroupTripTest` unmodified |
| 14 | No duplicate manifest rows | `orderAlreadyOnAnotherTrip` under lock; manifest counts asserted throughout |
| 15 | Company/tenant boundaries | `test_the_reconciliation_read_requires_the_view_permission`; `DistributionGroupTripTest` tenancy tests pass |

**Static gates:** Pint PASS (0 objections on my code; 3 pre-existing style diffs in
`GroupLoadingContextService`) · PHPStan `[OK] No errors` · ESLint clean ·
`tsc` **23 errors — identical to baseline, none in my files**.

Full regression deliberately not run, per instruction.

---

## 16. Browser Verification

> ### BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT

The UI requires an interactive login; authentication was not bypassed.

Verified instead through the real HTTP stack against live DG-001, **GET only** — the exact payload the
panel renders:

```
STATE   : added_after_finalization
CAPACITY: maximum 20 · current 7 · remaining 13 · overflow 0
SUMMARY : group_orders 7 · trip_orders 3 · unassigned 5 · exceptions 1
TRIPS   : TRP-001  3/60  remaining 57
EXCEPTION: ORD-00007 on TRP-001
```

**I deliberately did not exercise the Loading guard on live data** — `openGroupLoading` is a POST that
creates a loading session, which §14 forbids. It is proven by test instead.

---

## 17. Data Safety

Live state identical before and after: **19 orders · 3 groups · 2 trips · 4 manifest rows ·
0 loading sessions · group capacities `20,20,20` · trip capacities `60,60` · ORD-00007
`slot=NULL zone=9`**.

No order moved, no Group finalized, no Trip created, nothing deleted, no vehicle or driver assigned,
no inventory loaded, no capacity altered. Tests ran against `ecos_dev_test` under `RefreshDatabase`.
The one live write was a Sanctum token for read-only verification, **revoked**.

---

## 18. Explicit Non-Goals

**No automatic Group → Trip synchronization was implemented.**
**Orders are not automatically moved or deferred when Group capacity is exceeded.**

Also not done: no automatic destination selection, deferral, Group creation or Trip creation · no
change to Finalize, its idempotency, or its tests · no change to Group or Trip capacity values · no
new Order status, Group status, membership engine, source of truth, table or **migration** · no new
mutation endpoint or permission · no change to Preparation Wave, Vehicle/Driver assignment, Map or
Templates · no redesign of Loading and no new eligibility engine · no mutation of live data, and
specifically no modification of ORD-00007.

---

## 19. Remaining Gaps

1. **🛑 Approve Overflow — no safe representation (§5).** Owner decision A1–A4. Until then an
   over-capacity Group's only resolution is to move orders out or raise its maximum.
2. **🛑 Atomic multi-order move (§6).** Single-order moves are atomic and built; a multi-select batch
   cannot be guaranteed atomic by the existing API.
3. **"Every accepted Group order has a Trip" is not yet a hard invariant** (§7) — it needs a
   controlled synchronisation point that is not Finalize.
4. **Adding a late order to its Trip is not offered in this panel.** `POST /trips/{id}/orders` and
   `useAddTripOrder` both exist, but the Trips workspace still has **no navigation entry**, so that
   route is practically unreachable. The panel points the operator there in copy only.
5. **Pre-existing, untouched:** the Loading endpoint's broad `RuntimeException` catch means a
   database fault still reads as a business rejection; `distribution_virtual_slots` still carries the
   three non-contract capacity columns.

---

## 20. Next Task

Blocked on the two decisions above. Once ruled:

- If **A2**: implement the approval field + `assertFinalizable` consultation + the
  "Approve Overflow & Finalize" action. Requires migration authorisation.
- If **A1**: relabel the overflow action set to the two real resolutions and close item 1.
- Independently and unblocked: give the Trips workspace a navigation entry, which makes the existing
  add/remove/move UI reachable and closes gap 4.

---

> ## STATUS: IMPLEMENTED / BLOCKED — OWNER DECISION REQUIRED
>
> **No automatic Group → Trip synchronization was implemented.**
> **Orders are not automatically moved or deferred when Group capacity is exceeded.**
>
> Loading guard, state discriminator, overflow detection, single-order manual move and trip-integrity
> removal are implemented and focused-verified (31 tests / 341 assertions). Approve Overflow and
> atomic batch move are **blocked on owner decisions**. Browser not verified — authentication
> constraint. Not certified. No commit. No deploy.
