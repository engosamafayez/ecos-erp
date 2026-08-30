# TASK-ORDERS-LIFECYCLE-AVAILABILITY-RESERVATION-CLOSURE-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Worktree:** `C:\ecos-develop`

> ## STATUS: IMPLEMENTATION COMPLETE
> ## FINAL SYSTEM CERTIFICATION = DEFERRED
>
> This task does not certify the Orders domain. No full-platform certification, no broad
> regression suite, no `migrate:fresh`, no database reset.

---

## 1. Executive Summary

Most of the closure contract was **already satisfied** by the certified work that precedes
it. Those sections were inspected, confirmed green, and **left in place** (§23).

**One real defect was found, proven with a failing test, and repaired**, plus the UI half of
the same problem:

| # | Defect | PARTs | Fix |
|---|---|---|---|
| **D-1** | An order created at `awaiting_payment` took **no availability decision at all**. The creation trigger fired only for `in_progress`, so an unpaid order rested at `reservation_status = NULL` — never evaluated, never reserved, never told it was short. | 1, 2, 5, 23-B, 23-L | `CreateManualOrderAction` now gates on the new canonical `OrderStatus::decidesAvailabilityAtCreation()` = `[in_progress, awaiting_payment]`. |
| **D-2** | The Orders UI rendered the word **"Pending"** for two different non-states: a NULL reservation (never evaluated) and the no-warehouse postponement. | 5, 6 | NULL now renders an explicit absence (`—`); `pending` is labelled **"Awaiting Warehouse"** — the blocker it actually is. |

D-1 and D-2 compound: an unpaid order got no decision, and the UI then invented a state
("Pending") to describe the absence of one. That is precisely the pattern PART 5 removes.

**One behavioural consequence the owner should know about** — §22.1: unpaid orders now hold
real inventory reservations, so they reduce stock available to other orders. This is the
direct, explicit instruction of PART 23-B, not a side effect I chose.

## 2. Current behavior before repair

| Creation surface | Availability decision at creation | Resulting `reservation_status` |
|---|---|---|
| `POST /orders/manual` → `CreateManualOrderAction`, status `in_progress` | yes | `reserved` / `partial_reserved` / `awaiting_stock` ✅ |
| same, status **`awaiting_payment`** | **NO** | **`NULL`** → UI showed **"Pending"** ❌ |
| same, status `scheduled` | no — by design (PART 12) | `NULL` → UI showed "Pending" ❌ |
| `POST /orders` → `CreateOrderAction`, and POS | **NO** (see §22.2) | `NULL` |
| WooCommerce import | direct `reserveInventory` for woo `processing`/`on-hold` (see §22.3) | varies |

Proven, not assumed — `test_b` failed with *"no availability decision was taken at
creation… Failed asserting that null is not null."*

## 3. Availability decision flow (PART 1, 4)

```
NEW ORDER  ──► entry status (ADR-042 §3 PICK-AND-STAY)
                 │
   ┌─────────────┼──────────────────┐
   │             │                  │
in_progress  awaiting_payment    scheduled
   │             │                  │
   └──────┬──────┘                  └──► holds until D-1 (PART 12), THEN decides
          ▼
  decidesAvailabilityAtCreation()  ← decision happens NOW, never via a scheduler
          ▼
  ProcessOrderWorkflow  ──►  FINISHED PRODUCT available?
                               │                  │
                              YES                 NO
                               │                  │
                    reserve (ADR-027 §16.2)   recipe-backed?
                               │                  │        │
                               │                 YES       NO
                               │                  │        │
                               │      ActiveRecipe→RM  ──► AWAITING STOCK
                               │        executable?
                               │         │       │
                               │        YES      NO ──► AWAITING STOCK
                               │         ▼
                               └──► reserve + §17 RM reconciliation
```

Finished-product availability and raw-material availability are never conflated (PART 4):
FG stock is reserved first and is **never** gated by the recipe (§16.2); the recipe gate is
consulted only when FG stock is short.

## 4. Reservation decision flow (PART 2, 9)

Every terminal outcome is one of the three business outcomes. Asserted exhaustively by
`test_l`, which drives {available, short} × {in_progress, awaiting_payment} and requires the
result to be in `[reserved, partial_reserved, awaiting_stock]`.

