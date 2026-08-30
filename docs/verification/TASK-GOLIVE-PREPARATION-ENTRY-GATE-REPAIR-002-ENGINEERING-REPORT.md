# TASK-GOLIVE-PREPARATION-ENTRY-GATE-REPAIR-002 — Engineering Report

**Date:** 2026-08-10
**Runner:** `ecos-dev-testrunner` · **Database:** `ecos_dev_test`
**Type:** Targeted repair + runtime certification of the Preparation Entry Gate.
**Verdict:** Section 25.

---

## 1 — Executive Summary

The Preparation entry gate is repaired and runtime-certified. **Entry-gate matrix: 13/13 PASS.**
**RC-10: 17/17 PASS.** **PHPStan L0 + core L6: 0 errors.** No production business logic outside the two
Preparation files was touched; F4 and Option B are byte-identical to their certified state.

Three things are worth stating plainly, because two of them correct earlier claims of mine.

**1. The premise of REPAIR-001 was false, and I proved it rather than building on it.**
REPAIR-001 named `RecalculateWaveAction` as the authoritative guard that "refuses the invalid Order".
It contains no such guard — only a wave-status check and the same wave-exclusivity check
`guardOrdersReservable()` already performed. My earlier Batch B report claimed recalculate "correctly
rejects" the blocked order. **That was wrong.** The probe had sent `order_ids`; the endpoint reads
`add_order_ids`, so validation silently dropped the payload and nothing was ever submitted. Re-run with the
correct field, recalculate **also attached** the awaiting-stock order:

```
BYPASS PROBE (recalculate): http=200 attached_blocked_rows=1
```

All three wave paths were unguarded, not two. There was never a "correct behaviour already in the codebase"
at that location.

**2. The real authority already existed — the wave routes were the only paths bypassing it.**
`PreparationReleaseEngine` documents itself as *"The ONLY authority that decides whether an order may enter
(or remain in) a Preparation Session. All attachment decisions must pass through here."*
`OrderPreparationObserver`, `DailyPreparationSessionManager` and `WarehouseAssignedListener` all already
delegate to it. `PreparationWaveController` never called it. So the repair reuses the existing contract; no
second engine was created.

**3. `confirm` needed no invented status — the evidence resolved it.**
The rule requires `[new, in_progress, confirm]`, but the V3 `OrderStatus` enum has no `confirm` case.
`confirm_order` → `confirmed` (2026_07_13_000001:22) → **merged into `in_progress`** by
2026_07_22_100000_simplify_order_lifecycle_v3.php:30 (*"confirmed → in_progress (merged: was a separate
confirmation step)"*). In V3, Confirmed **is** In Progress; confirmation is carried by `orders.confirmed_at`.
The policy is therefore `[new, in_progress]` — the third state is not dropped, it is the second one.
No enum case was invented; no rule was reduced.

## 2 — Starting Commit

```
HEAD   : 6149875bd8a01820116b5deacbbfb8ef0e51cc05
branch : develop
```

Pre-flight (Part 21), before any DB-backed work:

```
app.env = testing · config db = ecos_dev_test · SELECT DATABASE() = ecos_dev_test
db.host = mysql · configurationIsCached() = false
reachable: ecos_dev, ecos_dev_test    ecos_erp / ecos_erp_test: NOT REACHABLE
```

## 3 — Existing Preparation Flow

`PreparationReleaseEngine::ineligibilityReason()` answers two questions, policy-driven:
`status_ineligible:<status>` (from `eligible_order_statuses`) and `no_warehouse_assigned`. Its policy is
resolved per company + warehouse via `resolvePolicy()`. Reservation state is deliberately not consulted —
consistent with the rule that eligibility is a function of order status.

## 4 — Entry Point Inventory (Part 1)

| Entry point | Status check before | Company scope before | Uses the authority? |
| --- | --- | --- | --- |
| `PreparationWaveController::store()` → `CreateWaveAction` | **NONE** | **NONE** | **NO → repaired** |
| `PreparationWaveController::recalculate()` → `RecalculateWaveAction` | **NONE** | **NONE** | **NO → repaired** |
| `guardOrdersReservable()` | wave membership only | none | **NO → repaired** |
| `OrderPreparationObserver` | via engine | session-scoped | **YES — already correct** |
| `DailyPreparationSessionManager` | via engine | company-scoped | **YES — already correct** |
| `WarehouseAssignedListener` | via engine | company-scoped | **YES — already correct** |
| `MoveToPreparationWorkflow` | guards `InProgress` | order-scoped | consistent (in_progress is eligible) |
| `PrepareOrderAction` | guards `ReadyForDispatch` | — | not an entry: it is the *exit* transition |
| `PreparationSessionPolicy` | data model, not a route | — | policy holder, not an entry point |

