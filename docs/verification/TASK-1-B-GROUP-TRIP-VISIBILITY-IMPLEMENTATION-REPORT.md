# TASK-1-B — GROUP ↔ TRIP VISIBILITY — IMPLEMENTATION REPORT

**Date:** 2026-08-24 · **Branch:** `develop` · No commit. No deploy. No migration.

> # IMPLEMENTED / FOCUSED VERIFIED
> **BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT** (§13)
>
> **No automatic Group → Trip re-synchronization was implemented.**

---

## 1. Objective

Make the Group ↔ Trip relationship and its exceptions **visible** instead of silently hiding the
difference. Live DG-001 showed 7 Group orders against a 3-order Trip with nothing on any screen
explaining why. This task reports the difference; it never closes it.

---

## 2. Contract Preserved

**No automatic Group → Trip re-synchronization was implemented.** Finalize was not modified.

| Contract | Status |
| --- | --- |
| `distribution_trip_orders` is an execution manifest; Group membership stays in `distribution_window_orders.virtual_slot_id` | Unchanged — both read from their existing owners |
| Finalize creates the canonical manifest and is idempotent | **Unchanged.** `GroupFinalizationService` not touched |
| `DistributionGroupTripTest::test_finalize_creates_the_canonical_trip_and_is_idempotent` | **Passes. The test method is untouched by this task** — verified: 0 references to anything TASK-1-B added. The class did gain an `ensureTodayWindow()` fixture helper, but in **TASK-1-A** (:432, called at :459), which that task reported separately |
| `test_trip_capacity_forces_a_split_and_never_duplicates_an_order` | **Passes, unmodified** |
| Trip capacity independent of Group capacity | Unchanged — live `60,60` vs `20,20,20`, and a test now pins the independence |
| Group ownership guard in `TripService::assignOrder` | Unchanged, and re-asserted by a new test |
| Group identity · Group→Trip ownership · Loading Preparation · Vehicle/Driver identity · assignment ledger · Preparation Wave · eligibility predicates | All untouched |

No new membership engine, source of truth, status, table, migration, capacity engine or quantity
engine. **No mutation endpoint was created.**

---

## 3. Files Changed

**Backend (2)**

| File | Change |
| --- | --- |
| `Distribution/Presentation/Http/Controllers/DistributionWindowController.php` | New **read** `groupReconciliation()`; `presentGroupTrips()` now exposes `remaining_capacity`; `TripStatus` imported |
| `routes/api.php` | `GET /windows/{window}/slots/{slot}/reconciliation`, guarded by the existing `permission:logistics.distribution.view` |

**Frontend (5)** — `types/index.ts` (`GroupTripReconciliation`; `GroupTrip.remaining_capacity`) ·
`services/distribution-workspace-service.ts` (`getGroupReconciliation`) ·
`hooks/use-distribution-workspace.ts` (`useGroupReconciliation`) ·
`components/group-trip-panel.tsx` (difference summary, unassigned list, exceptions, remaining
capacity) · `en/logistics.json` + `ar/logistics.json` (9 new keys).

**Tests (1 new)** — `tests/Feature/Logistics/GroupTripReconciliationVisibilityTest.php`, 10 tests.

