# TASK-SHIPPING-DISTRIBUTION-CORE-001 — Final Runtime Certification

**Date:** 2026-08-12 · **Runner:** `ecos-dev-testrunner` · **DB:** `ecos_dev_test` · **Branch:** `develop`
**Scope executed:** certification only. No Distribution production code was written or modified.

> # VERDICT: **SHIPPING DISTRIBUTION CORE = NOT CERTIFIED**
>
> Two independent blockers, both established from runtime evidence:
>
> 1. **The Distribution Core suite does not pass — 12 of 23.** 1 error + 10 failures. **Baseline-confirmed pre-existing**: the identical 1 error + 10 failures reproduce with every one of my changes reverted to `git HEAD`. Not a regression.
> 2. **TEST 12 cannot be satisfied as specified.** `distribution_window_orders` has **no warehouse column**, and no `distribution_*` table models a warehouse at all. There is no Distribution-side field against which `distribution warehouse == assigned warehouse` can be asserted.
>
> Everything outside Distribution passed: **115 regression tests / 385 assertions green**, PHPStan L0 + core L6 clean, MAIN untouched.
>
> Per PART 15 I stopped rather than changing production code to make the suite pass, and per PART 2 I did not touch another agent's files — **every Distribution Core file is untracked, in-flight work.**

---

## 1. Runtime Environment

| Check | Result |
|---|---|
| Docker Desktop | running — `ecos-dev-testrunner`, `ecos-dev-app`, `ecos-dev-mysql` all up |
| Other agent on the runner | **none** — no `phpunit`/`artisan` process was running; runner idle |
| DB-backed tests in flight on `ecos_dev_test` | none observed |
| Runner available | yes |

## 2. Database Safety

`SELECT DATABASE()` → **`ecos_dev_test`** ✔ verified before every test execution.

Never used: `ecos_dev` (for tests), `ecos_erp`, `ecos_erp_test`, MAIN. The DEV MySQL server hosts only `ecos_dev` and `ecos_dev_test` — MAIN lives on a **physically separate container** (`ecos-mysql`), so it was unreachable from this work by construction.

## 3. Source Parity

All Distribution sources, tests, and routes compared host ↔ `ecos-dev-testrunner`:

```
OK = 82    DIFF = 0    MISSING = 0
```

Covering 82 files: Distribution controllers (6), services (9), models (16), enums (9), events (7), resources (11), the service provider, **20 migrations**, both test files, and `routes/api.php`. `config/distribution.php` also present and identical.

No `git reset`, no revert, no other agent's file modified.

*(Note: my earlier report recorded these files as absent from `ecos-dev-app`. That remains true — the gap was app-container only. The **test runner** has them in full, which is what matters here.)*

## 4. Migrations

`php artisan migrate --force` on `ecos_dev_test` → **"Nothing to migrate"** — all 20 Distribution migrations were already applied. Tables verified present:

```
distribution_delivery_actions      distribution_trip_custody
distribution_delivery_exceptions   distribution_trip_orders
distribution_delivery_proofs       distribution_trip_returns
distribution_delivery_stops        distribution_trip_settlements
distribution_payment_collections   distribution_trips
distribution_slot_zones            distribution_virtual_slots
distribution_window_orders         distribution_windows
distribution_zone_plans            distribution_zones
```

No destructive change. No migration run against MAIN.

---

## 5. Warehouse → Shipping Integration

**Distribution does not model a warehouse.**

`distribution_window_orders` columns:

```
id · company_id · distribution_window_id · order_id · distribution_zone_id
virtual_slot_id · assignment_source · assigned_by · assigned_at
previous_window_id · assignment_reason · created_at · updated_at
```

A schema-wide scan for `%warehouse%` across every `distribution_*` table returns only three columns, all on `distribution_trip_returns` (`warehouse_confirmed_at`, `warehouse_confirmed_by`, `warehouse_confirmed_qty`) — returns confirmation, which belongs to Delivery/Loading and is explicitly out of scope.

**This is architecturally correct and simultaneously blocks the requested assertion.** It is correct because Shipping must never choose a warehouse (established boundary): Distribution keys on `order_id`, and the warehouse is reached transitively via `orders.assigned_warehouse_id`, which Distribution never reads or writes — so cross-warehouse substitution is impossible *by construction*.

It blocks the assertion because `assigned warehouse ≡ distribution warehouse` has **no Distribution-side field to compare against**. The requirement as written presumes Distribution carries a warehouse; it does not.

I did **not** add one. Doing so would be exactly the failure mode PART 5 forbids — "لا تصلح Shipping ليخترع Warehouse".

## 6. Test 12 Result

**NOT RUN — UNSATISFIABLE AS SPECIFIED.**

Two reasons, either sufficient:

1. No Distribution-side warehouse field exists (§5).
2. The pipeline step it depends on is broken: automatic collection into a window does not work in this suite (§6 below, `test_1`), so an order cannot reach Distribution to be asserted on in the first place.

The transitive property that *can* be stated from evidence: an order's `assigned_warehouse_id` is set by `BranchAssignmentEngine`, consumed unchanged by Reservation and Preparation (**runtime-proven** in TASK-ORDER-PREPARATION-FLOW-REPAIR-001: order = reservation = wave = `019f4e1c…`), and never referenced by any Distribution service. Distribution therefore cannot substitute a warehouse. That is a boundary guarantee, **not** the runtime assertion TEST 12 asks for.

## 7. Distribution 23-Test Result

```
Tests: 23, Assertions: 68, Errors: 1, Failures: 10       →  12 PASS / 23
```

| Test | Failure |
|---|---|
| `test_1_new_order_before_cutoff_enters_current_window_zone_and_slot` | assignment is `null` |
| `test_6_manager_manually_adds_late_order_to_current_window` | `null` vs `11` |
| `test_7_new_order_entering_existing_zone_updates_zone_count` | `0` vs `1` |
| `test_8_new_order_in_slotted_zone_updates_slot_aggregation` | `0` vs `1` |
| `test_9_zone_exceeding_slot_capacity_is_detected_as_overflow` | `false` vs `true` |
| `test_10_zone_attached_to_slot_after_collection_pulls_existing_orders_in` | `0` vs `2` |
| `test_11_overflow_produces_suggestions_that_do_not_mutate_anything` | size `0` vs `1` |
| `test_12_manager_approving_a_suggestion_changes_the_assignment` | **ERROR** |
| `test_19_late_manual_assignment_updates_aggregation_immediately` | `0` vs `1` |
| `test_21_individual_order_moves_between_slots_without_disturbing_zone_or_peers` | `null` vs slot id |
| `test_22_live_aggregation_updates_on_both_slots_after_reassignment` | `0` vs `3` |

### Baseline — the failures are NOT mine

The full suite was re-run with **every file I have changed in this session reverted to `git HEAD`** (11 files restored, 2 new files deleted):

```
BASELINE:  Tests: 23, Assertions: 68, Errors: 1, Failures: 10   ← identical
```

Byte-identical outcome. **Pre-existing.** My changes were then restored.

### Diagnosis (classification only — nothing was fixed)

Every failure reduces to one symptom: **no order ever lands in `distribution_window_orders`.** All downstream assertions — zone counts, slot aggregation, overflow detection, suggestions, reassignment, live aggregation — collapse from that single cause.

It is **not** the eligibility configuration, which was verified correct at runtime: `config('distribution.eligible_order_statuses') = ['new','in_progress']`, matching the V3 enum, with window `opens_at 00:00` / `closes_at 23:59`. It is **not** the fixtures: the test's `order()` helper sets `logistics_city_id`, and `makeCity()` links that city to a `distribution_zone_id`.

Both the **automatic** path (`test_1`) and the **manual late-assignment** path (`test_6`) fail, so the defect is in the assignment write itself, not in cutoff or window selection. Since `DistributionCollectionService`, `DistributionWindowOrder`, `ManualAssignmentService`, `config/distribution.php`, and `DistributionCoreTest` are **all untracked in-flight work**, this is an incomplete implementation, not a regression — and repairing it is outside this task's authorization.

## 8–13. Business Contracts, Live Update, Cutoff, Late Order, Reassignment, Capacity

**NOT ESTABLISHED.** The 23 PART-8 contracts, the PART-9 live-update scenario (received 5, total 7, remaining 2), the PART-10 cutoff behaviour, late manual assignment, individual reassignment, and PART-11 capacity/overflow are precisely what the failing tests were written to prove. With 11 of them failing on a shared root cause, none of these contracts can be certified from runtime evidence.

Two things *were* confirmed independently:

- **Contract 3 holds** — `confirmed` is not a standalone status. The V3 `OrderStatus` enum has no such case, and the live eligibility config correctly uses `['new','in_progress']`.
- **Contract 16 is structurally satisfied** — `RedistributionSuggestionService` is a separate service from `ManualAssignmentService`; suggestion and application are distinct code paths, so a suggestion cannot self-apply. (`test_11`, which asserts it, still fails for the collection reason above.)

## 12. Tenant Isolation

Not exercised for Distribution (blocked by §7). The existing tenant authority remains green elsewhere: `PreparationEntryGateTest` proves `cross-company → http=422 attached=0`, and `WarehouseCoverageBrandAssignmentTest` TEST 9 proves a foreign-company warehouse is refused even with matching geography *and* brand. `DistributionCollectionService` does scope by `company_id`, but that was verified by inspection, not runtime.

## 13. Idempotency

