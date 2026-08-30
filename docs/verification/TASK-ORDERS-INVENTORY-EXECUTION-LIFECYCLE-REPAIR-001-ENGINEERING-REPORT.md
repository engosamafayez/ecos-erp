# TASK-ORDERS-INVENTORY-EXECUTION-LIFECYCLE-REPAIR-001 — Engineering Report

**Date:** 2026-08-14
**Branch:** `develop`
**Scope:** Order Management / Order Lifecycle. No Preparation, Wave, Loading, Shipping,
Vehicle, Driver, Delivery, Settlement or Warehouse-Assignment redesign.

**Authoritative rule under repair:**
> A NEW ORDER DOES NOT MEAN AWAITING STOCK. Availability answers whether inventory
> blocks fulfilment; it is not the Order Status authority.

---

## 1. Current Behavior (as found)

New orders were landing in **Awaiting Stock**, and the state was being used as a
catch-all rather than as a statement about inventory.

Live evidence, gathered from both running stacks before any code change:

| DB | Order | `status` | `reservation_status` | warehouse | `reservation_failure_reason` |
|---|---|---|---|---|---|
| `ecos_dev` | ORD-00005 | `awaiting_stock` | `awaiting_stock` | assigned | Insufficient Inventory |
| `ecos_dev` | ORD-00006 | `awaiting_stock` | `awaiting_stock` | assigned | Insufficient Inventory |
| `ecos_erp` | ORD-00001 | `awaiting_stock` | `awaiting_stock` | **NULL** | **Warehouse Not Assigned** |

Two different blockers, one indistinguishable status.

**Honest finding on the `ecos_dev` rows.** FG-000001 stood at `on_hand = 5`,
`reserved = 5`, so `available = 0`; the five units were held by ORD-00001/3/4, all at
`ready_for_dispatch`. For ORD-00005/00006 the arithmetic was therefore *correct* — a
genuine finished-good block, not a fabricated one. The `ecos_erp` row is the older
geography-failure path and pre-dates the current code. The reported symptom was not one
bug but five contract violations, four of them live.

---

## 2. Root Cause

Traced path: `CreateManualOrderAction` → `BranchAssignmentEngine::assign` →
`FulfillmentEngine::run(ProcessOrderWorkflow)` → `ReserveOrderInventoryAction` → status write.

### D1 — Availability was acting as the status authority *(primary)*

`ProcessOrderWorkflow.php:156` and `MoveToPreparationWorkflow.php:111`:

```php
if ($reservationStatus === ReservationStatus::AwaitingStock) {
    $order->update(['status' => OrderStatus::AwaitingStock]);   // unconditional
}
```

The write was unconditional, so a shortage overwrote **any** lifecycle status.
`ExecuteReservationOnWarehouseAssigned` retries orders in `awaiting_payment`, and
`ProcessOrderWorkflow::guard` admits `scheduled` and `confirmed`. An unpaid order
therefore became "awaiting stock" and its payment blocker vanished from the only column
showing it; a confirmed order was silently un-confirmed, which ADR-042 §6 forbids.

The **success** path carried the mirror defect: a successful reservation forced
`InProgress` from every status except `Confirmed`, so having stock was enough to declare
an unpaid order In Progress.

### D2 — `awaiting_stock` as a catch-all

`ReserveOrderInventoryAction:281`: `$fulfilledLines === 0 => AwaitingStock`. An order with
no line, or with every quantity ≤ 0, reserved nothing and was routed to Awaiting Stock
despite no inventory block existing.

### D3 — Multi-line contract violated in the opposite direction

`ReserveOrderInventoryAction:282`: `$reservedLines === $totalLines - $skippedLines => Reserved`.
`$skippedLines` conflated "nothing to reserve" (quantity ≤ 0) with "could not reserve"
(a real shortage). A two-line order with line A reserved and line B entirely unavailable
satisfied the expression and was stamped **fully Reserved**, erasing the shortage.
ADR-027 §8 admits Reserved only when *every* line reaches `reserved_qty = quantity`.

### D4 — Automatic re-evaluation was one-fifth wired

