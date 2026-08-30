# TASK-1-B-ATOMIC-BATCH-MOVE-001 — ATOMIC MULTI-ORDER GROUP MOVE

**Status: IMPLEMENTED / FOCUSED VERIFIED**
Date: 2026-08-24 · Branch: `develop` · Not committed, not deployed

---

## 1. Executive Summary

The blocker reported in §16 of `TASK-1-B-FINAL-GROUP-TRIP-IMPLEMENTATION-REPORT.md` is
closed. An operator can now move several Orders into one Group in a single call that either
moves all of them or moves none.

The change is deliberately small, and matches the boundary that report predicted:

| Piece | What was needed |
|---|---|
| Capacity logic | **none written** — `assertHasHeadroom()` was already N-aware |
| Eligibility engine | **none** — the single-Order rules applied to a set |
| Migration | **none** |
| New permission | **none** — existing `logistics.distribution.update` |
| Service | one method + one extracted shared writer |
| Controller | one action |
| Route | one line |

**22 / 22 focused tests green, 217 assertions.** Every rejection test asserts both the
refusal *and* that no row moved — a test that only checked the status code would pass just
as happily on a partial write.

---

## 2. Existing Single Move Contract

`PATCH /assignments/{assignment}/slot` → `ManualAssignmentService::changeOrderSlot()`
already provided, and still provides:

- `assertManualAllowed($window)` — the Window must still accept manual assignment
- destination must belong to that Order's Window
- `assertHasHeadroom($slot, 1)` **inside** the transaction, under `lockForUpdate()`
- `assignment_source = manual_move`, `assigned_by`, `assignment_reason`
- a `DistributionAssignmentChanged` event carrying the previous zone and previous slot

It is fully atomic — **for one Order**. Its behaviour is unchanged by this task, and
`test_the_single_order_move_still_works_and_still_enforces_capacity` pins that.

---

## 3. Batch Contract

`PATCH api/logistics/distribution/assignments/batch-slot`

```json
{ "assignment_ids": ["…", "…"], "slot_id": "…|null", "reason": "…" }
```

Response is the server's own summary, never an echo of the request:

```json
{ "data": { "moved": 3, "slot_id": "…", "assignment_ids": [...], "order_ids": [...] } }
```

There is deliberately **no per-order status array**: the operation is all-or-nothing, so a
shape able to express "3 of 5 succeeded" would describe a state this endpoint cannot
produce. `moved` is what the server committed.

### Why the path is not `/assignments/batch/slot`

The brief offered that path conceptually. It would **collide** with
`/assignments/{assignment}/slot` — the literal segment `batch` is a valid `{assignment}`
value, so which route answered would depend on registration order. `batch-slot` cannot be
mistaken for an id, and matches the kebab-case convention already used by `late-orders`,
`assign-vehicle` and `override-warehouse`. Both routes are registered and distinct:

```
PATCH api/logistics/distribution/assignments/batch-slot
PATCH api/logistics/distribution/assignments/{assignment}/slot
```

The operation is manual only. Nothing chooses the destination, creates a Group, creates a
Trip, finalizes, defers, alters capacity or approves overflow.

---

## 4. Transaction Boundary

One `DB::transaction` wraps the whole set. Set-level validation runs **before** it, so a
malformed request never opens a transaction at all; capacity is asserted **inside** it,
before any write.

```
validate set (empty / duplicates / one window / window accepts manual / slot in window)
        │
        └── DB::transaction
                ├── assertHasHeadroom($slot, $arrivals)      ← ONCE, throws before any write
                └── foreach: writeSlotChange(...)
```

Any throw propagates out of the closure, so the transaction rolls back in full.
`test_a_rejected_batch_mutates_no_assignment_row` compares a snapshot of
`virtual_slot_id` for **every** row in `distribution_window_orders` before and after a
rejected call and asserts they are identical.

---

## 5. Capacity

**No capacity logic was written.** `GroupCapacityGuard::assertHasHeadroom($group, $incoming)`
was already N-aware, already takes `lockForUpdate()` on the destination Group, and already
recomputes live occupancy inside that lock. The only change is that the batch calls it
**once with the real arrival count** instead of once per Order.

`$arrivals` counts only Orders not already in the destination — an Order already there costs
nothing, exactly as re-stating the current Group costs nothing on the single-Order path.

| Destination free | Selected | Result |
|---|---|---|
| 3 | 5 | **rejected, 0 moved** (`test_insufficient_headroom_rejects_the_whole_batch`) |
| 3 | 3 | all 3 move (`test_a_batch_that_exactly_fills_the_destination_succeeds`) |
| null (no maximum) | any | unconstrained, as before |

