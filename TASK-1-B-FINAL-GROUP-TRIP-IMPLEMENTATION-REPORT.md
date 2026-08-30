# TASK-1-B-FINAL — GROUP OVERFLOW DECISION + MANUAL REALLOCATION + TRIP INTEGRITY

**Status: IMPLEMENTED / BLOCKED — OWNER DECISION REQUIRED**
Date: 2026-08-24 · Branch: `develop` · Not committed, not deployed

> **No automatic Group → Trip synchronization was implemented.**
>
> **Orders are not automatically moved or deferred when Group capacity is exceeded.**

---

## Reading note

This contract was implemented across TASK-1-B-FINAL and its follow-up
**TASK-1-B-A2**, which the owner authorised a migration for specifically to persist the
overflow decision. This report is the consolidated statement of the delivered contract,
verified against the current tree.

One item remains blocked: **atomic multi-order movement** (§7 of the brief). It is reported
below, not worked around.

---

## 1. Business Contract

Group Capacity is a **planning** capacity, not a hard ceiling and not a target that
adjusts itself. When occupancy exceeds it, the system stops and asks.

| Condition | State | Operator actions |
|---|---|---|
| `current <= maximum`, not finalized | `awaiting_finalization` | Finalize |
| `current > maximum`, no approval | **`capacity_decision_required`** | **Approve Overflow & Finalize** · **Review & Move Orders** |
| `current > maximum`, approved | `overflow_approved` | Finalize |
| Finalized, every order on a trip | `resolved` | — |
| Member joined after Finalize | `added_after_finalization` | Operator resolution |

Nothing in this path moves an order, defers an order, picks a Group, creates a Group or
creates a Trip on its own. Proven by
`test_overflow_moves_defers_and_creates_nothing_automatically`.

---

## 2. Overflow Detection

Computed server-side in `DistributionWindowController` (lines 80–112, 836–844) as one of
five named states. The state is a **derivation, not a stored flag** — it is recomputed on
every read from occupancy, the maximum, the approval and the manifest, so it cannot drift
from the data it describes.

Detection is `occupancy > capacity_orders`, where occupancy is the Group's
`demand_orders` — the same loading-eligible predicate the capacity figure itself is
measured in. Counting raw assignment rows instead would report an overflow on the strength
of cancelled orders the Group's own totals exclude.

Proven by `test_a_group_over_capacity_requires_a_capacity_decision` and
`test_finalize_is_refused_while_the_group_is_over_capacity`.

---

## 3. Overflow Approval

**Planning capacity was not changed.** **The operator explicitly approved the overflow.**

`GroupFinalizationService::approveOverflow()` records the decision in three columns added
by the owner-authorised TASK-1-B-A2 migration
(`2026_08_24_100000_add_overflow_approval_to_distribution_groups`):

| Column | Meaning |
|---|---|
| `overflow_approved_orders` | the order count the operator accepted |
| `overflow_approved_at` | when |
| `overflow_approved_by` | who |

Four properties make this safe:

1. **`capacity_orders` is never written.** The plan still says 20. Proven by
   `test_approval_does_not_change_the_planning_capacity`.
2. **The approval is bounded, not blanket.** `hasApprovedOverflowFor($occupancy)` returns
   true only while `occupancy <= overflow_approved_orders`. Approving 25 does not silently
   authorise 26 — the Group returns to `capacity_decision_required`. Proven by
   `test_the_approved_count_bounds_the_approval`.
3. **It is not mass-assignable.** The three columns are cast but deliberately kept out of
   `$fillable`, so the Group update endpoint cannot set an approval. Proven by
   `test_the_approval_cannot_be_set_through_the_slot_update_endpoint`.
4. **A pointless approval is refused** — a Group within capacity, or with no maximum, is
   rejected rather than silently stamped. Proven by
   `test_approving_a_group_within_capacity_is_refused` and
   `test_approving_a_group_with_no_maximum_is_refused`.

