# TASK-ORDERS-AVAILABILITY-LIFECYCLE-COMPLETION-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Worktree:** `C:\ecos-develop`

> ## STATUS: IMPLEMENTATION COMPLETE
> ## FINAL CERTIFICATION = DEFERRED
>
> Per project policy, no full-system certification, no broad regression suite, and no
> E2E campaign was run. Final certification happens after all remaining ECOS ERP
> modifications are complete.

---

## 0. Headline

Most of the contract was **already implemented and already correct**. It was inspected,
confirmed against a green baseline, and **left untouched**.

Two genuine defects were found, proven with failing tests, and repaired:

| # | Defect | Clause | Where |
|---|---|---|---|
| **D-1** | An order blocked by its **RECIPE** (raw-material shortage) never recovered — the raw material that unblocks it is not on the order's own lines, so the recovery listener selected nothing. Operator had to retry manually. | **C, D, M** | `RetryReservationOnStockAvailableListener` |
| **D-2** | Three Order UI surfaces reported reservation state from the `inventory_reserved_at` **timestamp**, which is stamped on *every* reservation attempt including a total shortage. An order holding zero inventory rendered a green **“Reserved ✅”**. | **E, R** | `order-detail-page.tsx`, `order-detail-drawer.tsx` |

Both are the same class of error the task exists to eliminate: **conflating two facts into
one signal**.

---

## 1. Existing implementation inspected

Read in full before changing anything:

| Artefact | State found |
|---|---|
| `ProcessOrderWorkflow` | **Correct.** RC-10 (no warehouse → `reservation_status = pending`, lifecycle untouched); shortage → `AwaitingStock` only via `yieldsToStockBlock()`; Scheduled D-1 guard; clause-F activate-before-reserve. |
| `RetryReservationOnStockAvailableListener` | Correct for FG stock; **incomplete for raw materials** (D-1). |
| `ExecuteReservationOnWarehouseAssigned` | **Correct.** ADR-027 §15 H3, subscribed to canonical `WarehouseAssigned`. |
| `ActivateScheduledOrdersCommand` + `routes/console.php` | **Correct.** D-1 horizon (`now()->addDay()`), daily 00:05, `withoutOverlapping()`. Command filter and workflow guard agree on the same boundary. |
| `OrderStatus::yieldsToStockBlock()` / `advancesToInProgressOnReservation()` | **Correct.** Deliberate asymmetry on `Scheduled` verified against ADR-042 §5/§7. |
| `ReserveOrderInventoryAction` | **Correct** (committed `ec43b470`). §16 recipe gate, §17 material reconciliation, `allow_negative_stock`, row-lock idempotency. |
| `ReconcileOrderMaterialReservationsAction` | **Correct** (committed). Reconcile-to-target, ledger-derived “held”. |
| `ManufacturingAvailabilityService` | **Correct** (committed). §16.4 company-scoped via `Product → Brand → Company`, fails closed. |
| `CreateManualOrderAction` | **Correct.** Entry status per ADR-042 §3 (PICK-AND-STAY), auto-initiate only from `InProgress`. |
| `OrderResource` | **Correct.** Exposes `reservation_status`, `reservation_failure_reason`, `reservation_shortage_lines`. |
| Order list column | **Correct.** `OrderInventoryExecutionCell` already reads the canonical field. |

## 2. Previous implementation reused

`TASK-ORDERS-AVAILABILITY-LIFECYCLE-REPAIR-001` was inspected (its report, and every file
it changed). **Its architecture was not rebuilt and its work was not discarded.**

Baseline before any change: `OrderAvailabilityLifecycleContractTest` — **23 tests, 59
assertions, green.** Its blocker was a *commit/deploy* entanglement, not missing code, and
`ec43b470` has since shipped the §16/§17 chain.

Reused rather than duplicated: the same listener, the same subscription, the same
`FulfillmentEngine` + `ProcessOrderWorkflow` entry point, the same
`Product::activeRecipe()` accessor, the same `OrderInventoryExecutionCell` component, the
same contract test file. **No new listener, event, subscription, service, action or UI
primitive was created.**

