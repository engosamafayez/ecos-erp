# TASK-GOLIVE-RESERVATION-POLICY-INVESTIGATION-001 — Engineering Report
## Reservation Policy vs `allow_negative_stock`

**Date:** 2026-08-09 · **Base:** `6149875b` · **Investigation only — no code, test, DB or commit changes.**

---

# 1 — EXECUTIVE SUMMARY

# ⛔ STOP — Condition 1 met

> *"allow_negative_stock له معنى مختلف عن الفرضية المطروحة."*

## The question, answered with evidence

# **YES — `allow_negative_stock` DOES affect Reservation, directly and by design.**

It is **not** limited to Manufacturing/Consumption. `ReserveOrderInventoryAction:157-183` contains a
dedicated branch, added under `TASK-REGRESSION-NEGATIVE-RESERVATION-001`, whose docblock states it
plainly:

> *"`allow_negative_stock` on a finished good **commits the full ordered quantity as reserved
> regardless of on-hand qty**. Lock any physically available units first; the remainder is a logical
> commitment that **drives inventory negative at shipment time** (DirectIssue path)."*

**The proposed business rule is therefore correct for `OFF` and incorrect in its assumption for `ON`.**

## A third policy dimension the proposal did not account for

**`can_manufacture` takes precedence over `allow_negative_stock`** (`:130-155`). A manufacturable
finished good commits the full quantity *unconditionally*, before `allow_negative_stock` is ever
consulted — per ADR-027, *"Manufacturing owns all Raw Material decisions."*

**Reservation is governed by a five-branch precedence ladder, not a single availability test.**

---

# 2 — RESERVATION FLOW (Part 1)

```
Order
 → MoveToPreparationWorkflow::execute()   auto-reserves when not already reserved
 → ReserveOrderInventoryAction::execute()  the ONE reservation authority
     :63    OrderWarehouseNotAssignedException when no warehouse   ← the only throw
     :97    $available = max(0.0, $item->availableQty())           ← clamped, per item
     :100   CASE 1  available >= requested          → full physical reserve
     :130   CASE 2  can_manufacture                 → commit full qty (RM deferred)
     :162   CASE 3  allow_negative_stock            → commit full qty, go negative later
     :186   CASE 4  available <= 0                  → skip line
     :194   CASE 5  0 < available < requested       → partial reserve
 → ReserveStockAction (lockForUpdate)      physical lock + TOCTOU protection
 → line->reserved_qty persisted
 → status determination (:218+) → Reserved / PartialReserved / AwaitingStock
```

**Single authority confirmed — no second reservation engine exists.**

---

# 3 — AVAILABILITY SOURCE (Part 3)

| Question | Answer |
| --- | --- |
| Does reservation use `on_hand_qty`? | **No** |
| Does it use `available_qty`? | **Yes — clamped** |
| Exact expression | `:97` — `$available = $item ? max(0.0, $item->availableQty()) : 0.0` |
| Scope | **Per inventory item** (warehouse + product + company), not a global sum |
| Does it use `availability_state` (Step 1)? | **No.** Reservation has its own policy and never reads it |

**Critical:** the `max(0.0, …)` clamp means a *negative* on-hand position is treated as **0** by
reservation. Scenario 7 therefore collapses into Scenario 6.

---

# 4 — THE THREE CONCEPTS ARE GENUINELY DIFFERENT (Part 7)

| Concept | Rule | Honours `allow_negative_stock`? |
| --- | --- | --- |
| **1. `availability_state`** (Step 1) | `null → Untracked · <= 0 → OutOfStock · > 0 → InStock` | ❌ **No** — physical only |
| **2. `manufacturing_availability`** | `available > 0 **OR** allow_negative_stock` | ✅ Yes — BOM component sourcing |
| **3. Reservation availability** | The five-branch ladder in §2 | ✅ Yes — **and also `can_manufacture`** |

**All three are distinct policies. None may be substituted for another.** This confirms the GD-2
resolution (`allow_negative_stock` is permission-to-proceed, not physical availability) *and* extends
it: reservation is a **third** consumer with its own precedence rules.

---

# 5 — SCENARIO MATRIX (Part 6)

