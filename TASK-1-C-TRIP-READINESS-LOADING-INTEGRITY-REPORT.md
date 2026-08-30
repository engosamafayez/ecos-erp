# TASK-1-C — TRIP READINESS + LOADING INTEGRITY + GROUP/TRIP HANDOFF

**Status: IMPLEMENTED / FOCUSED VERIFIED**
**Browser: NOT VERIFIED — AUTHENTICATION CONSTRAINT**
Date: 2026-08-25 · Branch: `develop` · Not committed, not deployed
Focused: **18 / 18 green, 228 assertions** · Regression: **90 / 90 green, 765 assertions**
PHPStan: **No errors** · Migration: **none**

> **No automatic Trip/Group reconciliation was implemented.**
>
> **Loading fails closed when Trip/Group integrity is invalid.**

---

## 1. Objective

A Trip must not enter Loading with an internally invalid manifest. This task audited the
existing Loading boundary, added the two guards that were genuinely missing, and exposed
readiness as a read so the operator sees *why* Loading is blocked rather than meeting a
refusal at the end.

---

## 2. Existing Loading contract (audit first, per §2)

`GroupLoadingContextService::open()` already refused on:

| Guard | Covers |
|---|---|
| `assertConsistent()` — trip ↔ group, company | §1.1, §1.2 |
| `assertManifestStillBelongsToGroup()` — every Trip Order is still a Group member | §1.3, §1.5, §3 |
| warehouse resolvable | §1.7 prerequisite |
| `driverVehicleAssignment === null` → refuse | §1.7, §7 |

All four were **kept and none weakened**. No second Loading eligibility engine was created.

---

## 3. Trip readiness rules

Readiness is exposed by `GroupLoadingContextService::readiness()`, which **runs the very
guards `open()` runs** and catches their refusals:

```
trip_belongs_to_group · manifest_membership · manifest_complete
warehouse_resolved    · vehicle_assigned    · driver_assigned
```

A panel that computed readiness its own way would eventually disagree with the thing that
actually refuses — showing READY on a Trip that then fails. Running the real guards makes
that impossible by construction. The read writes nothing, so it can be polled freely, and
the keys are stable i18n identifiers, never class or column names (§8).

---

## 4. Group membership integrity (§3)

Already implemented in TASK-1-B-FINAL and preserved here: a Trip carrying an Order that has
left its Group fails closed, naming the offending order numbers. It **repairs nothing** —
removal stays an operator decision through the existing
`DELETE /trips/{trip}/orders/{orderId}`.

---

## 5. Group → Trip completeness (§4) — NEW

`assertManifestIsComplete()` checks the inverse direction: every accepted Group Order must
appear across the Group's Trips. A Group with 10 accepted Orders whose Trips carry 9 does
not load; the tenth would otherwise stay behind silently.

**The accepted set is Finalize's own** — the identical
`aggregation->orders(window, null, group, warehouse)` call `GroupFinalizationService` uses
to build manifests. This compares the plan against itself rather than inventing a second
definition of membership (§6, §14).

**Scoped to finalized Groups, and that scoping is the substance of a bug I shipped and
fixed.** My first version applied the check unconditionally and **blocked legitimate
Loading** — four previously-green tests began returning 422. The cause: a Trip can be
created by vehicle assignment, before Finalize, with an empty manifest. §4 states the rule
as *"**After Finalize**, every accepted Group Order must belong to Trip/Trips"* — the
contract begins at Finalize, so a Group whose Trips were never finalized has no manifest
contract to violate. Applying it earlier is not a stricter guard; it is a redesign of the
Loading contract, which §18 lists as a STOP condition.

Across all the Group's Trips, not just the one being opened — a Group split over Trip
capacity is complete only when its Trips are taken together.

---

## 6. Duplicate protection (§5) — enforced by the database, no guard added

**The scenario §5 describes is structurally impossible**, and the test that proves it is
more valuable than a guard would have been.

`distribution_trip_orders_order_unique` is a UNIQUE index on **`order_id` alone**, so an
Order belongs to at most one Trip *anywhere*, ever — not merely once per Group. A duplicate
manifest row cannot be inserted; the attempt raises a constraint violation before any
service-layer check would see it.

