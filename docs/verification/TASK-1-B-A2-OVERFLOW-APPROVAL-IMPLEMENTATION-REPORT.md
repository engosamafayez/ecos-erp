# TASK-1-B-A2 — DISTRIBUTION OVERFLOW APPROVAL — IMPLEMENTATION REPORT

**Date:** 2026-08-24 · **Branch:** `develop` · No commit. No deploy.

> # IMPLEMENTED / FOCUSED VERIFIED
>
> **Planning capacity was not changed.**
> **The operator explicitly approved the overflow.**
> **No automatic Group → Trip synchronization was implemented.**
> **No automatic Order movement was implemented.**
> **No automatic defer was implemented.**
>
> **BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT.** Not certified.
> No STOP condition fired.

---

## 1. Business Contract

Group capacity is a **planning** capacity. A Group may exceed it; the operator then chooses
**Approve Overflow & Finalize** or **Review & Move Orders**. This task implements only the first —
the second shipped in TASK-1-B and was not touched.

`capacity_orders` is never changed by an approval. A Group with maximum 20 carrying 25 approved
orders still reports **maximum 20**.

---

## 2. Previous Blocker

The previous task stopped because *"capacity 20, 25 accepted"* had no representation:
`distribution_virtual_slots` had 12 columns with no status or approval column, none of the 7
Distribution events recorded a capacity decision, and the only levers — raising or nulling
`capacity_orders` — change the limit instead of approving an exception to it, which §1 forbids.

Option **A2** was authorised. Nothing else about the blocker changed: no new event contract was
invented, no new source of truth, no new permission, no new Finalize workflow.

---

## 3. Schema Change

One migration: `2026_08_24_100000_add_overflow_approval_to_distribution_groups.php`.
Three nullable columns on `distribution_virtual_slots`, no new table, no index, no FK:

| Column | Type | Why it is not redundant |
| --- | --- | --- |
| `overflow_approved_orders` | `unsignedInteger` nullable | **The bound.** Without a count an approval is a blanket waiver — approve at 25, drift to 40, still allowed — which is exactly the *"capacity is unlimited"* meaning §2 forbids |
| `overflow_approved_at` | `timestamp` nullable | When. §2 requires the approval to be auditable |
| `overflow_approved_by` | `unsignedBigInteger` nullable | Who. Matches this module's existing shape — `distribution_trips.finalized_by`, `dispatched_by`, `distribution_group_product_preparation.last_recorded_by` |

Reversible (`down()` drops only what `up()` added), backward compatible, **no backfill**. Verified on
the live dev schema: all three columns `null=YES default=NULL`, **0 rows carry an approval**, and
`capacity_orders` is unchanged at `20,20,20`. Every existing Group therefore behaves exactly as
before — within capacity it finalizes, over capacity Finalize still refuses.

Applied to `ecos_dev_test` and `ecos_dev`. **This is a schema addition only — no business data was
written, read or rewritten.**

---

## 4. Approval Representation

`VirtualCapacitySlot::hasApprovedOverflowFor(int $occupancy): bool` — true only while the approved
count still covers current occupancy.

**Cast but deliberately NOT `$fillable`.** An approval is recorded by `GroupFinalizationService`,
never mass-assigned from a request. Keeping the three columns out of `$fillable` makes that
structural rather than a rule to remember — and a test proves it: posting
`overflow_approved_orders: 99` to the Group edit endpoint leaves the value `NULL`.

**What the approval means:** *this operator accepted this Group proceeding despite exceeding its
planning capacity, for the occupancy it held at that moment.* It does **not** mean capacity changed,
and it does **not** mean capacity is unlimited — the stored count is what makes the second true.

---

## 5. Approval Action

Written by `GroupFinalizationService::approveOverflow()`, **inside the Group's existing row lock**,
before the prerequisite check reads it — so the approval and the decision it authorises cannot
straddle a concurrent write. No new lock, no new transaction, no idempotency framework.

It refuses a pointless approval, so no misleading audit row can exist:

- Group with **no maximum** → *"This group has no maximum, so there is no overflow to approve."*
- Group **within** capacity → *"…holds N orders and its maximum is M, so there is no overflow to approve."*

Both are 422 and both are tested.

---

## 6. Finalize Integration

`assertFinalizable()` was **not removed** and the capacity check was **not weakened** — it gained a
third outcome:

```
within capacity                      -> allowed   (unchanged)
over capacity, no approval           -> refused   (unchanged)
over capacity, approved for >= count -> allowed   (new)
```

`GroupCapacityGuard` is untouched and is still not consulted on the ingestion path — the reason it
gives (a refused assignment is unretryable because `order_id` is globally unique, so a capacity
refusal there would silently drop work out of Distribution) is why overflow is reachable at all.