| # | `allow_neg` | Available | Requested | Current behaviour | Branch | Status |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | OFF | 10 | 5 | Full physical reserve, `reserved_qty = 5` | `:100` CASE 1 | ✅ **PASS** |
| 2 | OFF | 5 | 5 | Full physical reserve (`>=` is inclusive) | `:100` CASE 1 | ✅ **PASS** |
| 3 | OFF | 5 | 6 | **Partial** — reserves 5, `failReason = 'Insufficient Inventory'`, `outcome: partial` | `:194` CASE 5 | ✅ **PASS** — matches PD-1's partial-reservation contract |
| 4 | ON | 10 | 5 | Full physical reserve. **`allow_negative_stock` never consulted** — CASE 1 fires first | `:100` | ✅ **PASS** |
| 5 | ON | 5 | 5 | Full physical reserve, same reason | `:100` | ✅ **PASS** |
| 6 | ON | 0 | 5 | **`reserved_qty = 5` committed with zero physical lock**, `outcome: negative_stock_committed` | `:162` CASE 3 | ⚠️ **GAP vs proposal** |
| 7 | ON | < 0 | > 0 | **Identical to #6** — `:97` clamps negative to 0 | `:97` + `:162` | ⚠️ **GAP vs proposal** |

**Additional row not in the proposal, but decisive:**

| # | Condition | Behaviour | Branch |
| --- | --- | --- | --- |
| 8 | `can_manufacture = true`, any `allow_neg`, available < requested | Commits **full** requested; RM evaluated later by `PrepareOrderManufacturingAction` | `:130` CASE 2 — **precedes CASE 3** |

## 5.1 The `OFF` + `available = 0` case the success criterion asks about

**Confirmed with evidence.** `:186` — `if ($available <= 0.0) { $skippedLines++; $failReason ??=
'Insufficient Inventory'; continue; }`. The line is skipped, contributing to the status
determination that produces **`AwaitingStock`**.

**Runtime proof already executed:** RC-10's `test_insufficient_stock_diverts_to_awaiting_stock`
(on-hand 0, requested 5) — order reaches `AwaitingStock`, does **not** dispatch. ✅

---

# 6 — DUPLICATE RESERVATION (Part 5) — ⚠️ **UNVERIFIED**

`OrderAlreadyReservedException` is **never thrown** in `ReserveOrderInventoryAction`. The only throw
is `OrderWarehouseNotAssignedException` (`:63`).

`ProcessOrderWorkflow` documents *"idempotent re-initiation (e.g. after partial reservation
approval)"*, and `MoveToPreparationWorkflow` re-reserves only when `reservation_status` is **not**
`Reserved`/`PartialReserved` — so in the certified path a duplicate is **avoided by the caller**, not
rejected by the action.

**Not verified:** whether calling `execute()` twice directly re-locks stock, double-counts
`reserved_qty`, or is a no-op. The status-determination block (`:218+`) was **not read** in this
investigation.

**Marked UNVERIFIED rather than guessed**, as required. It is the one open item for the follow-up.

---

# 7 — INVENTORY CONSEQUENCES OF CASE 3 (Part 8) — traced, not proposed

When `allow_negative_stock = ON` and physical availability is short:

| Field | Effect |
| --- | --- |
| `reserved_qty` (order line) | Set to the **full requested** quantity |
| `reserved_qty` (inventory item) | Increased **only** by the physically available portion (`:163-177`) |
| `on_hand_qty` | **Unchanged at reservation time** |
| `available_qty` | Unchanged beyond the physical portion |
| Inventory ledger / FIFO layers | **Untouched at reservation** |
| **At shipment** | Docblock: *"drives inventory negative at shipment time (**DirectIssue path**)"* |

> **Consequence worth surfacing:** order-line `reserved_qty` and inventory `reserved_qty` **diverge by
> design** in Case 3. The order believes it holds 5; inventory records only the physical portion. The
> gap is settled at dispatch via DirectIssue. **This is intended, but it means "reserved" means two
> different things depending on the branch taken.**

**Not traced (out of scope, no code read):** the DirectIssue shipment path and its FIFO interaction.

---

# 8 — TENANT ISOLATION (Part 9) ✅

`:102-109` and every other `StockOperationDTO` carry `company_id` **and** `warehouse_id`. The item
lookup (`:96`) is scoped by warehouse + product + company. **Reservation cannot draw inventory from
another company.**

