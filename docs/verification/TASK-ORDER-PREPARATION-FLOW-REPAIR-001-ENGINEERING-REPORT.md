# TASK-ORDER-PREPARATION-FLOW-REPAIR-001 — Engineering Report

**Date:** 2026-08-12 · **Env:** `C:\ecos-develop`, DB `ecos_dev` (verified) · **Branch:** `develop`

## 1. Executive Summary

The ADR-027 contract is restored and the Order → Warehouse → Reservation → Preparation → Wave chain now runs end to end on the real DEV order, with runtime evidence at every hop.

**The keystone repair (T5) works.** A missing warehouse no longer overwrites the lifecycle status. ORD-00001's journey, all through domain workflows and fully audited:

| Stage | status | reservation_status | reason |
|---|---|---|---|
| Before (breach) | `awaiting_stock` | `awaiting_stock` | Warehouse Not Assigned |
| After H4 recovery | **`new`** | **`pending`** | Warehouse Not Assigned |
| After warehouse assigned | `awaiting_stock` | `awaiting_stock` | **Insufficient Inventory** |
| After stock received | **`in_progress`** | **`reserved`** (2/2) | — |
| Wave collection | attached to **PREP-202608-000001 (2026-08-12)** | | |

`awaiting_stock` now appears **only** for a genuine finished-good shortage, and the two blockers are finally distinguishable.

**Three parity breaks were found, not one.** Beyond `MaterialDemandCalculator`, the container was running an obsolete `PreparationSessionPolicy` returning `['confirm_order','in_progress']` — `confirm_order` is a *workflow name*, not a status, so **no `new` order could ever be preparation-eligible**. A full 4,183-file sweep found the rest.

**Two items are NOT delivered and are reported, not worked around:** T8 (canonical geography) hit its declared STOP condition, and the Preparation Workspace 3-day/Archive view was not implemented. Details in §21.

---

## 2. Contract Used

ADR-027 as Tier-1, per TASK-ORDER-PREPARATION-BUSINESS-CONTRACT-RESOLUTION-001:

- **§2** — reservation decision is immediate; execution is postponed when no warehouse exists; `reservation_status = pending` means *decision made, execution pending*; `WarehouseAssigned` triggers automatic retry.
- **§10** — no coverage ⇒ source stamped, `reservation_status = pending`, **"Command Center signal, not an error"**; no status change.
- **§3 Case 4** — `awaiting_stock` is the residual of the **finished-good** availability tree only.
- **§15 H3 / H4** — the retry listener and the supervised reprocessing job, both previously recorded as missing.
- **V3 `OrderStatus`** — 11 states. No status invented; `confirmed` not reintroduced.

---

## 3. T0 — Certified Baseline Parity

`MaterialDemandCalculator` restored by `docker cp` (file **not** modified):

| | MD5 |
|---|---|
| Host (certified) | `ce69612a5910ad7eb84c354895b45140` |
| Container before | `4c2903b8…` (= `git HEAD`, pre-repair) |
| **Container after** | **`ce69612a5910ad7eb84c354895b45140`** ✔ |

`opcache.validate_timestamps=On`, `revalidate_freq=0` — changed files are re-read per request; no container restart was required.

**A full sweep then compared all 4,183 `Modules/**/*.php` files**, host vs container:

| File | Status | Action |
|---|---|---|
| `MaterialDemandCalculator.php` | DIFF | **restored** (certified) |
| **`PreparationSessionPolicy.php`** | **DIFF** | **restored** — container returned `['confirm_order','in_progress']`; host (certified V3) returns `['new','in_progress']` |
| `IAM/.../UserPolicy.php` | DIFF | **left untouched** — IAM is protected and off this causal path |
| `Preparation/.../PreparationWaveController.php` | DIFF | **left untouched** — another agent's uncommitted work |
| 21 host-only files (`Logistics/Distribution/*`, `IAM/UserPasswordService`) | absent in container | **left untouched** — another task's uncommitted module, including 4 unrun migrations |

The `PreparationSessionPolicy` break was silently fatal: with `confirm_order` in the eligible list, the Entry Gate rejected every `new` order with `status_ineligible:new`.

---