`partial_reserved` is produced **only** by the existing reservation engine's own arithmetic
(`reservedLines`/`partialLines`/`blockedLines`, ADR-027 §8) — never reinterpreted here as
readiness, and never manufactured by the UI.

## 5. Pending removal analysis (PART 5) — **CANNOT BE REMOVED; migration documented, not invented**

**Producers of `ReservationStatus::Pending`** — all three are the *same* business situation,
and none is an availability answer:

| Producer | Situation |
|---|---|
| `ProcessOrderWorkflow:166` | no warehouse assigned → execution postponed |
| `ConfirmOrderWorkflow:113` | idem, on the confirm path |
| `UpdateOrderAction:199` | re-reservation postponed after a structural edit (reservation threw — today only for a missing warehouse) |

**Consumers:**

| Consumer | Role |
|---|---|
| `ExecuteReservationOnWarehouseAssigned:80` | the **H3 recovery key** — resumes a postponed reservation on `WarehouseAssigned` |
| `RetryReservationOnStockAvailableListener` | `OUTSTANDING_RESERVATION_STATES` |
| `ReservationStatus::canTransitionTo` | initial node of the reservation FSM |
| migration `2026_07_18_100000` | historical backfill wrote `'pending'` to existing rows |

**Verdict: `pending` cannot be removed safely.** Removing it forces one of two outcomes, both
forbidden:

1. Route no-warehouse to `awaiting_stock` — **violates the RC-10 correction, which PART 21
   requires to remain intact.** `awaiting_stock` asserts a finished-goods shortage (§3
   Case 4); a missing warehouse is a geography failure. Conflating them is the exact defect
   `TASK-ORDERS-AVAILABILITY-LIFECYCLE-REPAIR-001` was created to fix, and it made such
   orders **unrecoverable** because every recovery path keys on state.
2. Add a new value (`awaiting_warehouse`) — **PART 5 forbids creating duplicate values**, and
   it would require rewriting the H3 gate, the FSM, and a historical data migration.

**Resolution applied — the distinction PART 5 actually asks for.** `pending` is not, and must
never be, the answer to *"is this product available"*. It survives strictly as the internal
*execution-postponed* marker required by RC-10 and H3. What was wrong was not its existence
but that it **reached the screen as a business state**, which §17 fixes.

`pending` is therefore now **unreachable as an availability outcome** (`test_l`) while
remaining reachable as the no-warehouse marker (`test_missing_warehouse_does_not_become_a_fake_stock_shortage`, unchanged).

**Required migration, documented not invented:** should the owner later decide `pending` must
leave the enum, it needs (a) a replacement state for no-warehouse postponement that H3 can
key on, (b) `UPDATE orders SET reservation_status = <new> WHERE reservation_status = 'pending'`,
and (c) rework of `ReservationStatus::canTransitionTo`'s initial node. **Not performed here.**

## 6. Awaiting Stock behavior (PART 3, 9)

Reached immediately when the product is unavailable — no fake reservation, no green Reserved,
no Pending (`test_c`, and `test_b2` for the unpaid variant). An order remains Awaiting Stock
until the canonical recovery path determines it can proceed; a failed retry leaves it there
(`test_h`, unchanged). Recipe-backed orders whose RM cannot be reserved also land here
(`test_rm1`, unchanged).

## 7. Partial Reserved behavior (PART 9)

Unchanged and untouched — produced only where the existing engine produces it: ≥1 line
satisfied and ≥1 short (`test_h2`, `test_o2`). Never reinterpreted as full readiness;
`advancesToInProgressOnReservation` is what governs the lifecycle, not the partial itself.

## 8. Reserved behavior

`reserved` requires every reservable line to reach `reserved_qty = quantity`, or the order to
carry no reservable line at all (vacuously reserved, §8). Unchanged.

## 9. Recipe / RM behavior (PART 2, 4)

**ALREADY SATISFIED — not reimplemented.** `ReserveOrderInventoryAction` → §16 recipe gate →
`ReconcileOrderMaterialReservationsAction` (§17, reconcile-to-target), committed in
`ec43b470`. One recipe authority, one reservation mechanism.