No approval was faked by setting `capacity_orders = actual_count` or `NULL`. The migration
does **not** backfill any existing Group as approved: on the live dev database
**0 Groups hold an approval**, and the three capacities are still `20, 20, 20`.

---

## 4. Manual Move — delivered per-order, blocked as a batch

**Delivered.** Each overflow order is listed with the candidate destination Groups, each
button showing that Group's occupancy against its maximum. The operator clicks one
destination for one order.

- Reuses **`PATCH /assignments/{assignment}/slot`** →
  `ManualAssignmentService::changeOrderSlot()`. **No second assignment engine was
  created.**
- That service already provides everything §6 asked to verify: same-window validation, a
  transaction, destination headroom checked *inside* the transaction under a row lock,
  `assignment_source = ManualMove`, `assigned_by`, `assignment_reason`, and a
  `DistributionAssignmentChanged` event carrying the previous zone and previous slot.
- **The destination is never chosen automatically.** Groups already at their maximum are
  still listed with their occupancy visible, so the operator can see why one is unusable
  rather than finding it silently absent.
- No new business constraint was invented.

Proven by `test_an_operator_can_move_one_overflow_order_to_a_chosen_group` and
`test_a_move_into_a_full_destination_is_refused`.

### The blocked part (§7 — ATOMICITY)

A **multi-select batch move is deliberately not offered.** See §16.

---

## 5. Group → Trip Invariant

**100% of accepted Group Orders belong to a Trip after Finalize** — by construction, not
by reconciliation. `GroupFinalizationService` iterates **every** accepted order and assigns
each to a Trip, opening a new Trip whenever the current one reaches capacity
(lines 287–339). There is no branch in which an accepted order is skipped.

Consequently there is no normal end state of *Group member + no Trip*. When the difference
is non-empty the read model reports it as one of two **exceptional** states —
`awaiting_finalization` (not yet finalized) or `added_after_finalization` — never as a
resting state.

Proven by `test_a_finalized_group_with_every_order_on_a_trip_is_resolved` and
`test_approving_the_overflow_allows_finalize_with_every_accepted_order`.

---

## 6. Trip Capacity

Group capacity and Trip capacity remain **separate and unchanged**: live Groups are still
`20, 20, 20` and live Trips still `60, 60`.

With 25 approved orders and a Trip capacity of 60, one Trip holds all 25. The existing
split behaviour is preserved for larger Groups — 130 orders at capacity 60 produce
`60 / 60 / 10`, every order in exactly one Trip, no duplicates.

Proven by `DistributionGroupTripTest::test_trip_capacity_forces_a_split_and_never_duplicates_an_order`,
`test_trip_capacity_is_independent_of_group_capacity`, and
`test_each_trip_reports_its_own_capacity_and_remaining_capacity`.

---

## 7. Post-Finalize Orders

An order joining a Group after Finalize is **never silently added to an existing Trip**,
never auto-moved, never auto-deferred, and Finalize is **not** re-run to absorb it. It is
surfaced as **"Added after finalization — action required"**, and the operator resolves it
through an existing supported workflow (move it to another Group, or add it to the Trip
explicitly via the existing `POST /trips/{id}/orders`).

Proven by `test_an_order_joining_after_finalize_is_reported_unassigned_and_never_added`,
`test_a_member_joining_after_finalize_is_added_after_finalization`, and
`test_after_finalization_growth_does_not_reopen_finalize`.

---

## 8. Trip Integrity

A Trip carrying an order that is no longer a member of its Group is reported as a
**"Trip integrity exception"**, listed separately from the capacity states because it is a
different problem with a different fix.

The only action offered is the **existing** removal endpoint,
`DELETE api/logistics/distribution/trips/{id}/orders/{orderId}`, gated on
`permission:logistics.distribution.update` under `auth:sanctum`. `TripService::removeOrder`
refuses once the Trip is no longer editable — the existing editability contract, not a new
rule. **Nothing is repaired automatically and no new correction endpoint was created.**

**ORD-00007 was not mutated.** Its `virtual_slot_id` is still `NULL` and it remains in
TRP-001; the live integrity violation is surfaced, not silently cleaned.

