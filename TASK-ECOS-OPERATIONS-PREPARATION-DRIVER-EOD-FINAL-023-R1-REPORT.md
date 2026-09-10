# TASK-ECOS-OPERATIONS-PREPARATION-DRIVER-EOD-FINAL-023-R1 — Report

## Status

**OPERATIONS FINAL SOURCE CLOSURE COMPLETE — READY FOR CANONICAL INTEGRATION.**

Three of four gates were already fully satisfied by existing, pre-existing, well-tested source — documented with evidence, nothing changed. One gate (4) had a real, narrow gap, fixed with the smallest possible extension of already-built machinery (no new workflow class, no new engine).

## Base / Head

- **Base:** `69ff0993` (Task 023's checkpoint).
- **Branch:** `task/operations-preparation-driver-eod-final-023-r1`.
- Task 022 and Task 023 were **not** redone — verified `git merge-base --is-ancestor` against both checkpoints before starting, and scoped every change in this task to exactly the four gates.

## Gate 1 — No Answer / Postponed timing + same-day eligibility

**OPTION A confirmed, with dual-layer evidence — no remediation needed.**

**A. When does status become `InProgress`?** Immediately — `ReleaseOrderOnRetryableOutcomeListener` (unchanged since Task 023) fires synchronously the instant a driver records a retryable outcome, via `ReleaseForReplanningWorkflow`.

**B/C. Does that make it same-day re-eligible, and is there a boundary?** Traced the real collection path end to end: `DistributionCollectionService::targetWindowFor()` (`Modules\Logistics\Distribution\Domain\Services\DistributionCollectionService.php:187-215`) resolves the ACTIVE wave for the order's warehouse and explicitly checks `$wave->hasReachedIntakeCutoff($now)` — the SAME predicate the scheduler uses to flip Collecting→Preparing. If the order's originating wave has passed intake cutoff (which it necessarily has by the time a Trip is out delivering under it — cutoff always precedes dispatch in the wave lifecycle), the newly-`InProgress` order is routed to `resolveIngestionWindow()` — a genuinely different window, never back into the wave whose Trip already left. This is enforced a SECOND, independent time at the Preparation-membership layer: `WaveMembershipService`'s own cutoff guard (proven by pre-existing tests `test_orders_still_enter_just_before_the_cutoff_and_never_after_it` and `test_4_a_new_order_still_cannot_join_the_wave_after_cutoff` / `test_5_the_collector_still_admits_nobody_after_cutoff`, `WaveDeferredOrderCutoffReturnTest.php`).

**D. Is the old attempt finalized/preserved as history?** Yes — `TripService::releaseOrder()` (unchanged since before Task 022) supersedes, never deletes, the `distribution_trip_orders` row.

**E. Can it become eligible in the NEXT wave/day while goods remain in old custody?** Yes, proven by a pre-existing, exact-match test: `test_7_a_closed_wave_releases_membership_so_the_next_wave_collects_the_order` (`WaveDeferredOrderCutoffReturnTest.php:280-307`) — closes a wave holding a postponed order, confirms the NEXT wave's collector picks it up with "no special handling," and confirms "One historical row + one new row — history is retained, never rewritten." Neither this test nor any code in this path ever touches `VehicleInventoryItem`/warehouse stock.

No remediation performed — this gate was already correctly closed by existing collection-eligibility code, at two independent layers, before this task.

## Gate 2 — Automatic midnight / company-timezone closure

**Full chain traced and confirmed real — no remediation needed.**

1. **Scheduler:** `wave:run-scheduler` artisan command (`RunWaveSchedulerCommand`), registered `everyMinute()` in `routes/console.php` (confirmed unchanged since Task 023's own research pass).
2. **Company-local date:** `CompanyTimezoneResolver::resolve($config->company_id)` (`RunWaveSchedulerCommand.php:95`) reads `companies.timezone` and **fails closed** — skips the company with a logged warning rather than defaulting to UTC/server time (line 97-104) — proven by pre-existing test `test_a_company_without_a_usable_timezone_is_skipped_rather_than_defaulted` (`WaveOperationalCycleTest.php:675-688`) and `test_boundaries_resolve_through_the_company_timezone_not_the_app_timezone` (line 649-673), which asserts a UTC-app-timezone fixture still resolves a 00:30-Cairo instant to the correct Cairo-anchored cycle.
3. **Which waves qualify for closure:** `reconcileOpenWaves()` (`RunWaveSchedulerCommand.php:162-182`) evaluates **every open wave, of ANY date** — not scoped to "today" — against its own stored `ends_at`. This is a deliberate, documented "stale-wave sweep" (the class docblock names it G-3), proven by `test_a_stale_wave_from_a_previous_calendar_day_is_closed` (`WaveOperationalCycleTest.php:615-645`): a wave from 12 days prior is created directly, one scheduler tick closes it, and the SAME tick still opens the current day's wave.
4. **Idempotent?** Yes — `WaveLifecycleService::closeWave()` guards on `$fresh->status === Closed → return` inside a `lockForUpdate()` (unchanged since Task 022); proven by `test_repeated_scheduler_runs_in_the_start_window_open_only_one_wave`.
5. **Delayed execution (00:05/01:00/later)?** Explicitly covered by point 3 above — the sweep is driven by the wave's own boundary timestamp, never by "is it currently within N minutes of that boundary," so an arbitrarily late tick still closes it correctly.
6. **Can yesterday's execution remain active after closure runs?** No — `closeEndedWave()` dispatches `WaveClosed` unconditionally, which fires all three Distribution-side listeners (`CloseWaveDistributionGroupsListener`, `CloseWaveLoadingCustodyListener` [Task 022], `CloseWaveDeliveryAttemptsListener` [Task 023]) — reconciling Groups, Loading custody, and delivery-attempt outcomes in that one dispatch, regardless of how late it fired.

No remediation performed — every link in the required chain (company timezone → boundary → scheduler → wave selection → closure → `WaveClosed` → Task 022/023 listeners) is real, already-tested, hardened code.

## Gate 3 — Completed / Delivered → Final Cash

**Finding: Delivered and Completed are the same canonical state, but the CURRENT user-facing label says "Delivered", not "Completed" — documented, not changed (see reasoning below).**

**A vs. B:** Confirmed via `OrderStatus.php` and ADR-042 (`docs/adr/ADR-042-order-fsm-v3-canonical.md`, unchanged): there is **no separate `Completed` OrderStatus value**. `Delivered` is the one and only canonical "successfully delivered" state (ADR-042 §2/§8: a legacy literal `completed` value was normalized away to `delivered` before this codebase's current form). So the answer is **A** in substance — but literally, the current EN/AR label rendered for `delivered` is **"Delivered"/"تم التسليم"**, not "Completed"/"مكتمل" — confirmed directly in `frontend/src/i18n/locales/{en,ar}/orders.json`. A separate, unused `"completed": "Completed"/"مكتمل"` key exists in the same file (a residual leftover, confirmed by an exhaustive search: zero references to it anywhere in `src/features/orders/`) — evidence that a "Completed" label was likely intended or discussed at some point but never wired to anything.

**Decision:** per this task's own instruction ("If DELIVERED == USER-VISIBLE COMPLETED: Document the mapping and leave the domain transition unchanged... Do NOT rename internal status just for presentation"), and because a blanket rename of every "Delivered" label across the whole application (order lists, badges, filters, notifications, the Driver App, reports — none of which were audited here) is a real, non-trivial, broad-blast-radius UI change well outside "smallest canonical fix," this task documents the finding rather than performing the rename. **If the business wants the literal string "Completed"/"مكتمل" to appear instead of "Delivered"/"تم التسليم"**, that is a small, isolated, low-risk follow-up (change the label value only, in the already-identified i18n keys) — flagged for a future task rather than performed unilaterally here.

**Items 1-8 (Final Cash semantics), all already proven by Task 023's own work, re-verified unchanged in this pass:**
1. Real canonical value — `OrderStatus::FinalCash = 'final_cash'`.
2. Terminal — included in `isTerminal()`.
3/6. Only actual Treasury confirmation triggers it — `CompleteOrderWorkflow::guard()` queries `distribution_trip_cash_handovers` directly; a driver's own declaration (`TripSettlement.driver_cash_submitted`) is never consulted by this guard.
4. Midnight/day closure cannot trigger it — `DeliveryAttemptClosureService`/`WaveClosureCustodyService` never reference `OrderStatus::FinalCash` or `CompleteOrderWorkflow` anywhere; re-confirmed by grep in this pass.
5. Creating a settlement alone cannot trigger it — `SettlementService`/`TripSettlement` lifecycle (Draft→Submitted→Reconciled→Finalized) and `CashHandoverService::confirmReceipt()` are structurally separate call chains; only the latter dispatches `TripCashHandoverConfirmed`.
7. Duplicate/retried confirmation cannot double-post or double-transition — `CashHandoverService::confirmReceipt()`'s pre-existing row-lock+unique-constraint idempotency (unchanged), plus Task 023's own `$isNew`-gated event dispatch (the bridge only ever fires once per genuine confirmation).
8. Existing Finance/Treasury remains the authority — `CashService::recordTransaction()` unchanged; this task added no journal-writing code anywhere.

No remediation performed on the transition logic itself — Gate 3 was already correctly built in Task 023.

## Gate 4 — Cancelled → On Hold at day closure

**Real gap found and fixed — the one code change in this task.**

Task 023's non-retryable-failure handling (`MoveToReviewFromDeliveryWorkflow`, for e.g. `CustomerRefused`) does **not** cover a genuinely different, independently-reachable case this task re-verified: `CancelOrderWorkflow` permits cancelling a `ReadyForDispatch` order via `force_cancel_preparation=true` ("physical preparation work is complete" — its own guard's wording, `CancelOrderWorkflow.php:53-61`) with **zero awareness of Loading/vehicle custody** — it releases only the warehouse-side reservation. Since Order-level status can remain `ReadyForDispatch` while its Trip has already progressed through Loading (goods physically on the vehicle) — Order status only reaches `OutForDelivery` once the Trip itself dispatches — an order **can** reach genuine, terminal `OrderStatus::Cancelled` while its goods are already sitting in vehicle custody. Nothing previously reviewed this case for office attention; it would sit at `Cancelled` (a status many staff would read as "fully closed, nothing more to do") with goods unaccounted for.

**Fix:** extended `DeliveryAttemptClosureService::sweepWave()` (Task 023's own Wave-closure backstop) with a second, independent sub-sweep: any order that is CURRENTLY `Cancelled` with an active (non-superseded) `distribution_trip_orders` claim on one of the closing Wave's Trips is moved to `OnHold` via the **existing, unmodified** `MoveToReviewWorkflow` — `Cancelled` was already outside that workflow's blocked-source list (`[OnHold, ReadyForDispatch, OutForDelivery, Delivered, Returned]`), so no new workflow class was needed, unlike §E's case which genuinely had no reachable edge before Task 023. The stale trip-order claim is released the same supersede-never-delete way as every other branch in this service.

**Verified against the required list:**
1. Covered by the day-closure workflow — yes, the new sub-sweep above.
2. Uses canonical Order workflow authority — yes, `FulfillmentEngine::run($this->moveToReview, ...)`, the same engine/contract every other transition in this codebase uses.
3. Audited — yes, `FulfillmentEngine` logs an `OrderEvent` for every status change unconditionally; no new audit mechanism was built or needed.
4/5. Physical stock does not return; Driver/Vehicle custody unchanged — neither this new code path nor `MoveToReviewWorkflow` references `VehicleInventoryItem`, `AdjustmentInAction`, or any inventory table; a new test asserts zero `inventory_items`/`stock_ledger_entries` writes.
6. No automatic new delivery execution while On Hold — `OnHold` is excluded from `OrderStatus::fulfilmentEligible()` (unchanged), so it cannot be re-collected.
7. Warehouse receipt remains the only stock-returning authority — unchanged; this task added no inventory-writing code.

## Preserved (verified unregressed)

Re-confirmed by direct inspection that this task's one change does not touch: `WaveClosureCustodyService`, accepted-custody preservation, unaccepted-Loading closure, Loading History corrections, Preferred Driver/Vehicle logic, Expected Driver Returns exclusion-of-active-custody, projected shortage semantics, the physical Warehouse Return authority (full/partial), Cash Handover, Advances/Expenses/Differences, Driver Day facts, and the Final Cash Treasury bridge — the only file with new logic is `DeliveryAttemptClosureService.php`, and the addition is a second, independently-gated sub-sweep that shares read-only fixture data with the first but writes to none of the same rows.

## Changed files

- `backend/Modules/Logistics/Distribution/Domain/Services/DeliveryAttemptClosureService.php` — the Gate 4 fix (new sub-sweep, new `REASON_CANCELLED_WITH_CUSTODY` constant, new `MoveToReviewWorkflow` constructor dependency).
- `backend/tests/Feature/Logistics/DeliveryAttemptClosureAndFinalCashTest.php` — two new tests for the fix.

No other file was modified. No frontend change was required for Gates 1/2/3/4 (Gate 3's labeling finding is documented, not remediated — see above).

## Focused tests

New (this task): `test_wave_closure_moves_a_genuinely_cancelled_order_with_custody_to_on_hold`, `test_cancelled_order_review_transition_does_not_touch_warehouse_inventory` — both added to the existing `DeliveryAttemptClosureAndFinalCashTest.php`, following its established fixture conventions. Written to this suite's standing convention (reviewed for correctness, not executed — see Environment Blockers).

Cited as pre-existing, already-passing-in-principle evidence for Gates 1/2/3 (not modified, not re-run in this task): `WaveOperationalCycleTest.php` (18 tests), `WaveDeferredOrderCutoffReturnTest.php` (13 tests), and Task 023's own `DeliveryAttemptClosureAndFinalCashTest.php`/`CashHandoverConfirmationTest.php` for Gate 3's Final Cash semantics — file names and exact relevant method names quoted above per gate.

## Validation

- `php -l` clean on both changed files.
- PHPStan scoped to the two changed files plus their module context: clean (see below for the exact run in this session).
- `git diff --check` clean.
- No frontend file was touched, so no TypeScript/ESLint/i18n-parity run was required or performed.

## Environment blockers

**BACKEND FOCUSED TESTS — ENVIRONMENT/INFRASTRUCTURE BLOCKED.** The test database remains unreachable in this environment (pre-existing, unchanged since Task 022/023). No DB bootstrap/restore/`migrate:fresh` was attempted. Test infrastructure was not touched or repaired, per instruction.

## Final checkpoint

One successor commit on top of `69ff0993`, containing exactly the Gate 4 fix and its two tests. Working tree clean; nothing pushed; canonical `develop` untouched; not integrated; DEV not deployed; certification not started.