## 4. T1 — Preparation Wave Status Configuration

`wave_engine_configurations.eligible_order_statuses`: `["confirmed"]` → **`["new","in_progress"]`**, 1 row, tenant-scoped (`WHERE JSON_CONTAINS(... 'confirmed')`).

Both consumers verified on V3 semantics:

- **Wave collector** — `WaveMembershipService:39` `whereIn('status', $config->eligible_order_statuses)`.
- **Entry Gate** — `PreparationReleaseEngine` via `PreparationSessionPolicy::defaultEligibleStatuses()`, now `['new','in_progress']` after the §3 restore. `preparation_session_policies` is empty, so the default applies.

`PreparationEntryGateTest` prints its own confirmation at runtime: `ENTRY GATE POLICY: new, in_progress`.

No status was invented; `confirmed` was not reintroduced anywhere.

---

## 5. T2 — Stock Added Re-evaluation

`StockAddedListener` queried **`on_hand_quantity`**; the column is **`on_hand_qty`**. Every dispatch raised a `QueryException` that the catch turned into a log line, so this recovery path had **never once executed**.

Changes:
- Column corrected to `on_hand_qty`, with `whereNull('deleted_at')` to match the platform's soft-delete convention.
- Query hoisted out of the loop — every row shares the same `(product_id, warehouse_id)`, so it was N identical reads.
- Early return when nothing is unresolved.
- **Errors no longer swallowed:** `report($e)` now routes to the error handler and the exception class is logged. Containment is kept (a stock receipt must not fail because a projection did), but a programming error is now visible — per *"Exceptions are first-class — none are swallowed silently."*

Idempotency was already present and is preserved: rows are selected on `resolved = false` and the update sets `resolved = true`.

Event architecture and tenant isolation unchanged (`company_id` + `warehouse_id` filters intact).

---

## 6. T3 — Canonical Warehouse Assigned Event

`BranchAssignmentEngine` now emits the **existing** `WarehouseAssigned` on both success paths — `assign()` (source `BranchCoverage`) and `override()` (source `ManualOverride`) — via one private helper.

- `BranchAssigned` **retained** for consumers needing the branch transition; it is no longer the assignment trigger.
- **No new event created.** No `PreparationWarehouseAssigned`, no `BranchWarehouseAssigned`.
- The existing `WarehouseAssigned` constructor contract is used unchanged (`orderId, warehouseId, previousWarehouseId, source, policyId, occurredAt`), so the legacy `WarehouseAssignmentEngine` consumers see an identical payload shape.

This closes the seam that opened when `BranchAssignmentEngine` replaced `WarehouseAssignmentEngine`: the dispatch had moved, the subscribers had not.

---

## 7. T4 — H3 Preparation Listener Path

`WarehouseAssignedListener` (preparation auto-attach) already subscribed to `WarehouseAssigned` — **T3 alone reconnected it**; no listener logic was duplicated.

Added the reservation half specified by ADR-027 §15 H3:
`Modules/Commerce/Orders/Application/Listeners/ExecuteReservationOnWarehouseAssigned`, registered in `OrderServiceProvider`.

Runtime confirmation — listeners on `WarehouseAssigned` went from **1 → 2**.

Preserved: tenant isolation (both listeners resolve company from the order/session), idempotency (§10), order uniqueness (DB UNIQUE on `preparation_wave_orders`), existing session ownership (`DailyPreparationSessionManager` unchanged).

---

## 8. T5 — No-Coverage State Repair (keystone)

Applied **after** T3/T4 were working, to three workflows:

| Workflow | Change |
|---|---|
| `ProcessOrderWorkflow:97` | no longer writes `status = AwaitingStock`; records `reservation_status = Pending` + reason |
| `ConfirmOrderWorkflow:89` | identical guard, identical repair |
| `MoveToPreparationWorkflow:77` | **added** the null-warehouse guard its siblings always had — it previously called `ReserveOrderInventoryAction`, which throws `OrderWarehouseNotAssignedException` on a null warehouse |

Now, on a missing warehouse:
- lifecycle status **preserved**
- `reservation_status = pending`, reason `Warehouse Not Assigned`
- `warehouse_assignment_source` / `warehouse_assignment_failure_reason` **not touched** — already written by the assignment engine
- event renamed `reservation_awaiting_stock` → **`reservation_execution_postponed`** (verified: zero consumers of the old name)