Not exercised for Distribution. Idempotency verified in adjacent layers this session: wave creation and membership (repeated scheduler runs → one wave, one membership row) and reservation retry (three-level guard).

## 14. Proximity Limitation

Recorded as instructed, unchanged:

**PROXIMITY = APPROXIMATION.** The platform's proximity handling is governorate/zone membership plus Haversine straight-line distance between branch and delivery coordinates. That is **not** true distance optimisation and **not** route optimisation. No Geography Engine was built, no coordinates added, no routing introduced.

## 15. Warehouse Assignment Regression

**PASS — 13/13.** `WarehouseCoverageBrandAssignmentTest` green, including the mandatory negative regression (right geography, wrong brand → refused) and D1 canonical Arabic resolution. `BranchAssignmentEngineTest` scenarios A–D green.

## 16. Preparation Regression

**PASS.** Preparation Entry Gate green (`ENTRY GATE POLICY: new, in_progress`; cross-company refused), Wave Engine green, V3 Transition Resolution green.

**Material Demand:** `MaterialDemandCalculator` untouched all session; container parity `ce69612a` (certified host version) verified; contract `available = on_hand − reserved` (`15 − 8 = 7`, `missing 3`) intact.

## 17. F4

**PASS.** `RecipeGateTenantRepairTest` markers all as specified: `F4_FORWARD`, `F4_REVERSE`, `MATRIX 6/6`, `DIRECT_FG`, `NEG_STOCK`, `RECIPE_MISSING`, `CROSS_BRAND`.

## 18. Option B

**PASS.** `OPTION_B: recipe=outofstock fg_stock=0 → order=awaiting_stock reservation=awaiting_stock reserved=0.00` — exactly the ADR-027 §16.1 contract.

## 19. IAM Regression

**PASS — untouched.** No IAM file was modified this session; `UserPolicy.php` was deliberately left alone despite a known host↔container parity difference (protected module, off the causal path). The tenant boundary remains certified.

### Combined regression run

```
OK (115 tests, 385 assertions)
```

## 20. PHPStan

**L0 platform-wide: [OK] No errors.** **Core L6: [OK] No errors.**

## 21. Pint

No new code was authored in this task, so no new scoped Pint surface exists. The files authored in prior tasks this session remain clean; known pre-existing violations were left untouched.

## 22. MAIN Control

**MAIN UNTOUCHED — verified.**

- `ecos_erp` on the separate `ecos-mysql` container: **551 tables**, no migration run, no writes.
- The DEV MySQL server hosts only `ecos_dev` and `ecos_dev_test` — MAIN is not reachable from the DEV stack.
- No schema change, no production data mutation, no test writes to MAIN.

---

## 23. Final Verdict

# SHIPPING DISTRIBUTION CORE = **NOT CERTIFIED**

| Certification condition | Result |
|---|---|
| 23/23 Distribution tests PASS | ❌ **12/23** — 1 error, 10 failures (pre-existing, baseline-confirmed) |
| TEST 12 PASS runtime | ❌ **unsatisfiable as specified** — no warehouse field in any `distribution_*` assignment table |
| Warehouse Assignment regression PASS | ✅ 13/13 |
| Preparation regression PASS | ✅ Entry Gate, Wave Engine, V3, Material Demand contract intact |
| F4 PASS | ✅ |
| Option B PASS | ✅ |
| IAM boundary PASS | ✅ untouched |
| MAIN untouched | ✅ verified |
| PROXIMITY recorded | ✅ **APPROXIMATION**, not true distance optimisation |

**Cause of non-certification:** the Distribution Core implementation is incomplete. Its collection/assignment write path does not place orders into `distribution_window_orders`, and eleven tests written to prove the business contracts fail on that single root cause. All of the implicated files — `DistributionCollectionService`, `DistributionWindowOrder`, `ManualAssignmentService`, `config/distribution.php`, `DistributionCoreTest` — are **untracked, in-flight work belonging to another agent**, which PART 2 forbids me from modifying and which this certification-only task does not authorize me to repair.

**Nothing was regressed by prior work in this session.** The baseline run proves the identical failures on unmodified `HEAD`.

### Required before re-certification

1. The owning agent completes the Distribution collection/assignment write path so `test_1` and `test_6` place orders into `distribution_window_orders`; the nine dependent failures should resolve with it.
2. A decision on TEST 12: either accept the boundary guarantee as sufficient (Distribution provably cannot substitute a warehouse, because it never references one), **or** authorize adding a warehouse reference to the Distribution assignment model — a schema change that is currently unauthorized and that must not be made by inventing warehouse selection inside Shipping.
3. Deploy the Distribution module into `ecos-dev-app` if it is to be exercised outside the test runner.

**STOPPED.** No Loading, Vehicle Inventory, Driver, Delivery, Cash Settlement, Route Optimization, Packing, Order Splitting, or Warehouse Transfer work was started.