I wrote an `assertManifestHasNoDuplicates()` guard first. The test written to exercise it
could not construct the state, which proved the guard was **unreachable code**. It was
removed rather than kept as decoration: an unreachable guard is worse than an absent one,
because the next reader assumes it is protecting something. §5 anticipates exactly this —
*"use existing database constraints/services where available."*

In its place: a comment at the removal site naming what enforces the invariant, and
`test_the_manifest_forbids_the_same_order_twice`, which proves the constraint refuses the
duplicate. The invariant is verified where it actually lives.

---

## 7. Capacity (§6)

Untouched. Group capacity, Trip capacity and the split behaviour are exactly as they were;
no second capacity engine was introduced. The regression suites covering them stay green.

---

## 8. Vehicle / Driver requirement (§7)

The existing refusal on `driverVehicleAssignment === null` is preserved verbatim. No new
assignment mechanism and no separate Driver/Vehicle relationship were created.

Readiness reports `vehicle_assigned` and `driver_assigned` as two facts because the operator
reads them as two — both derived from the **same** pairing row, not from a second lookup
that could disagree with it.

---

## 9. Loading UI (§8, §11)

Readiness travels with the existing `GET .../windows/{window}/slots/{slot}/trips` payload —
the endpoint the Trip panel already calls. **No new endpoint** (§9), and no second Loading
surface that could drift from the first.

Payload per Trip: `{trip_id, ready, checks[{key, ok}], reason}`. The frontend maps each
`key` to existing i18n; `reason` carries the server's own sentence for the first failing
check — the one the operator has to deal with, since a wall of consequential errors
obscures it.

**The frontend panel itself was not built in this task.** The contract it needs is in place
and tested; wiring the checklist into the Trip panel is UI work that belongs with a UI
verification pass, and claiming it without browser verification would be hollow.

---

## 10. ORD-00007 evidence (§10)

**Not mutated, not deleted, not reassigned.** Verified read-only after all work:

```
ORD-00007  status=in_progress  virtual_slot_id=NULL  trips=1
```

Exactly the integrity exception described: present in a Trip, absent from that Trip's
Group. Its *shape* is reproduced on isolated fixtures by
`test_a_trip_order_outside_the_group_reports_blocked` and
`test_a_refused_open_creates_no_loading_session_and_repairs_nothing`, which prove the guard
protects Loading and that a refusal leaves the offending row in place.

---

## 11. Security / tenancy (§13)

Unchanged. Company scope, Trip ownership, Group ownership and Loading permissions are all
as before. `test_readiness_and_loading_are_company_scoped` asserts a foreign company gets
404 from both the readiness read and the Loading open.

**A test-construction bug worth recording:** my first version read

```php
$this->actingAs($foreign)->getJson(".../windows/{$this->windowId()}/...")
```

`windowId()` authenticates as company A to read the window, and it runs *during string
interpolation* — after `actingAs($foreign)`. The request executed as company A and returned
200, so the assertion was testing nothing. Resolving the id into a variable first fixes it.
A tenancy test that silently re-authenticates mid-URL passes or fails for reasons unrelated
to tenancy.

---

## 12. Files changed

| File | Change |
|---|---|
| `Domain/Services/GroupLoadingContextService.php` | `assertManifestIsComplete()`; `readiness()`; duplicate guard removed with a note explaining what enforces §5 |
| `Presentation/Http/Controllers/DistributionWindowController.php` | readiness folded into the existing `groupTrips` payload |
| `tests/Feature/Logistics/GroupTripLoadingIntegrationTest.php` | `finalizedGroup()` fixture + 8 new tests |

No migration. No new endpoint. No new status. No new source of truth.

---

## 13. Focused tests

**18 / 18 green, 228 assertions** (10 pre-existing + 8 new).

| §14 | Test |
|---|---|
| 1, 11 | `test_a_valid_trip_reports_ready_and_opens` |
| 2 | `test_a_group_without_a_vehicle_reports_blocked_and_refuses_to_open` |
| 3, 10 | `test_a_trip_order_outside_the_group_reports_blocked` |
| 4 | `test_loading_is_refused_when_a_trip_does_not_carry_every_accepted_order` |
| 5 | `test_the_manifest_forbids_the_same_order_twice` |
| 6, 7, 15 | `test_readiness_and_loading_are_company_scoped` |
| 12, 13 | `test_a_refused_open_creates_no_loading_session_and_repairs_nothing` |
| 8 | pre-existing `test_a_trip_belonging_to_another_group_is_rejected` |
| 9 | pre-existing `test_a_group_exceeding_the_vehicle_order_count_is_still_rejected` |
| 14 | regression, §14 below |