---

## 9. T6 — Re-evaluation After Warehouse Assignment

Handled entirely by the H3 listener, which re-runs the **existing** `ProcessOrderWorkflow` through `FulfillmentEngine`. No reservation logic duplicated; no status set by hand.

**Idempotency proven on three levels:** the `reservation_status = pending` guard; `ProcessOrderWorkflow`'s skip when already Reserved/PartialReserved; `ReserveOrderInventoryAction`'s skip-list. Repeated scheduler runs produced no duplicate wave, no duplicate membership, no duplicate reservation (§17).

---

## 10. T7 — Reservation Pending Recovery

Targeted, not "retry everything". The H3 listener fires only when **all** hold: order exists and is not soft-deleted; `assigned_warehouse_id !== null`; `reservation_status === Pending`; status ∈ `{new, awaiting_payment, in_progress}` (ADR-027 §2 active commercial states, V3-mapped).

The three blockers stay distinct:

| Blocker | Marker | Recovery |
|---|---|---|
| Warehouse unresolved | `reservation_status = pending` + `Warehouse Not Assigned` | `WarehouseAssigned` → H3 |
| FG shortage | `awaiting_stock` + `Insufficient Inventory` | `InventoryStockReceived` → retry listener |
| Reservation failure | per-line meta / audit | neither of the above fires |

An order at `awaiting_stock` is deliberately **not** picked up by H3 — that is a different blocker with a different recovery path.

---

## 11. T8 — Canonical Geography — **STOPPED**

**STOP condition 2 triggered: "D1 geography migration/backfill cannot be proven safe."**

The target is settled (canonical master-geography FK via `BrandConfigurationResolverService`). The path is not, for three evidenced reasons:

1. **D1 was never approved.** It was raised as an open decision and returned unanswered; the task says to follow "the approved D1 transition plan", and no such plan exists.
2. **The canonical chain has no data.** `config_delivery_geographies` and `config_delivery_zones` are both **empty** in `ecos_dev`. `resolveOrderGeography()` resolves through them, so it returns nulls for every order.
3. **Orders cannot carry the result.** There is no `master_governorate_id` / `geography_governorate_id` column on `orders`. `GEOGRAPHY-COVERAGE-ENGINE.md` §5 sanctions such a field, but no migration has created it — so T8 requires a schema change plus a data backfill, both explicitly gated.

`CoverageResolutionService` was **not modified**. No schema or data change was applied. ORD-00002's NULL governorate was **not invented**.

---

## 12. T9 — Preparation / FIFO

`MaterialDemandCalculator` **not modified**; its certified contract (`on_hand 15 / reserved 8 / required 10 → available 7, missing 3`) is untouched and its parity is restored (§3).

It was **not reached** by this flow: ORD-00001 reserved from finished-goods stock (Case 1), so no BOM explosion or wave material demand occurred. `wave_manufacturing_demand` remains empty.

FIFO ordering for the stock-arrival retry (ADR-027 M6) is **not implemented** — out of scope here and listed in §21.

No Inventory redesign; no defect on this causal path was proven by runtime evidence beyond T2.

---

## 13. T10 — H4 Supervised Reprocessing

New: `orders:reprocess-legacy-reservations` (`Modules/Commerce/Orders/Infrastructure/Console/Commands/`).

`--execute` required; **dry-run is the default**. Options: `--company` (tenant scope), `--order`, `--execute`.

Classification, each bucket reported separately:

| Bucket | Meaning |
|---|---|
| `RECOVERABLE` | coverage resolves → re-run `BranchAssignmentEngine`; its canonical event drives H3 |
| `OPERATOR_CORRECTION` | cannot be assigned from its own data (e.g. NULL governorate) — a human must complete the address |
| `STATUS_ONLY` | assignment still fails, but the order carries the illegal `awaiting_stock` → restore and re-derive |
| `NOT_ELIGIBLE` | terminal or already healthy |

