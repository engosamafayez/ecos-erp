# TASK-GOLIVE-PREPARATION-RUNTIME-CERTIFICATION-001 — Engineering Report

**Date:** 2026-08-10
**Type:** Certification only. No production code was modified. Nothing was committed. No failure was fixed.
**Final verdict:** see Section 11.

> **Document provenance.** This report merges **two independent certification runs** that executed against the
> same worktree on 2026-08-10. **Run A** is the earlier run, recorded in the previous revision of this file.
> **Run B** is the run executed for this task. Run B is broader (636 tests vs 601) and **executed the suite
> Run A explicitly could not** — `OrderManufacturingIntegrationTest`, which Run A scoped into its Batch B and
> then never ran. That single difference reverses Run A's headline classification from **NEW (0)** to
> **NEW (13)**. Run A's environment findings are retained and attributed; its failure register is superseded.

---

## 1 — Certification target and repository state

Recorded at the start of execution, verbatim.

```
$ git rev-parse HEAD
6149875bd8a01820116b5deacbbfb8ef0e51cc05
```

```
$ git status --short
 M backend/Modules/Commerce/Orders/Application/Actions/ReserveOrderInventoryAction.php
 M backend/Modules/Inventory/Products/Infrastructure/Repositories/EloquentProductRepository.php
 M backend/Modules/Manufacturing/BillsOfMaterials/Domain/Services/ManufacturingAvailabilityService.php
 M docs/adr/ADR-027-reservation-ownership-policy.md
?? backend/tests/Feature/Manufacturing/RecipeCrossBrandReuseTest.php
?? backend/tests/Feature/Manufacturing/RecipeGateTenantRepairTest.php
?? backend/tests/Feature/Manufacturing/RecipeToOrderAvailabilityE2ETest.php
?? docs/verification/GO-LIVE-CERTIFICATION-001-FINAL-PILOT-RELEASE.md
?? docs/verification/TASK-GOLIVE-BLOCKERS-FINAL-REPAIR-001-ENGINEERING-REPORT.md
?? docs/verification/TASK-GOLIVE-BOM-OWNERSHIP-CONTRACT-001-ENGINEERING-REPORT.md
?? docs/verification/TASK-GOLIVE-IAM-ADMIN-IMPLEMENTATION-001-ENGINEERING-REPORT.md
?? docs/verification/TASK-GOLIVE-RECIPE-CROSS-BRAND-REUSE-CERTIFICATION-001-ENGINEERING-REPORT.md
?? docs/verification/TASK-GOLIVE-RECIPE-GATE-TENANT-REPAIR-001-ENGINEERING-REPORT.md
?? docs/verification/TASK-GOLIVE-RECIPE-OWNERSHIP-RUNTIME-FIXTURE-001-ENGINEERING-REPORT.md
?? docs/verification/TASK-GOLIVE-RECIPE-TO-ORDER-AVAILABILITY-E2E-001-ENGINEERING-REPORT.md
?? docs/verification/TASK-GOLIVE-RESERVATION-POLICY-INVESTIGATION-001-ENGINEERING-REPORT.md
?? docs/verification/TASK-GOLIVE-RESERVATION-RECIPE-NEGATIVE-STOCK-INVESTIGATION-001-ENGINEERING-REPORT.md
```

### 1.1 — Certification ran against the WORKTREE, not HEAD

**Explicitly confirmed: certification was executed against the CURRENT WORKTREE, not against HEAD `6149875b`.**
`git diff --stat` = **4 files changed, 185 insertions(+), 18 deletions(-)**, plus 3 untracked test files that
are part of the certified state.

Nothing was reset, checked out, stashed, cleaned, pulled, merged or discarded. No production code was
modified. No commit was made. The four modified files and fourteen untracked files were byte-identical at the
start and end of this task.

### 1.2 — The change under certification

