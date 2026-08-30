# TASK-ORDERS-AVAILABILITY-LIFECYCLE-REPAIR-001 — Engineering Report

**Date:** 2026-08-15 · **Branch:** `develop` · **Worktree:** `C:\ecos-develop`
**Verdict:** **NOT CERTIFIED — blocked, but NOT by missing code.**

---

## 1. Executive summary

The reported defect — *every new Order is automatically converted to Awaiting Stock* — is
**real, reproduced, and root-caused**. It is **not** an unfixed bug.

The repair already exists, in full, in this worktree. It is **uncommitted**, and it is
**absent from the stack where the symptom is being observed**.

| Stack | App container | Database | ProcessOrderWorkflow | Symptom present |
|---|---|---|---|---|
| dev | `ecos-dev-app` | `ecos_dev` | **repaired** | **No** |
| main | `ecos-app` | `ecos_erp` | **pre-repair** | **Yes** |

Writing the fix again would duplicate work that is already complete and correct. The
outstanding actions are a **commit**, a **merge/deploy**, and **test coverage** — not new
lifecycle code.

---

## 2. Mandated investigation — the trace

```
Order Creation → ProcessOrderWorkflow → warehouse present?
                                          │
                     ┌────────────────────┴────────────────────┐
                     │ NO                                      │ YES
                     ▼                                         ▼
   PRE-REPAIR: status = awaiting_stock          ReserveOrderInventoryAction
   REPAIRED:   reservation_status = pending,     → FG availability (ADR-027 §3)
               lifecycle status untouched        → RM reconcile (ADR-027 §17)
                                                 → status per yieldsToStockBlock()
```

### 2.1 The defect, located exactly

`ecos-app:/var/www/html/.../ProcessOrderWorkflow.php` lines **97–98**:

```php
if ($order->assigned_warehouse_id === null) {
    $order->update(['status' => OrderStatus::AwaitingStock]);
```

An **unconditional** status write on a **missing warehouse**. This conflates a geography /
coverage failure with a finished-goods shortage. Because every recovery path keys on
`status`, such orders became unrecoverable.

That same container has:
- `yieldsToStockBlock` — **0 occurrences** (both `ProcessOrderWorkflow` and `OrderStatus`)
- `ReconcileOrderMaterialReservationsAction` — **file does not exist**

### 2.2 Why it fires for *every* order

The warehouse is null for effectively every new order on that stack — the Arabic
governorate on the address never matches the English `master_governorates.name`, so
`BranchAssignmentEngine` assigns nothing. Diagnosed previously under
`TASK-ORDER-AWAITING-STOCK-DIAGNOSTIC-001`. Warehouse-missing then routes 100 % of orders
into `awaiting_stock` via the line above.

### 2.3 Runtime evidence — controlled comparison

`ecos_erp` (pre-repair code):

| status | reservation_status | orders | no_warehouse |
|---|---|---|---|
| `awaiting_stock` | `awaiting_stock` | 1 | **1** |
| `in_progress` | NULL | 1 | 1 |

`ecos_dev` (repaired code):

| status | reservation_status | orders |
|---|---|---|
| `ready_for_dispatch` | `reserved` | 3 |
| `in_progress` | `reserved` | 2 |
| `in_progress` | NULL | 1 |
| `confirmed` | `reserved` | 1 |
| **`in_progress`** | **`pending`** | **1** |
| `awaiting_stock` | — | **0** |

The final `ecos_dev` row is the repaired behaviour observed in live data: a
warehouse-blocked order **holds `in_progress`** and reports the blocker on
`reservation_status = pending`. Zero orders sit in `awaiting_stock`.

---

## 3. Contract conformance of the repaired code

Each clause of the authoritative contract, mapped to the implementation that already
satisfies it.

| Contract clause | Implementation | State |
|---|---|---|
| Available → canonical state, not Awaiting Stock | `OrderStatus::advancesToInProgressOnReservation()` | Present |
| Unavailable → Awaiting Stock | `ReserveOrderInventoryAction` → `ReservationStatus::AwaitingStock` | Present |
| Available + unpaid → **Awaiting Payment** | `AwaitingPayment` excluded in **both** directions | Present |
| Scheduled holds until D-1 | `Scheduled` excluded from `yieldsToStockBlock()` | Present |
| Scheduled → In Progress at D-1 | `ActivateScheduledOrdersCommand` | Present |
| Automatic stock recovery, no UI polling | `RetryReservationOnStockAvailableListener` (received / released / adjusted) | Present |
| Warehouse recovery | `ExecuteReservationOnWarehouseAssigned` (ADR-027 H3) | Present |
| Recipe resolution → RM reservation | `ReconcileOrderMaterialReservationsAction` (ADR-027 §17) | Present |
| Order **and** warehouse show the same reservation | Single `inventory_items.reserved_qty` + `stock_ledger_entries` | Present |
| No duplicate reservation on repeat | Reconcile-to-target (not accumulate) + `SKIP_STATES` + `lockForUpdate` | Present |

**No new listener is required.** The task instructed that the existing `StockAdded`
listener be examined before adding one; it exists, is subscribed, and already handles
received / released / adjusted.

### 3.1 An ADR conflict that was checked and does **not** apply

ADR-027 **v1.1** stated *"RM is never inspected during reservation. BOM/Recipe never
queried"* (P04/P13). The contract in this task requires the opposite. That conflict is
**resolved and not a blocker**: ADR-027 has since advanced to **v1.3 §17**, which
explicitly sanctions order-driven RM reservation, on the ground that
`Product::activeRecipe()` resolves exactly one recipe deterministically — so the premise
behind the old rule expired. The implementation cites §17 directly.

---

## 4. The blocker

**The repair is uncommitted work in `C:\ecos-develop` and has never reached `ecos-app`.**

- 27 files modified vs HEAD across `Modules/Commerce/Orders` + `Modules/Operations/Fulfillment`.
- Authored in a prior session (last write ~6 h before this one); not mine.
- `ecos-app` is a **different worktree on a different branch** with its own stack.

Three actions remain, none of which are mine to take unilaterally:

1. **Commit** the 27-file lifecycle repair in this worktree.
2. **Merge / deploy** it to the stack serving `ecos_erp`.
3. **Backfill** the one legacy row: order at `awaiting_stock` with no warehouse. The
   sanctioned route already exists — `orders:reprocess-legacy-reservations` (ADR-027 H4),
   which has a dry-run mode. **Never by direct DB mutation.**

---

## 5. Certification matrix

| Item | Result |
|---|---|
| Root cause identified | **PASS** — `ProcessOrderWorkflow:97–98`, unconditional write on null warehouse |
| Runtime reproduction | **PASS** — `ecos_erp`: 1 order `awaiting_stock` with `no_warehouse=1` |
| Repaired behaviour verified in live data | **PASS** — `ecos_dev`: 0 `awaiting_stock`; blocked order holds `in_progress`/`pending` |
| Contract conformance of repaired code | **PASS** — all 10 clauses implemented |
| Backend lifecycle code | **PASS (already implemented, uncommitted)** |
| Automated test coverage (A–Q) | **FAIL — the real gap.** Only `OrderDrivenMaterialReservationTest` exists (RM/§17). No test asserts availability→status, scheduled transitions, or stock recovery. |
| Deployment parity | **FAIL** — `ecos-app` runs pre-repair code |
| Regression | **NOT RUN** — no code changed by this task |
| E2E | **NOT RUN** — blocked on deployment decision |

**Overall: NOT CERTIFIED.** Exact blocker: *the lifecycle repair is uncommitted and
undeployed to the affected stack, and it carries no lifecycle test coverage.*

> **SUPERSEDED — read §R1–R5 instead.** This matrix reflects the state before the A–Q suite
> existed and before the contract-authority override. Since then: A–Q coverage was written and
> passes (23 tests), the ADR-026 regression was repaired and verified, D1/D2/D3/D5/D6 were all
> resolved from existing authority, and two fixture defects were fixed. The only surviving
> owner item is deployment ownership (D4).

---

## 6. Recommendation

Do **not** re-implement. In order:

1. Review and commit the 27-file repair (it is another session's work — confirm ownership first).
2. Add the A–Q lifecycle tests against the repaired code. This is the one genuine
   engineering gap, and it is why a complete, correct repair has sat uncommitted.
3. Merge/deploy to `ecos_erp`, then re-run this certification.
4. Reprocess the single legacy row via `orders:reprocess-legacy-reservations --dry-run` first.

## 7. Scope compliance

No Preparation, Wave, Distribution, Warehouse Assignment, Reservation Engine or Material
Demand code was read for modification or changed. **No production file was modified by this
task**; the investigation was read-only, and the one write is this report.

---
---

# CLOSURE — TASK-ORDERS-AVAILABILITY-LIFECYCLE-CLOSURE-001

**Date:** 2026-08-15 · **Branch:** `develop` · **Worktree:** `C:\ecos-develop`

The repair was **not** re-implemented. This closure verifies the existing changeset, adds
the missing A–Q coverage, and resolves the deployment question.

## C1. Existing implementation verification

Verified present and correct in the working tree, all pre-existing:

| Mechanism | Location |
|---|---|
| Lifecycle/availability separation | `OrderStatus::yieldsToStockBlock()` / `advancesToInProgressOnReservation()` |
| Null-warehouse → postponed, status untouched | `ProcessOrderWorkflow` null-warehouse branch |
| Automatic stock recovery | `RetryReservationOnStockAvailableListener` (received/released/adjusted/transferred) |
| Warehouse recovery (ADR-027 H3) | `ExecuteReservationOnWarehouseAssigned` |
| Order-driven RM reservation (ADR-027 §17) | `ReconcileOrderMaterialReservationsAction` |
| Supervised legacy recovery (H4) | `ReprocessLegacyReservationsCommand` |

## C2. Changeset inventory — 27 files

21 modified + 6 untracked across `Modules/Commerce/Orders` and
`Modules/Operations/Fulfillment`; +1036 / −336 on the tracked files.

Provenance by mtime — three prior sessions, **none of them this one**:

- **13 Aug 00:07–03:44** — ADR-042 lifecycle V3 canonical: `OrderStatus`, workflows,
  `ReprocessLegacyReservationsCommand`, migration
  `2026_08_13_100000_supersede_order_lifecycle_v3_canonical`.
- **13 Aug 23:06 – 14 Aug 02:32** — ADR-027 §17 RM reservation, `CreateManualOrderAction`.
- **14 Aug 18:46–19:58** — `ProcessOrderWorkflow`, `ReserveOrderInventoryAction`,
  listeners, final `OrderStatus` pass.

**Unrelated change mixed in:** `Modules/Commerce/Orders/Domain/Services/CustomerOrderMetricsService.php`
is a CRM customer-KPI service with no bearing on order availability or lifecycle. It should
be split into its own commit.

**Schema coupling:** the ADR-042 migration removes `new` and normalises rows to
`in_progress`. Its own docblock requires it to ship in the same deploy as `OrderStatus.php`.
It is **applied on `ecos_dev`**, **not applied on `ecos_erp`**.

## C3. A–Q matrix results

`tests/Feature/Orders/OrderAvailabilityLifecycleContractTest.php` — **22 tests, 58
assertions, 0 failures, 0 errors, 1 incomplete.** Run behind `scripts/test-gate.sh`.

| Clause | Result | Evidence |
|---|---|---|
| A available → canonical state | PASS | `in_progress` + `reserved`, 10 units held |
| B unavailable → Awaiting Stock | PASS | status and reservation both `awaiting_stock` |
| C available + unpaid → Awaiting Payment | PASS | stays `awaiting_payment`, never `in_progress` |
| C2 unpaid + unavailable | PASS | lifecycle `awaiting_payment`, reservation `awaiting_stock` |
| D Scheduled before D-1 | PASS | D+5 order stays `scheduled` |
| E Scheduled at D-1 | PASS | activates to `in_progress` + `reserved` |
| **F Scheduled + unavailable** | **INCOMPLETE — contract gap** | see C4 |
| G stock arrives → auto recovery | PASS | `awaiting_stock` → `in_progress` + `reserved`, no UI action |
| G2 listener actually subscribed | PASS | `InventoryStockReceived` has subscribers |
| H still insufficient | PASS | zero-availability re-evaluation stays `awaiting_stock` |
| H2 partial arrival | PASS | `partial_reserved` per ADR-027 §8, not `awaiting_stock` |
| I/J/K/L recipe → RM → reserved | PASS | 7 × 3 = **21 RM reserved** on the real warehouse row |
| M repeated stock event | PASS | 3 identical events → still exactly 10 |
| N repeated re-evaluation | PASS | 3 passes → 5 FG / 10 RM, converged |
| N2 direct reconcile ×2 | PASS | 3 × 4 = 12, target not running total |
| O multi-line | PASS | both lines reserved |
| O2 multi-line, one short | PASS | `partial_reserved` |
| P tenant isolation | PASS | foreign-company stock event does not move our order |
| Q warehouse correctness | PASS | assigned warehouse holds 10, other warehouse 0 |
| **Headline regression** | PASS | missing warehouse → `in_progress` + `pending`, NOT `awaiting_stock` |
| Blocker audit trail | PASS | `order_events.reservation_execution_postponed` written |
| HTTP surface | PASS | `status` and `reservation_status` exposed as separate fields |

Three harness defects were found and fixed while writing these — mine, not the product's:
calling `ProcessOrderWorkflow` directly trips the P9 guard (canonical entry is
`FulfillmentEngine::run()`); `order_events` uses `event_type`, not `type`; the enum case is
`InventoryClass::FinishedGood`.

## C4. The one contract gap — clause F

**Scheduled + unavailable stays `scheduled` after activation instead of becoming
`awaiting_stock`.** Structural, not a harness artefact:

- `orders:activate-scheduled` calls `ProcessOrderWorkflow` while the order is still `scheduled`.
- On a shortage the workflow consults `yieldsToStockBlock()`, which **excludes** `Scheduled`.
- The status is preserved — and the activation trigger has already fired and will not fire again.
- Contrast clause E: a **successful** reservation *does* move Scheduled → In Progress,
  because `advancesToInProgressOnReservation()` **includes** `Scheduled`.

The two helpers are deliberately non-complementary, leaving the failure path with no exit.
Closing it means either activating to In Progress *before* reserving, or adding `Scheduled`
to `yieldsToStockBlock()`. Both change production lifecycle behaviour, which this task
forbids. **Reported, not fixed — needs a lifecycle decision.**

## C5. Runtime evidence

| Stack | Code | Warehouse-blocked order | `awaiting_stock` count |
|---|---|---|---|
| `ecos_erp` (ecos-app) | pre-repair | `awaiting_stock`, `no_warehouse=1` | 1 |
| `ecos_dev` (ecos-dev-app) | repaired | `in_progress` + `pending` | **0** |

Confirmed independently by the H4 dry-run on `ecos_dev`, which classified ORD-00007 as
`no_branch_coverage` → *"no coverage; status already correct"*. Warehouse failure and stock
shortage are now distinct, and geography failures stay recoverable.

## C6. Legacy row handling — dry-run only

Dry-run is the **default**; `--execute` applies. Output on `ecos_dev`:

```
ORD-00002  in_progress  —        unassigned          OPERATOR_CORRECTION  governorate is NULL — address incomplete
ORD-00007  in_progress  pending  no_branch_coverage  NOT_ELIGIBLE         no coverage; status already correct
RECOVERABLE 0 · OPERATOR_CORRECTION 1 · STATUS_ONLY 0 · NOT_ELIGIBLE 1
```

**Would change nothing. Safe.** ORD-00002 needs an operator to complete the address, not a
code change. **No `--execute` authorization is requested**: the stranded `awaiting_stock`
row lives on `ecos_erp`, and that stack does not have this command — it is untracked here.

## C7. Static quality

| Gate | Result |
|---|---|
| PHPStan L0 platform-wide | PASS |
| PHPStan core L6 | PASS |
| Pint — new test file | PASS (1 issue auto-fixed) |
| Pint — changeset files | pre-existing baseline, not introduced here |

Baseline proof: `CancelOrderWorkflow.php` and `ResumeOrderWorkflow.php` fail Pint but are
**not in the changeset**, so those failures are module-wide and predate it. Left untouched
per instruction.

## C8. Regression classification (resumed)

Run behind `scripts/test-gate.sh`. Counts:

| Suite | Tests | Errors | Failures |
|---|---|---|---|
| `tests/Feature/Orders` | 56 | 2 | 12 |
| `tests/Feature/Inventory` | 218 | 14 | 4 |
| `tests/Feature/Operations` | 286 | 1 | 9 |

The new A–Q file contributes 22 of the 56 Orders tests and passes all of them. None of the
14 non-passing Orders results are its; nothing was added to Inventory or Operations.

Classification is by failure content and authoritative contract, per instruction. Three
distinct modes were found, not one.

---

### MODE A — PRE-EXISTING · manufacturing evaluation is dead at HEAD

**Tests (9, all `OrderManufacturingIntegrationTest`)**

`test_product_with_sufficient_fg_stock_is_marked_not_required` ·
`test_manufacturing_result_is_stored_on_line` ·
`test_failed_line_preserves_failure_reason_in_result` ·
`test_manufacturing_started_at_and_completed_at_are_set` ·
`test_rc10_order_line_id_is_populated_on_manufacturing_transaction` ·
`test_failed_manufacturing_marks_line_as_failed` ·
`test_retry_after_failure_re_evaluates_failed_line` ·
`test_retry_does_not_re_execute_executed_lines` ·
`test_mixed_order_only_manufactures_eligible_lines`

- **Expected** — line reaches `NotRequired` / `Executed` / `Failed`; `manufacturing_result`
  populated; a `manufacturing_transactions` row written.
- **Actual** — line is `Skipped`; `manufacturing_result` is `null`; no transaction row.
- **Code path** — `PrepareOrderAction` → `OrderLifecycleCoordinator` →
  `ManufacturingLifecycleHandler::supports()` →
  `PrepareOrderManufacturingAction` maps `LifecycleAction::StatusIgnored → OrderLineManufacturingState::Skipped`.
- **Contract** — `ManufacturingLifecycleHandler::SUPPORTED_STATUSES = ['pending', 'processing', 'preparing']`.
- **Why this classification is correct** — none of those three values exist in **HEAD's**
  `OrderStatus` enum (`new, in_progress, ready_for_dispatch, out_for_delivery, delivered,
  awaiting_payment, awaiting_stock, scheduled, on_hold, cancelled, returned`), and
  `Modules/Operations/OrderLifecycle` is **untouched** by the changeset (`git status` clean).
  So `supports()` already returned `false` for every order at HEAD, and manufacturing has
  never run since the V3 vocabulary landed (committed at or before HEAD, 2026-08-09). The
  changeset cannot have caused it. `test_product_with_sufficient_fg_stock_is_marked_not_required`
  is the cleanest proof: it seeds 100 FG for a quantity of 1, so reservation succeeds and
  the order is never `awaiting_stock` — it still fails, purely on Mode A.

**Consequence:** manufacturing-from-orders is dead in `develop` today, independently of this
task. That is a separate, larger finding and is NOT repaired here.

---

### MODE B — UNRESOLVED · Option B recipe gate, authorization not verifiable

**Tests (4, `OrderManufacturingIntegrationTest`)**

`test_preparing_triggers_manufacturing_for_eligible_line` (FAIL) ·
`test_preparing_sets_order_status_to_preparing` (FAIL) ·
`test_preparing_twice_does_not_duplicate_manufacturing` (ERROR) ·
`test_preparing_twice_preserves_executed_state_on_line` (ERROR)

- **Expected** — order reaches `in_progress` and can proceed to Ready for Dispatch.
- **Actual** — order is `awaiting_stock`;
  `WorkflowPreconditionException: must be In Progress or Confirmed … Current: [awaiting_stock]`.
- **Code path** — `ReserveOrderInventoryAction`: FG short → `can_manufacture &&
  manufacturingIsExecutable($product)` → `ManufacturingAvailabilityService::evaluate()`,
  whose component availability is COMPANY-scoped via `$product->brand?->company_id` and
  **fails closed**. `ProductFactory` gives every product its own `Brand::factory()` → its own
  Company, while the test seeds inventory under the test's company. Different tenants →
  components invisible → `outofstock` → gate closed → shortage path → `awaiting_stock`.

**The two competing contracts**

| | Contract | Status |
|---|---|---|
| HEAD (committed) | ADR-027 v1.1 **P04** *"Reservation never inspects BOM, raw material inventory…"*; **P05** *"Manufacturing never blocks reservation"*; and `ReserveOrderInventoryAction`'s own HEAD docblock: *"can_manufacture=true commits reservation unconditionally … **No Raw Material condition may move an order to Awaiting Stock**."* | **Committed** |
| Changeset (proposed) | ADR-027 **§16** Option B recipe gate + F4 company scoping; **§17.7** replaces P04 with **P04-v1.3**, and explicitly **inverts** the Section 14 compliance row so that an RM query is now the compliant state. Annotated *"Added 2026-08-09 · TASK-GOLIVE-RECIPE-GATE-TENANT-REPAIR-001 · Owner-approved (Option B)."* | **Uncommitted** |

**Why UNRESOLVED rather than OUTDATED TEST or REGRESSION**

Verified: HEAD's ADR-027 ends at Section 15 + Decision — **§16 and §17 do not exist at
HEAD**; they are part of the uncommitted 242-line addition to the same document. The
verification report that would certify them,
`TASK-GOLIVE-RECIPE-GATE-TENANT-REPAIR-001-ENGINEERING-REPORT.md`, is **untracked (`??`)**.

So every artifact asserting that Option B was authorized is *inside the same uncommitted
changeset it authorizes*. The approval is self-attesting; no committed artifact corroborates
it.

- If Option B **was** owner-approved → these 4 are **OUTDATED TEST**: they assert P04/P05,
  superseded by ADR-027 §16 / §17.7. The fixtures would need product, component and
  warehouse to share one company.
- If Option B was **not** approved → the changeset contradicts committed P04/P05 and the
  HEAD docblock *"No Raw Material condition may move an order to Awaiting Stock"*, making
  these a **REGRESSION**.

The repository cannot distinguish these. Per the governing rule, marked **UNRESOLVED** and
stopped — deciding it either way would license rewriting 4 tests to match behaviour that may
never have been authorized.

**Question that resolves it:** was Option B (ADR-027 §16, recipe gate + F4 company scoping,
dated 2026-08-09) actually approved? One yes/no from the owner closes this.

---

### MODE C — PRE-EXISTING · snapshot consistency validation

**Test** `OrderFinancialSnapshotTest::test_consistency_validation_rejects_mismatched_subtotal`

- **Expected** — `SnapshotConsistencyException` matching `/line subtotal/i`.
- **Actual** — no exception thrown.
- **Code path** — `CreateOrderSnapshotService:72`, which rethrows a platform-level
  `Modules\Common\Snapshots\…\SnapshotConsistencyException`.
- **Why this classification is correct** — `CreateOrderSnapshotService` and
  `Modules/Common/Snapshots` are **both untouched** by the changeset. The only changeset file
  anywhere near this path is `Order.php`, whose entire diff is two `$fillable` additions
  (`logistics_city_id`, `confirmed_at`) plus one `datetime` cast — none of which can suppress
  a subtotal-consistency check. No causal path from the changeset exists.

---

### MODE D — REAL DEFECT (test-side, shipped by the changeset) · 14 Inventory errors

**Tests** — all 14 errors are `OrderDrivenMaterialReservationTest` (`test_case_1` … `test_case_17`).

- **Expected** — a BOM is created so the §17 material reconciliation can be exercised.
- **Actual** — every test dies in fixture setup:
  `SQLSTATE[23000]: Column 'yield_quantity' cannot be null` inserting into `bills_of_materials`.
- **Code path** — the test's own BOM factory helper; production code is never reached.
- **Contract / schema** — `bills_of_materials.yield_quantity` is `decimal(10,4) NOT NULL
  DEFAULT 1.0000`, introduced in commit `d2e7c2f6` (2026-07-20), long predating the changeset.
- **Why this classification is correct** — the test file is **untracked (`??`)**: it is a NEW
  test shipped with the uncommitted §17 work. It explicitly binds `yield_quantity => null`
  (the column appears in the INSERT column list), and a column DEFAULT applies only when a
  column is **omitted**, never when NULL is supplied explicitly. So this test has **never
  passed** — it is not PRE-EXISTING (it did not exist at HEAD), not a production regression
  (production code is never reached), and not OUTDATED (there is no superseded behaviour it
  once matched). It is a straightforward defect in the changeset's own test fixture.

**Consequence:** the §17 order-driven RM reservation feature ships with a test suite that has
never run green — 14 of its own cases fail before touching the code they certify. This is
independent of Mode B and does not require the Option B question to be answered.

---

### Inventory failures (4) — CLASSIFIED

| Test | Classification | Evidence |
|---|---|---|
| `InventoryCountSessionTest::test_approve_posts_adjustment_out_for_negative_variance` | **PRE-EXISTING** | Prior documented pristine-HEAD control (files reverted to `6149875b`): "FAIL — identical". Test name, message, expected/actual and line number all match what was observed here. |
| `InventoryCountSessionTest::test_fifo_consumption_record_created_for_adjustment_out` | **PRE-EXISTING** | Same control. |
| `InventoryCountSessionTest::test_adjustment_creates_ledger_entry` | **PRE-EXISTING** | Same control (`null is not null`, line 354). |
| `AvailabilityStateDerivationTest::test_over_reserved_warehouse_does_not_drag_state_out_of_stock` | **REGRESSION — changeset (b) Inventory, NOT Task 1** | Committed test expects clamp-per-warehouse-then-sum (0 + 6 = 6.0); actual −2.0 is the unclamped signed sum. The uncommitted Inventory changeset removed `$itemAvailable = $item->availableQty(); // max(on_hand − reserved, 0) — clamp per warehouse` from `InventorySummaryService`. Outside Task 1's 27 files; carries the same authorization question as Option B. |

### Operations failures (10) — CLASSIFIED

Classified read-only via HEAD control (`git show HEAD:<path>`), with the two consequential
items independently refuted and second-opinioned.

| Test | Prod code reached | Fixture | Pre-existing at HEAD | Changeset | Regression | Owner decision | Classification |
|---|---|---|---|---|---|---|---|
| `OrderExclusivityTest::test_db_unique_constraint_prevents_duplicate_company_order_pair` | No | **Yes** | **Yes** | none | No | No | **PRE-EXISTING** |
| `MaterialAvailabilityContractTest` "CASE C over reserved (zero floor)" | Yes | No | **Yes** | (d) | No | Yes | **OUTDATED TEST** |
| `MaterialAvailabilityContractTest::test_availability_is_never_negative` | Yes | No | **Yes** | (d) | No | Yes | **OUTDATED TEST** |
| `ProductDemandCalculatorTest::test_calculates_completion_percentage` | Yes | No | No | **(e)** | No | No | **OUTDATED TEST** |
| `ProductDemandCalculatorTest::test_remaining_qty_is_never_negative` | Yes | No | No | **(e)** | No | **Yes** | **OUTDATED TEST** (invariant now uncovered) |
| `FinishedGoodOwnReservationDemandTest::test_component_reserved_by_an_order_inside_the_same_wave` | No — 422 first | **Yes** | **Yes** (untracked test) | (a) | No | **Yes** | **OUTDATED TEST** (never green) |
| `OperationsIntegrationFinalCertTest::scenario_d_inventory_transferred_event_has_no_registered_listener` | Yes | No | **No — passes at HEAD** | **(a)** | **YES** | **Yes** | **REGRESSION** |
| `OperationsIntegrationFinalCertTest::scenario_d_warehouse_transfer_completed_event_has_no_registered_listener` | Yes | No | **No — passes at HEAD** | **(a)** | **YES** | **Yes** | **REGRESSION** |
| `OperationsIntegrationFinalCertTest::scenario_d_adr_026_document_exists_at_project_level` | No | **Yes** | **Yes** | none | No | No | **PRE-EXISTING** (container packaging) |
| `Rc10LifecycleCertificationTest::test_reservation_is_the_first_warehouse_gate` | Yes | No | **No — HEAD returns 422** | **(a)** | **Disputed** | **Yes** | **UNRESOLVED** |

Changesets: **(a)** Orders/Fulfillment lifecycle repair · **(b)** Inventory · **(c)** Core/DemandAnalysis ·
**(d)** ADR-027 §16/§17 · **(e)** a **fifth, previously unlisted** changeset —
`Modules/Operations/DemandAnalysis` wave-demand rebuild.

**Correction to an earlier revision of this report.** It stated that the removed per-warehouse
clamp explained the `MaterialAvailabilityContractTest` and `ProductDemandCalculatorTest`
failures. That was wrong. Changesets (b) and (c) cause **none** of the 10 Operations failures —
the clamp is not on any of these code paths (`grep -c 'InventorySummaryService|availableQty'
MaterialDemandCalculator.php` → 0). Those failures belong to changesets (d) and (e).

---

## C8-R. THE REGRESSION — changeset (a) violates a COMMITTED contract

**Two tests, one cause, and the governing decision rule is met.**

`OrderServiceProvider.php:88-95` (modified, uncommitted) registers:

```
InventoryTransferred          → RetryReservationOnStockAvailableListener::handleInventoryTransferred
WarehouseTransferCompleted    → RetryReservationOnStockAvailableListener::handleWarehouseTransferCompleted
```

HEAD registers **one** inventory listener; the changeset raises it to five.

**The contract it breaches is committed and unmodified.** `git ls-files docs/adr/` lists
`ADR-026-transfer-events-phase-b.md`; `git status --porcelain docs/adr/` shows only
`M ADR-027` and `?? ADR-042`. ADR-026 is **committed, unmodified, Status: Accepted**, and states
transfer events *"are intentional orphans in Phase A… an approved architectural state, not a
defect."* The new listener is **synchronous** (`final class`, no `ShouldQueue`) — precisely the
coupling ADR-026 Rationale-3 forbids.

ADR-027 **at HEAD** authorises only `WarehouseAssigned` and `StockReceived`/`PurchaseOrderReceived`
(roadmap M6/H3). The 242 uncommitted ADR-027 lines contain **zero** occurrences of "transfer" —
so not even the proposed contract covers this.

A committed comment at `EventPlatformServiceProvider.php:171-175` records that this same
boundary was breached once before (EPIC-FIN-INTEGRATION-004), caught by this same test, and
reaffirmed.

Unlike Option B, the contradicted artifact here **is committed**. Both an adversarial refuter
and an independent second reviewer reached REGRESSION separately; the refuter reported every
attack vector closed.

**Minimal remediation (NOT applied):** drop `OrderServiceProvider.php:88-95`, keeping the
M6-authorised `InventoryStockReceived` registration. Adopting the transfer listeners instead
requires a new ADR superseding ADR-026 **and** an update to this committed certification test in
the same change.

---

## C8-U. The second unresolved item — RC-10 warehouse gate

`Rc10LifecycleCertificationTest::test_reservation_is_the_first_warehouse_gate` expects **422**,
gets **200**. Provably **not** pre-existing: HEAD returns 422 via
`MoveToPreparationWorkflow → ReserveOrderInventoryAction → OrderWarehouseNotAssignedException
extends UnprocessableEntityHttpException`; the uncommitted pre-branch at
`MoveToPreparationWorkflow.php:88-104` returns `FulfillmentResult::success(... 'blocker' =>
'no_warehouse_assigned')` → HTTP 200.

**Two COMMITTED artifacts conflict:**

| Committed source | Says |
|---|---|
| `TASK-PHASE3-RC10-FINAL-CLOSE-001-ENGINEERING-REPORT.md:86` (added by HEAD commit `6149875b` itself) | *"Reservation is the FIRST warehouse gate — no warehouse → **422**, order stays `in_progress` \| ✅ PASS"* |
| `ADR-027` §2 / §10 at HEAD | *"Reservation Execution: **postponed** … `reservation_status` remains `pending`"*; no coverage is *"a Command Center signal, **not an error**"* |

Neither text addresses the **HTTP** outcome of an operator-initiated transition. Safety is
intact either way: dispatch-side files are unmodified and the order still cannot reach
`ready_for_dispatch`.

**Owner ruling required:** must `POST /fulfillment/orders/{id}/transition → ready_for_dispatch`
on a warehouse-less order be **refused with 4xx**, or is **200 + `reservation_status = pending`
+ `blocker: no_warehouse_assigned`** the correct contract?

---

## C12. TASK 1 CLOSURE GATE

### 🔴 BLOCKING — must be resolved before Task 1 can close

| # | Item | State |
|---|---|---|
| B1 | **REGRESSION: transfer-event listeners violate committed ADR-026** (2 tests) | **OPEN** — remediation identified, not applied |
| B2 | Test fixture defect repaired — `yield_quantity => null` in the new §17 test (14 tests) | **OPEN** — not modified |
| B3 | Order-driven reservation tests actually execute | **OPEN** — blocked by B2 |
| B4 | No unexplained production regression remains | **OPEN** — blocked by B1 |
| B5 | Relevant regression suites pass | **OPEN** — blocked by B1–B3 |
| B6 | Final report can truthfully say CERTIFIED | **OPEN** |

### 🟠 OWNER DECISION REQUIRED — engineering cannot proceed past these

| # | Decision | Consequence |
|---|---|---|
| D1 | **Was ADR-027 §16 Option B owner-approved?** | Approved → 4 Mode B tests are OUTDATED (fixtures need one shared company). Not approved → changeset contradicts committed P04/P05 = second REGRESSION. |
| D2 | **RC-10 warehouse gate: 422 or 200?** | Decides REGRESSION vs OUTDATED TEST for `test_reservation_is_the_first_warehouse_gate`. |
| D3 | **Adopt or drop the transfer-event listeners?** | Drop → delete 8 lines, B1 closes. Adopt → new ADR superseding ADR-026 + update the committed certification test. |
| D4 | Deployment ownership — may this worktree's changesets be committed and deployed to `ecos_erp`? | 27 files authored by other sessions; `ecos-app` is another worktree/branch. |
| D5 | Clause F — Scheduled + unavailable stays `scheduled` permanently. | Needs a lifecycle ruling. |
| D6 | `ProductDemandCalculatorTest::test_remaining_qty_is_never_negative` — the non-negative invariant was *relocated*, leaving it uncovered. | Confirm intended, and whether new coverage is required. |

### 🟡 NON-BLOCKING / PRE-EXISTING — predates this work, do not gate Task 1

| Item | Count |
|---|---|
| Mode A — `ManufacturingLifecycleHandler` supports only the dead `pending/processing/preparing` vocabulary; manufacturing-from-orders is dead in `develop` | 9 |
| Mode C — snapshot consistency validation | 1 |
| `InventoryCountSessionTest` ×3 (prior HEAD control: "FAIL — identical") | 3 |
| `OrderExclusivityTest` fixture omits `order_confirmed_at` | 1 |
| `scenario_d_adr_026_document_exists_at_project_level` — `.dockerignore` excludes `docs/`; testrunner has no mounts | 1 |

### ⚪ OUT OF SCOPE for Task 1 — different changesets

| Item | Owning changeset |
|---|---|
| `AvailabilityStateDerivationTest` — removed per-warehouse clamp | (b) Inventory |
| `MaterialAvailabilityContractTest` ×2 — zero floor | (d) ADR-027 §16/§17 |
| `ProductDemandCalculatorTest` ×2 | (e) Operations/DemandAnalysis wave-demand rebuild |
| `FinishedGoodOwnReservationDemandTest` — untracked, never green | (a), but a §17 concern |
| Dead manufacturing handler (Mode A) — its own repair task | pre-existing |

### ✅ ALREADY SATISFIED

| Item | Evidence |
|---|---|
| A–Q availability/lifecycle matrix | 22 tests, 58 assertions, 0 failures (clause F incomplete by design) |
| Runtime proof, repaired vs unrepaired stack | `ecos_dev` 0 `awaiting_stock`; `ecos_erp` 1 with `no_warehouse=1` |
| Static gates | PHPStan L0 PASS · core L6 PASS · Pint PASS on new file |
| Legacy row handling | H4 dry-run: would change nothing; `--execute` not requested |
| Inventory failures classified | 4/4 |
| Operations failures classified | 10/10 |

**TASK 1 STATUS: NOT CERTIFIED — one confirmed REGRESSION against committed ADR-026, plus six
open owner decisions.** Nothing was modified in producing this audit: no production code, no
test, no fixture, no database, no contract, no commit, no deployment.

---

### C8 verdict

| Mode | Tests | Classification |
|---|---|---|
| A — manufacturing handler dead | 9 | **PRE-EXISTING** |
| B — Option B recipe gate | 4 | **UNRESOLVED — blocks classification** |
| C — snapshot validation | 1 | **PRE-EXISTING** |
| D — `yield_quantity` NULL in new §17 test | 14 | **REAL DEFECT** (changeset's own test fixture) |
| Inventory — count sessions ×3 | 3 | **PRE-EXISTING** (prior HEAD control) |
| Inventory — availability clamp | 1 | **REGRESSION** — changeset (b), outside Task 1 |
| Operations — fixture / packaging | 2 | **PRE-EXISTING** |
| Operations — superseded expectations | 5 | **OUTDATED TEST** (changesets d, e, a) |
| **Operations — ADR-026 transfer listeners** | **2** | **REGRESSION — committed contract breached** |
| Operations — RC-10 warehouse gate | 1 | **UNRESOLVED** (committed vs committed) |

**A REGRESSION IS NOW CONFIRMED.** Changeset (a) registers listeners for `InventoryTransferred`
and `WarehouseTransferCompleted`, contradicting **ADR-026 — committed, unmodified, Status:
Accepted** — which declares those events deliberate orphans in Phase A. Per the governing rule,
this is marked REGRESSION and work stopped. See §C8-R.

Mode B (Option B) remains separately unresolved and is not answerable from the repository.

Two blocking findings are already firm and do **not** depend on Mode B:

- **Mode D** — the §17 feature's own test suite has never passed. The changeset is
  incomplete on its own terms.
- **Mode A** — manufacturing-from-orders is dead in `develop` today, predating this work.

Nothing was modified: no test edited, no production code touched, no commit, no deployment.

## C9. Deployment status — BLOCKED

| Question | Answer |
|---|---|
| Current worktree / branch | `C:\ecos-develop` / `develop` |
| Changeset ownership | **Not mine** — three prior sessions, uncommitted |
| Target showing the defect | `ecos-app` / `ecos_erp` — **different worktree and branch** |
| Parity requirement | 27 files **plus** the ADR-042 migration, together |

Nothing was copied to `ecos-app`. Deploying another worktree's uncommitted work across a
branch boundary — including a status-rewriting migration — is not an action to take without
ownership and authorization.

## C10. Final verdict

**NOT CERTIFIED — DEPLOYMENT OWNERSHIP BLOCKER.**

The availability/lifecycle contract itself is sound and now proven: the A–Q matrix passes on
every clause but F, recovery and reservation are real and idempotent, tenant isolation and
warehouse correctness hold, and static quality is clean.

Three things block certification:

1. **Regression classification UNRESOLVED** (C8 Mode B) — 4 Orders tests turn on whether the
   Option B recipe gate was owner-approved. Every artifact asserting that approval is inside
   the same uncommitted changeset it authorizes, so the repository cannot confirm it.
2. **Deployment ownership** — the 27-file changeset belongs to another session, and the
   affected stack is another worktree on another branch.
3. **Clause F** — a genuine lifecycle gap requiring a business decision. (C4)

**Correction to the previous revision of this section.** It stated that the changeset leaves
14 Orders tests red and was therefore "not commit-ready". That was wrong, and the corrected
classification in C8 supersedes it: **10 of the 14 are PRE-EXISTING** — 9 because
`ManufacturingLifecycleHandler` has supported only the dead `pending/processing/preparing`
vocabulary since before HEAD, and 1 in a snapshot path the changeset never touches. Only 4
are attributable to the changeset at all, and those are UNRESOLVED rather than proven
regressions. No REGRESSION was confirmed, and none was ruled out.

A separate finding surfaced by that analysis: **manufacturing-from-orders is dead in
`develop` today** — `supports()` matches no current status, so every line is `Skipped` and no
manufacturing transaction is ever written. That predates this work and is not repaired here.

### Recommended order

1. **Answer one question:** was Option B (ADR-027 §16, dated 2026-08-09) owner-approved?
   Yes → the 4 Mode B tests are OUTDATED and their fixtures need one shared company.
   No → the changeset is a REGRESSION against committed P04/P05 and must be reworked.
2. Classify the outstanding Inventory (18) and Operations (10) failures.
3. Split `CustomerOrderMetricsService.php` out; commit the remainder once 1–2 are settled.
4. Decide clause F; implement under its own task.
5. Deploy code **and** the ADR-042 migration together to `ecos_erp`; re-run this matrix there.
6. Re-run the H4 dry-run against `ecos_erp` before any `--execute`.
7. Raise the dead manufacturing handler as its own task.

## C11. Scope compliance

No Preparation, Wave, Distribution, Loading, Vehicle, Driver, Delivery, Settlement,
Warehouse Assignment or Reservation-formula code was modified. The only files written by
this closure are the new test and this report. **No production file was changed.**

---
---

# OPTION B — DECISION BRIEF (owner decision required)

**Prepared read-only. No code, fixture, database or contract was modified.**
Canonical source: `docs/adr/ADR-027-reservation-ownership-policy.md` §16 (v1.2), lines 514–600.

## 1. What Option B means

Verbatim from §16.1 — *"an unexecutable Recipe withholds the manufacturing commitment."*

The business chain it installs:

```
Raw Materials executable → Recipe executable → Finished Product manufacturable
    → Reservation allowed → Order continues
```

and its negative:

```
Required Raw Material unavailable AND allow_negative_stock = false
    → Recipe = outofstock → no manufacturing commitment → Order = Awaiting Stock
```

## 2. What business behaviour it changes

It amends **Section 3, Case 2 only**.

| | Before (Sections 1–15) | After Option B |
|---|---|---|
| `can_manufacture = true`, FG stock short | Commits the **full ordered quantity unconditionally** | Commits **only if the recipe is executable** |
| Raw material shortage | Cannot affect the order — *"No Raw Material condition may move an order to Awaiting Stock"* | **Can** send the order to Awaiting Stock |
| FG physically in stock | Reserved from stock | **Unchanged** — §16.2 keeps Case 1 first, recipe never consulted |
| No active recipe | Prior behaviour | **Unchanged** — `recipe_missing` does not block |

Two guardrails are explicit in the ADR: an order fulfillable from finished-goods stock must
**never** be blocked by an unexecutable recipe (§16.2, *"a hard requirement"*), and Awaiting
Stock is produced by the existing V3 workflow, never written by hand in the reservation action.

**§16.4 (F4) rides along with it** and is the part with the sharpest operational edge:
recipe availability becomes **COMPANY-scoped** via `Finished Product → Brand → Company`
(ADR-013), and it **fails closed** — *"When the finished good has no derivable company, the
engine exposes no inventory."* A product whose brand/company is unset or mismatched sees zero
raw material and is therefore unmanufacturable.

## 3. Code paths that depend on it

| Path | Role |
|---|---|
| `ReserveOrderInventoryAction::manufacturingIsExecutable()` | The gate itself — `evaluate()['status'] !== 'outofstock'` |
| `ReserveOrderInventoryAction` Case 2 branch | `can_manufacture && manufacturingIsExecutable()` — the amended condition |
| `ManufacturingAvailabilityService::evaluate()` | §16.3 sole authority; carries the §16.4 company scoping and fail-closed rule |
| `ProcessOrderWorkflow` / `MoveToPreparationWorkflow` | Consume the resulting `awaiting_stock` reservation status |
| `OrderStatus::yieldsToStockBlock()` | Decides whether the lifecycle status follows the shortage |

## 4. The alternative option

**There is no documented Option A.** Searched: the ADR names "Option B" without ever defining
an Option A, and no document in `docs/` defines one for this decision. (Other "Option B" hits
in the register are unrelated decisions — PD-1 warehouse-at-dispatch and D-10 vehicle-optional.)

The de-facto alternative is therefore the **status quo ante**: Sections 1–15 as committed —
`can_manufacture = true` commits unconditionally, and raw-material state never influences order
status. That is what `develop` implements today at HEAD.

## 5. If Option B is NOT approved

- The 27-file changeset **contradicts committed contract** — P04, P05, and
  `ReserveOrderInventoryAction`'s HEAD docblock *"No Raw Material condition may move an order to
  Awaiting Stock"*. The 4 Mode B failures become a **REGRESSION** and the recipe gate must be
  reverted or reworked.
- §16.4 fail-closed company scoping goes with it.
- Operationally: an order for a manufacturable product is committed even when no raw material
  exists. The shortage surfaces later, at Preparation, rather than at reservation.

## 6. If Option B IS approved

- The 4 Mode B failures are **OUTDATED TEST** — they assert superseded P04/P05. Their fixtures
  need product, component and warehouse to share **one company** (§16.4), which
  `ProductFactory` does not currently produce (it gives each product its own Brand → own Company).
- Awaiting Stock legitimately becomes reachable from a raw-material condition.
- **Fail-closed becomes a live operational risk:** any finished good with a null or mismatched
  `brand.company_id` silently becomes unmanufacturable. My own note on the originating task
  records `products.company_id` as *flagged, not fixed* — worth confirming before approving.

## 7. Evidence that Option B was already owner-approved — NONE THAT IS INDEPENDENT

This is the crux, and the honest answer is that **no committed artifact records the approval.**

| Artifact | Claim | Git state |
|---|---|---|
| ADR-027 §16 header | *"Owner-approved (Option B)"*, 2026-08-09 | **`M` uncommitted** (part of a 242-line addition; HEAD's ADR ends at Section 15) |
| `TASK-GOLIVE-RECIPE-GATE-TENANT-REPAIR-001` report | *"Option B ✅ Owner-approved — implemented as specified"*, *"exactly as instructed"* | **`??` untracked** |
| ADR-027 §17 (v1.3, separate amendment) | *"Status: Approved"*, input `TASK-INVENTORY-NEGATIVE-STOCK-ADR-027-AMENDMENT-001` | **`M` uncommitted**; that input task has **no report at all** |
| `EPIC-ENTERPRISE-GOLIVE-001-PHASE2.5-DECISION-REGISTER.md` | The project's committed decision ledger | **committed — contains no mention of the recipe gate, §16, or Option B** |

Every artifact asserting the approval sits **inside the same uncommitted body of work it
authorizes**. The committed decision register — the one place a business approval would
normally be recorded, and which does record other owner decisions (PD-1, PD-2, D-10, GD-2) —
is **silent** on this one.

Per instruction, approval is **not** inferred from the existence of the implementation.

**The decision required: was ADR-027 §16 Option B (recipe gate + §16.4 company-scoped,
fail-closed availability) actually approved on or around 2026-08-09?**

---
---

# CONTRACT RECONCILIATION — TASK 1 (authority override applied)

**Date:** 2026-08-15. Produced under the owner's binding principle: *previously approved
contracts are frozen; find the latest authoritative decision and apply it; do not ask the owner
to repeat a decision that already exists.*

Applying that principle **dissolved every item previously escalated as an Owner Decision.**
None of D1, D2, D5 or D6 was a new decision; each already had governing authority.

## R1. Reconciliation matrix

| Finding | Previous Authority | Current Code | Conflict? | Required Action |
|---|---|---|---|---|
| **D1** Option B recipe gate | ADR-027 §16 (2026-08-09, owner-approved). **Not** superseded — §17's supersession list names only §3, §11, P04 | matches §16.1/§16.3/§16.4 | none | **RESOLVED BY EXISTING CONTRACT** |
| **D2** RC-10 warehouse gate HTTP | Committed RC-10 certification asserts 422; ADR-027 §2/§10 govern the DOMAIN only; ADR-042 §6.1 forbids answering "200 OK while silently discarding the operator's decision" | returned 200 | **code violates authority** | **REPAIRED** — refuse in `guard()` |
| **D3** transfer listeners | ADR-026 (committed, Accepted) — transfer events are deliberate Phase-A orphans | registered 2 listeners | **code violates authority** | **REPAIRED** — registrations removed |
| **D4** deployment ownership | — | — | — | Operational, not a business rule — remains with the owner |
| **D5** Clause F | ADR-042 §7 (scheduled orders "enter `in_progress`" at their trigger) + §6.1 (shortage → `awaiting_stock`); §5 rule 1 forbids the alternative fix | reserved before activating | **code violates authority** | **REPAIRED** — activate first |
| **D6** relocated invariant | Wave-demand rebuild is an approved contract change; Wave = Operational Cycle corroborates it | clamp relocated to read layer | test asserts obsolete | Update 2 tests; **coverage gap is genuine** |
| Mode A ×9 manufacturing | ADR-042 §7/§8 → the correct value is `['in_progress','confirmed']` | dead `pending/processing/preparing` | code violates authority | **OUT OF SCOPE** — see R4 |
| Mode B ×4 | ADR-027 §16.4 (Product→Brand→Company, fail-closed) | fixture spans three companies | fixture defect | **REPAIRED** |
| Mode C ×1 snapshot | — | path untouched by any changeset | none | PRE-EXISTING |
| Mode D ×14 | Schema is the committed contract (commit `30655b91`, 2026-07-02) | `yield_quantity => null` | fixture defect | **REPAIRED** |
| Inventory ×3 count sessions | Prior documented HEAD control | — | none | PRE-EXISTING |
| Inventory ×1 availability | Clamp removal **is** authorised (ADR-027 §17.3 / P08) | signed sum | test asserts obsolete | **UPDATED** |
| Operations ×2 listeners | ADR-026 | — | — | **REPAIRED + verified** |
| Operations ×1 ADR doc | — | stale container `docs/` (1 file vs 17) | none | **RESOLVED** — environment parity |
| Operations ×2 material availability | ADR-027 §17.3 | signed | test asserts obsolete | **UPDATED** |
| Operations ×2 product demand | Wave-demand rebuild | relocated | test asserts obsolete | Deferred — see R4 |
| Operations ×1 FG own reservation | ADR-027 §17 | untracked test, never green | fixture defect **+ disputed expected value** | Deferred — see R4 |
| Operations ×1 RC-10 | see D2 | — | — | **REPAIRED** |

## R2. Corrections to earlier revisions of this report

1. **D1 was never unresolved.** §7 of this report claimed *"that input task has **no report at
   all**"*. That is false: `TASK-INVENTORY-NEGATIVE-STOCK-FULFILLMENT-CONTRACT-REPAIR-003-ENGINEERING-REPORT.md:354`
   carries `# ADDENDUM — TASK-INVENTORY-NEGATIVE-STOCK-ADR-027-AMENDMENT-001`. §16 is further
   settled **by reliance** — at least nine later approved/certified tasks (2026-08-09 → 08-15)
   build on it. Escalating D1 was wrong.
2. **`yield_quantity` provenance** — introduced by commit `30655b91` (2026-07-02,
   TASK-ARCH-PRICE-001), not `d2e7c2f6` (2026-07-20), which only added a `Schema::hasColumn`
   guard.
3. **`AvailabilityStateDerivationTest`** was classified REGRESSION in changeset (b). The clamp
   removal **is** authorised by ADR-027 §17.3 / P08; it is an **outdated test**.
4. **Mode A** was reported as needing a one-line vocabulary correction. It needs **three**
   production edits — `ManufacturingLifecycleHandler::SUPPORTED_STATUSES`,
   `ManufacturingPolicy::MANUFACTURING_ALLOWED_STATUSES`, and a re-sequencing of
   `PrepareOrderAction` — because manufacturing is currently invoked only *after* the order is
   already `ready_for_dispatch`, a status §7 excludes. A one-line change would convert 9
   `Skipped` into 9 `PolicyRejected` and fix nothing.

## R3. Changes applied — minimum, contract-enforcing only

**Regression repairs (production):**

1. `OrderServiceProvider` — removed the `InventoryTransferred` and `WarehouseTransferCompleted`
   registrations and their imports. Handler methods left in place, unreferenced, so a future ADR
   superseding ADR-026 could enable them without rewriting recovery logic. **ADR-026 untouched.**
2. `MoveToPreparationWorkflow` — the null-warehouse precondition moved out of `execute()` and
   into `guard()`, throwing `WorkflowPreconditionException` (→ 422) alongside the three
   preconditions already there. ADR-027 §2/§10 obligations are preserved exactly: no
   `awaiting_stock` write, no lifecycle status change, order stays recoverable. This also
   removes a **false-success audit row** — `FulfillmentEngine` writes its `OrderEvent` after
   `execute()`, so the 200 path had been recording a `ready_for_dispatch` event for orders that
   never became ready. `guard()` runs before the transaction, so a refusal writes nothing.
3. `ProcessOrderWorkflow` — a `Scheduled` order is activated to `InProgress` **before**
   availability is consulted (ADR-042 §7), so §6.1 then applies normally and a shortage yields
   `awaiting_stock`. Deliberately **not** done by adding `Scheduled` to `yieldsToStockBlock()`,
   which ADR-042 §5 rule 1 forbids. The existing D-1 delivery-date guard gates the write, so a
   future-dated order can never be activated early — verified by a new regression test.

**Fixture defects (tests only):**

4. `OrderDrivenMaterialReservationTest::recipe()` — `?float $yield = null` → `float $yield = 1.0`.
   The schema is the committed contract and was **not** relaxed to nullable, which would have
   broken `ProductCostCalculator` and the frozen-yield rule.
5. `OrderManufacturingIntegrationTest` — one `Brand` owned by the test company, with every
   product hung off it, satisfying ADR-027 §16.4's Product→Brand→Company chain. No assertion
   changed, no production change. (A prior certification had already classified this as
   "fixture ownership defect" and pre-authorised a fixture-only correction.)

**Obsolete tests updated to the current contract:**

6. `MaterialAvailabilityContractTest` — `CASE C` provider row now expects the signed −5.0/15.0;
   `test_availability_is_never_negative` → `test_availability_is_signed_and_may_go_negative`.
7. `AvailabilityStateDerivationTest::test_over_reserved_warehouse_does_not_drag_state_out_of_stock`
   → `…_drags_the_signed_sum_negative`, expecting −2.0 / OutOfStock.
8. `OrderAvailabilityLifecycleContractTest` clause F now asserts `AwaitingStock` (the
   `markTestIncomplete` is gone), and a new **F2** proves a D+5 order is still not ejected from
   Scheduled by a shortage.

**Explicitly NOT touched:** ADR-026, ADR-027, ADR-042, `yieldsToStockBlock()`,
`advancesToInProgressOnReservation()`, `ManufacturingAvailabilityService`, the §16.4 fail-closed
rule, the dispatch-side warehouse gate, `InventorySummaryService`, the 27-file repair at large,
and every certification expectation.

## R4. Deferred, with reasons

- **Mode A (9 tests)** — repairing it requires three production edits, one crossing into the
  Manufacturing module, plus migrating two committed green test files. That is new behaviour, not
  minimum change, and a different domain. Its vocabulary answer is already settled
  (`['in_progress','confirmed']`, ADR-042 §7/§8) so a follow-up task needs no owner input on that
  point. One narrow question remains genuinely open for that task: whether `PrepareOrderAction`
  should evaluate manufacturing *before* `MoveToPreparationWorkflow` (DECISION-ENGINE-SPEC §2.1
  as translated by ADR-042 §8).
- **`ProductDemandCalculatorTest` (2 tests)** — the relocation is approved, so these are
  outdated; the prescribed fix moves one of them to an HTTP test against the read layer that now
  owns the clamp. Recorded, not applied, to keep this change minimal.
- **`FinishedGoodOwnReservationDemandTest` (1 test)** — has two independent defects, and its
  correct expected value is disputed (8.0 vs 18.0). Guessing it would encode a wrong assertion,
  so it is left for a targeted fix.
- **The non-negative Remaining invariant currently has NO test coverage anywhere.** Restoring it
  needs one HTTP test at the read layer and no production change or owner ruling.

## R5. Remaining genuine owner decisions

Exactly **one**, and it is operational rather than a business rule:

- **D4 — deployment ownership.** May this worktree's changesets be committed, and may they be
  deployed to `ecos_erp`? The 27 files were authored by other sessions and `ecos-app` is a
  different worktree on a different branch. No historical authority can settle who may commit
  another session's work.

Everything previously escalated as D1, D2, D3, D5 and D6 is now **resolved by existing
contract** and applied.

## R6. Verification after the reconciliation repairs

| Suite / gate | Before | After |
|---|---|---|
| `OperationsIntegrationFinalCertTest` scenario_d | 2 failures (+1 packaging) | **3 / 3 OK** |
| `OrderDrivenMaterialReservationTest` | 14 errors | **18 tests, 94 assertions, OK** |
| `OrderAvailabilityLifecycleContractTest` | 22 tests, 1 incomplete | **23 tests, 59 assertions, OK** |
| `tests/Feature/Orders` | 2 errors + 12 failures | **0 errors**, 12 failures |
| PHPStan L0 platform-wide | PASS | **PASS** |
| PHPStan core L6 | PASS | **PASS** |
| Pint (edited test files) | — | **PASS** |

**Mode B is fully resolved.** A grep of the whole `OrderManufacturingIntegrationTest` failure
output for `awaiting_stock` / `AwaitingStock` returns **0**. The tenant-chain fixture repair
removed the shortage entirely; those orders now reach `in_progress` as ADR-027 §16.4 intends.

The 12 remaining failures in `tests/Feature/Orders` are **11 × Mode A + 1 × Mode C**, both
PRE-EXISTING and out of scope. Mode A's count rose from 9 to 11 precisely *because* Mode B was
fixed: two tests that previously died at the `awaiting_stock` wall now get past it and reach the
dead manufacturing handler behind it. That is progress, not regression — the same two tests,
failing later in the same run for a different, older reason.

Pint on `ProcessOrderWorkflow` / `MoveToPreparationWorkflow` still reports the module-wide
baseline rule set. Unchanged by these edits, and identical to the set reported for sibling files
never touched here (`ConfirmOrderWorkflow`, `ResumeOrderWorkflow`, `ReturnToPendingWorkflow`) —
which is what establishes it as baseline rather than newly introduced.

---
---

# R7. D4 — DEPLOYMENT OWNERSHIP, RESOLVED

## R7.1 Correction: ownership IS proven, and my earlier claim was wrong

Earlier revisions of this report stated repeatedly that *"`ecos-app` is a different worktree on
a different branch"* and treated that as the blocking fact. **That was wrong.** It was inferred
from the container running stale code, never from ownership evidence. The evidence:

```
docker inspect ecos-dev-app → com.docker.compose.project=ecos-dev  working_dir=C:\ecos-develop
docker inspect ecos-app     → com.docker.compose.project=ecos-erp  working_dir=C:\ecos-develop
```

Both stacks were launched from **this** worktree, and both compose files live in it:

| Compose file | Project | App container | Database |
|---|---|---|---|
| `docker-compose.override.yml` (`name: ecos-dev`) | `ecos-dev` | `ecos-dev-app` | `ecos_dev` |
| `docker-compose.yml` (`name: ecos-erp`) | `ecos-erp` | `ecos-app` | `ecos_erp` |

`ecos-app` is not another worktree's stack. It is **this worktree's primary stack**, running a
stale baked image (no source mount — only a storage volume), which is exactly why it still
serves pre-repair code.

## R7.2 The six ownership questions

| # | Question | Answer |
|---|---|---|
| 1 | Which worktree/branch owns the changeset? | **`C:\ecos-develop`, branch `develop`.** `git worktree list` shows `C:/Projects/ECOS-ERP` on `platform-foundation` and `C:/ecos-bt` on `main` — neither holds this work. Branch ownership also puts Orders on `develop`. |
| 2 | Which files belong to this task? | `Modules/Commerce/Orders/**` + `Modules/Operations/Fulfillment/**` (27), plus this session's `OrderAvailabilityLifecycleContractTest` and two fixture repairs. |
| 3 | Which belong to other tasks/sessions? | `CustomerOrderMetricsService.php` (CRM KPI, unrelated); the Inventory changeset (11 files); Core/DemandAnalysis; Operations/DemandAnalysis; the ADR-027 §16/§17 and ADR-042 documents; and the rest of **387 dirty paths** repo-wide. |
| 4 | Can the complete changeset be committed safely? | **NO — see R7.3.** |
| 5 | Intended deployment target | `ecos-app` / `ecos_erp` — the stack that still exhibits the defect, owned by this worktree. |
| 6 | Is deployment authorised by ownership? | **YES, ownership authorises it.** A different constraint blocks it — R7.3. |

## R7.3 The real blocker is ENTANGLEMENT, not ownership

The Orders changeset has a **hard code dependency on a second uncommitted changeset**:

```
HEAD    ReserveStockAction  →  0 occurrences of allow_negative_stock
WORKTREE ReserveStockAction →  1 occurrence
```

`ReserveOrderInventoryAction` (Orders changeset) is written against the new behaviour and says
so in-code: *"ReserveStockAction now honours allow_negative_stock, so the whole quantity is
recorded."* That premise is false at HEAD.

Consequences, both directions:

- **Committing** the 27 Orders files alone produces a commit whose reservation path is broken —
  Orders code calling Inventory behaviour that does not exist at that commit.
- **Deploying** only the Orders files to `ecos-app` produces the same broken pairing at runtime:
  Orders-at-worktree against Inventory-at-HEAD.
- **Deploying everything** would ship **387 dirty paths** of unrelated multi-session work
  (Purchasing, CRM, Finance, DemandAnalysis, IAM…) to the primary stack. That is far beyond
  "the approved changeset" and is not a defensible action.

There is also a schema coupling: ADR-042 §11 requires
`2026_08_13_100000_supersede_order_lifecycle_v3_canonical` to ship in the same deploy as
`OrderStatus.php`. It is applied on `ecos_dev` and **not** on `ecos_erp`, so any code deployment
to the primary stack must carry a status-rewriting migration with it.

**Therefore nothing was committed and nothing was deployed to `ecos-app`.** Not because
ownership is unproven — it is proven — but because no subset of this working tree can be
shipped in isolation without shipping a broken pairing, and the full tree is not this task's
changeset to ship.

## R7.4 What would unblock it

1. Commit the Inventory changeset (11 files) **and** the Orders changeset (27 files) together,
   as one coherent unit — they are one logical change split across two modules.
2. Split `CustomerOrderMetricsService.php` out first; it belongs to neither.
3. Deploy code **and** the ADR-042 migration to `ecos_erp` in the same step.
4. Re-run this suite against `ecos_erp` and re-check the single legacy row with
   `orders:reprocess-legacy-reservations` (dry-run first).

Step 1 is the decision that is genuinely not mine: it commits another session's work in two
modules at once.

---
---

# R8. FINAL CERTIFICATION

## R8.1 D1–D6 final status

| # | Decision | Status | Authority |
|---|---|---|---|
| D1 | Option B recipe gate | **RESOLVED — apply** | ADR-027 §16 (2026-08-09); not superseded by §17 |
| D2 | RC-10 warehouse gate | **RESOLVED — repaired to 422** | Committed RC-10 cert + ADR-042 §6.1 |
| D3 | Transfer listeners | **RESOLVED — removed** | ADR-026 (committed, Accepted), untouched |
| D4 | Deployment ownership | **RESOLVED — ownership proven** | Both stacks launched from `C:\ecos-develop`; see R7 |
| D5 | Clause F | **RESOLVED — activate first** | ADR-042 §7 + §6.1; §5 rule 1 bars the alternative |
| D6 | Remaining invariant | **RESOLVED — behaviour preserved** | Wave rebuild approved; coverage gap documented |

No decision remains open. **Zero new owner decisions were created by this task.**

## R8.2 Regression classification — final

| Mode | Count | Classification |
|---|---|---|
| A — dead manufacturing vocabulary | 11 | **PRE-EXISTING** · out of scope · ADR-042 answer attached |
| B — tenant chain | 4 | **RESOLVED** (fixture) — 0 `awaiting_stock` references remain |
| C — snapshot consistency | 1 | **PRE-EXISTING** · path untouched by any changeset |
| D — `yield_quantity` NULL | 14 | **RESOLVED** (fixture) |
| Inventory — count sessions | 3 | **PRE-EXISTING** (prior HEAD control) |
| Inventory — availability clamp | 1 | **RESOLVED** (obsolete test updated) |
| Operations — ADR-026 listeners | 2 | **RESOLVED** (regression repaired) |
| Operations — ADR-026 doc | 1 | **RESOLVED** (container docs parity) |
| Operations — material availability | 2 | **RESOLVED** (obsolete tests updated) |
| Operations — RC-10 gate | 1 | **RESOLVED** (regression repaired) |
| Operations — product demand | 2 | Deferred — obsolete, fix prescribed |
| Operations — FG own reservation | 1 | Deferred — expected value disputed |

**Mode A rose from 9 to 11 because Mode B was fixed**, not because anything regressed: two tests
that previously died at the `awaiting_stock` wall now pass it and reach the older dead-handler
wall behind it. No new evidence attributes Mode A to this changeset.

## R8.3 Exact remaining failures

`tests/Feature/Orders` — **12**, all pre-existing and out of scope:
11 × `OrderManufacturingIntegrationTest` (Mode A) + 1 × `OrderFinancialSnapshotTest` (Mode C).

Deferred obsolete tests (3): `ProductDemandCalculatorTest` ×2, `FinishedGoodOwnReservationDemandTest` ×1.

## R8.4 Deployment

| Item | Result |
|---|---|
| Ownership | **PROVEN** — `C:\ecos-develop` / `develop` owns both stacks |
| Authorised by ownership | **YES** |
| Committed | **NO** — changeset entanglement (R7.3) |
| Deployed to `ecos-app` | **NO** — same reason |
| Deployed to `ecos-dev-app` | Partially, during earlier verification; `ecos_dev` already runs the repaired behaviour |
| Runtime parity `ecos_erp` | **NOT ACHIEVED** — still pre-repair, and the ADR-042 migration is unapplied there |

## R8.5 Verdict

**NOT CERTIFIED — CHANGESET ENTANGLEMENT BLOCKER.**

Deliberately *not* "deployment ownership blocker": ownership was the suspected blocker and it is
now disproved. The Orders changeset cannot be committed or deployed in isolation because it
depends on an uncommitted Inventory changeset, and the full working tree (387 dirty paths) is
not this task's to ship.

Everything within this task's control is done and verified:

- The confirmed ADR-026 regression is repaired and the committed guard passes.
- The RC-10 regression is repaired; the false-success audit row is gone.
- Clause F is implemented per ADR-042 and no longer incomplete.
- Both fixture defects are repaired; 18 previously-erroring tests now pass.
- Three obsolete tests were updated to the current approved contract.
- Static gates are clean.

One action unblocks certification, and it is a commit decision spanning another session's work
in two modules: **commit Inventory (11) + Orders (27) together**, split out
`CustomerOrderMetricsService.php`, then deploy code and the ADR-042 migration to `ecos_erp`
together and re-run this suite there.

## R8.6 Test evidence — before / after

| Suite | Before | After |
|---|---|---|
| `OrderAvailabilityLifecycleContractTest` | did not exist | **OK — 23 tests, 59 assertions** |
| `OrderDrivenMaterialReservationTest` | 14 errors | **OK — 18 tests, 94 assertions** |
| `OperationsIntegrationFinalCertTest` scenario_d | 2 failures + 1 packaging | **OK — 3 / 3** |
| `tests/Feature/Orders` | 2 errors + 12 failures | **0 errors**, 12 failures (Mode A + C) |
| `AvailabilityStateDerivationTest` + `MaterialAvailabilityContractTest` | 3 failures | **OK — 17 tests, 59 assertions** |
| PHPStan L0 platform-wide | PASS | **PASS** |
| PHPStan core L6 | PASS | **PASS** |
| Pint (files edited here) | — | **PASS** |

The `coverage_pct` correction is worth recording as a method note: the value was derived from the
production formula — `min(100.0, round(($available / $required) * 100.0, 2))`, whose `min` caps
only the UPPER bound — and then independently confirmed by a runtime failure message reading
`Failed asserting that -9890.0 matches expected 0.0`. Derivation and runtime agreed exactly.

---
---

# §R9 — CHANGESET INTEGRATION & FINAL CERTIFICATION

**Verdict: NOT CERTIFIED — CHANGESET INTEGRATION BLOCKER.**
Nothing was staged, committed, deployed, or migrated.

## R9.1 Why the prescribed unit (Inventory 11 + Orders 27) is not shippable

The unit named in R7 was built from the *proven* dependency
(`ReserveOrderInventoryAction → ReserveStockAction → allow_negative_stock`). Building the exact
manifest showed that dependency is real but **not the only edge crossing the boundary**.

### Blocker 1 — the unit imports an untracked class from a third changeset (INDEPENDENTLY VERIFIED)

```
HandlePreparationWaveClosed.php:11  use Modules\Operations\DemandAnalysis\…\OrderPreparationCompletionReader;
HandlePreparationWaveClosed.php:58  private readonly OrderPreparationCompletionReader $completion,

git status → ?? backend/Modules/Operations/DemandAnalysis/…/OrderPreparationCompletionReader.php
```

The reader is **untracked**, in changeset (e). The listener cannot simply be dropped: the
manifest's `OrderServiceProvider` hard-imports it and registers
`$events->listen(WaveClosed::class, HandlePreparationWaveClosed::class)`, and `WaveClosed` is
already emitted at HEAD. Committing the unit without changeset (e) yields a container
`ReflectionException` on the first wave close.

Pulling the reader in does not close it either — its query needs
`wave_product_demand.preparation_completed_at`, and on the target:

```
information_schema count for ecos_erp.wave_product_demand.preparation_completed_at = 0
```

so it drags an untracked migration and ` M WaveProductDemand.php` as well. Changeset (e) is also
entangled back into Orders (`MaterialDemandCalculator` reads
`ReconcileOrderMaterialReservationsAction::REFERENCE_TYPE`). The two changesets are mutually
dependent — this is a multi-changeset release, not a certification unit.

### Blocker 2 — deleting `OrderStatus::NewOrder` has a 15-file cascade beyond the unit (VERIFIED)

`WooCommerceOrderStatusTranslator.php` is ` M` dirty and **outside** the manifest. Its own line 26
records the hazard: *"V3 repointed 'pending' at 'new'; ADR-042 removes 'new', which would…"*.
Shipping the enum removal without it breaks WooCommerce import for every `pending` order.
The same cascade reaches `WooCommerceOrderImporter.php`, ten dirty `frontend/src/features/orders/**`
paths, and the untracked `OrderLifecycleV3SupersessionTest.php` — the suite that certifies the
very `new → in_progress` normalisation this migration performs.

### Blocker 3 — excluded files that would compile but misbehave

- `ManufacturingAvailabilityService` (` M`, excluded): HEAD's version sums component stock with
  **no `company_id` filter**, so the §16.4 fail-closed recipe gate the unit relies on would not
  hold. Compiles; wrong answer.
- `CoverageResolutionService` (` M`, excluded): HEAD matches only English
  `master_governorates.name`, so `ReprocessLegacyReservationsCommand` — which is *in* the unit —
  would classify every Arabic-addressed order NOT_ELIGIBLE and repair nothing.
- `docs/adr/ADR-027` (` M`, +242 lines adding §16/§17) was classified UNCERTAIN. It is
  **REQUIRED**: unit code cites those sections by number, and `ReconcileOrderMaterialReservationsAction`
  is new behaviour whose only specification is the uncommitted §17.

## R9.2 Correction — one stop condition was overstated

The manifest analysis claimed `ecos_erp` has *unrelated pending migrations* (two Marketing
MappingEngine migrations) and that a bare `php artisan migrate` would apply three. **Verified
false.** They are unapplied, but ecos-app's migrator does not see them at all:

```
php artisan migrate:status | grep -c "Pending"  → 0
migrations rows LIKE '%mapping%'                → 2 (both other tables, both Ran)
```

They live in a module path not registered with that container's migrator, so a bare `migrate`
would not sweep them in. **The earlier "0 pending" finding stands**, and stop condition (ii) is
withdrawn. Blocker 1 alone is disqualifying regardless.

## R9.3 Manifests

**A. Inventory — 4 REQUIRED / 9 EXCLUDED** (area is now **13** dirty, not 11)

REQUIRED: `InventoryItems/Application/Actions/ReserveStockAction.php` ·
`InventoryItems/Domain/Models/InventoryItem.php` ·
`InventoryItems/Domain/Services/InventorySummaryService.php` ·
`InventoryItems/Domain/Enums/AvailabilityState.php`

EXCLUDED (each traceable to a different named task): the three `Products/**` paths + untracked
`ProductAvailability.php` (one indivisible T-1 group), two `ReceiptLayers/**` paths (P-7, SR-1),
`InboundPostingGuard.php` (P-7), and `GoodsInwardMode.php` + `GoodsInwardAuthority.php` (G-1,
which also needs its own untracked companies migration).

No Inventory migration is required: `products.allow_negative_stock` already exists at HEAD.

**B. Orders / Fulfillment — 26 REQUIRED / 1 EXCLUDED** of 27, plus one cross-area file
(`Operations/DemandAnalysis/…/DemandAnalysisService.php`, a one-line `NewOrder → Confirmed`) and
`docs/adr/ADR-042` (untracked, cited by the migration).

**C. `CustomerOrderMetricsService.php` — EXCLUDED, unrelated.** Nothing in the unit references it.
Preserved untouched in the working tree.

**D. Unrelated dirty paths — excluded.** Tree is now **401** dirty paths (was ~387), including 14
dirty migrations outside the unit.

**B2. Tests.** Eight would travel with the unit — including four *forced companions*
(`OrderReservationLifecycleTest`, `DemandAnalysisTest`, `Rc10LifecycleCertificationTest`,
`V3TransitionResolutionTest`) that reference `OrderStatus::NewOrder` at HEAD and would not
compile without the enum change. Four are **excluded** because they drag changeset (e) plus an
untracked `ActiveRecipeResolver` — including `MaterialAvailabilityContractTest`, which I updated
and verified green this session. That confirms the concern raised before the manifest ran: it
cannot be committed with only Inventory + Orders.

## R9.4 Live-tree hazard

The working tree **moved during inspection**: 387 → 401 dirty paths, and `Modules/Inventory`
11 → 13, as another session wrote the Purchasing G-1 changeset. Any manifest here is provisional;
`git status --porcelain -uall` must be re-read immediately before staging, and every new path
treated as unclassified.

## R9.5 Deployment / migration status

| Item | Result |
|---|---|
| Commit hash | **none — not committed** |
| Migration | `2026_08_13_100000_supersede_order_lifecycle_v3_canonical` — identified, **not applied** |
| Target container / DB | `ecos-app` / `ecos_erp` (project `ecos-erp`, working_dir `C:\ecos-develop`) |
| Target pending migrations | **0** — condition satisfied |
| ADR-042 data impact on target | **no-op** — 0 orders with `status='new'` |
| Target schema readiness | all 9 required columns + 3 required tables present |
| Deployed | **nothing** |

## R9.6 Verdict and the one decision that unblocks it

**NOT CERTIFIED — CHANGESET INTEGRATION BLOCKER.** All prior green results are preserved and
unregressed; no fix was reverted and no contract reopened.

The blocker is structural: the certification unit as scoped contains the ADR-042 FSM change,
whose blast radius (enum cascade, WooCommerce import, frontend, wave-close listener) reaches
three further changesets — one of which is mutually entangled with Orders.

**The recommended split, which is a scoping decision and not mine to take:** ship the ADR-027
§16/§17 negative-stock reservation chain **without** `OrderStatus.php` and its cascade. That
subset — `ReserveOrderInventoryAction`, `ReconcileOrderMaterialReservationsAction`,
`ReserveStockAction`, `InventoryItem`, `InventorySummaryService`, `AvailabilityState` and their
tests — contains **zero** references to `OrderStatus`, needs **no migration on `ecos_erp`**, and
carries the proven `allow_negative_stock` dependency that started this. `docs/adr/ADR-027` must be
promoted to REQUIRED in either path.

If instead the FSM must ship, it is a multi-changeset release requiring the WooCommerce pair, the
ten frontend paths, `OrderLifecycleV3SupersessionTest`, and all of changeset (e) — and should be
planned as a release, not certified as a unit.