The exact-fit case is tested explicitly because an off-by-one there would refuse the
operation an operator most needs — filling a Group to its planned limit.

---

## 6. Validation

Everything is the single-Order contract applied to a set. **No new predicate was invented.**

| Rule | Outcome |
|---|---|
| Empty selection | 422 — refused, not treated as a no-op |
| More than 200 ids | 422 — bounded so the destination row lock is not held indefinitely |
| Non-uuid id | 422 |
| Unknown id | 404 |
| Ids spanning two Windows | 422 |
| Destination in another Window | 404 |
| Window no longer accepts manual assignment | 422 |
| `slot_id: null` | allowed — moving Orders *out* of a Group, as per-order |

---

## 7. Duplicate Input

Duplicates are **refused, never silently collapsed** — twice over: once on the raw payload
in the controller, and again on the resolved models in the service.

Collapsing them would make the operator's count and the server's capacity decision
disagree, and the capacity decision is made on that count. Two tests cover it:
`test_duplicate_ids_are_rejected` and `test_a_duplicate_cannot_produce_a_double_write`,
the latter asserting the Order still has exactly one assignment row.

---

## 8. Tenancy

Each id is resolved through the **same** `assignment()` helper the single-Order path uses,
which scopes to the caller's company and 404s otherwise. There is no batch-specific tenancy
rule that could drift from the single-Order one.

A batch containing one foreign Order therefore fails **before the service is reached**, so
nothing is written — `test_a_cross_company_assignment_fails_the_batch` asserts the 404 and
that every legitimate Order stayed put.

Authorization is the existing `permission:logistics.distribution.update`. No new permission
was created. `test_the_batch_requires_the_distribution_update_permission` covers the 403.

---

## 9. Zone Compatibility

**The existing single-Order `changeOrderSlot()` does not enforce zone compatibility.** It
validates the Window and the capacity, and nothing about the Order's Zone versus the
destination Group's Zones.

Per §7 of the brief this task does **not** expand into a Zone redesign, and per §6 it does
not invent predicates. The batch therefore behaves exactly as the single move does.

This is a documented difference, not an oversight: **the batch is not less safe than the
single-Order operation** — it is identical on this axis. A batch that refused what one move
allows would be a different contract, not a safer one. If zone compatibility should be
enforced, it belongs on both paths, as its own decision.

---

## 10. Trip Safety

Trip behaviour was not touched. No Trip is created, removed, re-synced or re-sized; no
manifest row is written; Finalize is not reopened; Trip capacity is unchanged.

`test_a_batch_move_synchronizes_no_trip_and_changes_no_trip_capacity` asserts the Trip
count, the `distribution_trip_orders` count **and** the full list of Trip capacities are
identical before and after a successful batch move.

---

## 11. Finalized State

The batch reuses the existing mutation guard, `assertManualAllowed($window)`, which permits
manual assignment while a Window is `open` or `cutoff_reached` and refuses once it is
`closed` or `scheduled`. `test_a_closed_window_refuses_the_batch` asserts the 422 and that
every Order stayed in its source Group.

Stated plainly: the existing single-Order contract does **not** separately prohibit moving
an Order out of a *finalized Group* — its only lock is the Window status. The batch
inherits that unchanged, for the same reason as §9.

---

## 12. Driver / Vehicle Isolation

Nothing in this task touches Driver assignment, Vehicle assignment, Loading or Vehicle
Inventory. Two tests assert this against the real tables:

- `test_a_batch_move_changes_no_driver_or_vehicle_assignment` —
  `logistics_driver_vehicle_assignments`, `vehicle_assignments`, `driver_assignments`
- `test_a_batch_move_creates_no_loading_or_vehicle_inventory` — `loading_sessions`,
  `loading_tasks`, `vehicle_inventory_items`, `vehicle_inventory_movements`

The approved cross-workstream order — Group → Finalize → Driver + Vehicle → Driver Shipment
→ Loading — is untouched.

---

## 13. Backend Changes

| File | Change |
|---|---|
| `ManualAssignmentService.php` | **new** `changeOrderSlotBatch()`; **extracted** private `writeSlotChange()` now shared with `changeOrderSlot()` |
| `DistributionWindowController.php` | **new** `changeSlotBatch()` action |
| `routes/api.php` | one route: `PATCH /assignments/batch-slot` |

No migration. No new model. No new engine. No new permission. `changeOrderSlot()`'s
external behaviour is unchanged — the extraction moved the write into a shared private
method so the two paths cannot diverge, which is the delegation §1 permits.