| Change | File | Behaviour |
| --- | --- | --- |
| **Option B** | `ReserveOrderInventoryAction` | `can_manufacture = true` no longer commits unconditionally. The manufacturing branch is taken only when `ManufacturingAvailabilityService::evaluate()` returns a status other than `outofstock`. Otherwise the pre-existing shortage path decides the outcome. |
| **F4** | `ManufacturingAvailabilityService` | Component availability is **COMPANY-scoped** via `product->brand->company_id`. A finished good with no derivable company **fails closed** — it sees no inventory rather than falling back to the global pool. |
| **F4** | `EloquentProductRepository::paginate` | The same company scoping applied to the product-list availability sub-select. |

---

## 2 — Runtime environment

### 2.1 — Runner

Host PHP **8.4.22** + host dev vendor, executing **directly against the worktree** with no copy step — the
highest-fidelity runner available. PHPUnit **11.5.55**, config `backend/phpunit.xml`.

Database: **MySQL 8.4** in container `ecos-mysql`, published on `127.0.0.1:3306`. Test database
**`ecos_erp_test`**, forced by `phpunit.xml` (`force="true"`) and re-forced in `tests/TestCase::setUp()`.

`ecos-app` **cannot run tests**: it is a production image (`composer install --no-dev`) with no PHPUnit,
PHPStan, Pint or Faker, and it mounts only `/var/www/html/storage`. Run A additionally measured the image as
**31 files stale** against the worktree (13 missing, 18 differing) — i.e. it predates commit `6149875b`.

### 2.2 — ROOT CAUSE of the test-database instability (resolves Run A §4, which could not isolate it)

Run A reported four consecutive schema-build failures leaving `ecos_erp_test` partially built (0/16/29/84
tables), with symptoms `1146 migrations doesn't exist`, `1050 Table already exists`, `1213 Deadlock` and
transient `1049 Unknown database`, and recorded **"root cause not isolated"**, having ruled out concurrent
suites on the grounds that none were run concurrently *within that session*.

**The root cause is now isolated: two independent certification processes were operating on the same
`ecos_erp_test` database at the same time.** Run A and Run B overlapped. Run B independently observed, via
`information_schema.processlist`, host-originated connections executing migrations that it had not started —
e.g. `alter table pos_sessions add warehouse_id`, `alter table inventory_receipt_layers add constraint` — in
a database Run B had just dropped and recreated seconds earlier. Each `DROP DATABASE` pulled the schema out
from under a still-running migrator, which then recreated tables into the fresh database, producing exactly
the "already exists"/"doesn't exist" pairs both runs saw.

Run A's own correction — *"an earlier reading of this as a migration-ordering defect was wrong; from a
genuinely clean database the migration set runs in correct order"* — is confirmed. **The migration set is
sound and fully replayable.** Proof, with all other PHP processes terminated and connections drained to zero:

```
$ DB_DATABASE=ecos_erp_test php artisan migrate --force --no-interaction
EXIT=0  DURATION_SEC=401
tables = 550        migrations = 696
```

550 tables / 696 migrations, matching the development database `ecos_erp` (551 / 698). Once the database was
exclusively held, `RefreshDatabase`'s `migrate:fresh` also succeeded, and the full suite ran to completion.

> **Operational note.** Terminating the stray processes required `taskkill /F /IM php.exe`, which will have
> killed Run A's in-flight PHP as well. Run A's incomplete Batch B is at least partly attributable to this
> contention, and vice-versa. **Two agents must not certify against one test database concurrently.**

### 2.3 — DEFECT (ENVIRONMENT, pre-existing) — data-destruction hazard *(carried from Run A, not re-verified in Run B)*

The container carried `bootstrap/cache/config.php` baked with `mysql.database = ecos_erp` (the real database)
and `app.env = production`, and has no `.env`/`.env.testing`. Laravel prefers a cached config over `env()`, so
both `phpunit.xml`'s forced `DB_DATABASE` and the `putenv()` guard in `tests/TestCase.php` are bypassed when
that cache is present. **A `RefreshDatabase` run in that state would have migrated and truncated `ecos_erp`.**

Run A cleared the caches and proved the effective target empirically before any test touched the database.
Run B ran entirely from the host worktree (which has a real `.env` and no config cache) and connected only to
`ecos_erp_test`, verified per-run. `ecos_erp` was not modified by either run.