## 3. Missing behaviour identified — D-1, and how it was proven

For a recipe-backed finished good, the thing that withholds the commitment is the ADR-027
§16 gate, and that gate closes on **raw-material** availability:

```
FG stock 0, RM stock 0
  → ReserveOrderInventoryAction:191  can_manufacture && manufacturingIsExecutable() == false
  → falls through to the shortage path (correct — clause B)
  → reservation_status = awaiting_stock, status = awaiting_stock
```

Then the raw material arrives:

```
InventoryStockReceived(productId = RAW MATERIAL)
  → reevaluate() ... whereHas('lines', product_id = RAW MATERIAL)
  → 0 candidates          ← the order's line is the FINISHED GOOD
  → order waits for ever on an event that, for it, never comes
```

Proven before repairing, by four new tests:

| Test | Before fix | After fix |
|---|---|---|
| `test_rm1_an_unexecutable_recipe_produces_awaiting_stock` | PASS (precondition) | PASS |
| `test_rm2_raw_material_arrival_recovers_the_order_automatically` | **FAIL** — stayed `awaiting_stock` | PASS |
| `test_rm3_replayed_raw_material_event_does_not_duplicate_the_reservation` | **FAIL** — `0.0` reserved | PASS |
| `test_rm4_a_foreign_company_raw_material_event_does_not_recover_our_order` | PASS (vacuous) | PASS (meaningful) |

## 4. Changes made

**Backend — 1 production file:**

`Modules/Commerce/Orders/Application/Listeners/RetryReservationOnStockAvailableListener.php`
- `reevaluate()` — the line-product predicate widened from `where('product_id', $productId)`
  to `whereIn('product_id', [$productId, ...$finishedGoodsConsuming])`. Every other
  predicate unchanged: `company_id`, the order's **own** `assigned_warehouse_id`,
  `OUTSTANDING_RESERVATION_STATES`, `RETRYABLE_STATUSES`, FIFO `orderBy('created_at')`.
- new `private finishedGoodsConsuming(string $materialId, string $companyId): array` —
  the reverse recipe edge.

**Frontend — 2 files, reservation-state display only:**

| File | Change |
|---|---|
| `order-detail-page.tsx` | KPI `reservedCount` now counts lines where `reserved_qty >= quantity`; header badge and Inventory-card field now render `OrderInventoryExecutionCell` from `reservation_status`. |
| `order-detail-drawer.tsx` | Inventory tab gains the canonical reservation-status row; `reservedAt` becomes a plain timestamp (`—` when absent) instead of a false “Not Reserved” label. |

**Not changed:** table columns, SmartToolbar, drawers/tabs architecture, filters, customer
intelligence, i18n keys (none added), payment lifecycle, `OrderStatus` cases, Required /
Available / Missing formulas, Wave, WooCommerce translation.

### 4.1 Why `finishedGoodsConsuming` is not a second recipe authority

The SQL join over `bill_of_material_lines` + `bills_of_materials` is a **prefilter only**.
Membership is then confirmed through `Product::activeRecipe()` — the canonical accessor.

This matters: `bills_of_materials` has **no unique constraint on (product_id, is_active)**
(documented on `ActiveRecipeResolver`), so several versions can carry `is_active = true`
while only the highest `bom_version_number` is the real recipe. A product whose *old*
version references the material but whose *current* one does not must not be selected, and
the prefilter alone cannot tell the difference. No rule is restated here.

## 5. New Order availability flow (unchanged — verified, not rewritten)

```
Order created → entry status per ADR-042 §3 (PICK-AND-STAY)
  ├─ InProgress      → ProcessOrderWorkflow runs now
  ├─ AwaitingPayment → waits for payment (availability never displaces it)
  └─ Scheduled       → waits for D-1

ProcessOrderWorkflow
  ├─ no warehouse  → reservation_status = pending, LIFECYCLE UNTOUCHED  (RC-10; 422 on the
  │                  explicit transition request — never AwaitingStock)
  ├─ FG available  → Reserved → InProgress, but only if advancesToInProgressOnReservation()
  └─ unavailable   → reservation_status = awaiting_stock ALWAYS;
                     status = AwaitingStock only if yieldsToStockBlock()
```