Only the two wave routes bypassed the authority. That is the whole defect.

## 5 — Authoritative Status Policy (Parts 2, 3)

`PreparationSessionPolicy::defaultEligibleStatuses()`:

```php
return [
    OrderStatus::NewOrder->value,    // 'new'
    OrderStatus::InProgress->value,  // 'in_progress' — subsumes the former confirm/confirmed
];
```

A **closed list**: anything not enumerated is ineligible, including unknown or future statuses. There is no
permissive fallback — `in_array()` on a closed list is deny-by-default.

Before this repair the list was `['confirm_order', 'in_progress']`, where `confirm_order` matched no enum
case at all. The effective policy had silently collapsed to `in_progress` alone, and **`new` was never
eligible**. Correcting that token was explicitly authorised.

## 6–13 — Status matrix (runtime, real HTTP)

```
ENTRY GATE POLICY: new, in_progress

new                        reservation=null       -> http=201  attached=1   ALLOW
in_progress                reservation=null       -> http=201  attached=1   ALLOW
confirm (confirmed_at set) reservation=null       -> http=201  attached=1   ALLOW
in_progress (unreserved)   reservation=null       -> http=201  attached=1   ALLOW
awaiting_stock             reservation=reserved   -> http=422  attached=0   REFUSE
ready_for_dispatch         reservation=reserved   -> http=422  attached=0   REFUSE
out_for_delivery           reservation=reserved   -> http=422  attached=0   REFUSE
delivered                  reservation=reserved   -> http=422  attached=0   REFUSE
cancelled                  reservation=reserved   -> http=422  attached=0   REFUSE
cross-company (in_progress)                       -> http=422  attached=0   REFUSE
recalculate(awaiting_stock)                       -> http=422  attached=0   REFUSE
duplicate entry                                   -> http=422  attached=1   REFUSE (exactly one membership)
```

| Part | Scenario | Result |
| --- | --- | --- |
| 5 | `new` eligible | **PASS** |
| 6 | `in_progress` eligible | **PASS** |
| 7 | `confirm` eligible (as `in_progress` + `confirmed_at`) | **PASS** |
| 8 | `awaiting_stock` refused even when reserved | **PASS** |
| 9 | `ready_for_dispatch` refused even when reserved | **PASS** |
| 10 | `out_for_delivery`, `delivered`, `cancelled` refused | **PASS** |

## 14 — Reservation Independence (Parts 4, 18)

Proven in both directions:

* **Reserved but ineligible → refused.** Every refusal row above carries `reservation=reserved`. A reserved
  order in a post-Preparation state is still refused, so reservation never grants eligibility.
* **Eligible but unreserved → accepted.** `test_an_eligible_order_is_accepted_even_when_not_reserved` —
  an `in_progress` order with `reservation_status = null` is accepted. No reservation requirement was
  invented, per Part 18.

## 15 — Company Isolation (Part 11)

A Company A actor submitting a Company B order (status `in_progress`, otherwise perfectly eligible) is
refused with 422 and zero mutation. The lookup is company-scoped, so a foreign id simply does not resolve;
absence is treated as refusal, never as unrestricted access. `store()`'s snapshot query is additionally
company-scoped in its own right, because it copies customer name, delivery zone and shipping cost into the
wave.

## 16 — Mutation Safety (Part 16)

Every refusal asserts the full footprint is untouched:

```
preparation_wave_orders = 0   preparation_waves = 0   preparation_wave_items = 0
stock_ledger_entries    = 0   inventory_layer_consumptions = 0
```

The gate runs **before** any mutation: in `store()` it precedes the order snapshot and DTO construction; in
`recalculate()` it precedes `RecalculateWaveAction` and therefore its `DB::transaction`.

## 17 — Cross-Brand Reuse (Part 20)