---

## 3 — Run B: primary DB-backed runtime execution

Single serial phpunit process. No DB-backed suite was run concurrently with another.

```
php -d memory_limit=4G vendor/bin/phpunit --no-coverage \
    --log-junit <scratch>/final_junit.xml \
    tests/Feature/Operations \
    tests/Feature/Manufacturing \
    tests/Feature/Inventory \
    tests/Feature/Orders \
    tests/Feature/Logistics/Phase3ModuleTest.php
```

| Metric | Value |
| --- | --- |
| Tests | **636** |
| Assertions | **1,928** |
| Passed | **615** |
| Failures | **18** |
| Errors | **3** |
| Skipped | 0 |
| PHPUnit deprecations | 12 |
| Duration | **505 s** (8 m 25 s) |
| Exit code | 2 |
| Database | `ecos_erp_test` (MySQL 8.4, 550 tables / 696 migrations) |

**Suites that passed in full** — every suite named in the certification brief except the two noted in
Section 4: `Rc10LifecycleCertificationTest` (17/17), `V3TransitionResolutionTest`, `PreparationWaveActionsTest`
(14/14), `PreparationSessionLifecycleTest` (7/7), `SoftReservationTest` (5/5), `WaveEngine/*`,
`DemandEngine/*` (except one, §4), `RecipeToOrderAvailabilityE2ETest` (8/8),
`RecipeCrossBrandReuseTest` (3/3), `RecipeGateTenantRepairTest` (10/10), `Logistics/Phase3ModuleTest`.

For reference, **Run A** covered `Manufacturing`, `Operations`, `Inventory`, `Commerce`: 601 tests, 1,716
assertions, 592 passed, 8 failures, 1 error, 558.3 s, exit 2. Run A's F4/Option B evidence markers
(`F4_FORWARD`, `F4_REVERSE`, `OPTION_B`, `DIRECT_FG`, `NEG_STOCK`, `RECIPE_MISSING`, `CROSS_BRAND`, `E2E A/B/E`)
all reproduced and are consistent with Run B's passing recipe suites.

---

## 4 — Failure register (21 failing tests, every one classified)

### 4.1 — NEW (13) — attributable to F4 + Option B

**All thirteen are in `tests/Feature/Orders/OrderManufacturingIntegrationTest`.** This is the suite Run A
scoped into Batch B and never executed, which is why Run A concluded NEW (0).

| # | Test | Observed |
| --- | --- | --- |
| 1 | `test_preparing_triggers_manufacturing_for_eligible_line` | `AwaitingStock` does not match expected `InProgress` |
| 2 | `test_preparing_sets_order_status_to_preparing` | `orders.status` = `awaiting_stock`, expected `in_progress` |
| 3 | `test_preparing_twice_does_not_duplicate_manufacturing` | **ERROR** |
| 4 | `test_preparing_twice_preserves_executed_state_on_line` | **ERROR** |
| 5 | `test_mixed_order_only_manufactures_eligible_lines` | failure |
| 6 | `test_product_with_sufficient_fg_stock_is_marked_not_required` | `Skipped` does not match expected `NotRequired` |
| 7 | `test_failed_manufacturing_marks_line_as_failed` | failure |
| 8 | `test_failed_line_preserves_failure_reason_in_result` | `null` is not of type `array` |
| 9 | `test_retry_after_failure_re_evaluates_failed_line` | failure |
| 10 | `test_retry_does_not_re_execute_executed_lines` | failure |
| 11 | `test_manufacturing_result_is_stored_on_line` | `null` is not of type `array` |
| 12 | `test_manufacturing_started_at_and_completed_at_are_set` | `null` is not `not null` |
| 13 | `test_rc10_order_line_id_is_populated_on_manufacturing_transaction` | table `manufacturing_transactions` is **empty** |

**Mechanism — traced end to end, not inferred:**

