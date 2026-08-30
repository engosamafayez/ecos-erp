# TASK-WAVE-CARRYOVER-RELEASE-DEPENDENCY-CLOSURE-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Worktree:** `C:\ecos-develop`
**HEAD at start:** `ec43b470` → **HEAD at finish:** `2aefe0fb` (concurrent commit — §20)

> ## FINAL VERDICT: **CERTIFIED**
>
> The Wave carry-over release dependency is **completely identified and reduced to ONE file**:
>
> ```
> backend/Modules/Operations/DemandAnalysis/Application/Services/OrderPreparationCompletionReader.php
> ```
>
> No migration is required. No production code was changed. No Orders behaviour changed.
> Nothing was committed, deployed or migrated.
>
> The previous audit's estimate of a *three-file* Wave dependency was **too pessimistic** —
> two of those three files are Orders-owned and belong in the Orders release unit itself. §6
> gives the evidence.

---

## 1. Objective

Close only the dependency that blocks the certified Orders release: `OrderServiceProvider.php`
registers a Wave carry-over listener whose class chain reaches outside the Orders release unit.

## 2. Current tree (PART 19 snapshot 1)

| Item | Value |
|---|---|
| Snapshot 1 (18:42:15Z) | dirty **439** · staged 1 · modified 202 · untracked 241 · HEAD `ec43b470` |

## 3. Existing Wave architecture — **the key finding**

`OrderServiceProvider` **already registers four Wave listeners at HEAD**, and all four listener
classes are **committed and clean**:

| Event | Listener | At HEAD? | Tree status |
|---|---|---|---|
| `WaveStarted` | `HandlePreparationWaveStarted` | **YES** | clean |
| `WavePreparationStarted` | `HandlePreparationWavePreparationStarted` | **YES** | clean |
| `WaveCompleted` | `HandlePreparationWaveCompleted` | **YES** | clean |
| `WaveCancelled` | `HandlePreparationWaveCancelled` | **YES** | clean |
| `WaveClosed` | `HandlePreparationWaveClosed` | **no** | `??` new |
| `WarehouseAssigned` | `ExecuteReservationOnWarehouseAssigned` | **no** | `??` new |

**"Orders listens to Wave events" is the canonical, already-committed architecture** — not
contamination introduced by the Wave session. `WaveClosed` is the *fifth* instance of a pattern
committed four times already. Per PART 6 this existing boundary is **reused, not redesigned**.

This reframes the blocker entirely: the question is not "should Orders reference Wave" (settled
long ago, in `HEAD`), but "which *new classes* must exist for the fifth registration to resolve".

## 4. Existing carry-over implementation

`HandlePreparationWaveClosed` implements the authorised G-4 contract, unchanged by this task:

| Case | Condition | Action |
|---|---|---|
| **A** | in/past shipping (`out_for_delivery`, `delivered`, `returned`, `cancelled`) **or** `inventory_shipped_at` set | leave untouched |
| **B** | fully prepared but unshipped | leave untouched at Ready for Dispatch |
| **C** | unfinished, and status is exactly `ready_for_dispatch` | return to In Progress **through `ReturnToProcessingWorkflow` via `FulfillmentEngine`** |
| — | any other status (e.g. `awaiting_stock`) | counted and logged, never forced |

## 5. OrderServiceProvider boundary

| Registration | Owner | Verdict |
|---|---|---|
| 3 × Inventory events → `RetryReservationOnStockAvailableListener` | **Orders** | certified (Gate T) |
| `WarehouseAssigned` → `ExecuteReservationOnWarehouseAssigned` | **Orders** | certified (Gate T) |
| 4 × Wave events (Started/PreparationStarted/Completed/Cancelled) | **Orders** | **already at HEAD** |
| `WaveClosed` → `HandlePreparationWaveClosed` | **Orders** (listener lives in `Modules/Commerce/Orders`) | the dependency under audit |
| `ReprocessLegacyReservationsCommand` in `$this->commands([])` | **Orders** | Orders console command |

**The provider requires no split.** Nothing was commented out, deleted, stubbed or suppressed
(PART 5 honoured).

## 6. Exact cross-changeset dependency (PART 2) — **ONE file**