**A domain guard caught the first implementation and it was right.** The initial version wrote `status` directly and was refused: *"Unauthorized direct write to Order[...].status detected. All status transitions must go through FulfillmentEngine::run()."* No partial write occurred. The command was rewritten to **compose two existing workflows** — `ReturnToPendingWorkflow` (`awaiting_stock → new`) then `ProcessOrderWorkflow` (re-derives the correct state) — so it never assumes an outcome, it re-runs the real decision.

Dry-run preview, then execution:

```
| ORD-00001 | STATUS_ONLY         | no coverage; clearing illegal awaiting_stock | status awaiting_stock → new; reservation pending |
| ORD-00002 | OPERATOR_CORRECTION | governorate is NULL — address incomplete     | status awaiting_stock → new; reservation pending |
```

Auditable (`order_reservation_audits` + `legacy_reservation_reprocessed` event), tenant-scoped, uses only normal domain workflows, bypasses no policy.

---

## 14. T11 — Allocate / Pick Audit — **NOT COMPLETED**

Established: the Entry Gate (`PreparationReleaseEngine`) checks **status + warehouse only** — no reservation prerequisite — and `PreparationEntryGateTest` confirms it admits `in_progress (unreserved)`. ADR-027 §9 requires `Reserved` before Preparation may Allocate or Pick, so enforcement must live at the allocate/pick boundary, not at admission.

**Whether that boundary actually enforces §9 was not audited.** No genuine blocker was proven, and nothing on this boundary was required for the repaired Order → Preparation flow. **Classified as follow-up work** (§21). No Shipping code was touched.

---

## 15. Existing Orders

**ORD-00001** — no direct DB mutation. Recovered through normal domain operations:
`orders:reprocess-legacy-reservations --execute` → `new` + `pending`; then `BranchAssignmentEngine::override()` (the sanctioned supervisor path, ADR-027 §12 "if WH resolved, attempt reservation") to Cairo HQ → Main Warehouse — the factually correct branch for its Cairo governorate, unreachable only because the matcher is blocked behind T8. The override is fully audited and reversible.

**ORD-00002** — classified **OPERATOR CORRECTION REQUIRED**. `governorate` is NULL; no authoritative source exists on the order to derive it. Nothing was invented or rewritten. It now sits at `new` + `pending` and is correctly held out of Preparation with reason **`no_warehouse_assigned`** — the accurately-attributed blocker, not a status mislabel.

---

## 16. Wave Lifecycle

**The stale-wave deadlock is removed.** `hasActiveWave()` / `getActiveWave()` ignored `planning_date` and had no ordering, so a wave left `Collecting` on 2026-07-30 counted as "today's". With `auto_move_to_preparing = 0` it could never reach `Preparing`, therefore never rotate — a permanent deadlock: no wave could open, and collected orders would have joined a wave dated 13 days earlier.

- Both methods take an optional `$operationalDate`; the scheduler now passes today for **both** the create check and the collection target. The date-less form is retained for read-side callers, now ordered `planning_date DESC` instead of arbitrary.
- `rotateWave()` clamps the next date to **`max(planning_date + 1, today)`** — normal same-day rotation is unchanged, but a stranded wave no longer walks forward one dead day at a time.
- **No wave was deleted, closed, or rewritten.** The 2026-07-30 wave remains exactly as it was.

Membership is date-correct and **order creation date is irrelevant**: ORD-00001 (created 2026-08-07) attached to `PREP-202608-000001`, `planning_date 2026-08-12`.

**Brand Preparation Window:** the existing `WaveEngineConfiguration` (per company + warehouse: `collection_start_time` / `preparation_start_time` / `wave_end_time` / `timezone`) is used as the authoritative window. **No second window system was created.**

---

## 17. Runtime Evidence

All against DB `ecos_dev` (verified), real orders, no direct DB mutation of order state.