1. `OrderManufacturingIntegrationTest::setUp()` creates `$this->company` and a warehouse in it:
   `Warehouse::factory()->create(['company_id' => $this->company->id])`. **All inventory lives in
   `$this->company`.**
2. `makeOutput()` / `makeComponent()` call `Product::factory()->finishedGood()` / `->rawMaterial()`.
   `ProductFactory::definition()` sets `'brand_id' => Brand::factory()` and derives
   `'company_id' => Brand::find(...)->company_id`. Each product therefore gets **its own new Brand, and its
   own new Company** — neither of which is `$this->company`.
3. **F4** then evaluates component availability as
   `where('company_id', $product->brand->company_id)`. The finished good's company owns no inventory, so every
   component reads `0` and the recipe evaluates to **`outofstock`**.
4. **Option B** consults that verdict: `can_manufacture && manufacturingIsExecutable($product)` is now false,
   the manufacturing branch is skipped, and the shortage path takes the order to **`awaiting_stock`**.

Failures 1, 2 and 6 are the direct expression of this; 3–5 and 7–13 are downstream (no manufacturing was
committed, so no transaction row, result payload or timestamp exists).

**Classification rationale.** These are NEW: they are produced by code that exists only in the worktree, via a
mechanism that is fully traced to the two worktree changes. Run B did not execute a HEAD control run —
`git archive` to a separate tree was available but was not used, because the causal chain above is
deterministic and does not require differential evidence. Run A's control (67 tests, 1,280.8 s, partially
contaminated) did not include this suite and therefore neither confirms nor refutes them.

**Two readings, both recorded; neither acted upon:**

* **Fixture debt.** F4 is behaving exactly as ADR-027 §16 and ADR-013 specify — a finished good owned by
  company X legitimately cannot consume company Y's stock. This fixture never established a coherent tenant
  chain; before F4 the global inventory pool masked that. The new `RecipeGateTenantRepairTest` includes
  `test_fixture_ownership_chain_is_single_tenant`, showing the new suites were written *with* a correct chain
  while this older suite was not updated.
* **Production exposure.** The same code path treats any finished good whose `brand_id` is NULL, or whose
  brand has a NULL `company_id`, as having zero component stock — it will never manufacture, and its orders
  will divert to `awaiting_stock`. Whether production data can reach that state is **not** established here;
  it depends on the `products.company_id` / brand-ownership question that Section 6.5 records as still open.

**Per the task instruction, a new defect was found and work STOPPED. Nothing was fixed.**

### 4.2 — PRE-EXISTING (3) — confirmed by Run A's HEAD control run

Reproduced at HEAD `6149875b` with identical message *and* line number, without Option B present:

| Test | Message |
| --- | --- |
| `OrderExclusivityTest::test_db_unique_constraint_prevents_duplicate_company_order_pair` | **ERROR** — SQL 1364, `order_confirmed_at` has no default |
| `RecipeFoundationTest::test_recipe_ignores_waste_percentage_if_submitted` | array *has* key `waste_percentage` (line 351) |
| `MaterialDemandCalculatorTest::test_missing_qty_uses_available_not_on_hand` | `15.0` vs expected `7.0` (line 153) |

### 4.3 — PRE-EXISTING (2) — reachability-argued, no control run

Neither test is reachable from any changed class. `ManufacturingAvailabilityService` is referenced only by
`ReserveOrderInventoryAction`, `EloquentProductRepository` and `ProductController`; `ReserveOrderInventoryAction`
only by the order workflows, `CreateManualOrderAction`, `UpdateOrderAction` and `WooCommerceOrderImporter`.

| Test | Message |
| --- | --- |
| `BranchAssignmentEngineTest::test_nearest_branch_selected_when_multiple_cover_area` | two strings are not identical |
| `OrderFinancialSnapshotTest::test_consistency_validation_rejects_mismatched_subtotal` | `SnapshotConsistencyException` not thrown |

Stated at its true strength: **argued from reachability, not proven by a control run.**

### 4.4 — UNVERIFIED (3)