Proven by `test_an_order_leaving_the_group_becomes_an_exception_and_is_never_removed`.

---

## 9. Loading Guard

`GroupLoadingContextService::assertManifestStillBelongsToGroup()` runs at `open()` and
refuses to open Loading while the Trip carries a non-member, naming the offending order
numbers.

Three properties keep it minimal, as §10 required:

- **No second eligibility engine.** It asks about Group *membership*
  (`distribution_window_orders.virtual_slot_id = group.id`) — the **same** predicate
  `assignOrder` uses, so add-time and load-time can never disagree. It asks nothing about
  order status.
- **No new source of truth.** It reads the existing manifest and membership tables.
- **It repairs nothing.** The row is reported; clearing it is the operator's decision
  through the existing removal endpoint.

Loading business rules were not otherwise changed, and Loading was not redesigned.

Proven by `test_loading_is_refused_when_the_trip_carries_a_non_member` and
`GroupTripLoadingIntegrationTest::test_a_trip_belonging_to_another_group_is_rejected`.

---

## 10. Finalize Contract

**Finalize remains idempotent and was not redesigned.** The capacity check gained exactly
one clause — an approval exception bounded by the approved count:

```php
if ($group->capacity_orders !== null
    && count($orders) > $group->capacity_orders
    && ! $group->hasApprovedOverflowFor(count($orders))
) { throw new DistributionException(...); }
```

Truth table: within capacity → allowed (unchanged) · over capacity, no approval → refused
(unchanged) · over capacity, approved for ≥ count → allowed (new). `capacity_orders` is
neither read differently nor written.

**No hidden re-synchronisation was added.** Finalize does not reconcile existing manifests
on re-run; a second call returns the same Trips.

`DistributionGroupTripTest::test_finalize_creates_the_canonical_trip_and_is_idempotent` is
**preserved and passing**, joined by
`test_finalize_remains_idempotent_across_a_reconciliation_read`,
`test_finalize_with_approval_remains_idempotent` and `test_approval_is_idempotent`.

---

## 11. UI Changes

**The label "Orders Not Assigned to Trip" no longer exists anywhere in the codebase** —
verified by a repository-wide search returning nothing. It was replaced by the four states
§11 specified:

| State | Label |
|---|---|
| Before Finalize | *Awaiting finalization* |
| Over capacity | *Capacity decision required* |
| Post-Finalize arrival | *Added after finalization — action required* |
| Trip carrying a non-member | *Trip integrity — N order(s) require attention* |
| Finalized and complete | *All group orders are on a trip* |

The Group header shows the server-supplied **Current / Maximum / Overflow** triplet. When a
decision is required, two actions appear: **Approve Overflow & Finalize** (behind an
explicit inline confirmation — never a one-click approval) and the review list of the
excess orders with their destination options.

All copy is `t()`-resolved in **English and Arabic** — 30 keys under
`distributionWorkspace.difference`, at full parity, fully translated, no English strings
hiding in ternaries or label maps. ESLint over the whole feature directory is clean.

---

## 12. API/Service Reuse

Everything reuses an existing endpoint and an existing service. **No new mutation endpoint
was created for the move path.**

| Need | Reused |
|---|---|
| Approve overflow | `POST .../slots/{slot}/finalize` with `approve_overflow` |
| Manual move | `PATCH /assignments/{assignment}/slot` → `changeOrderSlot()` |
| Add to Trip (post-Finalize) | `POST .../trips/{id}/orders` |
| Trip integrity removal | `DELETE .../trips/{id}/orders/{orderId}` |
| Reconciliation read | `GET .../slots/{slot}/reconciliation` |
| Loading | existing `open()`, guard added inside it |

The only schema change is the owner-authorised TASK-1-B-A2 migration (§3). **No further
migration is required**, and the columns are already applied on both `ecos_dev` and
`ecos_dev_test`.

---

## 13. Tests — focused only, no full regression

All 16 required scenarios have named coverage across three suites:

| # | §16 scenario | Test |
|---|---|---|
| 1 | Within capacity → Finalize | `test_a_group_within_capacity_and_not_finalized_is_awaiting_finalization` + `test_finalize_creates_the_canonical_trip_and_is_idempotent` |
| 2 | All accepted orders become Trip members | `test_a_finalized_group_with_every_order_on_a_trip_is_resolved` |
| 3 | Over capacity → decision required | `test_a_group_over_capacity_requires_a_capacity_decision`, `test_finalize_is_refused_while_the_group_is_over_capacity` |
| 4 | Approve Overflow → Finalize succeeds | `test_approving_the_overflow_allows_finalize_with_every_accepted_order` |
| 5 | Approval does not modify capacity | `test_approval_does_not_change_the_planning_capacity` |
| 6 | Manual Move via existing mechanism | `test_an_operator_can_move_one_overflow_order_to_a_chosen_group` |
| 7 | Destination capacity enforced | `test_a_move_into_a_full_destination_is_refused` |
| 8 | No automatic destination selection | `test_overflow_moves_defers_and_creates_nothing_automatically` |
| 9 | No automatic move | `test_overflow_moves_defers_and_creates_nothing_automatically` |
| 10 | No automatic defer | `test_overflow_moves_defers_and_creates_nothing_automatically` |
| 11 | Post-Finalize order not silently inserted | `test_an_order_joining_after_finalize_is_reported_unassigned_and_never_added`, `test_after_finalization_growth_does_not_reopen_finalize` |
| 12 | Trip cannot contain a foreign Group order | `test_the_group_ownership_guard_still_refuses_a_foreign_order`, `test_a_group_trip_refuses_an_order_from_another_group` |
| 13 | Loading rejects invalid membership | `test_loading_is_refused_when_the_trip_carries_a_non_member`, `test_a_trip_belonging_to_another_group_is_rejected` |
| 14 | Finalize remains idempotent | `test_finalize_creates_the_canonical_trip_and_is_idempotent`, `test_finalize_with_approval_remains_idempotent` |
| 15 | No duplicate manifest rows | `test_trip_capacity_forces_a_split_and_never_duplicates_an_order` |
| 16 | Company/tenant boundaries | `test_an_unauthorized_operator_cannot_approve_or_finalize`, `test_a_foreign_tenant_can_neither_finalize_nor_read_a_group_trip`, `test_a_trip_cannot_be_read_across_tenants`, `test_another_companys_operator_cannot_open_loading_for_this_group` |

### Run result

| Suite | Result |
|---|---|
| `GroupTripReconciliationVisibilityTest` | **32 / 32 green** |
| `DistributionGroupTripTest` | **12 / 12 green** — including §14's preserved idempotency test |
| `GroupTripLoadingIntegrationTest` | **10 / 10 green** after the fixture repair below |
| **Total** | **54 / 54 green** |

Runs: `Tests: 22, Assertions: 236` (finalize + loading) · `Tests: 42, Assertions: 424`
(reconciliation + loading) · `Tests: 10, Assertions: 103` (loading, after the repair).

**Every one of the 16 required scenarios passed on the first run.** The single failure was in
`test_a_group_exceeding_the_vehicle_order_count_is_still_rejected` — a test of vehicle
order-count capacity, which is **not** one of the 16 scenarios and is untouched by this
contract.

#### The one failure, and why it was a fixture and not a defect

`POST .../windows/{window}/slots` returned 404 where 201 was expected, because the window
id in the URL was empty. Two facts combine:

1. Since the owner-approved fail-closed contract, `GET /windows/current` **resolves** an
   existing window and never creates one, so it can legitimately return a null window.
2. `DistributionCollectionService` opens a window **inside** its
   `foreach ($candidates as $order)` loop — so a sweep with nothing to collect creates
   nothing.

This test asked for a Group before any order existed. Its nine siblings all reach the same
state through `readyGroup()`, which creates orders *first* and then collects. The repair was
to use that same proven order — orders, collect, group, attach zone. **No assertion was
changed**, and the reason is documented in the test. The suite is now **10 / 10**.