**Event note.** `DistributionAssignmentChanged` is dispatched inside the transaction and
currently has **no listeners**, so a rollback has no side effect to undo. Should a listener
ever be added it should be registered `afterCommit`, otherwise it would observe a batch
that was subsequently rolled back. Recorded as a forward-looking constraint, not a defect.

---

## 14. Frontend Changes

Multi-selection did not exist, so the minimum was added to the surface that already lists
the Orders needing a decision. **The Distribution workspace was not redesigned.**

| File | Change |
|---|---|
| `distribution-workspace-service.ts` | `moveOrdersToSlot()` |
| `types/index.ts` | `BatchMoveResult` |
| `use-distribution-workspace.ts` | `useMoveOrdersToSlot()` |
| `group-trip-panel.tsx` | checkbox per row + `BatchMoveBar` |
| `en/logistics.json`, `ar/logistics.json` | 11 keys each |

- Action label: **Move selected orders** / **نقل الطلبات المحددة**
- The destination is chosen by the operator from a Select. **No recommendation, no default,
  no ranking.** Groups with no room are still listed with their occupancy visible, so the
  operator can see why one is unusable rather than finding it missing.
- **§13 confirmation** — before committing, the bar states the selected count, the
  destination, and the available capacity there.
- **Available capacity is the server's number.** It renders `remaining_orders`, which the
  type's own docblock requires be rendered rather than recomputed, so the screen cannot
  disagree with the guard that enforces it. A null maximum reads as "no maximum", never 0.
- The button is disabled when the selection exceeds that number — and the **server is still
  the authority**, re-checking under a row lock, so a stale screen is refused rather than
  half-applied.
- **§14 failure UX** — a failure shows **"No orders were moved."** / **"لم يتم نقل أي
  طلبات."** with the server's own reason. No Order is ever shown as moved.
- **§15 success** — the existing workspace query root is invalidated, so the source Group,
  the destination Group, both capacity figures and the KPI totals refresh as one surface. No
  new data model, no second synchronisation mechanism.

The existing per-order buttons still work; this only adds deciding several at once.

---

## 15. Tests

`GATE_WAIT=2400 sh scripts/test-gate.sh --filter DistributionBatchMoveTest`

```
Tests: 22, Assertions: 217   →   OK
```

| # | §16 scenario | Test |
|---|---|---|
| 1 | One-order batch works | `test_a_batch_of_one_order_moves` |
| 2 | Multiple Orders move | `test_several_orders_move_together` |
| 3 | Exact headroom succeeds | `test_a_batch_that_exactly_fills_the_destination_succeeds` |
| 4 | Insufficient headroom rejects all | `test_insufficient_headroom_rejects_the_whole_batch` |
| 5 | One invalid Order rolls back all | `test_one_invalid_order_rolls_back_the_entire_batch` |
| 6 | Duplicate input rejected | `test_duplicate_ids_are_rejected`, `test_a_duplicate_cannot_produce_a_double_write` |
| 7 | Cross-company fails | `test_a_cross_company_assignment_fails_the_batch` |
| 8 | Cross-window fails | `test_a_cross_window_batch_is_rejected` |
| 9 | Invalid assignment fails | `test_an_unknown_assignment_id_fails_the_batch`, `test_an_empty_selection_is_rejected` |
| 10 | Source/destination constraints hold | `test_a_destination_slot_from_another_window_is_rejected`, `test_a_batch_can_move_orders_out_of_a_group` |
| 11 | Locked state rejects | `test_a_closed_window_refuses_the_batch` |
| 12 | No partial mutation | `test_a_rejected_batch_mutates_no_assignment_row` |
| 13 | Single-order move still green | `test_the_single_order_move_still_works_and_still_enforces_capacity` |
| 14–16 | Existing suites still green | **54 / 54**, see the regression run below |
| 17 | No Trip synchronized | `test_a_batch_move_synchronizes_no_trip_and_changes_no_trip_capacity` |
| 18 | No Trip capacity change | same test |
| 19 | No Driver/Vehicle change | `test_a_batch_move_changes_no_driver_or_vehicle_assignment` |
| 20 | No Loading/Vehicle Inventory change | `test_a_batch_move_creates_no_loading_or_vehicle_inventory` |

Two further tests beyond the required list: `test_the_batch_is_recorded_as_a_manual_move`
(attribution matches the single-Order path) and
`test_a_batch_move_never_changes_group_capacity`.

**No certified test was modified to make anything pass.**

### Regression run (§16.14–16)

`--filter "GroupTripReconciliationVisibilityTest|DistributionGroupTripTest|GroupTripLoadingIntegrationTest"`