| Test | Message |
| --- | --- |
| `InventoryCountSessionTest::test_approve_posts_adjustment_out_for_negative_variance` | two strings are not equal |
| `InventoryCountSessionTest::test_fifo_consumption_record_created_for_adjustment_out` | two strings are not equal |
| `InventoryCountSessionTest::test_adjustment_creates_ledger_entry` | `null` is not `not null` |

These executed cleanly and failed on their assertions in Run B. Run A could not classify them (its control
tree errored before reaching them) and explicitly declined to call them pre-existing on suspicion. Run B
adopts the same discipline. They concern inventory **counting** (stock take), outside Preparation scope.

### 4.5 — ENVIRONMENT (3)

| Item | Status |
| --- | --- |
| Concurrent certification processes sharing `ecos_erp_test` | **ROOT-CAUSED in §2.2** — was Run A's unresolved blocker |
| Cached-config data-destruction hazard (§2.3) | Open, pre-existing |
| Host CLI `memory_limit = 128M` insufficient (voided a Run A attempt at 315/601) | Open; Run B used `-d memory_limit=4G` |

---

## 5 — Scenario certification matrix

**Vocabulary.** `OrderStatus` has **no `Preparing` case**. Entering Preparation is the transition to
`ready_for_dispatch` via `MoveToPreparationWorkflow`, whose guard requires `InProgress`. Scenarios worded
against a `Preparing` state are certified against the real transition and flagged in §6.1, not silently
reinterpreted.

| # | Scenario | Status | Evidence |
| --- | --- | --- | --- |
| 1 | Recipe available → reservation → Preparing | **PASS** | `RecipeToOrderAvailabilityE2ETest::test_a_recipe_available_order_proceeds`; `Rc10::test_dedicated_move_to_preparation_runs_through_the_engine` |
| 2 | Recipe unavailable → AwaitingStock | **PASS** | E2E `test_b_recipe_outofstock_offnegative_component`; `RecipeGateTenantRepairTest::test_part15_unexecutable_recipe_blocks_manufacturing_reservation`; `Rc10::test_insufficient_stock_diverts_to_awaiting_stock` |
| 3 | AwaitingStock cannot enter Preparation | **UNVERIFIED** | No test asserts it. See §6.2 — verified independently in Run B. |
| 4 | `allow_negative_stock = ON` | **PASS** | E2E `test_c_shortage_permitted_by_allow_negative`; `test_part16_allow_negative_material_keeps_reservation_alive`; `test_cross_brand_reuse_with_allow_negative_stock` |
| 5 | `availability = on_hand − reserved` | **PASS** | `SoftReservationTest` (5/5); `AvailabilityStateDerivationTest`; formula fixed in ADR-027 |
| 6 | Preparation does not consume inventory prematurely | **PASS** | `Rc10::test_full_lifecycle…` — on-hand unchanged at reservation; FIFO layer and on-hand both draw 10→8 only at dispatch |
| 7 | Partial Preparation | **PARTIAL** | `PreparationWaveActionsTest::test_complete_product_fires_event_and_writes_timeline` passes, but against synthetic order UUIDs (§6.3) |
| 8 | Wave creation | **PASS** (wave mechanics) | `test_create_wave_fires_event_and_writes_timeline_and_audit` |
| 9 | Demand generation | **PASS** | `test_generate_demand_transitions_to_planning_and_writes_timeline`; `DemandEngine/*` |
| 10 | Allocation | **UNVERIFIED** | Preparation-side `analyze_materials` passes; `Modules/Operations/Loading` allocation actions have **zero tests** |
| 11 | Picking | **UNVERIFIED** | No picking test exists anywhere under `tests/` |
| 12 | Loading boundary | **UNVERIFIED** | `Modules/Operations/Loading` — 15 actions, `PreparedPool*`, allocation services — **zero test references** |
| 13 | Shipment / consumption boundary | **PASS** | `Rc10::test_full_lifecycle…` — `inventory_shipped_at` set, FIFO layer 10→8, on-hand 10→8 |
| 14 | AwaitingStock recovery | **PARTIAL** | Order level passes (`test_dedicated_awaiting_stock_route_persists`, `test_dedicated_resume_route_returns_to_in_progress`); wave-level `StockAddedListener` has **no test** |
| 15 | Wave cancellation | **PASS** (wave mechanics) | `test_cancel_wave_fires_event_and_writes_timeline` |
| 16 | Wave completion | **PASS** (wave mechanics) | `test_complete_wave_fires_event_and_writes_timeline` |
| 17 | Company isolation | **PASS** | `Rc10::test_cannot_transition_an_order_belonging_to_another_company`; `test_wrong_company_user_cannot_view_wave`; `RecipeGateTenantRepairTest` parts 6/7/8 |
| 18 | Cross-brand Raw Material reuse | **PASS** | `RecipeCrossBrandReuseTest` (3/3) + `test_part20_cross_brand_reuse_survives_company_scoping` |
| 19 | Reservation / Preparation state consistency | **PASS** | `V3TransitionResolutionTest` (5/5) — incl. `test_no_completed_edge_exists`, `test_retired_v2_vocabulary_no_longer_resolves` |
| 20 | Every route able to enter Preparing | **PASS** | 3 routes exist: `POST fulfillment/orders/{order}/move-to-preparation`, `POST fulfillment/bulk/move-to-preparation`, `POST fulfillment/orders/{order}/transition`. Covered by `Rc10` dedicated + generic + bulk tests |
| 21 | Full Order → Reservation → Preparation → Loading → Shipment | **INCOMPLETE** | `Rc10::test_full_lifecycle…` covers `in_progress → ready_for_dispatch → out_for_delivery → delivered` with FIFO consumption. It does **not** traverse a Preparation wave or a Loading session. No continuous end-to-end lifecycle exists |