The Trip is created only by the existing `buildTrips()` path. The approval action never creates a
Trip and never bypasses `GroupFinalizationService`.

---

## 7. Capacity Preservation

**Planning capacity was not changed.** Asserted directly: after approving a Group with maximum 2 and
3 orders, `capacity_orders` is still `2` — not raised to 3, not nulled. The read model reports
`maximum: 2, current: 3, overflow: 1, overflow_approved: true`, so the screen shows the plan and the
accepted exception side by side and never displays maximum 3.

Trip capacity untouched: live `60,60`. Group capacity untouched: live `20,20,20`.

---

## 8. Idempotency

| Claim | Test |
| --- | --- |
| Approving twice writes the same count and creates nothing | `test_approval_is_idempotent` — same figure, 1 Trip, 3 manifest rows |
| Finalize with the flag repeated returns the same Trip | `test_finalize_with_approval_remains_idempotent` |
| The approval survives Finalize | `test_the_approval_survives_the_finalize_operation` |
| A refused Finalize records no approval | `test_finalize_without_the_flag_is_still_refused_when_over_capacity` |
| No duplicate Trips or manifest rows | asserted in all of the above |

`DistributionGroupTripTest::test_finalize_creates_the_canonical_trip_and_is_idempotent` passes
**unmodified**, along with the split test and the tenancy tests — 12 tests in that class.

**One behaviour worth stating plainly, because a test of mine initially assumed otherwise:** once a
Trip exists, Finalize short-circuits on it *before* reading any prerequisite. So an order arriving
after finalization cannot make Finalize refuse, and growth past the approved count does **not**
re-open the capacity question through Finalize. That is the idempotency contract winning, not a hole
in the bound. `test_after_finalization_growth_does_not_reopen_finalize` now pins it — same Trip
returned, manifest **not** re-synced. The resolution for a late order remains move-it or add-it, and
the bound itself is asserted at the rule level (`test_the_approved_count_bounds_the_approval`:
approved for 3 → true for 2 and 3, false for 4 and 40).

---

## 9. Permissions

**No new permission.** The approval travels on the existing finalize route,
`POST /windows/{window}/slots/{slot}/finalize`, which already carries
`permission:logistics.distribution.update`. Approving is a qualifier on the Finalize the operator is
already performing, not a workflow of its own, so there is no second place where "who may finalize"
is decided. `test_an_unauthorized_operator_cannot_approve_or_finalize` asserts 403 with no approval
written and no Trip created.

---

## 10. UI Changes

Built on the TASK-1-B panel; no new visual framework.

**Over capacity:**

```
Group capacity
Current: 25     Maximum: 20     Overflow: +5
⚠ Capacity decision required

[Approve overflow & finalize]
The planning capacity is not changed. The approval records that you accepted this group as it stands.
```

**Confirmation step** (deliberate — the approval persists, so it should not be one stray click):

```
Approve 5 order(s) above the planning capacity and finalize this group?
[Approve & finalize]  [Cancel]
```

**After approval:**

```
Group capacity
Current: 25     Maximum: 20     Overflow: +5
Overflow approved
Approved for 25 orders. The planning capacity is unchanged.
```

**Maximum is never rendered as 25.** *Review & Move Orders* is the per-order move shipped in
TASK-1-B and was not modified. i18n: 6 new keys in both locales, **parity 2144/2144**, every Arabic
value verified as Arabic script, no hardcoded strings.

---

## 11. API Reuse

| Need | Route | New? |
| --- | --- | --- |
| Approve + finalize | `POST /windows/{window}/slots/{slot}/finalize` with `approve_overflow=true` | **none — the existing route** |
| Read the approval state | `GET .../slots/{slot}/reconciliation` (extended) | none |
| Move an order | `PATCH /assignments/{assignment}/slot` | none, untouched |

**No duplicate Finalize endpoint. No dedicated approval endpoint.** The existing route accepts the
approval state safely, so §10's preferred shape was achievable and no STOP was needed.

---

## 12. Tests

`GroupTripReconciliationVisibilityTest` — **32 tests**. With `DistributionGroupTripTest`:
**44 tests, 463 assertions, all green.**