Only `InventoryStockReceived` triggered a retry. `InventoryStockReleased`,
`InventoryStockAdjusted`, `InventoryTransferred` and `WarehouseTransferCompleted` all
raise availability and triggered nothing. In the observed data this was decisive: with
every physical unit already reserved, a **release** was the only event that could ever
free ORD-00005/00006, and nothing listened for it.

A secondary defect sat in the same listener: candidates were selected by
`status = awaiting_stock`. Once D1 is fixed, an unpaid order carries its block on
`reservation_status` alone, so that filter would have left exactly the orders this task
protects with no recovery path.

### D5 — Scheduled activation was D, not D-1

`ActivateScheduledOrdersCommand` selected `requested_delivery_date <= today`, and
`ProcessOrderWorkflow::guard` rejected anything later than today.

### D6 — A generic failure labelled as a stock shortage

`UpdateOrderAction:195` wrote `ReservationStatus::AwaitingStock` when re-reservation threw
(today only for a missing warehouse). Wrong claim, and the wrong recovery path: resumption
keys on `pending`, so orders parked there were picked up by nothing.

---

## 3. Availability Authority

**Reused, not rebuilt.** No second availability engine was introduced.

| Question | Authority |
|---|---|
| FG available quantity | `InventoryItem::availableQty()` — signed `on_hand − reserved` |
| FG decision tree | `ReserveOrderInventoryAction` (ADR-027 §3 Cases 1–4) |
| Recipe executability | `ManufacturingAvailabilityService` (ADR-027 §16.3, company-scoped) |
| Negative-stock commitment | `allow_negative_stock`, enforced inside `ReserveStockAction` |
| Active recipe | `Product::activeRecipe()` / `ActiveRecipeResolver` |
| RM requirement + reservation | `ReconcileOrderMaterialReservationsAction` (ADR-027 §17) |

No `stock > 0` shortcut was added anywhere. Warehouse, company, existing reservations and
`allow_negative_stock` are all respected exactly as the existing architecture defines them.

---

## 4. New Order Flow

```
Order created (PICK-AND-STAY entry status: in_progress | scheduled | awaiting_payment)
  → BranchAssignmentEngine assigns warehouse        (unchanged, §19)
  → ProcessOrderWorkflow
      → no warehouse?  reservation_status = pending, lifecycle UNCHANGED (ADR-027 §2/§10)
      → ReserveOrderInventoryAction  (FG tree, then ADR-027 §17 material reconcile)
      → reservation_status is ALWAYS written truthfully
      → lifecycle status changes only where the status admits it
```

Two new predicates on `OrderStatus` make the authority explicit and shared:

- `yieldsToStockBlock()` — may a shortage move this order to Awaiting Stock?
  `in_progress`, `awaiting_stock`, `on_hold`, `cancelled`.
- `advancesToInProgressOnReservation()` — may a successful reservation advance it?
  adds `scheduled`, still excludes `awaiting_payment` and `confirmed`.

The asymmetry is deliberate: `scheduled` appears in the second and not the first, which is
precisely what keeps scheduling and availability independent.

---

## 5. Awaiting Stock

Now means one thing: **at least one line carries real demand and none of the reservable
lines could be satisfied**. It is no longer produced by a missing warehouse, an
unresolved recipe, an absent reservation, a generic failure, or an empty order.

| Situation | `reservation_status` | lifecycle `status` |
|---|---|---|
| All reservable lines satisfied | `reserved` | unchanged or → `in_progress` |
| Some satisfied, some short | `partial_reserved` | unchanged or → `in_progress` |
| Nothing satisfiable | `awaiting_stock` | → `awaiting_stock` **only if the status yields** |
| No reservable line at all | `reserved` (vacuous) | unchanged |
| No warehouse | `pending` | **unchanged** |

---

## 6. Stock Re-evaluation

Five existing Inventory domain events now feed one listener. No new event, bus, queue or
architecture was introduced (§8).

| Event | Trigger condition |
|---|---|
| `InventoryStockReceived` | any receipt |
| `InventoryStockReleased` | any release (§22 — integrate, do not redesign) |
| `InventoryStockAdjusted` | only when `onHandAfter > onHandBefore` |
| `InventoryTransferred` | destination warehouse only |
| `WarehouseTransferCompleted` | destination warehouse only |