**Totals:** PASS 13 · PARTIAL 2 · UNVERIFIED 4 · INCOMPLETE 1 · plus 13 NEW failures against the Orders
manufacturing integration suite.

---

## 6 — Findings (flagged, not fixed)

### 6.1 — "Preparing" is not an order status *(Run A; independently confirmed in Run B)*

`OrderStatus` cases are `new`, `in_progress`, `ready_for_dispatch`, `out_for_delivery`, `delivered`,
`awaiting_payment`, `awaiting_stock`, `scheduled`, `on_hold`, `cancelled`, `returned`.
`MoveToPreparationWorkflow::guard()` requires `InProgress`; `execute()` transitions to `ReadyForDispatch`.
Its docblock: *"In V3, Preparing is an invisible engine state — orders stay In Progress while being prepared."*

### 6.2 — Scenario 3 is UNVERIFIED — **re-verified independently by Run B**

Run B did not take this on trust and checked the sources directly:

* `Rc10::test_dedicated_route_guard_refuses_an_invalid_source_state` builds its order with
  `status: 'delivered'` — **not** `awaiting_stock`.
* `grep awaiting_stock tests/Feature/Operations/V3TransitionResolutionTest.php` returns **no matches** — the
  refused-edge dataset contains no `awaiting_stock → ready_for_dispatch` case.
* `test_every_v3_state_is_handled_without_error` asserts only `$resolved === null || is_object($resolved)`,
  which is vacuous for this claim.

The behaviour is almost certainly correct **by construction** (the edge is unrouted and the guard requires
`InProgress`), but for a safety-critical go-live invariant, inference from code is not certification.

### 6.3 — The Preparation OS is not exercised against real orders — **re-verified by Run B**

`PreparationWaveActionsTest` contains **0** occurrences of `Order::factory`/`Order::create` and attaches
synthetic `Str::uuid()` order ids. `PreparationReleaseEngine`, `DailyPreparationSessionManager`,
`OrderPreparationObserver` and `PreparationSessionPolicy` have **zero references under `tests/`**. The wave
suites therefore cannot exercise order-status eligibility — the mechanism §6.2 depends on. This is the
structural gap behind scenarios 3, 7, 10, 11, 14 and 21.

### 6.4 — `preparation_wave_orders.order_confirmed_at` is NOT NULL, guarded only at the controller *(Run A)*