**Product unavailable ≠ warehouse unavailable** is preserved and separately asserted
(`test_missing_warehouse_does_not_become_a_fake_stock_shortage`).

## 6. Awaiting Stock recovery

Backend/domain-event owned; no frontend polling; no Orders page needed.

| Trigger | Subscribed | Recovers |
|---|---|---|
| `InventoryStockReceived` | yes | FG shortage **and now RM/recipe shortage** |
| `InventoryStockReleased` | yes | idem |
| `InventoryStockAdjusted` | yes (only when `onHandAfter > onHandBefore`) | idem |
| `WarehouseAssigned` | yes | postponed execution (`pending`) — H3 |
| `InventoryTransferred` / `WarehouseTransferCompleted` | **deliberately not** | ADR-026 Phase A boundary, guarded by a committed test. Handlers retained, unreferenced. |

## 7. Recipe / RM reservation

Order-driven RM reservation remains the single authority (`ReserveOrderInventoryAction` →
`ReconcileOrderMaterialReservationsAction`, ADR-027 §16/§17, committed `ec43b470`). Nothing
was reimplemented; no second reservation system; no `Reservation #1 + #2` for one target.
Only the **selection** of which orders get re-evaluated was widened.

## 8. Scheduled D-1 activation

Already implemented and correct — repaired only in the previous task, verified here, not
touched. Window opens at `requested_delivery_date - 1 day`; command filter and workflow
guard share that boundary so the command cannot select an order the guard then rejects.
Activation happens **before** availability is consulted (clause G), so a shortage at
activation yields `AwaitingStock` while a future-dated order is never ejected from
`Scheduled` by a shortage.

## 9. Payment interaction

Untouched. `AwaitingPayment` is excluded from `yieldsToStockBlock()` *and*
`advancesToInProgressOnReservation()` — a shortage cannot erase a payment block, and having
stock cannot declare an unpaid order In Progress. Asserted by `test_c` / `test_c2`.

## 10. Tenant isolation

Preserved, and extended to the new edge. §16.4 `ManufacturingAvailabilityService` was not
replaced. The reverse recipe lookup resolves ownership by **`Product → Brand → Company`** —
the same rule the gate uses to decide which inventory it may see — and **fails closed** when
no company is derivable. Company A can neither consume nor reserve Company B's inventory,
and no recipe is resolved across companies (`test_rm4`, `test_p`).

## 11. Negative stock interaction

Not redesigned, not touched. `ReserveStockAction` → `allow_negative_stock` (committed) is
intact; `ManufacturingAvailabilityService` treats a material as available when
`available > 0 || allow_negative_stock`. Availability stays conservative otherwise.

## 12. Reservation idempotency

Unchanged and re-proven at the new edge. Four levels: candidate filter → workflow
`alreadyReserved` skip → action `SKIP_STATES` + `lockForUpdate` → reconcile-to-target.
`test_rm3` fires the same RM event three times: `12.0`, not `36.0`. Scheduled activation is
idempotent by status filter; `withoutOverlapping()` covers the process level.

## 13. UI changes

Minimum, reusing the existing `OrderInventoryExecutionCell`. No redesign, no new primitive,
no new i18n key.

The defect, stated precisely: `ReserveOrderInventoryAction` writes
`'inventory_reserved_at' => now()` **unconditionally**, alongside every outcome including
`awaiting_stock`. So `Boolean(order.inventory_reserved_at)` was true for an order holding
nothing, and `pending` (no warehouse yet), `awaiting_stock` (real shortage) and
`partial_reserved` all collapsed into one green tick. Asserted by
`test_reserved_at_is_stamped_even_when_nothing_was_reserved`.

