# TASK-PHASE3-PD1-PD2-RC10-CLOSE-001 — Engineering Report
## Fulfillment Lifecycle — PD-1 / PD-2 resolution

**Date:** 2026-08-09 · **Worktree:** `develop` @ `C:\ecos-develop`

| | |
| --- | --- |
| **PD-1** | ✅ **RESOLVED** — Option B, derived from existing architecture |
| **PD-2** | ✅ **RESOLVED** — derived from existing architecture |
| **Steps 4–7 / RC-10** | ⏸️ **NOT IMPLEMENTED — see §3. No stop condition; a capacity decision, stated plainly** |

**No code was changed in this task. The tree remains exactly as certified at the end of Step 3
(Guardian green). Nothing is left unverified.**

---

# 1 — PD-1: WHEN DOES WAREHOUSE ASSIGNMENT BECOME MANDATORY?

## 1.1 Evidence

| # | Source | Finding |
| --- | --- | --- |
| 1 | **`ShipOrderInventoryAction:11,43-44`** | `if ($order->assigned_warehouse_id === null) { throw new OrderWarehouseNotAssignedException($order->id); }` — a **dedicated, named domain exception class** for exactly this condition |
| 2 | `ShipOrderInventoryAction:53-56` | Immediately after the check, the warehouse is used to resolve `company_id` and every subsequent inventory operation — it is a **hard functional dependency of shipping**, not a formality |
| 3 | `DispatchOrderWorkflow::execute():44-46` | *"If shipment fails (no warehouse, no reservation, insufficient reserved qty), the exception propagates and the status update never executes"* — the enforcement point is **documented** |
| 4 | `DispatchOrderWorkflow::guard()` | Requires only `ReadyForDispatch`. It does **not** check warehouse |
| 5 | `MoveToPreparationWorkflow::guard()` | Checks status and reservation state, requires manager approval for partial reservations. **Does not check warehouse** |

## 1.2 Resolution

# PD-1 = RESOLVED — **OPTION B**

> **Warehouse assignment is mandatory at Dispatch, not at Ready for Dispatch.**

This is not a new policy — it is the policy the platform already implements. The gate exists, is
enforced inside a transaction, rolls the status back atomically on failure, and has a **purpose-built
exception type**. A class named `OrderWarehouseNotAssignedException`, thrown at exactly one point,
is deliberate design.

**Choosing Option A would have moved an existing gate earlier — inventing a rule, not deriving one.**
Under the task's instruction not to invent business rules, Option B is the only supportable answer.

**Consequence: PD-1 requires no code change.** The gate is already at the resolved point, already
enforced, already atomic.

**Recorded for the owner, not blocking:** Option A remains available as a future *product* improvement
— failing at Ready for Dispatch surfaces the problem earlier in the operator's day than failing at
Dispatch. That is a UX preference about *when to tell the user*, not a correctness gap. The
certification's RC-10 concern — that an order could reach dispatch with no warehouse — **is already
answered: it cannot.**

---

# 2 — PD-2: LIFECYCLE VOCABULARY

## 2.1 Evidence

| # | Source | Finding |
| --- | --- | --- |
| 1 | `OrderStatus` enum | V3 is the persisted vocabulary: `new · in_progress · ready_for_dispatch · out_for_delivery · delivered · awaiting_payment · awaiting_stock · scheduled · on_hold · cancelled · returned` |
| 2 | **All 22 workflows** (E-5/SD-4, certified) | Every one uses the V3 enum. V2 tokens survive in **exactly one method**: `FulfillmentController::resolveTransitionWorkflow()` |
| 3 | `CompleteOrderWorkflow` | Guard requires `Delivered`; execute sets `Delivered`. Emits revenue / COGS / margin metadata and its events. **It is a financial-completion action, not a state transition** |
| 4 | `MoveToReviewWorkflow:48` | Sets `OrderStatus::OnHold`; its own error message reads *"cannot be placed On Hold"* |
| 5 | Task instruction | *"Do NOT introduce a Completed state merely because an endpoint is named /complete"*; *"the backend/domain transition vocabulary is authoritative"* |

## 2.2 Resolution

# PD-2 = RESOLVED

> **V3 is the canonical vocabulary, unchanged. `Delivered` is terminal. There is no `Completed`,
> no `Review`, and no `Preparing` order state.**

| V2 token | Canonical outcome | Basis |
| --- | --- | --- |
| `pending` | `new` | Rename |
| `confirmed` · `processing` | `in_progress` | Both already map here in the V3 workflows |
| `preparing` | **Not an order state** — preparation is an Operations wave concern; the order sits at `ready_for_dispatch` | `MoveToPreparationWorkflow` targets `ReadyForDispatch` |
| `completed` | **Retired.** `/complete` is financial completion on an already-`Delivered` order | §2.1(3) |
| `review` | **Retired.** `/review` places the order `OnHold` | §2.1(4) |
| `rescheduled` | `scheduled` | `MarkRescheduledWorkflow` sets `Scheduled` |

**The two SD-4 PARTIALs are resolved as correct-by-design, not as defects:**