Declared `timestampTz(...)` NOT NULL with no default, while `orders.confirmed_at` is nullable.
`PreparationWaveController` applies `?? now()`, and `WaveMembershipService` guards it, but `CreateWaveAction`
and `RecalculateWaveAction` pass `$line['confirmed_at']` straight through. **A defence-in-depth gap, not a
demonstrated production defect** — though the §4.2 `OrderExclusivityTest` error demonstrates the failure mode.

### 6.5 — Carried forward, still open

`products.company_id` remains a NOT NULL legacy column the `Product` model never references (ownership is
derived via `brand.company_id`); `StoreBomRequest` still has no company constraint. Per instruction, neither
was audited or modified. **§4.1 raises the stakes on this item:** F4 now makes brand/company ownership
load-bearing for whether a product can manufacture at all.

---

## 7 — PHPStan

**Static analysis is recorded as required and is explicitly NOT treated as runtime certification.**

| Run | Config | Scope | Result | Exit |
| --- | --- | --- | --- | --- |
| B | `phpstan.neon.dist` | `Modules` + `app` | **[OK] No errors** | 0 |
| A | `phpstan.neon.dist` (level 0) | `Modules` + `app` | **[OK] No errors** | 0 |
| A | `phpstan-core.neon.dist` (level 6) | `app/Core`, `app/Contracts`, `app/Traits` | **[OK] No errors** | 0 |

Run A re-ran from a cleared cache (204.4 s) to make the result authoritative. Both configs are ratcheted
against baselines, so a clean result means **no NEW violations**.

**Status: PASS.**

---

## 8 — Guardian

`bash engineering/quality-guardian/guardian.sh pre-push` — 8 validators. Run B: **282 s, exit 1**
(Run A: 326 s, exit 1 — same single failure).

| Validator | Result | Time |
| --- | --- | --- |
| PHP Syntax | ✓ PASS | 6 s |
| Composer Validate | ○ SKIP | 1 s |
| Laravel Bootstrap | ✓ PASS | 4 s |
| **Laravel Pint** | **✗ FAIL** | 3 s |
| PHPStan | ✓ PASS | 7 s |
| ESLint | ✓ PASS | 148 s |
| TypeScript | ✓ PASS | 102 s |
| Vite Production Build | ✓ PASS | 10 s |

**Guardian result: FAIL (exit 1) — 1 of 8 validators failed.**

Pint ratchet detail (push range `f0d7822a…HEAD`, 26 changed PHP files, baseline 628, violating in scope 4,
fixed since baseline 1). **NEW violations in 2 files:**

| File | Fixers |
| --- | --- |
| `backend/tests/Feature/Inventory/ProductPopulationScopeTest.php` | `ordered_imports` |
| `backend/tests/Feature/Operations/V3TransitionResolutionTest.php` | `binary_operator_spaces` |

**Classification: PRE-EXISTING relative to this certification.** Both files are **committed** and entered the
range via commit `6149875b`; neither is a worktree change. Run B verified the worktree changes separately:

* `ReserveOrderInventoryAction.php` — Pint **clean**.
* `ManufacturingAvailabilityService.php` — Pint **clean**.
* `EloquentProductRepository.php` — reports `concat_space`, `unary_operator_spaces`,
  `not_operator_with_successor_space`, which is **byte-identical to its existing baseline entry**. No new
  violation.
* The 3 untracked test files — Pint **passed**.

Per instruction, no Pint cleanup was performed.

---

## 9 — Exact commands executed, with runtime

