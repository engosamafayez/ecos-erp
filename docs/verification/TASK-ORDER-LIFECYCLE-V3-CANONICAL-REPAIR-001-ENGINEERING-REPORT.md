# TASK-ORDER-LIFECYCLE-V3-CANONICAL-REPAIR-001 — Engineering Report

**Date:** 2026-08-13 · **Branch:** `develop` · HEAD `6149875b`
**Nothing was modified.** No enum, no code, no config, no data, no test.

> # VERDICT: **NOT CERTIFIED — BUSINESS DECISION REQUIRED**
>
> Stopped at PART 0/1/18 before any edit, on three findings:
>
> 1. **Removing `new` from the enum breaks live data on contact.** `orders.status` is cast to `OrderStatus::class`, and `ecos_dev` holds a live order at `status = 'new'`. Deleting the case makes `OrderStatus::from('new')` throw — that order can no longer be hydrated, and any listing that touches it crashes. The required data migration is **not** in this task's approved scope (PART 18 stop).
> 2. **This task reverses a certified migration, in both directions.** `2026_07_22_100000_simplify_order_lifecycle_v3` explicitly performed `pending → new` and `confirmed → in_progress`. This task asks to remove `new` and restore `confirmed` — undoing both. That is a lifecycle **redefinition**, not drift repair, and it needs to be recorded as such or the next recovery pass will report the mirror-image drift.
> 3. **Verification is unavailable.** Another agent's phpunit is again running against the shared `ecos_dev_test`, and PART 0.12 says STOP rather than interfere. PART 20 forbids certifying without the E2E path actually executing.
>
> I am not declining the work. I am declining to execute a 12-file, 21-reference lifecycle change that breaks live data and cannot be verified in this session.

---

## 1. Executive Summary

The business contract (D1–D4) is clear and I have no objection to it. The blocker is **execution safety**, not intent.

`new` is not an incidental value. It is the V3 canonical replacement for `pending`, installed by a certified migration three weeks ago, and it is now load-bearing across Orders, Fulfillment, Preparation and Distribution — including two configuration values set by *earlier certified tasks in this same programme*.

## 2. Final Business Contract (recorded, unimplemented)

| # | Decision | Status |
|---|---|---|
| D1 | Entry Status = **pick-and-stay** | recorded |
| D2 | Payment method must not silently override entry status | recorded |
| D3 | `confirmed` is a real canonical status | recorded |
| D4 | `new` is removed from the canonical lifecycle | **blocked — §3** |

Target lifecycle: create → `in_progress` / `scheduled` / `awaiting_payment`; `in_progress` → Confirm → `confirmed`.

## 3. Blocker 1 — live data breaks on enum removal

```
ecos_dev orders (deleted_at IS NULL):
  in_progress   3
  new           1        ← ORD-00002
```

`Order.php:282` — `'status' => OrderStatus::class`.

With the case removed, hydrating ORD-00002 raises `ValueError: "new" is not a valid backing value for enum OrderStatus`. That is not a cosmetic failure: it breaks the order list, the order drawer, and every query that eager-loads it.

**PART 12** says historical `new` records must not be changed arbitrarily and the normalization requirement must be reported. **PART 18** says stop before executing a data migration unless it is explicitly in scope. It is not: PARTS 1–14 authorise enum, code, config and frontend changes — **no `orders.status` data migration is authorised**.

Those two instructions, taken together, make the enum removal unexecutable as written. That is the stop.

**What is needed:** an explicit decision on the existing `new` rows — migrate them to `in_progress` (matching the D4 intent), or to `scheduled`/`awaiting_payment` by inspection, or retain `new` as a *historical-only* value the enum still accepts but no input path offers. The third option is viable and materially cheaper, but PART 12 forbids me choosing it unilaterally ("no hidden compatibility state").

## 4. Blocker 2 — this reverses a certified migration

`2026_07_22_100000_simplify_order_lifecycle_v3.php:14-17`, verbatim:

```
*   processing    → in_progress
*   confirmed     → in_progress  (merged: was a separate confirmation step)
*   preparing     → in_progress  (invisible engine state; order remains In Progress)
```

and `pending → new` in the same migration, executed at `:30`.

D3 and D4 reverse both halves. That is legitimate — a business may change its mind about its own lifecycle — but it must be recorded as a **deliberate redefinition superseding that migration**, not as a defect repair. Otherwise:

- the migration's own comments become false;
- historical rows migrated `confirmed → in_progress` are now indistinguishable from genuine `in_progress` orders, and cannot be recovered;
- the next contract-recovery pass will find `confirmed` in the enum, the migration saying it was removed, and report the mirror-image drift.

**Recommendation (not an implementation):** supersede it with a new migration + a short ADR — which also discharges ADR-005 §5's never-written order-FSM ADR, the governance gap that allowed all of this drift.

## 5. Blast Radius — measured, not estimated

**21 references to `OrderStatus::NewOrder`** across at least 12 files:

`CreateManualOrderAction` · `PatchOrderAction` · `OrderDTO` · `ExecuteReservationOnWarehouseAssigned` · `ReprocessLegacyReservationsCommand` · `OrderSeeder` · `OrderController` · `OrderResource` · `DemandAnalysisService` · `ApprovePartialReservationWorkflow` · `ConfirmOrderWorkflow` · `MarkAwaitingStockWorkflow` (+ others)

**Configuration currently depending on `new`:**

| Location | Value | Note |
|---|---|---|
| `PreparationSessionPolicy:85` | `OrderStatus::NewOrder->value` | Preparation entry gate |
| `config/distribution.php:55` | `OrderStatus::NewOrder->value` | Distribution collection eligibility |
| `wave_engine_configurations` (DB) | `["new", "in_progress"]` | **set by a certified task in this programme** |

That last row matters: removing `new` re-breaks the Wave Engine, which TASK-ORDER-PREPARATION-FLOW-REPAIR-001 certified after finding it inert on the stale value `["confirmed"]`. **Removing `new` without simultaneously updating these three re-creates that exact outage** — and `confirmed` returning as canonical makes the old stale value accidentally valid again, which is a genuinely confusing failure mode.

**Cross-domain consumers requiring coordinated change (PART 19):** Preparation entry gate, Wave Engine config, Distribution collection config, plus every workflow guard listing `NewOrder` as an allowed source state.

## 6. Blocker 3 — verification unavailable

`foreign phpunit in runner: 1`. PART 0.12 is explicit: if another agent is using `ecos_dev_test`, **STOP** and do not interfere. PART 20 requires the E2E path to actually execute before certification, and PART 15 mandates 11 E2E cases.

I could have written the code and left it unverified. For a change that removes a status from a live enum across 21 call sites, unverified is worse than not started.

## 7. What I did NOT do

No enum change · no `CreateManualOrderAction` change · no `PAYMENT_CLEAR_STATUS_PREFERENCE` removal · no `ConfirmOrderWorkflow` change · no brand-policy normalization · no frontend change · no migration · no data change · no test change.

`git status` for this task is empty. `ecos_dev` untouched — verified read-only (4 orders, 556 tables). `ecos_erp`/MAIN never contacted. No `migrate:fresh`, `db:wipe` or destructive seed.

## 8. Required Decisions

**B1 — Disposition of existing `new` rows.** Migrate to `in_progress`? Case-by-case? Or keep `new` enum-valid as historical-only while removing it from every input path? *(Blocks D4 entirely.)*

**B2 — Supersede the V3 migration explicitly?** Approve a new migration + ADR recording that D3/D4 override `2026_07_22_100000`, so the reversal is documented rather than implicit.

**B3 — `confirmed` in Preparation/Distribution eligibility.** Once `confirmed` is canonical and `new` is gone, must `PreparationSessionPolicy`, `config/distribution.php` and `wave_engine_configurations` become `['confirmed','in_progress']`, or `['in_progress','confirmed']` plus `scheduled`? This is a fulfilment-policy question, and PART 19 forbids me changing Preparation semantics without proof.

**B4 — Confirm's position in the pipeline.** Does reservation happen at creation (`in_progress`) as today, or move to Confirm? D3 makes Confirm meaningful again; whether it becomes the reservation trigger is a separate business rule that PART 7/8 do not settle.

## 9. Recommended Execution Order (once B1–B4 land)

1. ADR + superseding migration (B2) — establishes authority first.
2. Enum: add `Confirmed`, remove `NewOrder` **in the same change** as the data migration (B1).
3. Update all 21 references and the three configuration sources **atomically** — partial application re-breaks Preparation and Distribution.
4. `CreateManualOrderAction`: delete the `new` auto-initiate gate; delete `PAYMENT_CLEAR_STATUS_PREFERENCE` (D1/D2).
5. Brand policy → canonical vocabulary; make read and write paths share one resolver.
6. `ConfirmOrderWorkflow` → writes `confirmed`.
7. Frontend entry options → `in_progress` / `scheduled` / `awaiting_payment`.
8. E2E cases 1–11, then regression, static, scope check.

Steps 2–3 must be one commit. There is no safe intermediate state.

## 10. Final Verdict

# NOT CERTIFIED — BUSINESS DECISION REQUIRED

**Blocking:** B1 (existing `new` rows — makes D4 unexecutable as scoped), B2 (superseding a certified migration), B3 (downstream eligibility), B4 (Confirm's role).

**Also unavailable:** the shared test runner, so PART 20's evidence requirement could not be met regardless.

I want to be plain about one more thing: this session has been long, and a 21-reference lifecycle change with a live-data dependency deserves a fresh context to be done safely. Even with B1–B4 answered, I would recommend starting it in a new session rather than continuing here — §9 is written to be picked up cold.

**Nothing was modified. No symptom was quick-fixed. No test was adjusted.**