Candidate selection is bounded by `company_id` + `assigned_warehouse_id` + a line-level
`product_id` match, ordered FIFO by `created_at`. No full-table scan; a stock movement
never walks orders it cannot affect.

`StockAddedListener` (Preparation) was left untouched — it serves wave shortage
resolution, a different consumer of the same event, and §26 keeps Preparation out of scope.

---

## 7. Recipe Resolution

Unchanged and already canonical. `ReconcileOrderMaterialReservationsAction::deriveMaterialTargets()`
loads `lines.product.activeRecipe.components`, i.e. `Product::activeRecipe()` —
`ofMany('bom_version_number','max')` filtered to `is_active` on a SoftDeletes model, the
same rule `ActiveRecipeResolver` wraps for bulk callers. `yield_quantity` is honoured. No
first-BOM, no inactive recipe, no duplicated resolution logic.

---

## 8. Raw Material Reservation

Unchanged. `ReconcileOrderMaterialReservationsAction` computes a **target** per material,
compares it to what the order already holds (derived from the canonical stock ledger), and
applies only the delta — reservation, release, or no-op. Reconciliation, not accumulation,
which is what makes repeated re-evaluation safe (§21).

---

## 9 & 10. Order UI and Warehouse UI

**No frontend change was required, and none was made.** `order-column-defs.tsx:365` passes
`order.reservation_status` straight from the API into `OrderInventoryExecutionCell`, which
is a pure function of that value plus `reservation_failure_reason`. There is no mock, no
static array and no UI-only reservation state. The Inventory Execution column was already
telling the truth about a backend that was wrong — so the repair belonged in the backend,
exactly as §13 requires.

Both surfaces read the same row: the Order page renders `orders.reservation_status`, and
the warehouse figure is `inventory_items.reserved_qty` written by `ReserveStockAction`
through the stock ledger. One reservation, two views.

---

## 11. Scheduled Transition

Activation moved from D to **D-1**, in both places that must agree:

- `ActivateScheduledOrdersCommand` — selects `requested_delivery_date <= now()->addDay()`
- `ProcessOrderWorkflow::guard` — rejects only beyond `now()->addDay()`

Runs `dailyAt('00:05')` via `routes/console.php`, `withoutOverlapping()`. An order due the
20th activates at 00:05 on the 19th. It uses the existing FulfillmentEngine + workflow —
no new transition system, and no direct `orders.status = 'in_progress'` write.

A Scheduled order is never moved early by unavailability (§16), and never held back at its
activation point by it either — it activates, then normal availability logic applies.

---

## 12. Payment Interaction

`awaiting_payment` is excluded in **both** directions. A shortage does not overwrite it
(a payment block outranks an inventory one) and a successful reservation does not advance
it (having stock says nothing about whether the customer paid). The shortage is still
recorded on `reservation_status`, so the Inventory Execution column shows Awaiting Stock
while the Status column keeps Awaiting Payment.

---

## 13. Warehouse Scope

Unchanged (§19). Reservation always targets `order.assigned_warehouse_id`. No warehouse is
selected inside this task. The re-evaluation listener matches on the order's own assigned
warehouse and, for transfers, only on the **destination** warehouse.

---

## 14. Tenant Isolation

Structural, not incidental: `company_id` is a predicate on candidate selection, paired with
the order's own warehouse. Company A's order can never be satisfied by Company B's
inventory even when both stock the same product, and a receipt into Company B's warehouse
does not re-evaluate a Company A order.

---

## 15. Idempotency

Four independent levels:

1. Candidate filter — a reserved order is no longer selected, so a replayed event is a no-op.
2. `ProcessOrderWorkflow` skips reservation when already `reserved`/`partial_reserved`.
3. `ReserveOrderInventoryAction` skips `reserved`/`transferred`/`consumed`/`released`.
4. `ReconcileOrderMaterialReservationsAction` reconciles to a target rather than adding.

Level 1 is also what bounds re-entrancy: reserving raw materials can itself emit a release
event, but an order that has just reserved is no longer a candidate, so the cascade
terminates by construction.

---

## 16. Concurrency

`ReserveOrderInventoryAction` now takes `Order ... lockForUpdate()` inside its transaction
and re-reads `reservation_status` under that lock. Two availability events can select the
same order in the same instant — a receipt and a release, say — and the unlocked
pre-check alone would have waved both through to reserve the same quantity twice.