| # | Command | Result | Duration |
| --- | --- | --- | --- |
| 1 | `git rev-parse HEAD` / `git status --short` | recorded §1 | — |
| 2 | `DROP DATABASE ecos_erp_test; CREATE DATABASE … utf8mb4_unicode_ci` | 0 tables | — |
| 3 | `DB_DATABASE=ecos_erp_test php artisan migrate --force --no-interaction` | exit 0 — 550 tables / 696 migrations | **401 s** |
| 4 | `php -d memory_limit=4G vendor/bin/phpunit --no-coverage --log-junit … tests/Feature/{Operations,Manufacturing,Inventory,Orders} tests/Feature/Logistics/Phase3ModuleTest.php` | **636 tests, 1,928 assertions, 18 failures, 3 errors**, exit 2 | **505 s** |
| 5 | `php -d memory_limit=4G vendor/bin/phpstan analyse --no-progress` | **[OK] No errors**, exit 0 | ~40 s |
| 6 | `php vendor/bin/pint --test <3 modified files>` | 1 file, matches baseline exactly | ~5 s |
| 7 | `php vendor/bin/pint --test <3 new test files>` | **passed** | ~5 s |
| 8 | `bash engineering/quality-guardian/guardian.sh pre-push` | **exit 1** (Pint only) | **282 s** |

Regression suites required by the brief: **RC-10** (`Rc10LifecycleCertificationTest`, 17/17 pass) · **Phase 3**
(`TASK-PHASE3-*` family: `V3TransitionResolutionTest`, `AvailabilityStateDerivationTest`,
`ProductPopulationScopeTest`, `ProductStockStatusWritePathTest` — all pass; `Logistics/Phase3ModuleTest` passes)
· **Manufacturing/Recipe** (all pass except pre-existing `RecipeFoundationTest`, §4.2) · **Inventory** (pass
except 3 UNVERIFIED count-session tests, §4.4) · **Operations/Preparation** (all pass except pre-existing
`OrderExclusivityTest` error and `BranchAssignmentEngineTest`, §4.2–4.3).

---

## 10 — Summary

| Dimension | Result |
| --- | --- |
| Runtime tests executed | 636 (1,928 assertions), 505 s |
| Runtime passed | 615 |
| **NEW failures** | **13** — all `OrderManufacturingIntegrationTest`, traced to F4 + Option B |
| PRE-EXISTING failures | 5 (3 control-confirmed, 2 reachability-argued) |
| UNVERIFIED failures | 3 |
| ENVIRONMENT issues | 3 (one root-caused and resolved in §2.2) |
| Scenario matrix | 13 PASS · 2 PARTIAL · 4 UNVERIFIED · 1 INCOMPLETE |
| PHPStan | PASS |
| Guardian | **FAIL** (Pint; pre-existing, committed files) |

**Blocking reasons for certification:**

1. **13 NEW runtime failures** introduced by the change under certification (§4.1).
2. **Scenarios 10, 11, 12 UNVERIFIED** — the entire `Modules/Operations/Loading` module (15 actions, pool and
   allocation services) has **zero test coverage**, so the Loading boundary cannot be certified at all.
3. **Scenario 3 UNVERIFIED** — the "AwaitingStock must not enter Preparation" invariant is unproven (§6.2).
4. **Scenario 21 INCOMPLETE** — no continuous Order → Reservation → Preparation → Loading → Shipment lifecycle
   is exercised anywhere.
5. **Guardian FAIL.**

---

## 11 — Verdict

# PREPARATION BACKEND NOT CERTIFIED

---

## 12 — Attestations

* Certification executed against the **CURRENT WORKTREE**, not HEAD alone. The two differ (§1.1).
* HEAD at execution: `6149875bd8a01820116b5deacbbfb8ef0e51cc05`, branch `develop`, repository `C:\ecos-develop`.
* **No production code was modified.** The F4 + Option B changes were left exactly as found.
* **Nothing was committed.** No reset, checkout, stash, clean, pull or merge was performed.
* **No failure discovered during certification was fixed.** On finding the NEW defect in §4.1, work stopped.
* **No DB-backed test suite was run concurrently by this run.** Cross-process contention with a second
  certification session did occur and is disclosed in §2.2.
* The test database `ecos_erp_test` was dropped and rebuilt (authorised); the production/development database
  `ecos_erp` was never modified.
* Static analysis was recorded separately and **not** treated as runtime certification.
* Out-of-scope work (UI, IAM, Pint cleanup, `products.company_id` audit, `StoreBomRequest`) was not started.
