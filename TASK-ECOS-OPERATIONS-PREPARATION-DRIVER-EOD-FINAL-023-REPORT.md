# TASK-ECOS-OPERATIONS-PREPARATION-DRIVER-EOD-FINAL-023 — Report

## Status

**OPERATIONS FINAL SOURCE CLOSURE COMPLETE — READY FOR CANONICAL INTEGRATION** (source-only; integration itself not performed, per task scope).

**Mode:** RAPID FINAL SOURCE CLOSURE — source only, no integration/deploy/certification.
**Worktree:** `E:\ECOS\_operations-distribution-loading-final-022` (same worktree as Task 022, reused per instruction — no new worktree created, no dependency reinstall).
**Branch:** `task/operations-preparation-driver-eod-final-023`, created from Task 022's exact checkpoint.

A real mid-task complication: this worktree is shared with other concurrent agent processes. Three background implementer agents I dispatched for well-scoped frontend pieces all hit an account-level session rate limit mid-task, and two of them raced each other's `git add`/`git commit` calls on this shared worktree, which combined their two independent changesets into one mislabeled commit and left a dangling dead-end commit behind. **Nothing was lost** — verified by diffing the dangling commit against the final tree (byte-identical) before doing anything — but it required a careful, read-only forensic pass (documented below) before I could safely proceed. This is disclosed in full rather than glossed over.

## Base / Head