**RC-6 and D-8 untouched. No regression. D-9/`ScopeResolver` not reopened.**

---

# 9 — TEST AUDIT (Part 10) — no test modified

| Test | Classification |
| --- | --- |
| `test_reserve_throws_on_insufficient_stock` | **V2-STALE / INCORRECT EXPECTATION.** Contradicts the action's own docblock (`:32` — *"Does NOT throw… for insufficient stock"*) and the certified V3 divert-to-`AwaitingStock` behaviour |
| `test_reserve_idempotency_throws_already_reserved_exception` | **INCORRECT EXPECTATION** — the exception is never produced. **True contract UNVERIFIED (§6)** |
| `test_reserve_throws_when_no_warehouse_assigned` | **V3-VALID** — the one real throw |
| RC-10 `test_insufficient_stock_diverts_to_awaiting_stock` | **V3-VALID, executed, passing** |
| **Missing coverage** | Scenarios **6, 7 and 8** — no test exercises `allow_negative_stock` or `can_manufacture` reservation commitment |

**The missing coverage is the more serious finding than the two failing tests.** The branch that
commits stock the warehouse does not hold is **untested**.

---

# 10 — FINDINGS

| # | Finding | Severity |
| --- | --- | --- |
| **F1** | `allow_negative_stock` **does** affect reservation — commits full quantity without physical stock | **Contradicts the task's hypothesis** |
| **F2** | `can_manufacture` **outranks** `allow_negative_stock`; a third dimension not in the proposed rule | High |
| **F3** | Order-line and inventory `reserved_qty` **diverge by design** in Case 3 | Medium — operational meaning |
| **F4** | Scenarios 6, 7, 8 have **zero test coverage** | **High** |
| **F5** | Duplicate-reservation contract **UNVERIFIED** | Medium |
| **F6** | The two "defects" are **stale V2 expectations**, now evidenced at `:32` and `:186` | Confirms prior classification |
| **F7** | Reservation uses **clamped per-item** availability; negative positions read as 0 | Informational |

---

# 11 — DECISION REQUIRED FROM THE OWNER

| # | Question |
| --- | --- |
| **1** | **Is Case 3 intended?** Should `allow_negative_stock = ON` allow an order to reserve stock the warehouse does not physically hold, settling negative at dispatch? The code says yes, deliberately (`TASK-REGRESSION-NEGATIVE-RESERVATION-001`). **Confirm or reverse.** |
| **2** | **Is the `can_manufacture` precedence intended?** Manufacturable goods commit fully regardless of stock *and* regardless of `allow_negative_stock`. |
| **3** | **The two stale tests** — correct them to assert the certified V3 behaviour (Option A), or change reservation to throw (Option B, which reopens PD-1 and RC-10)? |

**I did not answer any of these.** Question 1 is precisely STOP condition 1; Question 3 was already
escalated in the previous task.

---

# 12 — ENGINEERING RECOMMENDATION

1. **Confirm Case 3 (Q1) as intended.** It is documented, deliberate, and traceable to a named task.
2. **Then correct the two stale tests** to assert `AwaitingStock` and the real idempotency contract —
   Option A. This is alignment with the ratified PD-1 contract, **not** weakening assertions.
3. **Then close F4 — the real gap.** Add coverage for Scenarios 6, 7 and 8. A branch that commits
   unheld stock deserves tests more than the two currently failing ones do.
4. **Resolve F5** by reading `ReserveOrderInventoryAction:218+` and testing a direct double call.

**None of this is a Pilot blocker:** the certified end-to-end flow proves shortage parks safely in
`AwaitingStock` without dispatching unreserved stock.

---

# 13 — EXACT NEXT TASK

**`TASK-GOLIVE-RESERVATION-POLICY-DECISION-001`** — owner answers Q1–Q3 (§11), then a bounded
engineering task: correct the two stale tests, add Scenario 6/7/8 coverage, and resolve the
duplicate-reservation contract. Estimated small; no architecture change if Q1 is confirmed.

---

**INVESTIGATION ONLY — honoured in full. No application code, test, database, migration, RBAC,
deployment or commit change. Phase 3, PD-1, PD-2, RC-10, RC-6 and D-8 untouched. No second
reservation engine considered. Nothing assumed about `allow_negative_stock` from its Manufacturing
usage — every statement above cites a file and line.**