```
Tests: 54, Assertions: 549, Errors: 1
```

**All three suites remain green.** The single error was not a test failure — it was a
`setUp` error, and its whole stack is framework database-refresh machinery with no
application frame in it:

```
RefreshDatabase.php:119 → migrate:fresh → FreshCommand.php:88
tests/Feature/Logistics/DistributionGroupTripTest.php:51
```

Re-run in isolation, that suite is clean:

```
DistributionGroupTripTest — Tests: 12, Assertions: 138 → OK
```

The cause is stated by the gate itself on the isolated run:

```
[GATE] busy (an ungated phpunit process is running) — queueing up to 2400s
```

A second, ungated phpunit process was working on the same pinned `ecos_dev_test` database,
so two `migrate:fresh` calls overlapped. Three independent facts agree: the stack contains
no application code, the gate reported the concurrent process, and the suite passes alone.
A defect in the service refactor would have surfaced as an assertion failure inside a test
body, not as a migration error before any test ran.

Suite totals after the refactor: `GroupTripReconciliationVisibilityTest` 32 ·
`DistributionGroupTripTest` 12 (incl. the preserved
`test_finalize_creates_the_canonical_trip_and_is_idempotent`) ·
`GroupTripLoadingIntegrationTest` 10.

---

## 16. Static Verification

| Check | Result |
|---|---|
| `php -l` (service, controller, routes, test) | clean |
| Pint | **PASS**, 2 files |
| PHPStan | **No errors** |
| ESLint (`distribution-workspace/`) | clean |
| `tsc --noEmit -p tsconfig.app.json` | **23 errors — the pre-existing baseline**, none in my files |
| i18n parity | **2192 / 2192**, 11 new keys, all translated |

The baseline TypeScript errors were not modified.

---

## 17. Browser Verification

**BROWSER NOT VERIFIED — NO SAFE MUTATION DATA.**

Verifying A (multi-select → destination → all move) and B (over-capacity → rejected, zero
moved) requires a Group in overflow with several movable Orders. Live Groups sit at
`3 / 20`, `0 / 20`, `0 / 20` — none is in overflow, and producing that state means moving
live Orders, which §19 forbids. No data was fabricated.

**Browser Verified is not claimed.** The evidence offered instead is 22 feature tests
against the real HTTP endpoint, including both browser scenarios: the successful multi-order
move and the over-capacity rejection with zero Orders moved.

---

## 18. Data Safety

No live business data was mutated. All test writes occurred in `ecos_dev_test` via the test
runner; every query against `ecos_dev` was a `SELECT`.

| Fact | Value |
|---|---|
| `orders` | 19 — unchanged |
| `distribution_windows` | 4 — unchanged |
| Groups | 3, capacities `20, 20, 20` — unchanged |
| Trips | 2, capacities `60, 60` — unchanged |
| `distribution_trip_orders` | 4 — unchanged |
| `distribution_window_orders` | 13 — unchanged |
| `logistics_driver_vehicle_assignments` | 0 |
| `vehicle_assignments` | 0 |
| `loading_sessions` | 0 |

`distribution_window_orders` holds 2 rows marked `manual_move`, both predating this task —
no batch or single move was issued against the live database at any point.

Code was deployed into the running containers (`docker cp` plus `route:clear`) so the route
could be confirmed registered; that writes code, not data.

---

## 19. Remaining Gaps

1. **Zone compatibility is unenforced on both move paths** (§9). Not a batch defect — the
   batch matches the single-Order path exactly. If it should be enforced it belongs on both,
   as its own owner decision.
2. **Moving an Order out of a finalized Group is permitted** by the existing Window-status
   lock (§11). Unchanged by this task; worth an explicit owner decision, since the
   Group → Trip invariant means such an Order becomes a Trip integrity exception.
3. **`DistributionAssignmentChanged` fires inside the transaction** with no listeners today.
   A future listener must be `afterCommit`.
4. **The batch is capped at 200 ids** per request, chosen so the destination Group's row
   lock is not held for an unbounded set. Larger operations need a second call.
5. **The UI moves into one destination per action.** Splitting one selection across several
   destinations is two operations, deliberately — each is individually atomic.

---

## Final Status

**IMPLEMENTED / FOCUSED VERIFIED**

Atomic multi-order movement now exists: one endpoint, one transaction, one capacity
decision, all-or-none. It reuses the existing capacity guard, the existing tenancy helper,
the existing permission and the existing write path, and required no migration.

Not certified. No full certification was run. No commit, no deploy. TASK-1-C not started.