---

## 17. API

No API contract change. `OrderResource` already exposed `reservation_status`,
`reservation_failure_reason` and `reservation_shortage_lines`. Their **values** are now
correct.

---

## 18. Tests

`backend/tests/Feature/Commerce/OrdersInventoryExecutionLifecycleTest.php` — 22 cases
mapped to the §23 matrix. Every assertion reads persisted state (`orders`,
`inventory_items`, `stock_ledger_entries`), never a workflow return value. Stock movements
go through the real `ReceiveStockAction` / `ReleaseStockAction` so real events fire through
the real bus — nothing dispatches an event by hand, which is how the release path stayed
dead in the first place.

**Run against `ecos_dev_test` (`SELECT DATABASE()` verified): 22 tests, 53 assertions,
20 passed / 2 failed.** The two failures were `test_e` and `test_f`, both D-1 activation,
and both were a **real defect the suite caught** — the Carbon/string comparison in
`ProcessOrderWorkflow::guard` described in §19. The fix is in, and the same code path is
independently proven green at runtime by SCENARIO 4. A full-suite re-run to confirm 22/22
is queued behind test-database contention (see below) and is the one piece of evidence
still outstanding.

| § | Case | Test | Result |
|---|---|---|---|
| A | New order + available | `test_a_...` | PASS |
| B | New order + unavailable | `test_b_...` | PASS |
| C | Available + payment pending | `test_c_...`, `test_c2_...` | PASS |
| — | Shortage must not un-confirm | `test_c3_...` | PASS |
| D | Scheduled stays before D-1 | `test_d_...` | PASS |
| E | Scheduled activates at D-1 | `test_e_...` | fixed, re-run queued |
| F | Scheduled + unavailable → Awaiting Stock | `test_f_...` | fixed, re-run queued |
| — | Unavailability never moves Scheduled early | `test_f2_...` | PASS |
| G | Awaiting Stock + stock added | `test_g_...` | PASS |
| G2 | **Release by another order unblocks** | `test_g2_...` | PASS |
| H | Still insufficient → stays | `test_h_...` | PASS |
| I/J/K/L | Recipe → materials → reserved, warehouse agrees | `test_ijk_...` | PASS |
| Q | Only the ACTIVE recipe contributes | `test_q_...` | PASS |
| L/O | Reservation in assigned warehouse only | `test_lo_...` | PASS |
| M | Duplicate stock event | `test_m_...` | PASS |
| N | Repeated re-evaluation, qty + ledger | `test_n_...` | PASS |
| P | Cross-company denied | `test_p_...`, `test_p2_...` | PASS |
| 18 | Multi-line partial vs all-short | 2 tests | PASS |
| 2 | No reservable line ≠ Awaiting Stock | 1 test | PASS |

### Test-database contention (§28)

`ecos_dev_test` is shared, and a concurrent agent ran `phpunit` against it throughout this
task (`Modules/Sales/Customers/Tests`, then `WaveEngine/WaveOperationalCycleTest`).
`RefreshDatabase` calls `migrate:fresh`, so two agents drop each other's tables mid-run —
the table count was observed collapsing 416 → 28. Runs were therefore gated on a verified
idle window (no `phpunit` process **and** zero non-Sleep connections, sustained).

An attempt to sidestep the contention with a private schema (`ecos_dev_test_orders`) was
made and **abandoned**, and the reason is worth recording because it is deliberate, not a
defect: `tests/TestCase.php::setUp()` pins the database name in code —

```php
putenv('DB_DATABASE=ecos_dev_test');
$_ENV['DB_DATABASE']    = 'ecos_dev_test';
$_SERVER['DB_DATABASE'] = 'ecos_dev_test';
```

— and then resets the `Env` repository singleton, all before `parent::setUp()` boots the
app. Its own comment explains why: PHPUnit's `force="true"` only calls `putenv()`, while
Laravel's immutable Dotenv reads `$_ENV` first, so the Docker-baked value would otherwise
win and a suite could be pointed at the **runtime** database. That guard is correct and
must stay.

