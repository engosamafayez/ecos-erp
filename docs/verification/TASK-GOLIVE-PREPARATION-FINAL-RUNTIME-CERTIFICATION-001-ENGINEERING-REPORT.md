# TASK-GOLIVE-PREPARATION-FINAL-RUNTIME-CERTIFICATION-001 — Engineering Report

**Date:** 2026-08-10
**Runner:** `ecos-dev-testrunner` · **Database:** `ecos_dev_test`
**Type:** Runtime certification of the remaining Preparation Backend scenarios.
**Verdict:** Section 32.

---

## 1 — Executive Summary

Certification **halted on STOP condition #2: the actual Picking path cannot be executed, because it does not
exist.**

Picking is not merely untested — it is **not implemented**. `preparation_pick_lists` and
`preparation_pick_list_items` rows are created once, at wave start, with `quantity_picked = 0` and status
`pending`, and **nothing in the codebase ever advances them**. Three independent investigations plus my own
verification agree, and the evidence is unambiguous:

* **No route anywhere contains `pick`** — a case-insensitive search of the whole `routes/` directory returns
  zero matches. There is no picking HTTP surface at all.
* The repository contains exactly **four** `*PickList*` files: two enums and two models. No controller, no
  action, no service, no job, no policy, no request, no resource.
* `quantity_picked` has exactly **one** production assignment in the entire backend:
  `StartPreparationAction.php:84 → 'quantity_picked' => 0`.
* `picked_at` and `picked_by` appear **only** in the migration and the model's `$fillable`/`casts` — they are
  never assigned by anything.
* `PickListItemStatus::InProgress`, `::Picked` and `::Short` have **zero references** repository-wide. The
  state machine was declared and never built. `2026_07_05_101200` even adds an index
  `idx_pick_list_items_status` for a status transition that never occurs.

Per Part 33, `Picking PASS` is mandatory for certification. It cannot be obtained, and Part 28 forbids
substituting wave-item completion as evidence — which resolves against `preparation_wave_items.id`, a
different table entirely.

**The certified baseline held throughout and was protected, not merely assumed:** Entry Gate **13/13 PASS**,
RC-10 **17/17 PASS**, PHPStan L0 + core L6 **0 errors**, **0 NEW failures**, MAIN untouched.

## 2 — Starting Commit

```
HEAD   : 6149875bd8a01820116b5deacbbfb8ef0e51cc05
branch : develop
total tracked diff : 8 files, +306/−27
F4 / Option B      : 3 files, +71/−18   (frozen — unchanged by this task)
```

Concurrency check (Part 1): **0 host PHP processes, 0 active connections to `ecos_dev_test`, no processes in
the runner.** No second agent was operating on the shared destructive database. This mattered — an earlier
session was corrupted by exactly that.

## 3 — Certified Baseline

F4, Option B, recipe availability, company isolation, cross-brand RM reuse, negative-stock policy, the
Preparation Entry Gate and `PreparationReleaseEngine`'s authority were **not reopened**. No new eligibility
engine was created. No production code was modified by this task at all.

## 4 — Runtime Environment (Part 2) — **PASS**

```
app.env = testing · config db = ecos_dev_test · SELECT DATABASE() = ecos_dev_test
db.host = mysql   · configurationIsCached() = false
reachable: ecos_dev, ecos_dev_test    ecos_erp / ecos_erp_test: NOT REACHABLE
```

Verified before every DB-backed batch. No `RefreshDatabase` ran before the target was proven.

## 5 — Entry Gate Regression (Part 23) — **PASS 13/13**

Re-run as regression protection, not re-certification:

```
✔ Default policy is a closed list of authorised statuses
✔ New order is eligible          ✔ In progress order is eligible
✔ Confirmed order is eligible    ✔ An eligible order is accepted even when not reserved
✔ Awaiting stock is refused even when reserved
✔ Ready for dispatch is refused even when reserved
✔ Out for delivery / Delivered / Cancelled refused even when reserved
✔ An order from another company is refused even when eligible by status
✔ Recalculate route enforces the same policy
✔ Duplicate preparation entry remains blocked
```

The Entry Gate certified in REPAIR-002 is intact.

## 6 — Allocation — **NOT EXECUTED**

Halted by the Part 29 STOP. The production path is fully traced and documented (10 verified routes,
`routes/api.php:882-920`: `POST /api/loading/sessions` → `open` → `assignments` → `start-loading` →
`load-product` → `complete-loading` → `start-allocation` → `complete-allocation`), with exact FormRequest
rules and written tables. **No PASS is claimed.** Route existence is explicitly not certification (Part 28).