Untouched. The repair adds no Brand-level predicate anywhere — scoping is by `company_id` only, consistent
with ADR-027 §16.5. The certified cross-brand behaviour (`RecipeCrossBrandReuseTest` 3/3 + `part20`) is part
of the certified baseline and was not reopened.

## 18 — Duplicate Entry (Part 19)

`test_duplicate_preparation_entry_remains_blocked` — second attempt returns 422 and the order still has
**exactly one** membership row. The pre-existing exclusivity rule is preserved unchanged; no new duplicate
behaviour was invented.

## 19 — Runtime Tests

| Run | Scope | Result | Duration |
| --- | --- | --- | --- |
| Entry-gate matrix + E2E | `PreparationEntryGate` + `BypassGuard` + `LifecycleE2E` | **20/20 PASS**, 117 assertions | 390.9 s |
| Final evidence (post-Pint) | `PreparationEntryGate` + `BranchAssignmentEngine` | **entry gate 13/13 PASS** | 396.4 s |
| RC-10 | `Rc10LifecycleCertificationTest` | **17/17 PASS** | within regression |
| Preparation regression | whole `tests/Feature/Operations` | 225 tests, 746 assertions, 3 failures + 1 error | 547.7 s |

Preparation mechanics still green after the repair: demand aggregation (3+2=5), `available = on_hand −
reserved` (10−6=4), no physical consumption during preparation, partial preparation `6/short 4 → 10/short 0`.

**Behavioural note discovered while re-basing my own test.** `StartPreparationAction:161` soft-reserves the
wave's demand via `SoftReservationService` (*"Soft-reserve inventory for this wave now that preparation has
started"*). So `reserved_qty` legitimately **rises** when preparation starts. That is a reservation, not a
consumption — `on_hand` and the FIFO layers are untouched, which are the invariants that matter. My original
assertion ("preparation must not alter the reservation") was too strong and was corrected to assert the
soft-reservation explicitly.

## 20 — RC-10 (Part 22)

**17/17 PASS.** No regression; no RC-10 test modified or weakened.

## 21 — PHPStan (Part 24)

Cold, result cache cleared: `phpstan.neon.dist` level 0 (`Modules` + `app`) → **[OK] No errors**;
`phpstan-core.neon.dist` level 6 → **[OK] No errors**. Exit 0, 177.1 s. Both ratcheted — no NEW violations.

## 22 — Guardian (Part 25)

7/8 PASS, exit 1, 281 s. PHP Syntax PASS · Composer SKIP · Laravel Bootstrap PASS · **Pint FAIL** · PHPStan
PASS · ESLint PASS · TypeScript PASS · Vite Build PASS.

Pint names only the two known files, attributed by push range `f0d7822a...HEAD` to commit `6149875b` itself:
`ProductPopulationScopeTest.php` (`ordered_imports`) and `V3TransitionResolutionTest.php`
(`binary_operator_spaces`). Neither is in the working-tree diff; neither was modified. `--no-verify` was not
used and nothing was suppressed.

**Scope caveat, stated rather than glossed.** Guardian's Pint validator derives its file list from the git
push range, so it does **not** cover uncommitted work — including this repair. I closed that gap with a
scoped check on exactly the files this task changed, which initially **failed** and was fixed:

```
php vendor/bin/pint --test <5 files changed by this task>   →  {"tool":"pint","result":"passed"}
```

## 23 — Failure Classification (Parts 23, 27)

| Failure | Classification | Basis |
| --- | --- | --- |
| `MaterialDemandCalculator::missing_qty_uses_available_not_on_hand` (15.0 vs 7.0) | **PRE-EXISTING** | Parent-commit control run earlier reproduced the identical message, line and expected/actual at HEAD |
| `OrderExclusivity::db_unique_constraint_prevents_duplicate_company_order_pair` (SQL 1364 `order_confirmed_at`) | **PRE-EXISTING** | Same — identical SQL error at HEAD control |
| `BranchAssignmentEngine::nearest_branch_selected_when_multiple_cover_area` | **PRE-EXISTING** | **Controlled experiment in this task:** reverted `defaultEligibleStatuses()` to the HEAD value inside the runner and re-ran — **fails identically**. Not caused by this repair. Also fails in isolation, so it is deterministic, not test-ordering contamination |
| `TransferEvents::scenario_d_adr_026_document_exists_at_project_level` | **ENVIRONMENT** | `ADR-026-transfer-events-phase-b.md` exists in the worktree and this test **passed in Batch A** (host run); the file is simply absent from the runner image. Container packaging gap, not code |