**Deliberately NOT built — a per-material breakdown on the Order screen.** Clause E is
satisfied by the canonical result (`reservation_status`) being visible on the Order, and by
the Warehouse/Inventory side showing the real commitment — which it does: RM reservations
are written to `inventory_items.reserved_qty` and `stock_ledger_entries`
(`reference_type = 'sales_order_material'`), asserted by `test_i_j_k_l` and `test_rm2`. A
new per-material panel would need a new API field plus a new UI section — a new feature, not
a missing state, and outside “add only the minimum UI change required. No redesign.”
**Recorded as a product decision, not delivered.**

## 14. Targeted tests

Runner: `GATE_WAIT=2400 scripts/test-gate.sh` inside `ecos-dev-testrunner` (never bare
phpunit). No `migrate:fresh`, no `db:wipe`, no other agent's process killed.

`OrderAvailabilityLifecycleContractTest` — **28 tests, 72 assertions, OK** (was 23/59).

Against the mandated minimum-proof list:

| # | Required proof | Test | Result |
|---|---|---|---|
| 1 | Available new product → normal lifecycle | `test_a` | PASS |
| 2 | Unavailable new product → Awaiting Stock | `test_b` | PASS |
| 3 | Stock available → automatic Awaiting Stock → In Progress | `test_g`, **`test_rm2`** | PASS |
| 4 | Available product with Recipe → RM reservation | `test_i_j_k_l` | PASS |
| 5 | Repeated stock event → no duplicate reservation | `test_m`, **`test_rm3`** | PASS |
| 6 | Scheduled before activation → remains Scheduled | `test_d`, `test_f2` | PASS |
| 7 | Scheduled at D-1 → In Progress / availability evaluated | `test_e` | PASS |
| 8 | Scheduled unavailable product → Awaiting Stock | `test_f` | PASS |
| 9 | Company isolation | `test_p`, **`test_rm4`** | PASS |
| 10 | Negative-stock behaviour intact | not touched; `can_commit`/§16.4 paths unchanged | N/A |

Suites covering the changed behaviour (79 tests total):
`OrderAvailabilityLifecycleContractTest` + `OrdersInventoryExecutionLifecycleTest` +
`OrderDrivenMaterialReservationTest` + `OrderReservationLifecycleTest` → **77 pass, 2 fail**
(both pre-existing, §17).

The full Inventory suite, the full Orders suite and platform-wide regression were **not**
run, per policy.

## 15. Static checks

| Check | Scope | Result |
|---|---|---|
| `php -l` | both changed PHP files | **PASS** |
| PHPStan (`phpstan.neon.dist`) | changed listener | **PASS — no errors** |
| Pint | both changed PHP files | **PASS** |
| `tsc -p tsconfig.app.json` | whole app | **0 errors in changed files** (24 pre-existing elsewhere, none in `order-detail-page.tsx` / `order-detail-drawer.tsx`) |
| ESLint | both changed frontend files | **PASS — clean** |

No unrelated baseline errors were fixed.

## 16. Deployment status

| Target | Action | Note |
|---|---|---|
| `ecos-dev-testrunner` | listener + contract test copied | verified by hash |
| `ecos-dev-app` (`ecos_dev`) | **DEPLOYED** — `RetryReservationOnStockAvailableListener.php` + `ProcessOrderWorkflow.php` | hashes match the tree; `finishedGoodsConsuming` confirmed present by reflection **inside the running container**. `ProcessOrderWorkflow` was included because the stack was missing *only* the already-approved clause-F activate-before-reserve block — same feature, not an unrelated change. |
| Frontend (dev) | **live via host-native Vite (:5173)**; `ecos-dev-nginx` has no `public/` mount and serves no bundle for this app | static-verified only — **not browser-verified** |
| `ecos-app` (`ecos_erp`) | **NOT DEPLOYED — STOPPED, dependency reported** | see below |
| Migrations | **none run**; this task needs none | no destructive operation, no `migrate:fresh` |
| Commit | **not committed** | worktree holds ~390 dirty paths from other in-flight sessions |