## 7 — Allocation Priority — **NOT EXECUTED**

Same reason. No priority policy was invented.

## 8 — Partial Allocation — **NOT EXECUTED**

Same reason.

## 9 — Picking — **NOT IMPLEMENTED (blocking)**

### 9.1 — Creation exists

`POST /api/preparation/waves/{waveId}/start` (`routes/api.php:813`) → `PreparationWaveController::start` →
`StartPreparationAction::execute`:

```
StartPreparationAction.php:66   PreparationPickList::create([... status => PickListStatus::Pending ...])
StartPreparationAction.php:77   foreach ($wave->waveItems as $item) {
StartPreparationAction.php:84       PreparationPickListItem::create([... 'quantity_picked' => 0,
                                        'status' => PickListItemStatus::Pending ...]) }
```

One pick list per wave (unique index `uq_preparation_pick_lists_wave_id`), one item per wave item.

### 9.2 — Execution does not exist

| Artefact | Status |
| --- | --- |
| Any route containing `pick` | **NONE** — zero matches across `routes/` |
| `*PickList*` files in repo | **4** — `PickListStatus`, `PickListItemStatus`, and the 2 models |
| Writers of `quantity_picked` | **1** — the literal `0` at `StartPreparationAction.php:84` |
| Writers of `picked_at` / `picked_by` | **NONE** — migration + `$fillable` declarations only |
| `PickListItemStatus::Picked` / `::InProgress` / `::Short` | **0 references** repository-wide |
| Controller / action / service / job / policy / request / resource | **NONE EXIST** |

The pick list is a write-once, immediately-orphaned snapshot. `PreparationWaveResource:152-157` exposes only
a header (`id`, `status`, `items_count`, `generated_at`); item-level `quantity_to_pick` / `quantity_picked`
are never serialised to any API response.

### 9.3 — Why wave completion is not a substitute

`PATCH /api/preparation/waves/{waveId}/items/{itemId}/complete` resolves `{itemId}` against
**`preparation_wave_items.id`** (`PreparationWaveController.php:254-256`) and is handled by
`CompleteProductAction`, which contains no reference to `PreparationPickListItem`. It writes a different
table. Part 28 explicitly forbids using it as Picking evidence, and it would have been a false positive.

**A second divergence worth recording:** `POST /api/preparation/waves/{waveId}/advance` →
`WavePreparationService::startPreparation` moves a wave to Preparing **without creating any pick list at
all**. So depending on which start route an operator uses, a Preparing wave may have a pick list or none.

## 10 — Partial Picking — **NOT IMPLEMENTED**

Cannot exist when picking execution does not. Recorded as NOT IMPLEMENTED per Part 7, not faked.

## 11 — AwaitingStock Recovery — **NOT EXECUTED**

Halted by the STOP. Path traced (replenish via `POST /api/goods-receipts` + `/post`, then
`POST /api/fulfillment/orders/{order}/resume` or `/transition`) but not exercised. No recovery behaviour is
claimed or invented.

## 12 — AwaitingStock Negative Control — **PASS (via Entry Gate)**

Already runtime-proven and re-confirmed in this task's regression: an `awaiting_stock` order **carrying
`reservation_status = reserved`** is refused at the Preparation entry with HTTP 422 and zero mutation. This
is the Part 9 proof that *reservation ≠ Preparation eligibility*.

## 13 — Wave Cancellation — **NOT EXECUTED**

Halted by the STOP. `POST /api/preparation/waves/{waveId}/cancel` → `CancelWaveAction` exists as a full HTTP
path, but its effect on order restoration, reservations and allocations was not exercised. **UNVERIFIED.**

## 14 — Cancellation Idempotency — **NOT EXECUTED**

Same. No idempotency behaviour was invented.

## 15 — Loading — **NOT EXECUTED**

Halted by the STOP. 18 routes traced and verified. Earlier `DistributionModuleTest` (50/50) and
`DeliveryModuleTest` (35/35) results are **deliberately not re-claimed** — they were produced in the
previously contaminated shared environment.

## 16 — Loading Capacity — **NOT EXECUTED**

Same. No capacity rule invented.

## 17 — Inventory Consumption Boundary — **PASS (Preparation half)**

Re-proven in this task's Operations regression: preparation does not consume physical inventory —
`on_hand` unchanged, FIFO layers unchanged, `inventory_layer_consumptions = 0`.

Recorded behaviour: `StartPreparationAction:161` **soft-reserves** the wave's demand via
`SoftReservationService`, so `reserved_qty` legitimately rises at preparation start. That is a reservation,
not a consumption; `on_hand` and FIFO are the invariants that prove nothing physical moved.