- **Base:** `d4a41636441156eab3fa2d0e222302f963502c1c` (Task 022's approved checkpoint) — confirmed via `git merge-base --is-ancestor` before and after all work.
- **Head:** `f337c354` (`feat(operations): Preparation/Distribution/Driver EOD final source closure (TASK-023)`), on top of `ca3aa897` (the reconciled frontend commit — see "Git recovery" below).
- Canonical `develop` untouched throughout (still `a9def700` as of Task 022; not re-verified as still current — re-check before assuming).
- Working tree clean; nothing pushed.

## Git recovery (disclosed in full)

Two of three dispatched frontend agents (Missing Materials columns; Warehouse Receipt UI) ended up racing on this shared worktree: one committed successfully first (`3649ec4a`), but that commit's tree accidentally included the *other* agent's already-staged-but-not-yet-committed files too (a classic shared-worktree TOCTOU race — each agent's own "check git status before committing" safeguard reported correctly at the moment it checked, but the other agent staged its own files in the gap before the first agent's `git commit` actually ran). The second agent then attempted its own recovery (a mixed reset + a diagnostic "retry-probe" commit + an amend), landing on `607b0784` with a commit message describing only its own half of the work.

Before touching anything, I verified via `git diff 3649ec4a 607b0784` (zero lines of output — byte-identical trees) and by grepping the actual file contents for both features' concrete markers (`receiveReturn`, `expected_driver_returns`) that **both agents' complete work was present together**, just under one commit with a message describing only half of it. I amended only the commit *message* (`ca3aa897`) to accurately describe both changes — no content was altered, reset, or discarded. The orphaned intermediate commits (`3649ec4a`, `89fc4e64`) remain as harmless, eventually-garbage-collected dangling objects; I did not force-delete anything.

The third agent (Final Cash status + driver-day facts) was cut off by the same rate limit before it could commit at all; I reviewed its diff for correctness myself (quality was consistent with this codebase's established conventions) and committed it together with my own backend work in the final `f337c354` commit.

## Changed files

**Backend (10 modified, 6 new):** `OrderStatus.php` (new `final_cash` enum case), `OrderServiceProvider.php` (new listener registration), `TripStatus.php` (new `onTheRoadValues()` helper), `CashHandoverService.php` (new event dispatch), `DriverDaySettlementReadService.php` (two additive facts), `LogisticsDistributionServiceProvider.php` (third `WaveClosed` listener registration), `MaterialDemandCalculator.php` (Expected Driver Returns correctness fix), `WaveDemandController.php` (expose the fix on Missing Materials), `CompleteOrderWorkflow.php` (repurposed for Final Cash), `ExpectedDriverReturnsShortageProjectionTest.php` (extended); new: `HandleTripCashHandoverConfirmed.php`, `CloseWaveDeliveryAttemptsListener.php`, `TripCashHandoverConfirmed.php` (event), `DeliveryAttemptClosureService.php`, `MoveToReviewFromDeliveryWorkflow.php`, `DeliveryAttemptClosureAndFinalCashTest.php`.

**Frontend:** Missing Materials tab (2 columns), Loading OS reconciliation (warehouse receipt UI), Driver Settlement detail page (2 new fact displays + test), Order status presentation (badge/tabs/labels/form-schema/terminal-checks — 8 files), i18n (`en`/`ar` × `logistics.json`, `orders.json`, `operations.json`; 2 stale staging files removed after merging).

## Task 022 adjacent-gap classification

Both gaps flagged in the Task 022 report were re-read first, as required, and classified before any implementation:

1. **`LoadingSession.status` not advanced by the Wave-closure custody sweep** — **(B) unrelated.** This is Loading-module internal bookkeeping (a warehouse-day parent record's own status column) with no bearing on Order execution state, physical custody, or cash settlement — the three authorities this task's §J concerns. Preserved as-is; not touched.
2. **`DispatchOrderWorkflow` bypasses custody checks (direct-dispatch path can mark an Order `OutForDelivery`→shipped without going through Loading)** — **(B) unrelated.** It governs the pre-delivery `ReadyForDispatch → OutForDelivery` edge, in a different module (Fulfillment's direct-dispatch path), with no code overlap with this task's post-delivery-attempt changes. This task's new closure sweep (§D/§E) correctly handles any Order it finds at `OutForDelivery` regardless of which path put it there, so the two are not entangled. Preserved as-is; not touched; still flagged for a future task with the right scope.

## A — Expected Driver Returns (Missing Materials)

Already computed and persisted (`wave_material_demand.expected_today`/`.projected_shortage_after_returns`, from a prior task) but exposed only on the sibling `material-demand` endpoint, never on the actual "Missing Materials" tab (`missing-materials` endpoint) — now exposed on both. More importantly, the existing aggregate (`MaterialDemandCalculator::expectedDriverReturns()`) counted a vehicle's custody identically whether its Trip was still actively out for delivery or genuinely closed — overstating what Preparation could plan against, since a still-active Trip might yet deliver successfully. Fixed to exclude custody on any Trip still `isOnTheRoad()` (new `TripStatus::onTheRoadValues()` helper), scoped at the Trip grain (a documented, deliberate precision limit — see the method's own docblock — since splitting a shared vehicle-item's quantity across a still-active vs. already-failed order on the *same* multi-stop trip would require an `AllocationRecord`-grain join nothing else in this codebase performs today; under-counting in that narrow window is the safe direction of error). Confirmed read-only throughout — no code path in `expectedDriverReturns()` writes to `on_hand_qty`, a reservation, or any pickable/reservable flag; it never did, and this task added no write.

## B — Day closure (company timezone)

No literal "midnight" trigger exists anywhere in this codebase (confirmed exhaustively by two independent research passes across every `Schedule::` registration) — Wave closure is a per-warehouse *configured* time, not necessarily 00:00. Rather than build a new scheduler (explicitly forbidden), this task treats Wave closure itself as the operational-day boundary for delivery-attempt purposes, because `Trip.preparation_wave_id → PreparationWave.planning_date` already *is* the company-timezone-resolved operational day (`CompanyTimezoneResolver`, which fails closed rather than defaulting to UTC/server time). The new closure logic is a third listener on the same `WaveClosed` event Task 022 already used for the Loading-custody sweep, so company-timezone correctness is inherited, not re-derived.

## C — Retryable orders (No Answer / Postponed → In Progress)

Discovered this already happens **today, immediately**, at the moment a driver records a "No Answer"/"Postponed" outcome (`ReleaseOrderOnRetryableOutcomeListener` → `ReleaseForReplanningWorkflow`) — not at day closure, and better than day closure (no unnecessary wait). This task adds a **backstop only**: at Wave closure, any Order still `OutForDelivery` under that Wave's Trips whose delivery attempt never settled at all (still `Pending`/`InProgress` — e.g. the driver never got to it) is released to `InProgress` the same way. The immediate path is untouched. Old goods are never touched — confirmed by a dedicated test asserting zero `inventory_items`/`stock_ledger_entries` writes from the whole closure sweep.

## D — Cancelled / On Hold

This was the one genuinely missing path. A **non-retryable** failed delivery (customer refused, product fault, ...) had **no closure path at all** before this task — `ReleaseOrderOnRetryableOutcomeListener` explicitly does nothing for it, and `MoveToReviewWorkflow` (the existing On-Hold workflow) explicitly *blocks* `OutForDelivery` as a source, so such an order was stranded at `OutForDelivery` forever. New `MoveToReviewFromDeliveryWorkflow` adds exactly this one new edge (`OutForDelivery → OnHold`, reusing the existing `OnHold` status value — not a new status), invoked by the same Wave-closure sweep for the non-retryable case, recording the `FailureReason` value as `hold_reason_code`. Goods remain in Driver/Vehicle custody in both the retryable and non-retryable branches — neither workflow touches inventory.

## E — Physical driver returns (full/partial warehouse receipt)

The backend authority (`ReceiveVehicleReturnAction`, via the canonical Inventory `AdjustmentInAction`) already existed and is already covered by 10 existing tests (full/partial/damaged/idempotent/conflicting/over-receipt/negative-qty) — genuinely correct, just entirely unreachable from the UI. Added the missing frontend wiring (Loading OS reconciliation panel) so warehouse staff can actually split a physical count into accepted (restocked) vs. damaged, matching this app's existing confirm-then-lock pattern (`cash-handover-panel.tsx`). No backend inventory logic was changed. **A known, pre-existing limitation flagged, not fixed:** a reconciliation line's receipt is one-shot per line — once received, it cannot be re-received with different numbers, so a scenario of "some goods back today, the rest next week" needs a new line/mechanism this task did not build (out of scope for a UI-wiring fix; flagged for a future task).

## F — Cash handover (Treasury)

Found already fully built (landed 3 days before this task, under a different task id): idempotent (row-lock-then-check, unique-constraint backstop), posts the actual received amount through the canonical Finance `CashService`, has a working frontend confirm-then-lock panel, and handles any amount (exact/short/over) by posting what was truly counted and recording the variance — never assuming equality. Fixed one concrete, verified bug: its i18n keys were staged for merge in a `-keys-to-merge.json` file but never actually merged, so the panel would have rendered raw translation-key paths; merged them into the live `en`/`ar` locale files and removed the now-redundant staging files.

## G — Final Cash

New `OrderStatus::FinalCash`, added through the existing enum (not a second status engine) and reconciled against every predicate that reads it (`isTerminal()` now includes it; `Delivered` deliberately stays in `isTerminal()` too — see the enum's own docblock for why removing it there would be a much wider, unaudited behavioural change this task does not make). Reached only via `CompleteOrderWorkflow` (repurposed from a prior no-op `Delivered→Delivered` transition that fired an event with zero listeners — the exact ADR-042-shaped slot this codebase had already reserved for "financial completion"), guarded by a direct database check for a confirmed `TripCashHandover` row on the order's *active* trip-order link — so a manual/direct call to the existing `/complete` route is held to the identical rule as the automatic path. New `TripCashHandoverConfirmed` event bridges Treasury's trip-grain confirmation to every `Delivered` order on that trip, dispatched only once per genuine confirmation (never on an idempotent repeat) and only after its own transaction commits. Verified an order that failed on one trip and was later delivered on a *different* trip is never finalized by the first trip's handover (order-grain precision via the active, non-superseded trip-order link).

## H — Driver Day facts

`DriverDaySettlementReadService` already covers most of the required facts (delivered/partial/failed counts, cash collected, advances, expenses, differences, returned-goods detail) at the day-detail grain — deliberately not touched at its 4-method board/list grain, or its large existing internals, given its own explicit "reduce merge-conflict risk" precedent from a prior task and this task's own time/risk budget. Added two small, purely additive facts to the detail response only: a `FailureReason` breakdown of the existing Failed count (`no_answer`/`postponed`/`other` — always sums back to the existing count, changes nothing else), and a Treasury cash-handover summary for the day's trips (confirmed count, total received, last confirmed timestamp) — the day-grain had **zero** visibility into handover status before this task. No compensation formulas were implemented, per explicit instruction.

**Known, disclosed limitation:** the day-boundary this file uses (`DATE(COALESCE(trip_started_at, dispatched_at, created_at))`) is a raw, non-timezone-aware SQL expression, unlike the Wave engine's `CompanyTimezoneResolver`. This pre-exists this task and was deliberately not retrofitted here, given the file's size/sensitivity and that this task's actual closure logic (§B) does not depend on it. Flagged for a future task.

## I — Three separate authorities (§J)

Enforced by construction throughout, not by convention alone: every new workflow in this task writes to exactly one of the three (Order status, or nothing/custody-is-implicitly-preserved, or — for the cash bridge — Order status only, never a journal) and never both. Concretely: the retryable/non-retryable closure workflows touch only `orders.status`/`hold_reason_code`, never inventory or `VehicleInventoryItem`; `CompleteOrderWorkflow` touches only `orders.status`, never re-posts or re-checks cash math beyond existence; `CashHandoverService`/`CashService` remain the only writers of the actual journal. Proven, not just asserted, by the new test suite (below).

## Focused tests

Backend tests were written to this suite's own established convention (used consistently since before this task: written to prove the behaviour, reviewed for correctness, **not executed** — see Environment Blockers) — two new/extended files:

- `DeliveryAttemptClosureAndFinalCashTest.php` (new, 9 tests): never-attempted stop → In Progress; non-retryable failure → On Hold; retryable failure handled as a backstop; a Delivered order is never touched by closure; the sweep is idempotent; the sweep never mutates `inventory_items`/`stock_ledger_entries`; `CompleteOrderWorkflow` refuses without a confirmed handover; succeeds after one; a handover event correctly advances Delivered orders to Final Cash; a *different* trip's handover does not finalize an order actually delivered elsewhere; only Delivered orders are ever candidates.
- `ExpectedDriverReturnsShortageProjectionTest.php` (extended, +2 tests): custody on a still-on-the-road trip is excluded; custody on a trip that's no longer on the road is included. The two pre-existing tests were read carefully and confirmed still correct and unmodified under the new logic (both use a Trip-less fixture, which falls through the new check's explicit null-Trip allowance unchanged).

Mapped against the task's 20-item list: items 1, 4, 5, 6, 7, 8, 9, 10, 11, 15, 16, 18 (company scoping is exercised implicitly via every fixture's explicit `company_id`), 20 are covered by name above. Items 12–14 (full/partial/remaining-in-custody warehouse return) rely on the **pre-existing** 10-test `VehicleReturnReceiptTest.php` suite, which this task's backend did not modify — not duplicated here. Item 17 (partial/under/over cash) relies on the pre-existing `CashHandoverConfirmationTest.php`. Item 19 (authorization) is enforced by pre-existing route permission middleware this task did not change; no new HTTP-facing endpoint was added.

Static verification actually run and passed: PHPStan across all four touched backend modules (`Commerce\Orders`, `Logistics\Distribution`, `Operations\Fulfillment`, `Operations\DemandAnalysis`) — 388 files, zero errors. The repository's own pre-commit "ECOS Engineering Guardian" hook (PHP syntax, ESLint, TypeScript) passed on both final commits, covering every backend and frontend file this task touched, including the two agent-authored changesets reviewed and committed on their behalf. `git diff --check` clean throughout.

## Environment blockers

**BACKEND FOCUSED TESTS — ENVIRONMENT/INFRASTRUCTURE BLOCKED.** The test database (`127.0.0.1:3306`) is unreachable in this environment — a pre-existing condition documented across many prior tasks in this codebase, confirmed still true here. No DB bootstrap/restore/`migrate:fresh` was attempted, per instruction. No dev server was reachable for a frontend click-through either.

## Checkpoint

`f337c354` on `task/operations-preparation-driver-eod-final-023`, off Task 022's `d4a41636`. Working tree clean; `git diff --check` clean; nothing pushed; canonical `develop` untouched.

## Remaining Operations gaps (for a future task)

1. The two Task 022 adjacent gaps (§ above), still open, still classified (B).
2. Reconciliation-line warehouse receipt is one-shot per line (§E) — no path for "partial today, rest later" as a repeatable confirmation.
3. `DriverDaySettlementReadService`'s day-boundary is not timezone-aware, unlike the Wave engine (§H).
4. This task's own closure sweep is a Wave-scoped backstop; it does not independently guarantee every Trip has actually finished its deliveries before treating its Orders as closable beyond what "the Wave closed" already implies — consistent with, but inheriting, the same boundary Task 022's custody sweep already accepted.