**NEW failures introduced by this repair: 0.**

The `BranchAssignmentEngine` classification deserves the emphasis: it passed in Batch A and failed here, which
is exactly the shape of a regression. It would have been easy to wave it away as flaky. The revert-and-retest
control is what settles it, and it says the failure is independent of this change.

## 24 — Remaining Gaps

* **Batch B scenarios still outstanding** (explicitly out of scope here, per the task's closing rule):
  Allocation, Picking, AwaitingStock Recovery, Loading, Wave Cancellation, Full Order → Shipment, Phase 3.
* **Three pre-existing failures and one environment failure** above remain open; none was repaired, per
  Part 28 (this task closes only the Entry Gate).
* **`PreparationReleaseEngine` prerequisites are status + warehouse only.** The repair reuses that contract
  verbatim. If the business later wants additional Preparation prerequisites, they belong in the engine, not
  in the controller.
* **Docs are not packaged into the runner image**, which will keep failing any test that asserts a document
  exists at project level. Worth fixing in the image, not in the test.

## 25 — Certification Verdict

The certification rule requires runtime proof that `new`, `in_progress` and `confirm` are allowed, every
other status is rejected, wrong-company is rejected, rejected orders produce zero Preparation mutation, and
F4 + Option B do not regress.

| Requirement | Result |
| --- | --- |
| `new` allowed | **PASS** |
| `in_progress` allowed | **PASS** |
| `confirm` allowed | **PASS** (as `in_progress` + `confirmed_at`; no status invented) |
| Every other status rejected | **PASS** (awaiting_stock, ready_for_dispatch, out_for_delivery, delivered, cancelled) |
| Reservation does not override status | **PASS** (all refusals carried `reserved`) |
| Wrong company rejected | **PASS** |
| Rejected order → zero mutation | **PASS** |
| All entry points agree | **PASS** (both wave routes now refuse; others already delegated) |
| Duplicate entry still blocked | **PASS** |
| RC-10 | **PASS 17/17** |
| PHPStan L0 + core L6 | **PASS** |
| F4 / Option B regression | **NONE** — byte-identical, 3 files +71/−18 |

# PREPARATION ENTRY GATE = CERTIFIED

**Preparation Backend is NOT certified by this task**, and is not claimed to be. The remaining Batch B
scenarios in Section 24 must be completed first.

## 26 — Changes

| File | Type |
| --- | --- |
| `Modules/Operations/Preparation/Domain/Models/PreparationSessionPolicy.php` | production — authorised policy correction |
| `Modules/Operations/Preparation/Presentation/Http/Controllers/PreparationWaveController.php` | production — entry gate |
| `tests/Feature/Operations/PreparationEntryGateTest.php` | new test (the matrix) |
| `tests/Feature/Operations/PreparationBypassGuardTest.php` | test — control re-based; recalculate payload bug fixed |
| `tests/Feature/Operations/PreparationLifecycleE2ETest.php` | test — re-based onto eligible inputs |

Total production diff: **5 files, +179/−21**, of which F4/Option B is the unchanged **3 files, +71/−18**.

Two of my own earlier tests had to be re-based because they created waves from **reserved**
(`ready_for_dispatch`) orders — which the authoritative rule now correctly refuses. That is the rule working
as specified, not a regression, and the tests were wrong to assume reservation-first ordering.

## 27 — Attestations

* F4, Option B, Recipe ownership, negative-stock semantics and RC-10 architecture were **not** reopened or
  modified — proven by an unchanged 3-file, +71/−18 diff.
* No second reservation, recipe-availability or negative-stock engine was created. The existing
  `PreparationReleaseEngine` contract is reused, not reimplemented.
* No new Order status, no new reservation state, no new error architecture — refusals use the existing
  `abort(422, …)` convention with machine-readable codes.
* All DB-backed execution ran in `ecos-dev-testrunner` against `ecos_dev_test`. Never `ecos-dev-app`.
* **MAIN untouched** — `ecos_erp` 551 tables / 2 orders, `ecos_erp_test` 550 tables, containers and images
  unchanged, `C:\Projects\ECOS-ERP` clean (0 entries).
* No Batch B scenario was repaired. **Nothing committed.**