The downstream consumption boundary (dispatch → `ShipOrderInventoryAction` → FIFO) is proven only within
RC-10's own lifecycle test, not from a prepared/loaded wave.

## 18 — Full Order → Shipment — **NOT EXECUTED**

Cannot be completed: the chain requires Picking, which has no execution path. Partial coverage that does
exist — Order → Reservation → Preparation → Wave → Demand → item completion → wave completion — was proven
in earlier tasks and re-confirmed here, but the chain **cannot** be closed end to end.

## 19 — Quantity Integrity — **PARTIAL**

Across the executed stages quantities stayed coherent: demand aggregation 3 + 2 = 5; `available = on_hand −
reserved` (10 − 6 = 4); partial preparation `6 / short 4 → 10 / short 0` with nothing lost or invented.
Allocated / picked / loaded quantities could not be compared because those stages were not executed.

## 20 — Shipment Boundary — **UNVERIFIED from Preparation**

RC-10 proves consumption occurs at dispatch (FIFO 10 → 8, `on_hand` 10 → 8), not at preparation. The
prepared → loaded → shipped junction remains unexercised.

## 21 — Company Isolation — **PASS (Preparation entry)**

Re-confirmed: a Company A actor submitting a Company B order — otherwise perfectly eligible by status — is
refused 422 with zero mutation, on both wave routes. Full cross-company flow through allocation/picking/
loading was **not executed**.

## 22 — Cross-Brand Reuse — **PASS (baseline, unchanged)**

Not reopened. No Brand-level predicate exists anywhere in the Preparation entry path; scoping is by
`company_id` only, per ADR-027 §16.5.

## 23 — Negative Stock — **PASS (baseline, unchanged)**

Not reopened, not duplicated inside Preparation. `ManufacturingAvailabilityService` remains the sole
authority.

## 24 — RC-10 (Part 22) — **PASS 17/17**

Including `test_insufficient_stock_diverts_to_awaiting_stock`. No F4 or Option B regression. No RC-10 test
modified or weakened.

## 25 — Phase 3 (Part 21) — **NOT EXECUTED**

Halted by the STOP. **Not** marked PASS. The environment did not block it; execution was stopped
deliberately, which is recorded rather than presented as an environmental limitation.

## 26 — Preparation Regression (Part 23) — **PASS with 4 known failures**

`tests/Feature/Operations` — 225 tests, 743 assertions, 3 failures + 1 error, 425.9 s. Entry Gate 13/13 and
RC-10 17/17 both green within it.

## 27 — PHPStan (Part 24) — **PASS**

Cold, cache cleared: level 0 (`Modules` + `app`) **[OK] No errors**; core level 6 **[OK] No errors**.
Exit 0, 233 s. Both ratcheted — no NEW violations.

## 28 — Guardian (Part 25) — **FAIL (Pint only, PRE-EXISTING proven)**

7/8 PASS, exit 1, 398 s. PHP Syntax · Laravel Bootstrap · PHPStan · ESLint · TypeScript · Vite Build all
PASS; Composer SKIP; **Pint FAIL**.

Pint names only `ProductPopulationScopeTest.php` (`ordered_imports`) and `V3TransitionResolutionTest.php`
(`binary_operator_spaces`), attributed by push range `f0d7822a...HEAD` to commit `6149875b` itself. Neither
is in the working-tree diff; neither was modified. `--no-verify` was not used; nothing was suppressed.

**Scoped Pint on uncommitted work (Part 25 requirement).** Guardian's Pint derives its file list from the git
push range and therefore does **not** cover uncommitted changes. Running it explicitly over all 10
uncommitted PHP files found one violation — in `EloquentProductRepository.php`, an **F4 file**:

```
fixers: concat_space, unary_operator_spaces, not_operator_with_successor_space
```

**Control run: the HEAD version of that file fails with the identical fixers**, so F4 did not introduce it —
the file was already Pint-dirty at `6149875b` and sits inside Guardian's 628-file ratchet baseline as
accepted debt. Classified **PRE-EXISTING**. Part 34 forbids reopening F4, so it was **not** fixed. All files
this task touched (none — no code was changed) and all files from prior tasks in this session pass.

## 29 — Failure Classification (Part 26)