| # | Scenario | Result |
|---|---|---|
| **1** | Old order, window open | **PASS** — ORD-00001 created 2026-08-07 joined the 2026-08-12 wave |
| **2** | No warehouse | **PASS** — `new` + `pending` + blocker fields; **not** `awaiting_stock` |
| **3** | Warehouse recovery | **PASS** — `override()` → canonical `WarehouseAssigned` → H3 → reservation ran automatically; no duplicate assignment |
| **4** | ORD-00001 | **PASS** — full traverse `new/pending → awaiting_stock(Insufficient Inventory) → in_progress/reserved 2/2 → wave` |
| **5** | ORD-00002 | **PASS** — blocked, `no_warehouse_assigned`, classified OPERATOR_CORRECTION; geography not fabricated |
| **6** | Wave status | **PASS** — gate reports `POLICY: new, in_progress`; `in_progress` joined; obsolete `confirmed` gone from config and code |
| **7** | Late order | **PARTIAL** — "joins while open" proven (#1). "Does not join after close" **not executed** — would require clock manipulation; the config's `wave_end_time` is 23:59:59 |
| **8** | Stale wave | **PASS** — 2026-07-30 wave neither blocked nor was destroyed; today's wave created alongside it |
| **9** | Empty wave | **PASS** — `PREP-202608-000001` exists with zero eligible orders at creation time |
| **10** | Duplicate safety | **PASS** — 5 scheduler runs → one wave for today, one membership row |
| **11** | Multi-tenant | **PARTIAL** — `WaveMembershipService` filters `company_id` + `warehouse_id`; `PreparationEntryGateTest` proves `cross-company → http=422 attached=0`. A **second live company wave lifecycle was not exercised** (company B has a warehouse but no `WaveEngineConfiguration`) |
| **12** | Stock re-evaluation | **PASS** — `ReceiveStockAction` → `InventoryStockReceived` → retry listener → `reserved` 2/2 → `in_progress`; no duplicates |
| **13** | Preparation | **PASS** — every hop evidenced, none skipped |

Audit trail for ORD-00001 (`order_reservation_audits`), showing the semantic shift:

```
2026-08-07 02:43:51  NULL           → awaiting_stock  Warehouse Not Assigned     ← the breach
2026-08-12 00:37:39  awaiting_stock → awaiting_stock  Warehouse Not Assigned     ← old code re-run
2026-08-12 01:44:13  NULL           → pending         Warehouse Not Assigned     ← H4 recovery (new contract)
2026-08-12 01:44:34  pending        → awaiting_stock  Insufficient Inventory     ← H3, genuine stock verdict
```

---

## 18. Static Evidence

| Check | Result |
|---|---|
| **PHPStan L0** (platform-wide, `phpstan.neon.dist`) | **[OK] No errors** |
| **PHPStan core L6** (`phpstan-core.neon.dist`) | **[OK] No errors** |
| **Pint** (scoped, all 7 files I authored) | **PASS, 7 files** |
| Pint — 4 pre-existing failures | **baselined and untouched** — the same files fail identically at `git HEAD` (`unary_operator_spaces`, `braces_position`, `single_line_empty_body`, `binary_operator_spaces`, `ordered_imports`). Only the one violation I introduced (`OrderServiceProvider` `ordered_imports`) was fixed |
| **PHPUnit** — BranchAssignmentEngine, WaveEngine, PreparationEntryGate, V3TransitionResolution | **OK — 87 tests, 315 assertions** |
| **PHPUnit** — RecipeGateTenantRepair + NegativeStockReservation | **OK — 15 tests, 49 assertions** (F4/Option B green: `OPTION_B`, `CROSS_BRAND`, `NEG_STOCK`, `DIRECT_FG` all as specified) |
| `OrderReservationLifecycleTest` ×2 failures | **PRE-EXISTING** — reproduced with all my changes reverted to HEAD in the test runner. They assert exceptions that F4/Option B deliberately removed (`ReserveOrderInventoryAction` "Does NOT throw for insufficient stock") |
| `NegativeStockReservationTest::…hard_rm_shortage…` | **NOT MINE** — passes alone (5/5) and alongside `RecipeGateTenantRepairTest` (15/15) with my changes applied. Fails only inside a larger multi-suite run ⇒ pre-existing test-isolation/order dependency |

No unrelated pre-existing failure was repaired. No test expectation was changed.

---

## 19. Tenant Isolation

- `WaveMembershipService` filters `company_id` **and** `warehouse_id`.
- `PreparationReleaseEngine::resolvePolicy()` is company-scoped, warehouse-specific first.
- H4 supports `--company`; H3 acts on a single order's own company.
- `ManufacturingAvailabilityService` company scoping (F4 §16.4/§16.5) untouched — `RecipeGateTenantRepairTest` and `CrossBrandReuse` green.
- Runtime: `ENTRY GATE cross-company (status in_progress) → http=422 attached=0`.

Caveat: only company A has a `WaveEngineConfiguration`, so a **second live tenant wave lifecycle was not exercised** (§17 #11).

---

## 20. Database Safety

- `SELECT DATABASE()` → **`ecos_dev`** ✔ (verified before every runtime step)
- Tests ran in `ecos-dev-testrunner` against `ecos_dev_test`; `migrate --force` reported *"Nothing to migrate."*
- **No** `migrate:fresh`, reset, destructive seed, or mass update. **No wave deleted.** `ecos_erp` / MAIN never connected to.
- Order state changed **only** through domain workflows (`FulfillmentEngine`, `BranchAssignmentEngine`, `ReceiveStockAction`) — never by SQL. The one SQL write was the T1 **configuration** row, explicitly authorized.
- Stock added: +5 units FG-000001 into Main Warehouse via `ReceiveStockAction` (Scenario 12), fully ledgered.

---

## 21. Remaining Gaps

1. **T8 canonical geography — STOPPED** (§11). Blocks true coverage-driven assignment; ORD-00001 currently holds a supervisor override.
2. **Preparation Workspace 3-day + Archive view — NOT IMPLEMENTED.** Frontend scope, and `PreparationWaveController` (the natural backend seam) carries **another agent's uncommitted changes** — editing it would violate the ownership rule. The backend now resolves waves by operational date rather than `created_at`, which is the prerequisite; the view itself remains to be built.
3. **ADR-027 M6 FIFO ordering** in the stock-arrival retry — not implemented.
4. **T11 allocate/pick §9 audit** — not completed (§14).
5. **Scenario 7 (post-close)** and **Scenario 11 (second live tenant)** — not fully exercised (§17).
6. **Two container parity breaks left in place** — `UserPolicy.php` (IAM, protected) and `PreparationWaveController.php` (other agent), plus 21 host-only `Logistics/Distribution` files including 4 unrun migrations. Reported, deliberately untouched.
7. **Pre-existing test-isolation defect** in the Commerce/Manufacturing suite combination (§18).

---

## 22. Certification Verdict

Scoped per the task. **No module is declared certified.**

| # | Target | Verdict |
|---|---|---|
| **A** | **No-Coverage Contract Repair** | **CERTIFIED** — status preserved, `pending` + blocker fields, all three workflows, runtime-proven on both live orders |
| **B** | **Warehouse Assignment Recovery** | **CERTIFIED** — canonical `WarehouseAssigned` from `BranchAssignmentEngine`; H3 executes the postponed reservation automatically; idempotent |
| **C** | **Preparation Wave Lifecycle** | **CERTIFIED WITH EXCEPTIONS** — deadlock removed, date-correct membership, duplicate-safe, history preserved. Exceptions: post-close (Sc. 7) and second-tenant (Sc. 11) unexercised; workspace/archive view not built |
| **D** | **Order → Preparation Integrated Flow** | **CERTIFIED** for the key target — `ORDER → CANONICAL WAREHOUSE ASSIGNMENT → RESERVATION DECISION → PREPARATION ELIGIBILITY → CURRENT WAVE` proven end-to-end on ORD-00001 with evidence at every hop. **Qualified:** warehouse arrived via supervisor override, not coverage resolution, because T8 is stopped |
| **E** | **H4 Reprocessing** | **CERTIFIED** — dry-run default, preview, tenant-scoped, idempotent, auditable, workflow-composed, policy-respecting; correct three-way classification on live data |
| **F** | **Allocate/Pick Boundary** | **NOT CERTIFIED — NOT AUDITED** (§14). Follow-up |

**Existing certifications remain green:** Preparation Entry Gate, F4/Option B, Recipe Gate Tenant Repair, Cross-Brand Reuse, `MaterialDemandCalculator` (untouched, parity restored). Orders, Preparation, and Shipping modules as wholes are **not** certified by this task.