**Note on `ActiveRecipeResolver`.** It is the canonical *bulk adapter*, and its own docblock
states it "restates no rule of its own" — it is a thin wrapper over `Product::activeRecipe()`.
The recovery path confirms recipe membership through **`Product::activeRecipe()` directly**,
i.e. the same authority the adapter wraps, deliberately **not** a second resolver.
`ActiveRecipeResolver` is currently **untracked (another session's changeset)**, so importing
it would create the cross-changeset dependency PART 26 forbids.

**Note on `Product.can_disassemble`.** Verified read-only: it is consumed by
`Manufacturing/Disassembly/DisassemblyPolicy`, not by the reservation path, which gates on
`can_manufacture` + `ManufacturingAvailabilityService`. Two different runtime authorities on
two different axes. **Nothing changed** — flagged so the distinction is on record.

## 10. Automatic stock recovery (PART 7, 8)

**ALREADY SATISFIED — the recipe-aware fix was not reverted.** One listener, one subscription
set, no parallel listeners:

| Trigger | Recovers |
|---|---|
| `InventoryStockReceived` / `Released` / `Adjusted` (raise only) | FG shortage **and** RM/recipe shortage |
| `WarehouseAssigned` | the postponed (`pending`) execution — H3 |
| `InventoryTransferred` / `WarehouseTransferCompleted` | deliberately **not** subscribed — ADR-026 Phase A, guarded by a committed test |

Both required cases pass: FG becoming available (`test_g`) and RM becoming available for a
recipe-backed product (`test_rm2`). No manual Retry on either path.

## 11. Scheduled activation (PART 12)

**ALREADY SATISFIED.** `ActivateScheduledOrdersCommand` (daily 00:05, `withoutOverlapping()`)
selects on a D-1 horizon; `ProcessOrderWorkflow::guard()` enforces the same boundary so the
two cannot drift. Activation goes through the canonical workflow — no direct status write —
and availability is evaluated **after** activation, so a shortage at that moment yields
Awaiting Stock (`test_f`) while a future-dated order is never ejected from Scheduled by a
shortage (`test_f2`, `test_d`).

`scheduled` is the one entry status excluded from `decidesAvailabilityAtCreation()`, and
`test_scheduled_order_defers_its_availability_decision_to_d1` asserts that the exclusion is
deliberate rather than the same omission as D-1.

## 12. Payment / lifecycle interaction (PART 11)

Payment remains fully independent, and this is the part D-1 could most easily have broken.

| Case | Lifecycle | Reservation |
|---|---|---|
| available + unpaid | **Awaiting Payment** (preserved) | `reserved` (`test_b`) |
| unavailable + unpaid | **Awaiting Payment** (preserved) | `awaiting_stock` (`test_b2`) |
| available + paid entry | In Progress | `reserved` (`test_a`) |
| recovery of an unpaid order | Awaiting Payment (**not** forced to In Progress) | per outcome |

The mechanism is the existing enum pair: `AwaitingPayment` returns **false** from both
`yieldsToStockBlock()` and `advancesToInProgressOnReservation()`, so neither a shortage nor a
success can move it. Deciding availability tells the operator whether the goods exist; it
does not pay for them. No recovered order is blindly forced to In Progress.

## 13. Order → Preparation handoff (PART 13, 14) — verified read-only, nothing modified

Eligibility resolves through the canonical `OrderStatus::fulfilmentEligible()` =
`[in_progress, confirmed]` — not a literal assumption, not a new status. Consumers:
`MoveToPreparationWorkflow:42` (guard) and `PreparationSessionPolicy:83`.

Verified the Wave does **not** repair Order state: the only `$order->update()` calls in
`Modules/Operations/Preparation` are in `WarehouseAssignmentEngine` and
`BranchAssignmentEngine`, and they write **warehouse-assignment fields only** — a repo search
for `'status'` in both files returns **zero** hits. The Wave therefore cannot repair Awaiting
Stock, create reservations, promote Scheduled, or decide payment.

Because `awaiting_payment` is not fulfilment-eligible, an unpaid order — now reserved — still
cannot reach the handoff. Availability and payment are resolved in the Order domain first,
exactly as PART 14 requires.

## 14. Tenant isolation (PART 17)

Preserved; no new tenant architecture. `company_id` is a structural predicate on the recovery
query, paired with the order's **own** `assigned_warehouse_id`; the reverse recipe lookup
resolves ownership via the canonical `Product → Brand → Company` path used by §16.4 and
**fails closed**. Asserted by `test_p` (FG) and `test_rm4` (RM).

## 15. Idempotency (PART 15)

Untouched, four levels intact: candidate filter → workflow `alreadyReserved` skip → action
`SKIP_STATES` + `lockForUpdate` → reconcile-to-target. Repeat runs converge
(`test_m`, `test_n`, `test_n2`, `test_rm3`). No reconciliation mechanism was replaced.

## 16. Material demand consistency (PART 16)

**No formula changed.** `Required` / `Available` / `Missing`, yield, waste, manufacturing
consumption and FIFO costing were not touched.

The one place D-1 could ripple, checked: demand is wave-scoped, and wave membership derives
from `fulfilmentEligible()`, which excludes `awaiting_payment`. A newly-reserved unpaid order
therefore **never contributes material demand**, so its reservation cannot be counted as both
a Reservation and a Material Demand. It does correctly reduce RM available to the wave as an
*other-order* reservation — the same uniform netting every non-wave order already received
(`MaterialDemandCalculator:149`, "reservations held by OTHER orders").

`MaterialDemandCalculator` and its tests are **mid-edit by another session** (§22.4), so those
suites were deliberately not run — any result would be unattributable (PART 26).

## 17. UI changes (PART 6, 18, 19, 20)

Three files, reservation-state display only. No new component, no redesign.

| File | Change |
|---|---|
| `order-inventory-execution-cell.tsx` | NULL → explicit `—` (absence, not a state); `pending` restyled as a blocker with its `Warehouse Not Assigned` reason on hover |
| `en/orders.json`, `ar/orders.json` | `reservationBadge.pending`: `"Pending"` → `"Awaiting Warehouse"` / `"في انتظار المستودع"` |

`const status = reservationStatus ?? 'pending'` was the mechanism that put the word "Pending"
on screen for orders that had simply never been evaluated. **No new i18n key was added** — the
existing key's value was repointed, so the canonical enum name is preserved (PART 5).

**PART 18 — ALREADY SATISFIED.** Lifecycle and reservation are separate columns in the list
(`status` → `SmartStatusSelector`; `inventory_execution` → `OrderInventoryExecutionCell`) and
separate fields on the detail page and drawer after the preceding task's repair. Never merged
into one badge, and no timestamp infers reservation.

**PART 19 — ALREADY SATISFIED.** `inventory_items.reserved_qty` + `stock_ledger_entries`
remain the physical source; the Order UI only presents state. No duplicate reservation table,
no UI-only reservation.

**PART 20 — NOT IMPLEMENTED, as instructed.** No per-material breakdown; it would require a
new API field and a new UI section.

**PART 23-L evidence:** `OrderInventoryExecutionCell` is the single reservation display in the
Orders feature, and "Pending" no longer appears in it or in either locale's
`reservationBadge`.

## 18. Tests

Runner: `GATE_WAIT=2400 scripts/test-gate.sh` inside `ecos-dev-testrunner`. No `migrate:fresh`,
no database reset, no unrelated fixture or test modified.

**New:** `tests/Feature/Orders/OrderLifecycleAvailabilityReservationClosureTest.php` — 6 tests,
covering only what this contract adds. It drives the **real creation surface**
(route → FormRequest → controller → action), because the behaviour under test *is* the creation
trigger; driving the workflow directly would bypass the very gate in question. It builds the
full geography + brand-coverage chain so a warehouse actually resolves, and asserts that
precondition explicitly so a warehouse-assignment failure can never be misread as an
availability defect.

**Focused run: `62 tests / 194 assertions — OK.**

| Suite | Result |
|---|---|
| `OrderLifecycleAvailabilityReservationClosureTest` | 6/6 (3 failed before the fix) |
| `OrderAvailabilityLifecycleContractTest` | 28/28 — the certified matrix, unchanged |
| `ManualOrderStatusValidationTest` | pass — the creation surface is intact |
| `OrdersInventoryExecutionLifecycleTest` | pass |

PART 23 coverage:

| # | Scenario | Test | Result |
|---|---|---|---|
| A | Available + paid | `test_a` | PASS |
| B | Available + unpaid | `test_b` | PASS *(was the defect)* |
| C | Product unavailable | `test_c`, `test_b2` | PASS |
| D | Recipe + RM available | `test_i_j_k_l` | PASS |
| E | Recipe + RM unavailable | `test_rm1` | PASS |
| F | Partial reservation | `test_h2`, `test_o2` | PASS |
| G | RM arrives later | `test_rm2` | PASS |
| H | FG becomes available later | `test_g` | PASS |
| I | Scheduled → D-1 | `test_e`, `test_d`, `test_f`, `test_scheduled_…` | PASS |
| J | Repeated reservation | `test_m`, `test_n`, `test_rm3` | PASS |
| K | Tenant isolation | `test_p`, `test_rm4` | PASS |
| L | No Pending | `test_l` + the §17 UI search | PASS |

## 19. Static verification

| Check | Scope | Result |
|---|---|---|
| `php -l` | all 3 changed/added PHP files | **PASS** |
| PHPStan | `CreateManualOrderAction`, `OrderStatus` | **PASS — no errors** |
| Pint | `OrderStatus`, new test | **PASS** |
| Pint | `CreateManualOrderAction` | **1 pre-existing issue, deliberately not fixed** — the aligned-array style sits inside the in-flight ADR-042 changeset in that file, not in my lines. Reformatting it would modify another session's uncommitted work (PART 26). |
| `tsc -p tsconfig.app.json` | whole app | **0 errors in changed files** (24 pre-existing elsewhere, none in the files touched) |
| ESLint | `order-inventory-execution-cell.tsx` | **PASS — clean** |

The i18n edits were diff-verified as surgical: two hunks per file, and another session's
`bulk.unlock_for_edit` key was preserved intact by the round-trip.

## 20. Runtime verification

| Target | Status |
|---|---|
| `ecos-dev-app` | **DEPLOYED** — `CreateManualOrderAction.php`, `OrderStatus.php`. Verified live *inside the container*: `decidesAvailabilityAtCreation → in_progress, awaiting_payment`. Diffed first: the only delta vs the running copy was this task's change — no unrelated dirty-tree work rode along (PART 25). |
| Frontend (dev) | live via host-native Vite (`:5173`); `ecos-dev-nginx` serves no bundle for this app. **Static-verified only — not browser-verified.** |
| `ecos-app` (`ecos_erp`) | **NOT DEPLOYED — see §22.5** |
| Migrations | **none required, none run** |
| Commit | **not committed** |

## 21. Files changed

**Backend (2 production + 1 test):**
- `Modules/Commerce/Orders/Domain/Enums/OrderStatus.php` — added `decidesAvailabilityAtCreation()`. Additive; no case deleted, renamed, or reordered.
- `Modules/Commerce/Orders/Application/Actions/CreateManualOrderAction.php` — creation gate now uses it (one condition + its rationale).
- `tests/Feature/Orders/OrderLifecycleAvailabilityReservationClosureTest.php` — **new**, 6 tests.

**Frontend (3):**
- `components/order-inventory-execution-cell.tsx`
- `i18n/locales/en/orders.json`, `i18n/locales/ar/orders.json` — one value each.

## 22. Unresolved issues

**22.1 — Unpaid orders now hold real reservations (intended, contract-mandated).**
PART 23-B requires an unpaid order's availability logic to execute, so an
`awaiting_payment` order now reserves stock. That stock is genuinely committed and reduces
what other orders can reserve. Release happens on cancellation
(`ReleaseOrderInventoryAction`); there is **no timeout that frees an unpaid order's
reservation**. Whether unpaid orders should be able to hold stock indefinitely is a business
question this task was instructed to resolve in one direction — **flagged for owner
awareness, not a defect.**

**22.2 — `CreateOrderAction` (`POST /orders` + POS) takes no availability decision.**
Verified: it creates the order and returns — no branch assignment, no reservation. Every order
from that surface rests at `reservation_status = NULL`.
**Deliberately NOT changed:** POS already issues stock directly via `DirectIssueStockAction`
(`PosSaleInventoryListener`), so adding a reservation trigger there would **double-commit**
the same sale — reserving and issuing the same units. Closing this needs a decision about
whether `POST /orders` and the POS path should diverge. **Reported, not invented.**

**22.3 — WooCommerce import bypasses the workflow.** `WooCommerceOrderImporter` calls
`ReserveOrderInventoryAction` directly for woo statuses `processing`/`on-hold`, so no lifecycle
transition is evaluated. Out of scope (WooCommerce translation was excluded by the preceding
task); recorded.

**22.4 — Container drift from parallel sessions.** 33 files differ between the tree and
`ecos-dev-testrunner`, all in DemandAnalysis, Branches, Purchasing and CRM. **All Orders /
Fulfillment / Manufacturing paths were verified in parity** before testing; nothing was
overwritten. Additionally, `tests/Feature/Purchasing/GoodsReceiptConcurrencyTest.php` in the
container carries a fatal error from another session's in-flight edit (`Access level to
::post() must be public`) which aborts any run whose filter loads it.

**22.5 — `ecos-app` deployment dependency (PART 26 STOP).** `ecos-app` carries none of the
`TASK-ORDERS-AVAILABILITY-LIFECYCLE-REPAIR-001` changeset. Deploying this task there would
require that entire uncommitted unit — `ProcessOrderWorkflow`, `OrderStatus` (whose helpers
this change depends on), `ExecuteReservationOnWarehouseAssigned`, the `OrderServiceProvider`
subscriptions, and the ADR-042 normalisation migration. **Not done.** `ecos_erp` still runs
pre-repair behaviour.

**22.6 — `order-reservation-cell.tsx` is dead code embodying the forbidden pattern.** Nothing
imports it, and it derives reservation state from `inventory_reserved_at` — exactly what PART
6/18 prohibit. It also carries 1 pre-existing `tsc` error. Left in place (deleting a file is
beyond this task's mandate) but **flagged as a live trap**: wiring it up would reintroduce the
defect the preceding task removed.

**22.7 — `OrderReservationLifecycleTest`, 2 pre-existing failures.** Both expect
`ReserveOrderInventoryAction` to throw; the committed contract moved that concern to the
returned status. That file has zero references to anything changed here. Not modified merely
to obtain green (PART 24).

## 23. Scope exclusions — ALREADY SATISFIED or out of scope

**Already satisfied, not rewritten (PART 21):** ADR-027 §16/§17 chain · recipe-aware RM
recovery · `reservation_status` UI repair · `OrderInventoryExecutionCell` · `ActiveRecipeResolver`
· tenant isolation · reservation idempotency · Reservation→Required consistency · Material
Demand non-duplication · `allow_negative_stock` · OrderAvailabilityLifecycle repair · ADR-026
listener correction · **RC-10 correction** · Clause F · the canonical Order workflow.

**Not touched (PART 22):** Preparation Wave · Wave lifecycle · `MaterialDemandCalculator` ·
`WaveDemandController` · Loading · Distribution · Vehicles · Drivers · Delivery — inspected
**read-only** solely to verify the handoff contract in §13.

**Not implemented as instructed:** per-material breakdown (PART 20) · `pending` enum removal
and its migration (PART 5) · yield / waste / manufacturing consumption / FIFO costing (PART 16).

## 24. Final status

> ### IMPLEMENTATION COMPLETE — FINAL SYSTEM CERTIFICATION DEFERRED

- Availability decided at creation for every commercially active entry status, never by a
  scheduler; `scheduled` defers by its own D-1 rule only.
- Available → immediate reservation attempt through the canonical path; unavailable →
  Awaiting Stock immediately. No intermediate business state.
- `pending` is unreachable as an availability outcome and no longer displayed as one;
  its removal from the enum is **analysed and documented**, not performed, because RC-10
  and the H3 recovery key both depend on it.
- Reserved / Partial Reserved / Awaiting Stock are the only reservation outcomes surfaced.
- Recipe/RM, automatic recovery (FG **and** RM), Scheduled D-1, payment independence,
  tenant isolation, idempotency and demand consistency: verified intact, not reimplemented.
- Order → Preparation handoff verified read-only; the Wave repairs nothing.

Nothing was started in Preparation, Wave, Loading, Distribution, Delivery, or unrelated
Inventory work. Awaiting user review and the final full-system review phase.