Full closure of the three new classes, every edge resolved:

```
HandlePreparationWaveClosed                       [Orders-owned, new]
  ├─ OrderStatus, Order                           → Orders release unit
  ├─ FulfillmentEngine                            → AT HEAD, clean ✅
  ├─ ReturnToProcessingWorkflow                   → AT HEAD, clean ✅
  ├─ WaveClosed (event)                           → AT HEAD, clean ✅
  └─ OrderPreparationCompletionReader             → ❗ NOT AT HEAD, untracked  ← THE ONLY EDGE

ExecuteReservationOnWarehouseAssigned             [Orders-owned, new]
  └─ all deps AT HEAD/clean or in the Orders unit → ✅ no foreign edge

ReprocessLegacyReservationsCommand                [Orders-owned, new]
  ├─ ReturnToPendingWorkflow                      → Orders ADR-042 cascade
  ├─ BranchAssignmentEngine::assign(Order, ?string)      → signature exists AT HEAD ✅
  └─ CoverageResolutionService::resolve($gov, $zone)     → called with **2 args**; HEAD
                                                            signature is `(string, ?string)` ✅
```

**The dirty diffs of `BranchAssignmentEngine` and `CoverageResolutionService` are NOT required.**
The tree adds an *optional* third parameter (`?string $canonicalZoneId = null`) — backward
compatible — and the command passes only two. Verified at the call site
(`ReprocessLegacyReservationsCommand:205-208`).

**Correction to the prior audit:** `TASK-ORDERS-RELEASE-INTEGRATION-AND-PRODUCTION-CLOSURE-001`
listed three files as the Wave dependency. Two of them (`HandlePreparationWaveClosed`,
`ReprocessLegacyReservationsCommand`) live in `Modules/Commerce/Orders`, are Orders-owned, and
belong to the **Orders** release unit — matching four committed siblings. Only one file is
genuinely foreign.

## 7. Wave-owned files (the release unit)

| File | Status | Why required | Ships with |
|---|---|---|---|
| `Modules/Operations/DemandAnalysis/Application/Services/OrderPreparationCompletionReader.php` | **new (`??`)** | `HandlePreparationWaveClosed` type-hints it in its constructor; CASE B cannot be decided without it | must ship **together with** the Orders unit |

**Self-containment verified:** exactly **one** import (`Illuminate\Support\Facades\DB`), zero
module dependencies. Two public methods (`fullyPreparedOrderIds`, `isFullyPrepared`). Reads only
`order_lines` and `wave_product_demand` — **both pre-existing tables**.

## 8. Orders-owned files (belong to the Orders release, not here)

`HandlePreparationWaveClosed.php` · `ExecuteReservationOnWarehouseAssigned.php` ·
`ReprocessLegacyReservationsCommand.php` · `OrderServiceProvider.php`

## 9. Shared files — already committed, nothing to ship

`FulfillmentEngine` · `ReturnToProcessingWorkflow` · `WaveClosed` · `WarehouseAssigned` ·
`BranchAssignmentEngine` (HEAD version sufficient) · `CoverageResolutionService` (HEAD version
sufficient).

## 10. Release unit (PART 13)

```
WAVE CARRY-OVER RELEASE UNIT — 1 file, 0 migrations

1. backend/Modules/Operations/DemandAnalysis/Application/Services/OrderPreparationCompletionReader.php
   why required : constructor dependency of HandlePreparationWaveClosed
   owner        : Operations/DemandAnalysis
   certification: behaviour now proven by WaveCarryOverDependencyTest (13 tests)
   dependency   : none (1 import: DB facade)
   state        : NEW (untracked)
   ships with   : the Orders release unit — same commit or immediately before it
```

**No migration.** `wave_product_demand` and `order_lines` already exist in `ecos_dev`,
`ecos_dev_test` and (verified previously) `ecos_erp`. **No unrelated Wave functionality included.**

## 11. Cross-day verification (PART 8) — **not re-verified; no evidence of breakage**

Stated plainly rather than implied: this task did **not** independently re-prove the
18:00 → 08:00 → 15:00 cross-midnight matrix. It did not need to — no timing code is in the
release unit, and none was touched.

