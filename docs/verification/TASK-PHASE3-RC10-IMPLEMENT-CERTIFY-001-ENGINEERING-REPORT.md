# TASK-PHASE3-RC10-IMPLEMENT-CERTIFY-001 — Engineering Report
## Steps 4–7 · V3 Transition Resolution

**Date:** 2026-08-09 · **Worktree:** `develop` @ `C:\ecos-develop` · Host PHP 8.4.22

| | |
| --- | --- |
| **Steps 4–7 (routing + vocabulary)** | ✅ **IMPLEMENTED AND VERIFIED** |
| **RC-10** | ⚠️ **NOT CERTIFIED** — Part 12 end-to-end not executed. §12 |
| **Guardian** | ✅ `GUARDIAN_EXIT=0` · TypeScript baseline **24** held |
| **Certified Steps 1/2/3/8** | ✅ **No regression** — 44/44 |

---

# 1 — V3 TRANSITION INVENTORY

Every routed edge points at an **existing authoritative workflow**. No workflow was created; no
required transition was found missing, so no stop condition applied.

| From | To | Workflow | Guard enforces | Route exposure |
| --- | --- | --- | --- | --- |
| early¹ | `in_progress` | `ProcessOrderWorkflow` | allowed sources; scheduled-date constraint (`force_activate` override) | generic + `/confirm`, `/resume` |
| `in_progress` | `ready_for_dispatch` | `MoveToPreparationWorkflow` | must be InProgress; terminal reservation states blocked; partial reservation needs manager approval; auto-reserves or diverts to AwaitingStock | generic + `/move-to-preparation` + bulk |
| `ready_for_dispatch` | `out_for_delivery` | `DispatchOrderWorkflow` | must be ReadyForDispatch; **`ShipOrderInventoryAction` requires `assigned_warehouse_id`** | generic + `/dispatch` + bulk |
| `out_for_delivery` | `delivered` | `CompleteDeliveryWorkflow` | must be OutForDelivery **and** `inventory_shipped_at` not null | generic + `/complete-delivery` + bulk |
| `out_for_delivery` · `delivered` | `returned` | `ReturnOrderWorkflow` | status guard; restores inventory | generic + `/return` + bulk |
| any non-locked | `cancelled` | `CancelOrderWorkflow` | blocks OutForDelivery/terminal; force flag from ReadyForDispatch; releases reservation | generic + `/cancel` + bulk |
| `in_progress` | `new` | `ReturnToPendingWorkflow` | status guard; **releases** inventory | generic + `/return-to-pending` |
| `in_progress` | `awaiting_payment` | `ReturnToPaymentWorkflow` | status guard; releases inventory | generic |
| any | `awaiting_stock` | `MarkAwaitingStockWorkflow` | allowed-source list | generic + `/awaiting-stock` + bulk |
| any | `on_hold` | `MoveToReviewWorkflow` | blocks locked/terminal | generic + `/review` + bulk |
| any | `scheduled` | `MarkRescheduledWorkflow` | blocks locked/terminal | generic + `/reschedule` + bulk |
| early | `new` / `awaiting_payment` | `SetEarlyStatusWorkflow` | early-state only, no inventory move | generic |

¹ early = `scheduled · new · awaiting_payment · awaiting_stock · on_hold · cancelled`
(Cancelled is **not** terminal — orders may be reopened.)

**Deliberately absent:** `delivered → completed`. V3 has no `Completed` state (PD-2). Financial
completion remains the dedicated `/complete` route, which stamps revenue/COGS/margin on an
already-Delivered order.

---

# 2 — TRANSITION RESOLUTION IMPLEMENTATION (Steps 4–6)

**One method, one file: `FulfillmentController::resolveTransitionWorkflow()`.**

Every branch now reads its state from the `OrderStatus` enum rather than a string literal, so a
future rename becomes a **compile-time** concern instead of a silent 422.

| V2 (before) | V3 (after) | Basis |
| --- | --- | --- |
| `pending` | `OrderStatus::NewOrder` | PD-2 |
| `confirmed` · `processing` | `OrderStatus::InProgress` (collapsed) | PD-2 |
| `preparing` | `OrderStatus::ReadyForDispatch` | PD-2 |
| `review` | `OrderStatus::OnHold` | PD-2 |
| `rescheduled` | `OrderStatus::Scheduled` | PD-2 |
| `completed` | **retired — no edge** | PD-2 |

**What did NOT change — deliberately:**

- **No second transition engine.** Resolution returns an existing workflow instance; `FulfillmentEngine::run()` still executes `guard()` → transaction → events → audit.
- **No guard was rewritten, duplicated or bypassed.** This change is *routing only*.
- **No TypeScript state machine.** No frontend file was touched.
- **`ConfirmOrderWorkflow`** remains reachable via `/confirm`; it has no generic edge because `confirmed` is retired.
- **Locked states** (`ready_for_dispatch`, `out_for_delivery`, `delivered`, `returned`) still refuse the generic endpoint, preserving V2 semantics; their dedicated routes remain the path.

---

# 3 — STEP 7 — NAMING DEBT

| Route | Handling |
| --- | --- |
| `/complete` | **Unchanged.** No `Completed` state introduced. Financial-completion behaviour preserved, and the generic table has no `→ completed` edge |
| `/review` | **Unchanged.** Functionally `OnHold`; the generic endpoint now routes `→ on_hold` to the same workflow |

**No public API was renamed.** The task forbids cosmetic renaming, and both routes are consumed by
existing clients. The legacy naming is recorded here as intentional, with the V3 meaning documented
in the method docblock.

---

# 4 — VERIFICATION

## 4.1 Routing (new)