| # | Required | Test |
| --- | --- | --- |
| 1 | Within capacity → Finalize succeeds | `..._is_resolved`, `..._reports_matching_counts_and_no_difference` |
| 2 | Over capacity → rejected without approval | `test_finalize_without_the_flag_is_still_refused_when_over_capacity` |
| 3 | Approve → Finalize succeeds with all accepted orders | `test_approving_the_overflow_allows_finalize_with_every_accepted_order` |
| 4 | Capacity unchanged | `test_approval_does_not_change_the_planning_capacity` |
| 5 | Approval survives Finalize | `test_the_approval_survives_the_finalize_operation` |
| 6 | Finalize idempotent | `test_finalize_with_approval_remains_idempotent` + `DistributionGroupTripTest` |
| 7 | Approval idempotent | `test_approval_is_idempotent` |
| 8 | No duplicate Trip | asserted in 6 and 7 |
| 9 | No duplicate manifest rows | asserted in 3, 6, 7 |
| 10 | Manual Move unchanged | `test_an_operator_can_move_one_overflow_order_to_a_chosen_group`, `test_a_move_into_a_full_destination_is_refused` — both pre-existing, unmodified |
| 11 | Ownership / tenancy intact | `test_the_reconciliation_read_requires_the_view_permission`, `test_the_group_ownership_guard_still_refuses_a_foreign_order`, `DistributionGroupTripTest` tenancy tests |
| 12 | Unauthorized cannot approve/finalize | `test_an_unauthorized_operator_cannot_approve_or_finalize` |
| 13 | No live data mutation | §15 |

Extra rows: approving a within-capacity Group is refused · approving a no-maximum Group is refused ·
the approval is not mass-assignable · the bound holds at the rule level · a Group without an approval
is never treated as approved · post-finalization growth does not re-open Finalize.

**Two of my own test expectations were wrong and I corrected them, not the implementation:** a
whole-array `capacity` compare that broke when the payload legitimately grew, and a "growth
re-blocks Finalize" assertion that contradicted the idempotency contract (§8).

---

## 13. Static Checks

`php -l` clean on all touched PHP · **Pint PASS** on all 4 backend files + the test ·
**PHPStan `[OK] No errors`** · **ESLint clean** on all 4 frontend files ·
`tsc -p tsconfig.app.json` **23 errors — identical to baseline, none in any file I touched**.

Full regression deliberately not run.

---

## 14. Browser Verification

> ### BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT

The UI requires an interactive login; authentication was not bypassed and no isolated browser
fixture was created in live data.

Verified through the real HTTP stack against live DG-001, **GET only**:

```
state   : added_after_finalization
capacity: maximum 20 · current 7 · remaining 13 · overflow 0
          overflow_approved false · overflow_approved_orders null · overflow_approved_at null
```

DG-001 is within capacity, so it correctly shows no approval and no decision. **I did not approve or
finalize it** — §8 forbids it. The approval path is proven by test instead.

---

## 15. Data Safety

| Check | Result |
| --- | --- |
| Orders / groups / trips / manifest | **19 / 3 / 2 / 4** — unchanged |
| Group capacities | **20,20,20** — unchanged |
| Trip capacities | **60,60** — unchanged |
| **Live groups carrying an approval** | **0** — DG-001 not approved |
| Live Groups finalized / Trips created / Orders moved | none |
| ORD-00007 | untouched (`virtual_slot_id = NULL`) |
| Vehicles / drivers assigned, inventory loaded | none |

Tests ran against `ecos_dev_test` under `RefreshDatabase`. The one live write was a Sanctum token for
read-only verification, **revoked**. The migration added three nullable columns and wrote no data.

---

## 16. Remaining Gaps

1. **After finalization, a late order cannot be resolved by re-Finalize** (§8) — by design.
   Its resolutions are *move it out* (shipped) or *add it to the Trip* (`POST /trips/{id}/orders`
   exists, and `useAddTripOrder` exists, but the Trips workspace still has **no navigation entry**,
   so that route is practically unreachable).
2. **Atomic multi-order move is still not available** — carried over from the previous task. Moves
   are per-order and atomic individually; a batch would need a new endpoint.
3. **The `overflow_approved` state takes precedence over `added_after_finalization`** when a
   finalized Group later exceeds its approved bound. Both facts are true; the more urgent one is
   shown. Worth a look if operators find it confusing.
4. **Pre-existing, untouched:** the Loading endpoint's broad `RuntimeException` catch;
   `distribution_virtual_slots` still carries the three non-contract capacity columns.

---

## 17. Next Task

Unblocked and small: **give the Trips workspace a navigation entry.** It closes gap 1, and makes the
already-built add/remove/move membership UI reachable — the single highest-value remaining item in
this workstream. A batch-move endpoint (gap 2) needs authorisation before it can be built.

---

> ## STATUS: IMPLEMENTED / FOCUSED VERIFIED
>
> **Planning capacity was not changed.** **The operator explicitly approved the overflow.**
> **No automatic Group → Trip synchronization was implemented.** **No automatic Order movement was
> implemented.** **No automatic defer was implemented.**
>
> 44 tests / 463 assertions green, certified tests unmodified, one minimal reversible migration with
> no backfill, no new permission, no new endpoint, no live data mutated.
> Browser not verified — authentication constraint. Not certified. No commit. No deploy.