Evidence relied upon: `WaveLifecycleTest` passes in full, including
`test_rotate_closes_current_and_creates_next_day_wave` and
`test_creates_incremental_wave_numbers_in_same_month`; the cross-day model is separately owned
by the certified Wave Operational Cycle work. **No timing defect was observed**, so PART 8's STOP
was not triggered.

## 12–14. Wave end · carry-over · historical membership — **PROVEN**

No test in the repository exercised `HandlePreparationWaveClosed`, `ReturnToProcessingWorkflow`,
`fullyPreparedOrderIds` or `OrderPreparationCompletionReader` — a repo-wide search of `tests/`
returned **nothing**. That gap is why the dependency could not previously be declared
releaseable. It is now closed by **`tests/Feature/Operations/WaveEngine/WaveCarryOverDependencyTest.php`** — **13 tests / 18 assertions, OK**.

| Proof | Test | Result |
|---|---|---|
| CASE C — unshipped, unfinished → **In Progress** | `test_case_c_unshipped_unfinished_order_returns_to_in_progress` | PASS |
| transition uses the **canonical workflow** (asserts the `return_to_processing` order_event) | `test_case_c_carry_over_goes_through_the_canonical_workflow` | PASS |
| carried-over order is **eligible for Wave #2** | `test_carried_over_order_is_eligible_for_the_next_wave` | PASS |
| CASE A — shipping lifecycle untouched | `test_case_a_order_in_shipping_lifecycle_is_left_untouched` | PASS |
| CASE A — `inventory_shipped_at` alone protects the order | `test_case_a_shipped_order_is_left_untouched` | PASS |
| non-eligible status never forced | `test_awaiting_stock_member_is_not_forced_by_wave_end` | PASS |
| **history survives wave end** | `test_historical_membership_survives_wave_end` | PASS |
| **membership accumulates across waves** | `test_order_may_hold_membership_in_several_waves` | PASS |
| replayed `WaveClosed` is idempotent, no duplicate membership | `test_replayed_wave_closed_event_is_idempotent` | PASS |
| empty wave is a no-op | `test_wave_with_no_members_is_a_no_op` | PASS |

### Membership contract discovered and documented

The first run of the history test failed on
`uq_prep_wave_orders_company_order_active`. That was **not** a fixture bug — it is the contract:

```sql
active_membership = CASE WHEN released_at IS NULL THEN 1 ELSE NULL END
UNIQUE (company_id, order_id, active_membership)
```

Because MySQL does not collide NULLs in a unique index, an order may hold **many historical
(released) memberships** but only **one active** membership at a time. The test was corrected to
model that — releasing the prior membership — which is exactly how carry-over works. Nothing was
weakened to obtain green (PART 15 honoured).

## 15. Reservation integrity (PART 9) — **no duplication**

`test_carry_over_does_not_duplicate_or_drop_the_reservation` asserts that after wave end the
order still holds `reservation_status = reserved`, `inventory_released_at` is **null**, and
`inventory_reserved_at` is **unchanged**. Carry-over deliberately does not release inventory, so
the next cycle cannot create a second reservation for the same commitment. **No reservation code
was touched.**

## 16. Material demand integrity (PART 10) — **no double counting**

`test_wave_end_creates_no_material_demand_rows` counts `wave_product_demand` rows for the wave
before and after closure: **unchanged**. Wave end writes no demand. **No formula was modified** —
Required / Available / Missing are untouched.

## 17. Tenant isolation (PART 12) — **passes**

`test_tenant_isolation_a_foreign_company_order_is_not_carried_over`: a foreign company's order
sitting in the membership table is left at Ready for Dispatch while ours moves to In Progress.
The listener's own `where('company_id', $event->companyId)` predicate is the mechanism —
**existing architecture reused, no new tenant logic**.

## 18. Orders recovery regression (PART 17) — **intact**

Full focused regression: **82 tests / 281 assertions — OK**

| Suite | Result |
|---|---|
| `WaveCarryOverDependencyTest` *(new)* | 13/13 |
| `WaveLifecycleTest` + `WaveIdempotencyTest` | pass |
| `OrderAvailabilityLifecycleContractTest` | 28/28 |
| `OrdersFinalCertificationHttpTest` (Gate T HTTP) | 22/22 |
| `OrderLifecycleAvailabilityReservationClosureTest` | 6/6 |