**Not changed by this task, stated because `git diff` shows them dirty:**
`TripService.php` carries +25 uncommitted lines and `GroupFinalizationService.php` is also dirty —
both were last written on **2026-08-21** by an earlier workstream. Verified: zero lines of mine in
`TripService` (0 additions matching this task's identifiers). The two services that own Finalize and
Trip membership were not modified here.

**A separate read endpoint, deliberately.** Extending `groupTrips` would have changed a payload that
`finalizeGroup` also returns and that two components consume, so the certified `GroupTrip[]`
contract stays exactly as it is. Only *mutation* endpoints were prohibited.

---

## 4. UI Changes

Inside the existing Group Transport panel, using existing components (`Card`, `Badge`, `Field`,
the panel's own layout) — no new visual framework:

```
Group orders   Trip orders   Not on a trip
     7              3              5

TRP-001   3/60 orders · Remaining 57 · loading · Vehicle — · Driver —

Orders not assigned to a trip (5)
These orders belong to this group but are on none of its trips…
  ORD-00009  ready_for_dispatch  unpaid   OSAMA FAYEZ AHEMD · Nasr City · Cairo   718.55
  …4 more

⚠ 1 order(s) require attention
  ORD-00007 · TRP-001
  Assigned to this trip but no longer a member of the group.
  Nothing was changed automatically. Resolve this on the trip itself.
```

The exceptions block renders **only when an exception exists**, so its presence carries meaning.
There is deliberately **no control** in it — resolving an exception is an operator decision taken on
the Trip through the existing membership endpoints.

**Responsive:** the unassigned list is cards, not a table. It sits two levels inside an already
nested panel, where a seven-column grid would be unreadable on mobile; each card wraps
identifier/status/payment on one line and customer/geography/value on the next.

**i18n:** 9 new keys in **both** locales — parity exact at **2122/2122**, every new Arabic value
verified as Arabic script, no hardcoded strings.

---

## 5. Data Sources

| Displayed | Source | Owner |
| --- | --- | --- |
| Group orders + the member rows | `DistributionAggregationService::orders(window, null, slot, warehouse)` — the same call **Finalize itself** uses, under the same loading-eligible predicate | Group |
| Trip orders, capacity, status, vehicle, driver | `distribution_trips` + `distribution_trip_orders` via the existing `presentGroupTrips()` | Trip |
| Remaining capacity | `Trip::remainingCapacity()` — the Trip's own existing method, exposed not reimplemented | Trip |
| Group capacity triplet | `slotSummaries()` (already rendered on the Group card from TASK-1-A) | Group |

**No new eligibility predicate.** Group membership is read through the canonical aggregate, so the
count here always equals the count on the Group card. No order appears merely for being
fulfilment-eligible: it must be a member of **this** Group.

---

## 6. Group/Trip Calculation

Both differences are computed **server-side**; the client derives no membership of its own.

```
members  = canonical Group member ids (loading-eligible)
manifest = distribution_trip_orders of this Group's NON-CANCELLED trips

unassigned_orders = members  −  manifest
exceptions        = manifest −  members
```

**It is not `group_orders − trip_orders`, and the brief's expected "4 unassigned" is where that
shows.** On live DG-001 the subtraction gives 7 − 3 = 4, but the true answer is **5 unassigned and 1
exception** — because ORD-00007 occupies a manifest slot while no longer being a member. Two
independent sets require two independent differences; subtraction silently conflates them. This is
precisely the confusion the feature exists to remove, so I implemented the set difference and am
flagging the discrepancy rather than reproducing the expected number.

**Cancelled Trips are excluded**, matching Finalize's own idempotency read: a Group whose only Trip
was cancelled holds no live execution, so its former manifest must not make its orders look assigned.

---

## 7. Unassigned Orders

Group members present in no manifest of that Group. Rendered **as supplied** by the canonical order
aggregate: order number, customer, city / governorate, value, payment state, order status.

Live DG-001 — the five `DZ-0002` orders that joined 63 minutes after Finalize:

```
ORD-00009  ready_for_dispatch  unpaid  Nasr City/Cairo  718.55
ORD-00012  ready_for_dispatch  unpaid  Nasr City/Cairo  199.11
ORD-00016  ready_for_dispatch  unpaid  Nasr City/Cairo  199.11
ORD-00018  ready_for_dispatch  unpaid  Nasr City/Cairo  199.11
ORD-00019  ready_for_dispatch  unpaid  Nasr City/Cairo  199.11
```

They are **not** added to the Trip. That is deliberate.

---

## 8. Trip Exceptions

Manifest rows whose order is no longer a Group member. Live DG-001 returns exactly one:

```
ORD-00007 on TRP-001 · assignment_type=auto · status=ready_for_dispatch
```

`assignment_type = auto` is carried through because it is diagnostic: it proves the row was written
by Finalize, so the order **was** a legitimate member and later left — not an incorrect write. (Its
cause is recorded on the order itself: *"City changed from [Maadi] to [Obour City]; zone
re-resolved."*)

**Not removed. Not moved. Not repaired.** Visibility and diagnosis only.

---

## 9. Capacity Handling

Trip capacity was not changed and is not derived from Group capacity. Live: trips `60,60`, groups
`20,20,20` — identical before and after.

The panel now shows each Trip's `capacity` **and** `remaining_capacity`, both server-computed, so
the screen and `assignOrder`'s capacity refusal cannot disagree. A new test pins the independence
directly (`test_trip_capacity_is_independent_of_group_capacity`), and the existing split test passes
unmodified.

---

## 10. Finalize Contract

`GroupFinalizationService` was **not modified**. Finalize still creates the canonical manifest once,
returns existing Trips on retry, and re-syncs nothing.

`test_finalize_remains_idempotent_across_a_reconciliation_read` additionally proves that reading the
difference does not disturb idempotency: finalize → read → finalize yields the same trip number, one
Trip and an unchanged manifest row count.

The pre-existing `DistributionGroupTripTest::test_finalize_creates_the_canonical_trip_and_is_idempotent`
passes, and **this task did not touch it** — its method body contains no reference to anything added
here. Stated precisely, because the file's timestamp is today: that class gained an
`ensureTodayWindow()` fixture helper during **TASK-1-A** (reported there), and no assertion in it was
changed by either task.

---

## 11. Ownership Guard

`TripService::assignOrder`'s guard — a Group-owned Trip may carry only its own Group's orders — is
untouched and re-asserted: `test_the_group_ownership_guard_still_refuses_a_foreign_order` posts a
non-member to the Trip and expects **422**, with the manifest count unchanged.

Nothing bypasses Group, company, or Trip ownership: the new read resolves window and slot through
the controller's existing `window()` / `slot()` tenant helpers, and Trips are filtered by
`virtual_slot_id`.

---

## 12. Focused Verification

`GroupTripReconciliationVisibilityTest` — **10 tests, 115 assertions, all green.**

| Required check | Test |
| --- | --- |
| 1. Group order count shown | `test_a_finalized_group_reports_matching_counts_and_no_difference` |
| 2. Trip order count shown | same, and `test_each_trip_reports_its_own_capacity_and_remaining_capacity` |
| 3. Unassigned correctly identified | `test_an_order_joining_after_finalize_is_reported_unassigned_and_never_added` |
| 4. Exception displayed | `test_an_order_leaving_the_group_becomes_an_exception_and_is_never_removed` |
| 5. Finalize remains idempotent | `test_finalize_remains_idempotent_across_a_reconciliation_read` |
| 6. Ownership guard enforced | `test_the_group_ownership_guard_still_refuses_a_foreign_order` |
| 7. Trip capacity independent | `test_trip_capacity_is_independent_of_group_capacity` |
| 8. No automatic re-sync | manifest counts asserted before/after in tests 3, 4 and 5 |
| 9. No business data mutated | `test_the_reconciliation_read_mutates_nothing` — 9 tables plus the full membership map compared before/after two reads |

Plus `test_the_difference_is_a_set_difference_not_a_subtraction`, which reproduces the live DG-001
shape (members off-manifest **and** manifest rows off-group at once) and asserts the answer is *not*
`group_orders − trip_orders`; and `test_the_reconciliation_read_requires_the_view_permission` (403
for an unprivileged actor).

**Contract suites re-run, unmodified: 39 tests / 449 assertions green** —
`DistributionGroupTripTest` (Finalize idempotency, split, ownership, tenancy) +
`DistributionGroupManagementTest` + the new class.

**Static gates:** Pint PASS · PHPStan `[OK] No errors` · ESLint clean on all 4 changed frontend
files · `tsc -p tsconfig.app.json` **23 errors, identical to baseline, none in
distribution-workspace**.

Full regression deliberately not run, per instruction.

---

## 13. Browser Verification

> ### BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT

The UI requires an interactive login; no authenticated session was available and authentication was
not bypassed.

**Verified instead through the real HTTP stack against live DG-001 — the exact payload the panel
renders, GET only:**

```
SUMMARY: group_orders=7  trip_orders=3  unassigned_orders=5  exception_orders=1
TRIPS:   TRP-001  3/60 orders  remaining=57  status=loading  vehicle=—  driver=—
UNASSIGNED (5): ORD-00009, ORD-00012, ORD-00016, ORD-00018, ORD-00019
EXCEPTIONS (1): ORD-00007 on TRP-001  type=auto  status=ready_for_dispatch
```

This matches the expected conceptual result, with the one correction in §6: **5 unassigned, not 4**,
and ORD-00007 shown clearly as the exception.

---

## 14. Data Safety

Live state identical before and after:

| | Value |
| --- | --- |
| orders / groups / assignments | **19 / 3 / 13** |
| trips / manifest rows | **2 / 4** |
| windows / slot_zones | **4 / 3** |
| trip capacities | **60,60** |
| group capacities | **20,20,20** |
| vehicles / drivers / loading sessions | **0 / 0 / 0** |

No Group, Trip, order, membership, capacity, vehicle, driver or Loading row was created or modified.
No business data was mutated. Tests ran against `ecos_dev_test` under `RefreshDatabase`. The one
live write was a Sanctum token for read-only verification, **revoked**.

---

## 15. Explicit Non-Goals

Not implemented and not to be inferred: **automatic Group → Trip re-synchronization** · any change
to Finalize · any change to Trip capacity or to Group capacity · a new mutation endpoint · a new
membership engine, source of truth, status, table, migration, capacity engine or quantity engine ·
any change to Group identity, Group→Trip ownership, Loading Preparation, Vehicle/Driver identity,
the assignment ledger, Preparation Wave, Distribution eligibility or Fulfillment eligibility · any
repair of the live DG-001 or ORD-00007 rows.

---

## 16. Remaining Issues

1. **The §13 policy decision from the TASK-1-B audit is still open** — *which Group-side operations
   must consult Trip state*. This task implements option **B1** (make the divergence visible) and
   leaves B2/B3 available; it forecloses nothing.
2. **No UI action to resolve an exception.** The endpoints exist (`POST`/`DELETE`
   `/trips/{id}/orders`, `POST /trips/{id}/orders/move`, all `logistics.distribution.update`, all
   gated on `isEditable()`), but this task was told not to create a mutation endpoint and did not
   wire them. Doing so needs its own scope — and the Trips workspace still has **no navigation
   entry**, so the existing add/remove/move UI remains unreachable.
3. **Group-side edits are still not blocked after Finalize** — unchanged by design, and now visible
   rather than silent.
4. **Unverified in this environment:** Vehicle/Driver display in the trip rows renders `—` because
   there are zero vehicles, zero drivers and zero pairings live.

---

> ## STATUS: IMPLEMENTED / FOCUSED VERIFIED
>
> **No automatic Group → Trip re-synchronization was implemented.** Finalize is unmodified, its
> idempotency test passes untouched, Trip capacity is unchanged and independent, the ownership guard
> is re-asserted, and no business data was mutated. Browser verification unavailable —
> **BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT**. Not certified.
>
> No commit. No deploy.