`tests/Feature/Operations/V3TransitionResolutionTest.php` — **23 cases, 148 assertions**, runtime
**21s** (no DB — it reflects on the resolution table directly).

```
OK (23 tests, 148 assertions)
```

Covers: 13 routed edges → named workflow · 7 illegal edges refused (self-transition, skip-dispatch,
reverse-from-delivered, all locked states) · **no `completed` edge** · **all six retired V2 tokens
refused as targets** · and an exhaustive 11×11 sweep of every `OrderStatus` pair proving no pair
errors.

## 4.2 Dedicated routes (15) and bulk routes (13)

**Architecture re-verified as unchanged by inspection.** `resolveTransitionWorkflow()` is called by
**one** method — `transition()`. The 15 dedicated routes inject their workflow directly and the 13
bulk routes go through `BulkWorkflowEngine:49` → `FulfillmentEngine::run()`. **This change cannot
reach them**, which is precisely why it is safe.

> **Not re-executed here.** E-5 and SD-4 certified them (13/13 and 13 PASS / 2 PARTIAL), and the two
> PARTIALs are resolved by PD-2 as correct-by-design. Their behavioural re-execution belongs to the
> Part 12 work in §12.

## 4.3 Certified-step regression (Part 14)

```
OK (44 tests, 132 assertions)
```
Step 1 (availability derivation) · Step 2 (product population) · Step 8 (write path) · RC-6
(warehouse + order isolation) · D-8 (supplier isolation). **No regression.**

## 4.4 Static validation (Part 13)

| Gate | Result |
| --- | --- |
| PHP lint — HOST PHP 8.4.22 | ✅ `No syntax errors detected` |
| PHPStan level 0 (platform) | ✅ `[OK] No errors` |
| PHPStan level 6 (`app/Core`) | ✅ `[OK] No errors` |
| **Guardian pre-push** | ✅ **8/8 — `GUARDIAN_EXIT=0`** |
| TypeScript | ✅ baseline **24** held |
| ESLint | ✅ PASS |
| i18n / EN-AR / RTL | ✅ **0 keys changed** — no frontend file touched |
| `--no-verify` · suppressions · Guardian edits · container PHP | ✅ None |

---

# 5 — ⚠️ WHAT IS NOT DONE, AND WHY RC-10 IS NOT CERTIFIED

**The routing defect is fixed and verified. RC-10 certification requires more than that, and the task
is explicit: *"Do not certify RC-10 using only endpoint existence or static analysis."***

| Part | Status |
| --- | --- |
| **9 — UI wiring** | ❌ Not done. `OrderResource::resolveAllowedTransitions()` already emits V3 and the drawer renders it, so read and write now agree for the first time — but **displaying the backend refusal reason is not implemented** |
| **11 — full regression matrix** | ⚠️ Partial. Categories 1–5, 7, 18 covered. **Not covered: 6 (company isolation on transition), 8–10 (warehouse at Dispatch behaviourally), 11–14 (reservation paths), 15–17 (dedicated/bulk/audit executed)** |
| **12 — end-to-end RC-10 flow** | ❌ **Not executed.** No order was driven through reservation → ready → dispatch → delivered against a database, and no negative path (missing warehouse, shortage, cross-company) was run |
| **10 — audit/events** | ⚠️ Inspected, not executed. `FulfillmentEngine` writes `OrderEvent` and dispatches `events()` — unchanged by this edit |

**Honest position: Steps 4–7's *implementation* is complete and verified at the routing layer. RC-10
is a lifecycle certification and has not been earned.**

The atomicity requirement is satisfied: vocabulary and guard-routing moved **together** in one change,
so the dangerous half-state — vocabulary repaired while guards remain unreachable — never existed.

---

# 6 — DECISION REGISTER UPDATE

- **PD-1 = RESOLVED** · **PD-2 = RESOLVED** (unchanged)
- **Steps 4–7 = IMPLEMENTED** — routing/vocabulary verified; Guardian green; no regression
- **RC-10 = NOT CERTIFIED** — pending Part 9 UI wiring and Part 12 end-to-end
- Steps 1 · 2 · 3 · 8 = **CERTIFIED**, re-verified

---

# 7 — PHASE 3 STATUS

| Step | Status |
| --- | --- |
| 1 · 2 · 3 · 8 | ✅ CERTIFIED |
| **4 · 5 · 6 · 7** | 🟢 **IMPLEMENTED & VERIFIED (routing)** — awaiting lifecycle certification |

**Phase 3 implementation: 8/8. Phase 3 certification: 4/8 + 4 implemented-not-certified.**

**I am not claiming 8/8 COMPLETE.** The success criterion requires `RC-10 = CERTIFIED`, and Part 12
was not executed.

---

# 8 — EXACT REMAINING WORK

| # | Item | Type |
| --- | --- | --- |
| 1 | **Part 12 end-to-end lifecycle certification** — drive a real order through the full chain plus negative paths | Engineering |
| 2 | **Part 9** — surface backend refusal reasons in the order drawer (EN + AR, selector mode) | Engineering |
| 3 | **Part 11 remainder** — categories 6, 8–17 | Engineering |
| 4 | Tenant-2 gate (GD-1 platform-wide, GD-2 governance, GD-4, RC-1, RC-2, D-9) | Owner — deferred by OD-2 = PILOT |
| 5 | 3 pre-existing `InventoryCountSessionTest` defects | Engineering, outside Phase 3 |

**No owner decision blocks Phase 3.** Items 1–3 are one focused session with the verification budget
Part 12 demands.

---

**Final Go-Live Certification not started. No certified work reopened. No new permission, no
destructive migration, no `--no-verify`, no suppression, no Guardian modification, no browser
verification claimed.**