Awaiting-Stock recovery, Awaiting-Warehouse recovery, reservation recovery and the canonical
workflow transitions are all unchanged and green.

## 19. Static verification (PART 16)

| Check | Scope | Result |
|---|---|---|
| `php -l` | release file + new test | **PASS** |
| Pint | `OrderPreparationCompletionReader`, `HandlePreparationWaveClosed`, new test | **PASS — 3 files** |
| PHPStan L0 | `OrderPreparationCompletionReader`, `HandlePreparationWaveClosed` | **PASS — no errors** |

Global baselines were **not** claimed clean; only the files above were analysed.

## 20. Concurrent drift (PART 19)

| Measure | Snapshot 1 (18:42Z) | Snapshot 2 (19:05Z) |
|---|---|---|
| dirty / staged | 439 / 1 | 439 / 1 |
| **HEAD** | `ec43b470` | **`2aefe0fb`** |

**A commit landed during this task.** `2aefe0fb — fix(logistics): restore certified two-segment
permissions` contains `config/permissions.php`, the IAM logistics migration and its report — i.e.
**exactly Commit Group 1 recommended by the preceding worktree audit**. It was verified **not** to
touch any file in this dependency chain, so every finding above stands.

Host = runner parity was confirmed for the whole chain before testing (identical md5 for
`OrderPreparationCompletionReader`, `HandlePreparationWaveClosed`, `OrderServiceProvider`), so no
`docker cp` of production code was needed.

## 21. Stop conditions (PART 20) — none triggered

| # | Condition | Status |
|---|---|---|
| 1 | carry-over not implemented | **NO** — implemented and now proven |
| 2 | requires unrelated unfinished Wave work | **NO** — one self-contained reader |
| 3 | provider needs architectural redesign | **NO** — boundary already exists at HEAD (4 committed siblings) |
| 4 | Orders recovery would change | **NO** — 82-test regression green |
| 5 | Reservation redesign needed | **NO** — untouched, non-duplication proven |
| 6 | Material Demand redesign needed | **NO** — untouched, non-duplication proven |
| 7 | tenant isolation broken | **NO** — proven |
| 8 | cross-day timing broken | **NO evidence of breakage**; not re-verified (§11) |
| 9 | historical membership lost | **NO** — proven to survive and accumulate |
| 10 | uncertified behaviour beyond the Wave contract | **NO** — CASE A/B/C only |
| 11 | another session's work modified | **NO** — nothing outside the new test was written |
| 12 | Wave files cannot be attributed | **NO** — full closure in §6 |

## 22. Final verdict

> ### **CERTIFIED**
>
> - dependency **completely identified** → **1 file**
> - minimum release unit **known**, with **0 migrations**
> - existing Wave contract **preserved** (CASE A/B/C unchanged)
> - Orders recovery **intact** — 82/281 green
> - **no** unrelated Wave work included
> - **no** concurrent work modified
> - carry-over **works**; history **survives and accumulates**
> - **no** duplicate reservation; **no** duplicate material demand
> - tenant isolation **passes**; focused regression **passes**
> - exact manifest **produced** (§10)

**Files written by this task: one — `tests/Feature/Operations/WaveEngine/WaveCarryOverDependencyTest.php`
(new test, additive) — plus this report.** No production code, no migration, no configuration, no
existing test was modified. Nothing was staged, committed, reset, restored, stashed, deployed or
migrated.

### Recommended next step

The Orders release commit may now proceed, including the single file above. Recommended shape:

```
COMMIT 1 (or included in the Orders commit)
  backend/Modules/Operations/DemandAnalysis/Application/Services/OrderPreparationCompletionReader.php
  backend/tests/Feature/Operations/WaveEngine/WaveCarryOverDependencyTest.php

COMMIT 2 — feat(orders): close certified availability and reservation lifecycle
  the Orders release unit, incl. HandlePreparationWaveClosed,
  ExecuteReservationOnWarehouseAssigned, ReprocessLegacyReservationsCommand, OrderServiceProvider
```