The consequence is that no suite can be redirected to another schema without editing a
shared tracked file, so **waiting for a genuine idle window is the only sanctioned path**.
The private schema was dropped and the shared database used, gated on idleness.

---

## 19. Runtime Evidence

All runtime proof below was executed against the **live dev runtime database `ecos_dev`**
(`SELECT DATABASE()` verified before each script), through the real Actions and the real
event bus. No mocks, no hand-dispatched events, no fabricated state.

### SCENARIO 3 — automatic re-evaluation, on the exact orders from the report

The two orders that prompted this task. Two units received via `ReceiveStockAction`;
**no order was touched by hand**, no refresh, no button, no status edit.

| | before | after |
|---|---|---|
| ORD-00005 `status` / `reservation_status` | `awaiting_stock` / `awaiting_stock` | `in_progress` / **`reserved`** |
| ORD-00006 `status` / `reservation_status` | `awaiting_stock` / `awaiting_stock` | `in_progress` / **`reserved`** |
| `reservation_failure_reason` | Insufficient Inventory | cleared |
| `inventory_items` | on_hand 5, reserved 5 | on_hand 7, **reserved 7** |
| `order_lines.reserved_qty` | 0 | 1 each |

The Order surface and the warehouse surface show the **same** reservation (§12), because
both read the one row `ReserveStockAction` wrote.

### SCENARIO 4 — Scheduled → In Progress at D-1

```
created ORD-SC4-24901  status=scheduled  delivery=2026-08-15  (today=2026-08-14)
--- running orders:activate-scheduled (no manual action) ---
Activating Scheduled orders due on or before 2026-08-15 (D-1 horizon)
  ACTIVATED  #ORD-SC4-24901 → in_progress
  Activated 1 | Skipped 0 | Failed 0
RESULT  status=in_progress  reservation=reserved  line_reserved=1
```

### Bug found *by* this certification

The D-1 tests failed on first run, exposing a **pre-existing** defect in
`ProcessOrderWorkflow::guard`. `requested_delivery_date` is cast `date:Y-m-d`, so the
attribute is a Carbon instance and the cast format governs only serialisation. The guard
did `(string) $order->requested_delivery_date`, yielding `"Y-m-d H:i:s"`, and compared it
to a `"Y-m-d"` string — `"2026-08-15 00:00:00" > "2026-08-15"` is true for **every** date.
Scheduled activation therefore never succeeded, before this task or after it; the command
logged a silent SKIP each night. Fixed by normalising to a day before comparing.

---

## 20. UI Evidence

Verified over real HTTP against the dev stack (`http://127.0.0.1:8081`), authenticated
with a server-issued token — the same API the SPA consumes.

`GET /api/orders`:

```
ORDER        STATUS             INVENTORY_EXEC     FAILURE_REASON
ORD-00001    ready_for_dispatch reserved           None
ORD-00002    in_progress        None               None
ORD-00003    ready_for_dispatch reserved           None
ORD-00004    ready_for_dispatch reserved           None
ORD-00005    in_progress        reserved           None
ORD-00006    in_progress        reserved           None
```

`GET /api/orders/{ORD-00006}` — Order Detail / Reservation information:

```
status                     in_progress
reservation_status         reserved
reservation_failure_reason None
reservation_shortage_lines []
inventory_reserved_at      2026-08-14T16:03:52+00:00
assigned_warehouse_id      019f4e1c-2e1b-7269-bfbb-8a414cb07cab
lines: qty=1  reserved_qty=1
```

`reservation_status` is exactly what `order-column-defs.tsx:365` feeds into the Inventory
Execution column, so that column now renders the **Reserved** badge and the Status column
renders **In Progress**.

### REAL E2E = PENDING USER BROWSER SMOKE

The browser pane was checked at `http://localhost:5173/app/orders` and returned the
sign-in screen; no authenticated session exists, and credentials must not be entered.
The following three surfaces are therefore **unverified at the rendered layer** and are the
user browser smoke to run:

1. **Orders list reservation state** — ORD-00005 / ORD-00006 Status column = *In Progress*
2. **Reservation badge** — Inventory Execution column = *Reserved* (emerald), not *Awaiting Stock*
3. **Order Detail drawer** — reservation information, no shortage lines

Each is already confirmed at the API layer that feeds it (above), so what remains is
rendering confirmation, not behavioural doubt.