### 16.1 The `ecos-app` dependency — STOP, per clause U

`ecos-app` carries **none** of the previous task's repair (`0` matches for
`OUTSTANDING_RESERVATION_STATES` / `handleStockReleased` / `finishedGoodsConsuming`).
Deploying this task's file there would require another uncommitted changeset:
`ProcessOrderWorkflow.php`, `OrderStatus.php` (the `yieldsToStockBlock()` /
`advancesToInProgressOnReservation()` helpers this file's selection depends on),
`ExecuteReservationOnWarehouseAssigned.php`, the `OrderServiceProvider` subscriptions, and
the ADR-042 normalisation migration.

Per clause U this was **not** done. `ecos_erp` therefore still runs the pre-repair
behaviour, exactly as `TASK-ORDERS-AVAILABILITY-LIFECYCLE-REPAIR-001` §R8.4 recorded.

## 17. Known unrelated issues

1. **`OrderReservationLifecycleTest` — 2 failures, PRE-EXISTING, out of scope.**
   `test_reserve_idempotency_throws_already_reserved_exception` and
   `test_reserve_throws_on_insufficient_stock` expect `ReserveOrderInventoryAction` to
   **throw**. That file is **committed** (`ec43b470`), was not touched here, and its own
   docblock states the superseding contract: *“Does NOT throw InsufficientStockException
   for insufficient stock — that concern has moved to the returned status”*; `SKIP_STATES`
   returns the current status instead of raising. The test file contains **zero** references
   to the listener or to `InventoryStockReceived`, so this change cannot reach it. Obsolete
   expectations against a committed contract — **not modified merely to obtain green**
   (clause Q).

2. **Live-tree hazard.** Mid-session, `tests/Feature/Purchasing/GoodsReceiptConcurrencyTest.php`
   inside the testrunner began raising a fatal error (`Access level to ::post() must be
   public`) from another agent's in-flight edit. It aborts any run whose filter loads that
   file. Not caused here, not repaired here.

3. **Container drift (informational).** 33 files differ between the tree and
   `ecos-dev-testrunner` — all in DemandAnalysis, Branches, Purchasing and CRM, i.e. other
   agents' active work. **Every Orders / Fulfillment / Manufacturing path was verified in
   parity** before testing. Nothing was overwritten.

4. **Pre-existing, recorded not fixed:** a partial reservation is never completed by a later
   arrival (`PartialReserved` is treated as already-reserved; delta-reservation is
   unimplemented — the listener already documents this as §18). Order detail
   `wfDateReserved` and the drawer History row still label `inventory_reserved_at` as
   “Reserved”; both are chronology rows rather than state claims, so they were left alone.

5. **`order-reservation-cell.tsx`** carries 1 pre-existing `tsc` error and appears to be a
   legacy sibling of `order-inventory-execution-cell.tsx`. Not investigated — out of scope.

## 18. Final implementation status

> ### IMPLEMENTATION COMPLETE
> ### FINAL CERTIFICATION = DEFERRED

- Existing lifecycle implementation: **inspected, reused, left untouched where correct.**
- Correct availability behaviour: **verified** (available → canonical lifecycle;
  unavailable → Awaiting Stock; missing warehouse ≠ shortage).
- Automatic stock recovery: **completed** — the recipe/raw-material edge now recovers, which
  it previously never did.
- Recipe / RM reservation: **reused as-is** (ADR-027 §16/§17, committed).
- Scheduled D-1 activation: **verified correct**, unchanged.
- Correct UI visibility: **repaired** — reservation state now reads the canonical backend
  field on every Order surface instead of a timestamp.

Nothing was started in Preparation, Wave, Loading, Vehicle, Driver, Delivery, Settlement or
Accounting. No certified Procurement task was reopened. No `OrderStatus` value was deleted
or renamed; the excluded normalisation changeset was not started; WooCommerce translation
was not touched.