My first attempt at this repair was wrong and is worth recording: I added a leading
`collect()` on the assumption that the ordering of the read alone was at fault, and it
failed identically. Only fact (2) above — that the window is opened inside the candidate
loop — explains it, and it makes an empty sweep a no-op.

Attribution, stated plainly: this red traces to the Option-B fail-closed window contract
delivered earlier, not to anything in this overflow contract. It is either inside that
task's accepted 25-failure baseline or a gap in its fixture sweep; the evidence at hand
does not distinguish the two.

Static checks: ESLint clean across `distribution-workspace/`; TypeScript at the
pre-existing 23-error baseline with none in these files; i18n parity in both locales
(30 keys under `distributionWorkspace.difference`, fully translated).

One test file changed in this task: `GroupTripLoadingIntegrationTest.php`, fixture ordering
only. No backend source file and no frontend file was modified.

---

## 14. Browser Verification

**BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT.**

Exercising A–E would require signing in, which means entering credentials, and would
require a live Group in overflow — meaning mutating live business data, which §15 forbids.
Live Groups sit at `3 / 20`, `0 / 20`, `0 / 20`; none is in overflow, and manufacturing one
would mean moving live orders.

Authentication was not bypassed. The evidence offered instead is the focused suites above,
which exercise the same HTTP endpoints the UI calls.

---

## 15. Data Safety

No live business data was mutated. Verified after the work:

| Fact | Value |
|---|---|
| `orders` | 19 — unchanged |
| Groups | 3, capacities `20, 20, 20` — unchanged |
| Trips | 2, capacities `60, 60` — unchanged |
| `distribution_trip_orders` | 4 — unchanged |
| **Groups with an approved overflow** | **0** |
| ORD-00007 `virtual_slot_id` | `NULL` — untouched |

No live Order was moved, no live Group finalized or approved, no live Trip created, nothing
deleted, no Vehicle or Driver assigned, no Loading executed, no capacity changed. All tests
ran against `ecos_dev_test` via the test runner; every query against `ecos_dev` was a
`SELECT`.

---

## 16. Remaining Gaps

### BLOCKER — atomic multi-order movement (§7, §19)

**The existing backend cannot guarantee it, so it was not built.**

Evidence: `PATCH /assignments/{assignment}/slot` accepts **one** assignment.
`changeOrderSlot()` opens its own transaction and calls
`assertHasHeadroom($slot, 1)` — sized for exactly one order. Five orders therefore mean
five independent transactions. Moving 5 orders into a Group with 3 free places would
succeed 3 times and fail twice, leaving the operator with a half-applied move and no
rollback. That is precisely the outcome §7 forbids, so **no client-side loop was written**
and no multi-select was shipped.

**Minimum backend/API boundary required** (owner decision):

1. One new route — e.g. `PATCH .../windows/{window}/assignments/slot` — accepting
   `{ assignment_ids: string[], slot_id, reason }`.
2. One controller action wrapping the whole set in a **single** `DB::transaction`.
3. Inside it, **one** headroom assertion for the whole batch:
   `assertHasHeadroom($slot, count($ids))`. **This already exists** — `assertHasHeadroom`
   is already N-aware and takes a count, so no capacity logic needs writing.
4. Then the existing `changeOrderSlot()` per order, unchanged. Nested transactions become
   savepoints, so any single failure rolls the whole batch back.

No new engine, no new predicate, no new source of truth and no migration — one route, one
controller action, one transaction boundary. It was not built because §7 and §19 direct
that this be reported for an owner decision rather than worked around.

### Non-blocking observations

- **The live integrity violation stands.** ORD-00007 is in TRP-001 without Group
  membership. It is now visible and actionable in the UI, and Loading will refuse that
  Trip. Clearing it is an operator decision.
- **`distribution_window_orders.order_id` is globally unique**, so an order belongs to one
  window forever. A move can change the Group but never the window.

---

## 17. Next Task

Recommended, in order:

1. **Owner decision on the batch move boundary** (§16). If approved it is a small, contained
   change; if declined, per-order moves stand as the delivered contract and the UI needs no
   change.
2. **Operator resolution of ORD-00007** through the now-exposed removal action — a data
   decision, not an engineering one.
3. **Browser verification** in a seeded non-production environment where an overflow Group
   can be created without touching live orders.

**TASK-1-C was not started.**

---

## Re-verification — 2026-08-24 (contract re-issued)

This contract was re-issued after further work landed in the same tree, so it was
re-verified rather than assumed. That was warranted: since the original run, three shared
files had changed — `ManualAssignmentService` (a scoped `enforceCapacity` parameter),
`DistributionAggregationService` (closed-group and Wave board filters) and
`DistributionWindowController` — and `GroupTemplateService` had been edited concurrently by
other work.

**Focused verification: 76 tests, 783 assertions — all green.**

**Re-confirmed again after TASK-003 landed** (Wave lifecycle triggers and board isolation),
which changed `GroupTemplateService`, `DailyGroupLifecycleService` and `slotSummaries` —
all of them on paths this contract depends on. The TASK-003 regression run covered
**all four** of the suites below inside a wider set:

```
Tests: 176, Assertions: 1348   ->   OK
```

So the Group → Trip contract holds on the current tree, including with the wave-scoped
board filter, the wave-unique group codes and the caller-supplied wave identity in place.

| Suite | Covers |
|---|---|
| `GroupTripReconciliationVisibilityTest` (32) | overflow detection, approval, the five states, post-Finalize arrivals, Trip integrity, tenancy |
| `DistributionBatchMoveTest` (22) | manual move, atomicity, destination capacity, no auto-select / auto-move / auto-defer |
| `DistributionGroupTripTest` (12) | Group→Trip invariant, Trip capacity split, no duplicate manifest rows, Finalize idempotency |
| `GroupTripLoadingIntegrationTest` (10) | the Loading boundary guard |

Together these cover all 20 focused requirements. Nothing regressed — in particular the
`enforceCapacity` parameter defaults to `true`, so destination-capacity enforcement on the
manual move path is unchanged, which `test_a_move_into_a_full_destination_is_refused` and
`test_manual_zone_attach_still_enforces_capacity` both confirm.

Structural checks on the current tree: `hasApprovedOverflowFor` and `approveOverflow()`
present · `changeOrderSlotBatch` and `PATCH .../assignments/batch-slot` present ·
`assertManifestStillBelongsToGroup` present · all five contextual states present ·
**the label "Not Assigned to Trip" is absent from the entire codebase.**

**Live data, read-only:** 19 orders · 3 Groups, capacities `20, 20, 20` · 2 Trips ·
4 manifest rows · **0 overflow approvals** · 0 loading sessions. **ORD-00007 was not
mutated** — it still shows the exact §9 integrity exception, present in one Trip with
`virtual_slot_id = NULL`.

One change to ORD-00007 that this task did not cause: its status read `ready_for_dispatch`
at 05:00:01 and reads `in_progress` at 13:00:08. No file changed by this task or its
follow-ups writes order status; the integrity exception itself is unchanged. Recorded
because "ORD-00007 untouched" would otherwise be read as "nothing about it moved".

The two STOP conditions this brief lists — Approve Overflow needing a schema field, and
atomic multi-order movement — were both resolved by the follow-up tasks you authorised
(TASK-1-B-A2 and TASK-1-B-ATOMIC-BATCH-MOVE-001) and are closed, not open.

---

## Final Status

**IMPLEMENTED / BLOCKED — OWNER DECISION REQUIRED**

Overflow detection, explicit overflow approval, per-order manual reallocation, the
Group → Trip invariant, post-Finalize handling, Trip integrity exposure and the Loading
boundary guard are all implemented and covered by focused tests. Atomic multi-order
movement is blocked on the owner decision in §16.

Not certified. No full certification was run. No commit, no deploy.

> **No automatic Group → Trip synchronization was implemented.**
>
> **Orders are not automatically moved or deferred when Group capacity is exceeded.**