---

## 21. Regression

Preparation, Wave, Loading, Distribution, Vehicle, Driver, Delivery, Settlement and
Warehouse Assignment were not modified.

---

## 22. Static Quality

| Gate | Result |
|---|---|
| PHPStan level 0, platform-wide | 1 error, in `Modules/Sales/Customers/Tests/Feature/CustomerPreferredGovernorateTest.php` — **untracked**, not in git history, authored by a concurrent agent; unrelated to this task and left alone per §27 |
| PHPStan core level 6 | **No errors** |
| Pint | 11 issues, identical to the HEAD baseline. `ProcessOrderWorkflow`, `MoveToPreparationWorkflow` and `OrderStatus` already failed at HEAD with the same fixer set; the four files new to this task are clean. **No new style debt.** |
| TypeScript / ESLint / Vite | Not applicable — no frontend change |

---

## 23. Contract Gaps

1. **`partial_reserved` never completes.** ADR-027 §8 declares
   `PartialReserved --> Reserved : Remaining Stock Arrives`, but `ProcessOrderWorkflow`
   treats `partial_reserved` as already-reserved and skips reservation entirely, so the
   transition is unreachable. Completing it needs delta-reservation, which the current
   engine does not implement. Deliberately **not invented here** (§18); `partial_reserved`
   is therefore excluded from re-evaluation candidates rather than selected and ignored.

2. **Order with no reservable line** has no ADR-027 state. Treated as vacuously `reserved`,
   since no shortage exists and the order must not be held out of the lifecycle. Worth an
   explicit ADR clause.

3. **`ecos_erp` stale rows.** ORD-00001 in `ecos_erp` still carries
   `awaiting_stock` + "Warehouse Not Assigned" from the superseded path. A data repair is
   out of this task's scope; `ReprocessLegacyReservationsCommand` already exists for it.

4. **No per-agent test isolation exists.** `ecos_dev_test` is a single shared schema and
   `tests/TestCase.php` pins it in code (correctly — see §18). Combined with
   `RefreshDatabase`'s `migrate:fresh`, two agents running suites concurrently destroy
   each other's schema mid-run, and neither can opt out. This is an infrastructure gap,
   not a defect in this task: it cost several full 8-minute run cycles here and will keep
   doing so. A per-worker database suffix (the usual `ParaTest`/`TEST_TOKEN` pattern) or
   an advisory-lock gate around the suite would remove it. Worth its own task.

---

## 24. Final Verdict

### NOT CERTIFIED — two layers outstanding

Every backend layer is implemented and evidenced; two certification layers cannot be
closed from here.

| Layer | Verdict |
|---|---|
| Backend | PASS |
| Availability authority | PASS — reused, no second engine |
| Order lifecycle | PASS |
| New order behaviour | PASS |
| Awaiting Stock behaviour | PASS |
| Automatic stock re-evaluation | PASS — proven on the reported orders |
| Recipe resolution | PASS |
| Raw material reservation | PASS |
| Order Reserved UI (API layer) | PASS |
| Warehouse reservation | PASS — same row, both surfaces |
| Scheduled → In Progress (D-1) | PASS at runtime; suite re-run queued |
| Payment interaction | PASS |
| Tenant isolation | PASS |
| Idempotency | PASS |
| Concurrency | PASS — row lock added |
| API | PASS |
| Runtime | PASS |
| Static quality | PASS — no new debt |
| **Full test suite green** | **OUTSTANDING** — 20/22 confirmed; the 2 failures are fixed and independently proven at runtime, but the confirming re-run is blocked by concurrent-agent contention on `ecos_dev_test` |
| **Rendered UI** | **OUTSTANDING** — API verified over real HTTP, but the visual Orders page (Status KPI cards, badge rendering, Order Detail drawer) needs an authenticated browser session, and signing in requires entering a password, which is outside what I may do |
| Regression (R/S/T) | **OUTSTANDING** — adjacent suites not yet re-run, same contention |

Certification should be re-issued once (a) the suite re-run confirms 22/22, (b) the
adjacent reservation/inventory/lifecycle suites are re-run, and (c) a signed-in operator
confirms the Orders page renders the states this report evidences at the API layer.