Plus `test_readiness_keys_expose_no_internals`, asserting the operator never sees a class or
column name.

### What these tests actually cost, and why that matters

Four iterations, each exposing a wrong assumption of mine about the Group → Trip → manifest
lifecycle. Only the first was a defect in shipped code; the rest were my fixtures. All five
share one root confusion — treating *"a Trip exists"* as equivalent to *"the manifest is
built"*, which is precisely the distinction §4 rests on.

1. **Completeness applied pre-Finalize** — blocked valid Loading. *Real defect.*
2. `readyGroup()` never finalizes, so no manifest exists.
3. A Group can hold **two** Trips; only one carries the manifest.
4. Finalize is idempotent — *"already finalized → return what exists"* — so assigning a
   vehicle first makes a later Finalize a no-op. The approved order is **Finalize, then
   Vehicle**, and the fixture now builds it that way.
5. Duplicates cannot occur; the database already guarantees it.

Each was caught only because the fixture asserts its own preconditions. Without
`assertGreaterThan(0, …)` on the manifest, this suite would have gone green while
exercising empty sets, and the completeness guard would have been reported as passing
without ever having fired.

---

## 14. Regression

`--filter "GroupTripReconciliationVisibilityTest|DistributionGroupTripTest|DistributionBatchMoveTest|DistributionDailyGroupWaveLifecycleTest"`

```
Tests: 90, Assertions: 765   ->   OK
```

**No regression.** The four suites cover the reconciliation states and overflow approval,
the Group/Trip contract and Finalize idempotency, the atomic batch move, and the Wave
lifecycle core — the paths most exposed to a change at the Loading boundary.

No certified test was modified. `test_finalize_creates_the_canonical_trip_and_is_idempotent`
is untouched, and Finalize semantics were not changed (§15) — the completeness guard reads
the finalized state, it does not reconcile it.

---

## 15. Browser verification

**BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT.**

Scenarios A–C need an authenticated session, and B and C additionally need a Group placed
into a blocked state — which on live data means breaking Group/Trip membership. §16 forbids
it. Authentication was not bypassed and no data was fabricated.

The three scenarios are covered by tests on isolated fixtures instead: a valid Trip
reporting ready and opening, a missing assignment reporting blocked on both vehicle and
driver, and the ORD-00007 shape reporting a membership failure.

---

## 16. Data safety

No live mutation. Verified read-only after all work:

| Fact | Value |
|---|---|
| orders | 19 |
| groups | 3 |
| trips | 2 |
| trip manifest rows | 4 |
| **loading_sessions** | **0** |
| vehicle_assignments | 0 |
| vehicle_inventory_movements | 0 |
| **ORD-00007** | **unmutated** — `in_progress`, group NULL, in 1 trip |

Every mutation test ran in `ecos_dev_test`.

---

## 17. Remaining gaps

1. **The readiness UI panel is not built.** The backend contract is in place and tested;
   rendering the checklist in the Trip panel is UI work that needs a browser pass to be
   worth claiming.
2. **Browser verification** outstanding (§15).
3. **§14.8 "invalid Trip state"** is covered only by the pre-existing foreign-group test.
   There is no explicit Trip-status gate on Loading beyond editability, and adding one
   would mean introducing a status rule — a STOP condition — so it was left alone and is
   noted here rather than invented.

---

## 18. Next task

The natural successor is the Loading readiness UI: render the checklist, wire the blocked
reasons to i18n, and verify Scenarios A–C in a browser against non-production data. That
is UI-only and needs no further backend contract.

**TASK-1-C did not start the Driver Loading implementation**, per the brief.

---

## Final status

**IMPLEMENTED / FOCUSED VERIFIED**

Loading now fails closed on both directions of the Group/Trip invariant: an Order in the
Trip that left the Group, and an accepted Group Order that never reached a Trip. Readiness
is a read that runs the real guards. Duplicate protection was found to be the database's
already, and the unreachable guard was removed rather than kept.

No automatic repair, no new endpoint, no new status, no new source of truth, no migration,
no live data mutated, and D-02 security unchanged.

Not certified. No commit, no deploy.