| Failure | Classification | Basis |
| --- | --- | --- |
| `BranchAssignmentEngine` (one test per run) | **PRE-EXISTING** | Controlled experiment in REPAIR-002: reverting the policy to HEAD reproduces it. It also **moved between tests across runs** (`nearest_branch_selected` → `single_branch_covering_area`), which is non-determinism, not a regression |
| `MaterialDemandCalculator::missing_qty_uses_available_not_on_hand` | **PRE-EXISTING** | HEAD control reproduced identical message/line/values |
| `OrderExclusivity::db_unique_constraint…` (SQL 1364 `order_confirmed_at`) | **PRE-EXISTING** | HEAD control reproduced identical SQL error |
| `TransferEvents::scenario_d_adr_026_document_exists` | **ENVIRONMENT** | The ADR exists in the worktree and this test passed in Batch A (host run); the file is absent from the runner image — container packaging gap |
| `EloquentProductRepository` Pint | **PRE-EXISTING** | HEAD control fails with identical fixers |

**NEW failures: 0.**

## 30 — Remaining Gaps

1. **Picking is not implemented** — §9. Blocking. Requires product/engineering work, not a test.
2. **Allocation, AwaitingStock Recovery, Loading, Wave Cancellation, Full Order → Shipment, Phase 3** — NOT
   EXECUTED, halted by the STOP. All production paths are traced and documented so a follow-up need not
   rediscover them.
3. **Two divergent wave-start routes** — `/start` creates a pick list, `/advance` does not. Worth reconciling
   regardless of the Picking decision.
4. **Docs are not packaged into the runner image**, so any test asserting a project-level document will keep
   failing there.

## 31 — Certification Matrix

| Scenario | Result | Evidence | Status |
| --- | --- | --- | --- |
| Entry Gate | **PASS** | 13/13, real HTTP, zero mutation on refusal | certified |
| Allocation | **NOT EXECUTED** | 10 routes traced; not exercised | halted by STOP |
| **Picking** | **NOT IMPLEMENTED** | no route, 4 files, 1 writer (`=> 0`), 3 dead enum cases | **blocking** |
| AwaitingStock Recovery | **NOT EXECUTED** | path traced; not exercised | halted by STOP |
| Wave Cancellation | **NOT EXECUTED** | route exists; effects unexercised | UNVERIFIED |
| Loading | **NOT EXECUTED** | 18 routes traced; not exercised | halted by STOP |
| Full Order → Shipment | **NOT EXECUTED** | chain requires Picking | blocked by §9 |
| Company Isolation | **PASS** (entry) | cross-company refused 422, zero mutation | partial scope |
| Inventory Boundary | **PASS** (Preparation) | `on_hand` + FIFO unchanged; 0 consumptions | certified |
| RC-10 | **PASS 17/17** | full lifecycle + awaiting-stock diversion | certified |
| Phase 3 | **NOT EXECUTED** | halted by STOP | not claimed |
| PHPStan | **PASS** | L0 + core L6, cold, 0 errors | certified |
| Guardian | **FAIL** | 7/8; Pint pre-existing, proven by control | pre-existing |

## 32 — Final Verdict

Part 33 permits certification only when Allocation, Picking, AwaitingStock Recovery, Wave Cancellation,
Loading, Full Order → Shipment and Phase 3 all PASS. One of those is **not implemented in the product**, and
six were not executed because the STOP condition fired. Part 33 also states plainly: *do not downgrade
missing evidence to PASS.*

# PREPARATION BACKEND = NOT CERTIFIED

**What is not to blame:** the certified baseline. Entry Gate 13/13, RC-10 17/17, PHPStan clean, zero NEW
failures, F4 and Option B frozen and unregressed. The Preparation mechanics that exist — eligibility,
demand, availability arithmetic, non-consumption, partial preparation — all hold.

**What blocks certification:** the Preparation → Shipment chain has a **missing link, not a broken one**.
Picking was designed (tables, enums, models, an index for its status transitions) and never built. No amount
of testing can certify a workflow that has no execution path; this needs an implementation decision.

## 33 — Attestations

* **No production code was modified by this task.** Total tracked diff unchanged at 8 files, +306/−27.
* F4, Option B, recipe availability, cross-brand reuse, negative-stock policy, Entry Gate policy and
  `PreparationReleaseEngine`'s authority were **not reopened** — F4/Option B frozen at 3 files, +71/−18.
* No second eligibility engine, no synthetic shortcut, no direct status writes, no fake UUIDs used to claim
  an application path (Part 27).
* Wave completion was **not** accepted as Picking evidence; route existence was **not** accepted as
  certification (Part 28).
* All DB-backed execution ran in `ecos-dev-testrunner` against `ecos_dev_test`. Never `ecos-dev-app`.
* **MAIN untouched** — `ecos_erp` 551 tables / 2 orders, `ecos_erp_test` 550 tables, containers and images
  unchanged, `C:\Projects\ECOS-ERP` clean.
* No STOP condition was worked around. **Nothing committed.**