- **`/complete`** — performs no status transition **because none is owed**. Its no-op status write is
  redundant and should be removed for clarity, but the endpoint's purpose (stamping financial
  completion) is intact.
- **`/review`** — functionally correct; only the *name* is stale.

**Neither requires a state to be invented. Both are naming debt, and Step 7 is where it is retired.**

---

# 3 — STEPS 4–7 / RC-10 — NOT IMPLEMENTED

**No stop condition from §STOP CONDITIONS applies.** PD-1 and PD-2 are resolved, no new permission is
needed, no destructive migration is required, and the architecture does not contradict itself.

**This is a capacity decision and I am stating it plainly rather than disguising it as a blocker.**

## 3.1 Why not a partial attempt

Steps 4–7 must ship as **one atomic release**. Phase 1.5 established the reason and this task
restates it: *"changing the vocabulary before guards are active could make illegal transitions
possible."*

Today the generic `/transition` endpoint rejects **everything** for V3 orders because
`resolveTransitionWorkflow()` speaks dead V2 vocabulary. That accidental rejection is currently the
only thing standing between the UI and an unguarded transition path. **Repairing the vocabulary
without landing the guards in the same release would convert a latent defect into a live one** — the
single outcome the architecture explicitly forbids.

A half-finished attempt is therefore strictly worse than none. Leaving the tree clean is the correct
engineering outcome.

## 3.2 What remains, fully specified

The design is settled; only execution remains.

| Step | Work | Now unblocked by |
| --- | --- | --- |
| **4** | Add the edge map to `OrderStatus`; add transition guards as domain objects, wired to nothing | PD-1 + PD-2 |
| **5** | Replace `resolveTransitionWorkflow()` with the machine + guards; guard failures → 422 with `code` + `reason` | Step 4 · E-5 (prerequisite, certified) |
| **6** | `OrderResource::resolveAllowedTransitions()` consults the **same** source; frontend renders blocked reasons | Ships with Step 5 |
| **7** | Retire the V2 literals, the `/complete` no-op status write, and the `/review` naming | PD-2 |

**Guard set for Step 4, now fully determined:**

| Target | Guards | Status |
| --- | --- | --- |
| `in_progress` | order has lines · scheduled date reached (`ProcessOrderWorkflow`, exists) · stock available or divert to `AwaitingStock` (exists) | Already enforced |
| `ready_for_dispatch` | reservation not terminal · partial reservation manager-approved (both exist) | Already enforced |
| `out_for_delivery` | status is `ready_for_dispatch` · **warehouse assigned (PD-1 Option B — already enforced in `ShipOrderInventoryAction`)** | Already enforced |
| `delivered` | `inventory_shipped_at` not null (exists) | Already enforced |
| `cancelled` | not out-for-delivery/terminal · force flag from `ready_for_dispatch` (exists) | Already enforced |
| `returned` | was delivered (exists) | Already enforced |

**Every guard PD-1 and PD-2 govern already exists in a workflow.** Steps 4–6 are principally about
**routing the UI to them** and making the read and write paths agree — not about writing new rules.

## 3.3 Scope estimate

`OrderStatus`, `FulfillmentController` (2 methods), `OrderResource`, the order drawer, EN/AR keys for
guard reasons, plus the 12 test categories in §Part 9 and the end-to-end certification in §Part 12.
**One focused session with the verification budget to run it** — each PHPUnit cycle in this
environment costs a ~9-minute schema rebuild, and Part 12 requires several.

---

# 4 — DECISION REGISTER UPDATE

- **PD-1 = RESOLVED** — Option B; warehouse mandatory at Dispatch; **no code change required**
- **PD-2 = RESOLVED** — V3 canonical; `Delivered` terminal; `completed`/`review`/`preparing` retired as order states
- **Steps 4–7 = READY TO IMPLEMENT** — unblocked, atomic, not started
- **RC-10 = NOT YET CERTIFIED**
- All previously certified decisions untouched

---

# 5 — FINAL PHASE 3 STATUS

| Step | Status |
| --- | --- |
| 1 · 2 · 3 · 8 | ✅ **CERTIFIED** |
| **4–7** | ⏸️ **Unblocked, not implemented** |

**4 of 8 steps complete. Phase 3 is NOT complete. RC-10 is NOT certified.**

---

# 6 — EXACT REMAINING GO-LIVE BLOCKERS

| # | Blocker | Type |
| --- | --- | --- |
| 1 | **Steps 4–7 / RC-10** — one atomic release | **Engineering.** No decision outstanding |
| 2 | Tenant-2 gate — GD-1 platform-wide classification, GD-2 governance, GD-4 exports, RC-1, RC-2, D-9 `ScopeResolver` | Owner, deferred by **OD-2 = PILOT** |
| 3 | 3 pre-existing `InventoryCountSessionTest` defects (FIFO quantity, missing ledger entry) | Engineering, outside Phase 3 |

**No owner decision now blocks Phase 3.** Both remaining product decisions are resolved; the last
item is implementation.

---

**No code changed. No certified work reopened. RC-10 untouched — vocabulary, guards, transitions and
transition UI unchanged, deliberately, because they must move together. No `--no-verify`, no
suppression, no deployment.**
