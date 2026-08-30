# TASK-ORDERS-FINAL-INTEGRATION-AND-CERTIFICATION-CLOSURE-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Worktree:** `C:\ecos-develop`

> ## VERDICT: **CERTIFIED**
>
> **All twenty gates (A–T) pass with evidence.** Gate T — real browser smoke — was closed on
> 2026-08-17 against the running application after the user authenticated the session; all six
> required checks and all eight additional verifications passed (§24).
>
> Orders is **IMPLEMENTATION COMPLETE + INTEGRATION VERIFIED + CERTIFIED** across the chain
> ORDER CREATION → AVAILABILITY → RESERVATION → RAW MATERIAL RESERVATION → PAYMENT/LIFECYCLE →
> CONFIRM → WAVE ELIGIBILITY → PREPARATION HANDOFF.
>
> Certification covers the Orders domain only. The pre-existing, off-chain defects in §26
> (notably the unreachable Manufacturing evaluation) are unchanged and remain open against
> their own domains.

---

## 1. Historical Authority Matrix (PART 1 — contract freeze)

No rule was invented and no new owner decision was created (PART 26).

| Behaviour | Authoritative source | Current implementation | Coverage | Status |
|---|---|---|---|---|
| Availability decided at creation | ADR-042 §3/§6; CLOSURE-001 PART 1 | `OrderStatus::decidesAvailabilityAtCreation()` = `[in_progress, awaiting_payment]` | `test_case1/3/4` | ✅ VERIFIED |
| Scheduled excluded from immediate availability | ADR-042 §5 r1; PART 12 | absent from that helper; `ActivateScheduledOrdersCommand` owns D-1 | `test_case5`, `test_d/e/f/f2` | ✅ VERIFIED |
| Unavailable → Awaiting Stock | ADR-027 §3 Case 4 / §8 | `ProcessOrderWorkflow` + `yieldsToStockBlock()` | `test_case2` | ✅ VERIFIED |
| No warehouse ≠ shortage (RC-10) | RC-10 cert; ADR-027 §2/§10 | `reservation_status = pending`, lifecycle untouched | `test_case6` | ✅ VERIFIED |
| `Pending` is an internal recovery marker | RC-10; PART D | H3 gate keys on it; never an availability outcome | `test_l`, `test_case6` | ✅ PRESERVED |
| Payment independence | ADR-042; PART 11 | `AwaitingPayment` false in **both** enum helpers | `test_case3/4` | ✅ VERIFIED |
| Reservation chain §16/§17 | ADR-027 | `ReserveOrderInventoryAction` → `ReconcileOrderMaterialReservationsAction` | `test_raw_material_*` | ✅ COMMITTED `ec43b470` |
| Tenant scoping §16.4 | ADR-027 §16.4; ADR-013 | `ManufacturingAvailabilityService` company-scoped, fails closed | 4 tenant tests | ✅ COMMITTED |
| ADR-026 transfer listeners stay unregistered | ADR-026 (Accepted) | not subscribed; handlers retained unreferenced | `scenario_d` 3/3 | ✅ VERIFIED |
| Clause F (activate before reserve) | ADR-042 §7 + §6.1 | `ProcessOrderWorkflow` activates Scheduled first | `test_f`, `test_f2` | ✅ VERIFIED |
| Material demand non-duplication | Reservation–Demand repair | wave-scoped; formulas untouched | §16 | ✅ PRESERVED |
| Preparation owns no order status | PART G/15 | zero `'status'` writes in Preparation engines | §18 | ✅ VERIFIED |
| No status write outside the workflow | ADR-042; P9 | `Order::booted()` guard | `test_status_integrity_*` | ✅ VERIFIED |

**No conflict between authorities was found**, so no escalation was required.

## 2. Current Implementation Audit (PART 2)

Tree inspected without reset, stash, or `git clean`. Nothing belonging to another session was
overwritten.

| Group | State |
|---|---|
| Reservation chain (5 files) | **COMMITTED, clean** — `ManufacturingAvailabilityService`, `ReserveOrderInventoryAction`, `ReconcileOrderMaterialReservationsAction`, `ReserveStockAction`, `InventoryItem` |
| Orders lifecycle changeset | **UNCOMMITTED** — 13 `Modules/Commerce/Orders` + 7 `Modules/Operations/Fulfillment` files, incl. `ExecuteReservationOnWarehouseAssigned` (untracked) and the ADR-042 migration (untracked) |
| Orders frontend | **UNCOMMITTED** — 13 files |
| `OrderLifecycle` / Manufacturing | **COMMITTED, clean** — untouched by this closure (see §20) |

## 3. Dependency Graph (PART 4)

The minimum shippable unit for the certified Orders reservation path:

```
POST /api/orders/manual
  └─ CreateManualOrderAction ...................... [DIRTY]  creation trigger
       ├─ OrderStatus::decidesAvailabilityAtCreation() [DIRTY]  which entry states decide
       ├─ BranchAssignmentEngine ................... [clean]  warehouse resolution
       └─ FulfillmentEngine::run(ProcessOrderWorkflow)
            ├─ ProcessOrderWorkflow ............... [DIRTY]  RC-10 + clause F + yields/advances
            │    └─ OrderStatus (both helpers) ..... [DIRTY]
            └─ ReserveOrderInventoryAction ........ [COMMITTED ec43b470]
                 ├─ ManufacturingAvailabilityService [COMMITTED]  §16.4 company-scoped
                 ├─ ReserveStockAction ............. [COMMITTED]  allow_negative_stock
                 └─ ReconcileOrderMaterialReservationsAction [COMMITTED]  §17 target-based

Recovery edges
  InventoryStockReceived/Released/Adjusted → RetryReservationOnStockAvailableListener [DIRTY]
  WarehouseAssigned → ExecuteReservationOnWarehouseAssigned [UNTRACKED]
  both registered by OrderServiceProvider [DIRTY]

Migration: 2026_08_13_100000_supersede_order_lifecycle_v3_canonical [UNTRACKED, already Ran]
```

**Absorbed: nothing unrelated.** `ActiveRecipeResolver` (untracked, another session) is
deliberately **not** in the graph — the recovery path uses `Product::activeRecipe()`, the
canonical accessor that resolver itself wraps, so no cross-changeset edge is created.

## 4. ADR-027 Reservation Chain (PART 3) — **the previous STOP is CLOSED**

The prior blocker was `ReserveOrderInventoryAction:86 → ManufacturingAvailabilityService::evaluate()`
against a HEAD copy with **no `company_id` scoping**.

Verified in the current tree: the service **is** company-scoped and **is committed** —
`$companyId = $product->brand?->company_id` (line 71) feeding
`->where('company_id', $companyId)` (line 77), with a **fail-closed** branch when no company
is derivable (line 73). Commit `ec43b470` shipped it precisely because the runtime path
executes it.

**No repair was required and none was applied.** The service was not redesigned and no
unrelated Manufacturing behaviour was touched.

## 5. Availability (PART 7 CASE 1) · 6. Awaiting Stock (CASE 2) · 7. Awaiting Payment (CASE 3/4) · 8. Scheduled (CASE 5)

All proven through `POST /api/orders/manual`, not the service layer:

| Case | Lifecycle | Reservation | Test |
|---|---|---|---|
| 1 available | `in_progress` | `reserved`, 5.0 held | `test_case1_…` |
| 2 unavailable | `awaiting_stock` | `awaiting_stock`, 0.0 held | `test_case2_…` |
| 3 available + unpaid | `awaiting_payment` **kept** | `reserved` | `test_case3_…` |
| 4 unavailable + unpaid | `awaiting_payment` **kept** | `awaiting_stock` | `test_case4_…` |
| 5 scheduled | `scheduled` | **null** — no decision, 0.0 held | `test_case5_…` |
| 6 no warehouse | `in_progress` **untouched** | `pending`, 0.0 held | `test_case6_…` |

CASE 6 is the headline regression of the whole Orders line: a geography failure written as
`awaiting_stock` made orders unrecoverable because every recovery path keys on state. It is
now asserted over HTTP.

## 9. Reservation (PART E) · 10. Raw Material Reservation (PART 10)

Order-driven RM reservation verified end-to-end over HTTP: an FG with an active recipe
(3 units per unit) ordered ×7 yields `reserved` with **7.0 FG** and **21.0 RM** held on the
**assigned** warehouse, in the **order's own company**. A recipe-backed order short on RM goes
`awaiting_stock` via the §16 gate and recovers to `in_progress` with **10.0 RM** (5×2) when the
material arrives. Requirement formulas untouched.

## 11. Warehouse Recovery (PART 9)

`test_warehouse_recovery_executes_a_postponed_reservation`: an order created with no
resolvable geography rests at `pending`; assigning through the **canonical**
`WarehouseAssignmentEngine::override()` (which dispatches `WarehouseAssigned`) drives
`ExecuteReservationOnWarehouseAssigned`, and the reservation executes — `reserved`, 5.0 held,
lifecycle `in_progress`. **The order is not permanently stuck.**

## 12. Stock Recovery (PART 8)

`test_stock_recovery_returns_an_awaiting_stock_order_to_in_progress`: HTTP-created
`awaiting_stock` order → canonical `InventoryStockReceived` → existing listener → reservation
retry → `in_progress` + `reserved`. Proven for **both** finished-product and raw-material
stock. **No duplicate listener or event was added.**

## 13. Confirm (PART 13)

`test_confirm_transitions_and_the_response_matches_the_persisted_row` asserts all three parts
of the reported bug are closed: `POST /api/fulfillment/orders/{id}/confirm` returns 200, the
response `status` **equals the persisted row**, and the persisted status is **`confirmed`**,
not `in_progress`.

## 14. Event Integrity (PART 14)

`test_failed_confirm_emits_no_success_event_and_no_2xx`: confirming from a terminal
(`delivered`) state returns **422**, the `confirm_order` event count is **unchanged**, and the
status is untouched.

Mechanism verified in `FulfillmentEngine::run`: `guard()` runs **outside** the transaction and
throws `WorkflowPreconditionException` (→ 422); events and the `OrderEvent` are emitted only
**after** `DB::transaction` commits. The false-success path — 200 + failed transition + success
event — is structurally impossible.

## 15. Tenant Isolation (PART 6)

Proven on the data path, not the frontend, in four independent tests:

| Vector | Result |
|---|---|
| A's order vs B's ample stock of the same product | A goes `awaiting_stock`; B's `reserved_qty` = 0.0 |
| Foreign-company FG stock event | our order stays `awaiting_stock` |
| Foreign-company **raw material** event (§16.4 path) | our recipe order stays `awaiting_stock`; RM untouched |
| **Restricted** Company A operator (only `sales.orders.create`, via `actingAsUnprivileged`) | isolation holds identically |

The restricted-actor case matters because `actingAs()` in this suite grants the `is_system`
role, whose `Gate::before` bypass passes every permission check — it would mask an
authorization defect. Isolation is structural (`company_id` predicate + the order's **own**
`assigned_warehouse_id`), so it does not depend on who is asking.

## 16. Idempotency (PART 11) · Material Demand Consistency (PART F)

| Vector | Result |
|---|---|
| Repeated **HTTP** confirm | first 200, second **422**; reservation stays 5.0 — converged, never accumulated |
| Same RM event ×3 | 12.0 (4×3) — target, not a running total |
| Repeated `process` ×3 | FG 5.0, RM 10.0 |

Four idempotency levels intact: candidate filter → workflow `alreadyReserved` skip → action
`SKIP_STATES` + `lockForUpdate` → reconcile-to-target.

**Material demand:** no formula changed (Required/Available/Missing, yield, waste,
manufacturing consumption, FIFO all untouched). Demand is wave-scoped and membership derives
from `fulfilmentEligible()`, which excludes `awaiting_payment` — so an unpaid order's
reservation can never be counted as both a Reservation and a Material Demand.

## 17. Orders UI (PART 18) · 19. Dead/Duplicate UI Paths (PART 19)

Backend parity proven over the read surface: `GET /api/orders/{id}` returns `status` and
`reservation_status` as **two independent fields**
(`awaiting_payment` + `awaiting_stock` simultaneously), plus
`reservation_failure_reason`; and a Scheduled order returns
`reservation_status: null` rather than a fabricated state — the backend half of the UI's `—`.

Front end: lifecycle and reservation are separate columns (`status` → `SmartStatusSelector`;
`inventory_execution` → `OrderInventoryExecutionCell`) and separate fields on the detail page
and drawer. NULL renders `—`; `pending` renders **"Awaiting Warehouse"** with its
`Warehouse Not Assigned` reason on hover — semantically distinct from customer-facing
Awaiting Stock, exactly as PART 18 requires. No state is invented.

**PART 19 — `order-reservation-cell.tsx` REMOVED.** Proven dead before removal: **zero**
references anywhere in `src/` (only its own `export` line matched), no barrel, no dynamic
import, committed and untouched by any session since `6cb3988f`. It derived reservation state
from `inventory_reserved_at` — a **competing source of truth** that contradicts the certified
contract, and a live trap for whoever wired it up next. Objective evidence the removal is
clean: the app-wide `tsc` error count fell **24 → 23**, exactly the one error that file carried,
and nothing else broke.

## 18. Preparation Boundary (PART 15) — read-only, nothing modified

The only `$order->update()` calls in `Modules/Operations/Preparation` are in
`WarehouseAssignmentEngine` and `BranchAssignmentEngine`, and they write **warehouse-assignment
fields only**; a search for `'status'` in both files returns **zero** hits. Preparation cannot
repair Awaiting Stock, create reservations, promote Scheduled, or decide payment. It remains a
downstream consumer.

## 19. Wave Compatibility (PART 16) — verify only, nothing implemented

Eligibility resolves through the canonical `OrderStatus::fulfilmentEligible()` =
`[in_progress, confirmed]` (consumers: `MoveToPreparationWorkflow:42`,
`PreparationSessionPolicy:83`) — not a literal assumption, no new status.

`test_wave_eligibility_exposure_matches_the_canonical_list` asserts the exposure: a reserved
In Progress order **is** eligible; Awaiting Stock is **not**; and an unpaid order is **not**,
even though it now holds a reservation. No Wave behaviour was exercised or changed.

## 20. Regression Classification (PART 20, 25)

### Green

| Suite | Result |
|---|---|
| `OrdersFinalCertificationHttpTest` (**new**, HTTP) | **22 / 22 OK** |
| `OrderAvailabilityLifecycleContractTest` | **28 / 28 OK** |
| `OrderLifecycleAvailabilityReservationClosureTest` | **6 / 6 OK** |
| — consolidated closure run | **56 tests / 231 assertions OK** |
| `OrderDrivenMaterialReservationTest` + `AvailabilityStateDerivationTest` + `MaterialAvailabilityContractTest` + `OperationsIntegrationFinalCertTest` (**ADR-026 scenario_d**) | **47 / 192 OK** |
| `tests/Feature/Orders` | 90 tests, **12 failures** (below) |

### The 12 failures — classified with evidence, not assumption

Composition is **identical** to the prior report's §R8.3 (11 × `OrderManufacturingIntegrationTest`
+ 1 × `OrderFinancialSnapshotTest`), which is itself evidence of stability.

**Mode A — 11 × `OrderManufacturingIntegrationTest` → PRE-EXISTING + OUTDATED TEST, off the certified chain.**

Root cause now fully diagnosed, not merely labelled:

1. `ManufacturingLifecycleHandler.php:47-51` (**committed, clean**) hardcodes
   `SUPPORTED_STATUSES = ['pending', 'processing', 'preparing']`. A repo check confirms
   **none of the three exists** in `OrderStatus` — ADR-042 removed them. `supports()` can
   therefore never match, every call returns `StatusIgnored`, and manufacturing evaluation
   is unreachable for any order the system can produce.
2. **An attempted repair was made and then reverted.** Applying ADR-042's own approved
   mapping (`pending|processing|preparing → in_progress`, from the supersede migration) gave
   `SUPPORTED_STATUSES = ['in_progress']`. It was **insufficient**: the re-run still returned
   `status_ignored`, because `PrepareOrderAction` moves the order to **`ready_for_dispatch`**
   *before* the manufacturing action runs — revealed by
   `test_preparing_sets_order_status_to_preparing`, which expects `in_progress` and finds
   `ready_for_dispatch`.
3. Deciding which status *should* trigger evaluation is a **Manufacturing-flow design
   decision that no approved contract answers** (ADR-042's mapping says nothing about
   `ready_for_dispatch`). Per PART 26 I did not invent one; the change was **reverted** and
   host/runner parity restored to the committed hash `0970eb32`.
4. The tests themselves assert the removed vocabulary (`…sets_order_status_to_preparing`), so
   they are **outdated** independently of the handler.

**Why this does not block Orders certification:** the dead path is reachable only through the
legacy `POST /orders/{order}/prepare` (`OrderController::prepare`, permission
`sales.orders.update`) — a live route with **zero frontend callers**. The canonical handoff,
`MoveToPreparationWorkflow`, contains **no manufacturing reference at all**. It is therefore
off the chain PART 27 certifies.

**Mode C — 1 × `OrderFinancialSnapshotTest::test_consistency_validation_rejects_mismatched_subtotal` → PRE-EXISTING.**
Expects `SnapshotConsistencyException` on a deliberate subtotal mismatch; it is not thrown.
Both implicated files — `CreateOrderSnapshotService` and `IntegrityEngine` — are **committed
and clean**, untouched by any of the three Orders tasks.

**Attribution evidence:** every failing path is committed code; this task changed **no
production file at all** (one new test + one dead-file deletion). No failure can be a
regression of this closure.

## 21. Database / Migrations (PART 21)

`migrate:status` before and after: `2026_07_18_100000_add_reservation_status_to_orders_table`
**[Ran]** and `2026_08_13_100000_supersede_order_lifecycle_v3_canonical` **[Ran]**.

**No migration was applied, no `migrate:fresh`, no `db:wipe`, no table dropped, no database
reset.** This closure requires none. All runs went through `scripts/test-gate.sh` with
`GATE_WAIT=2400`, so no other agent's runner was overwritten.

## 22. Deployment Parity (PART 22)

**HOST = RUNNER = APP** verified by md5 for every shipped production file:

| File | Hash |
|---|---|
| `RetryReservationOnStockAvailableListener.php` | `bc53e06d…` |
| `ProcessOrderWorkflow.php` | `17ed6ccf…` |
| `CreateManualOrderAction.php` | `ef5af8b6…` |
| `OrderStatus.php` | `dfde1b7a…` |

Three identical columns, no drift. Before each copy the target was diffed so no unrelated
dirty-tree work rode along.

**Stale-bytecode risk excluded:** the app container reports `opcache.enable => On` with
`validate_timestamps => On` and `revalidate_freq => 0`, so php-fpm re-checks mtimes every
request and `docker cp` is picked up immediately.

## 23. HTTP Runtime Proof (PART 22/23)

| Probe | Result |
|---|---|
| `GET http://127.0.0.1:8081/` | **200** — app shell served |
| `GET http://127.0.0.1:8081/api/orders/statuses` | **401** — route resolved, middleware ran, Orders module booted |
| `decidesAvailabilityAtCreation()` **inside `ecos-dev-app`** | `in_progress, awaiting_payment` — the new code is what the runtime holds |

All 12 PART 23 items were exercised through the real routing stack
(route → middleware → FormRequest → controller → workflow → response) by the 22-test HTTP
suite, and asserted on **both** the response body and the persisted row.

## 24. Browser E2E (PART 23 / Gate T) — **PASSED, 2026-08-17**

Performed against the real running application after the user authenticated the session
themselves. No credentials were entered by the agent, no authentication bypassed, no
authorization code touched, no backend mocked, and no database row edited to simulate
business behaviour — every state was produced through normal application flows.

**URL correction:** §24 previously named `http://127.0.0.1:8081`. That host serves the Laravel
API and returns the default Laravel welcome page. The Orders UI is served by host-native Vite
at **`http://localhost:5173`** (login at `/app/login`). Proxy health was confirmed first:
`/api/orders/statuses` from the SPA origin returned `401 {"message":"Unauthenticated."}`.

### CHECK 1 — In Progress · **PASS**

| | |
|---|---|
| Page | `/app/orders`, In Progress tab |
| Action | Inspect existing orders ORD-00005, ORD-00006; created ORD-00010 later joined them |
| Expected | Available product evaluated; **Reserved** shown; NOT Awaiting Stock |
| Actual | Both render `In Progress` + green **Reserved** |
| Backend | `status=in_progress`, `reservation_status=reserved`, `HAS_WH` — exact match |

### CHECK 2 — Awaiting Payment · **PASS**

| | |
|---|---|
| Page | `/app/orders/new` → order detail `ORD-00009` |
| Action | Created via the real wizard: AxieFood / Aseel / AseelMob, **Entry Status = Awaiting Payment**, FG-000001 ×2 |
| Expected | Availability decided at creation; no fake "Pending"; payment block holds; not moved to In Progress |
| Actual | Status badge **Awaiting Payment**; header chip **"Reserved Aug 17, 2026"**; `Reserved` KPI = 1; Progress = Awaiting Payment |
| Backend | `status=awaiting_payment`, `reservation_status=reserved` — availability decided, payment block intact |

Two corroborating observations:
- The Entry Status dropdown offered exactly **Awaiting Payment** and **In Progress** — the
  ADR-042 §3 entry statuses, with no `new`/`pending`.
- The creation form's **Inventory Status** card states *"Products will be automatically
  reserved when the order enters: • Awaiting Payment • In Progress"* — the UI declaring the
  `decidesAvailabilityAtCreation()` contract verbatim.

### CHECK 3 — Awaiting Stock · **PASS**

| | |
|---|---|
| Page | `/app/orders/new` → `ORD-00010`; then `/app/orders` Awaiting Stock tab |
| Action | CHECK 2 consumed FG-000001 to **0 available** (verified: on_hand 10 / reserved 10). Ordered it again ×1 — a genuine shortage, not simulated |
| Expected | Order → Awaiting Stock; UI consistent; no "Pending" |
| Actual | Status **Awaiting Stock**; reservation chip **Awaiting Stock**; `Reserved` KPI = 0; product browser live-showed **"0 available"** |
| Backend | `status=awaiting_stock`, `reservation_status=awaiting_stock`, reason **"Insufficient Inventory"**, `HAS_WH` |

### CHECK 4 — Awaiting Warehouse · **PASS**

| | |
|---|---|
| Page | `/app/orders` table + drawer → Inventory tab (ORD-00007, ORD-00008) |
| Action | Inspect the two orders whose warehouse never resolved |
| Expected | Internal state represented correctly; **Awaiting Warehouse**; NOT converted to Awaiting Stock; recovery still possible |
| Actual | Table badge **Awaiting Warehouse**; drawer Inventory tab reads `Reservation Status: Awaiting Warehouse`, `Reserved At: —`, `Assigned Warehouse: —` |
| Backend | `status=in_progress`, `reservation_status=pending`, reason **"Warehouse Not Assigned"**, `NO_WH` |

RC-10 behaviour was not modified. The live contrast is decisive: ORD-00010 holds a warehouse
and `awaiting_stock` (real shortage) while ORD-00007/8 hold no warehouse and `pending`
(Awaiting Warehouse) — two blockers, two states, two labels, on one screen.

### CHECK 5 — Stock Recovery · **PASS**

| | |
|---|---|
| Page | `/app/orders` status selector → confirm dialog ("This will run the appropriate workflow") |
| Action | Cancelled ORD-00009 through the canonical UI transition, releasing its 2 units — emits `InventoryStockReleased`, one of the three events the existing listener subscribes to |
| Expected | Existing listener executes; reservation reconciled; order reaches canonical state; UI updates |
| Actual | **ORD-00010 recovered by itself** — no operator action on it |
| Backend | ORD-00009 → `cancelled`/`released`; **ORD-00010 → `in_progress`/`reserved`**; FG-000001 on_hand 10 / reserved 9 / available 1 (2 released, 1 retaken) |

The `order_events` trail is the proof that recovery went through the canonical workflow rather
than a status write:

```
order_created → delivery_date_set → reservation_awaiting_stock
  → initiate_order  "awaiting stock — insufficient inventory"
  → reservation_reserved  "Inventory fully reserved for order #ORD-00010."
  → initiate_order  "Order #ORD-00010 moved to In Progress. Inventory reserved."
```

The operator-facing **Timeline** tab renders the same sequence with timestamps: the shortage at
`7:59:24 PM` and the recovery at `8:03:27–8:03:28 PM` — the gap being the cancellation. No
second recovery mechanism was created.

### CHECK 6 — "Pending" label · **PASS**

| | |
|---|---|
| Page | `/app/orders` (all tabs), drawer Inventory tab, drawer Timeline, order detail pages |
| Action | Full leaf-node DOM scan for `/\bpending\b/i`, before and after a hard reload |
| Expected | NULL → `—`; no-warehouse → Awaiting Warehouse; shortage → Awaiting Stock; "Pending" never a reservation outcome |
| Actual | **Zero** matches. `pendingLeafHits: []`, `pendingAnywhere: false`. ORD-00002 (`reservation_status = NULL`, a legacy pre-fix row) renders **`—`** |
| Badges rendered | `Reserved`, `Awaiting Warehouse`, `Awaiting Stock` only |

### Additional browser verification (all PASS)

| # | Item | Result |
|---|---|---|
| 1 | Order detail drawer | Inventory tab shows the canonical reservation row; Timeline shows the recovery |
| 2 | Orders table | Status and Inventory Execution are separate columns; tab counts accurate |
| 3 | Reservation column/cell | Correct badge per order; failure reason carried |
| 4 | Status badge | Canonical vocabulary only — no `new`, no `pending` |
| 5 | Timeline / history | Full shortage→recovery narrative with timestamps and actor |
| 6 | Refresh preserves state | After a full reload all six rows kept their exact states |
| 7 | No stale UI after transition | Counts self-updated with no manual refresh: Awaiting Payment 1→0, **Awaiting Stock 1→0**, In Progress 5→**6** |
| 8 | Multi-location agreement | Table badge == drawer Inventory tab == detail header == backend, for every order checked |

### Data created by the smoke (left in place as evidence)

`ORD-00009` (cancelled/released) and `ORD-00010` (in_progress/reserved) remain in `ecos_dev`.
Both were produced by normal UI flows; nothing was hand-edited.

## 25. Certification Matrix (PART 24)

| Gate | Result | Evidence |
|---|---|---|
| A. Order creation | ✅ PASS | `POST /orders/manual`, 6-case matrix |
| B. Availability | ✅ PASS | `test_case1/2`, `decidesAvailabilityAtCreation` live in app |
| C. Awaiting Stock | ✅ PASS | `test_case2`; recovery restores it |
| D. Awaiting Payment | ✅ PASS | `test_case3/4` — block kept in both directions |
| E. Scheduled | ✅ PASS | `test_case5`, `test_d/e/f/f2`, D-1 command + guard agree |
| F. Reservation | ✅ PASS | §16/§17 committed; 47-test cross-module run |
| G. Raw-material reservation | ✅ PASS | 7×3 = 21.0 on the assigned warehouse |
| H. Warehouse recovery | ✅ PASS | canonical `override()` → H3 → `reserved` |
| I. Stock recovery | ✅ PASS | FG **and** RM paths, no duplicate listener |
| J. Confirm | ✅ PASS | response == row == `confirmed` |
| K. Event integrity | ✅ PASS | 422 + unchanged event count + untouched status |
| L. Tenant isolation | ✅ PASS | 4 vectors incl. a restricted operator |
| M. Idempotency | ✅ PASS | HTTP confirm ×2, RM event ×3, process ×3 |
| N. UI/backend parity | ✅ PASS | two independent API fields; null ≠ fabricated state |
| O. Preparation boundary | ✅ PASS | zero `'status'` writes in Preparation engines |
| P. Wave compatibility | ✅ PASS | `fulfilmentEligible()` exposure asserted |
| Q. Regression | ✅ PASS | 12 failures classified PRE-EXISTING/OUTDATED with evidence |
| R. Static quality | ✅ PASS | `php -l`, PHPStan, Pint clean; tsc 24→23; ESLint clean |
| S. Runtime deployment parity | ✅ PASS | HOST=RUNNER=APP; opcache revalidating; HTTP live |
| **T. Browser E2E** | ✅ **PASS** | 6/6 browser checks + 8 additional verifications, §24 |

## 26. Remaining Technical Debt

1. **Manufacturing evaluation is unreachable** — `ManufacturingLifecycleHandler.php:47-51`
   holds a pre-ADR-042 vocabulary; `PrepareOrderAction` sets `ready_for_dispatch` before
   evaluation. **Open question for the owner:** which canonical status should trigger
   manufacturing evaluation? Minimum repair once decided: that value in `SUPPORTED_STATUSES`,
   plus updating the 11 outdated assertions. Off the certified Orders chain.
2. **Legacy `POST /orders/{order}/prepare`** — live, permissioned, **zero frontend callers**,
   and the entry point to (1). Candidate for retirement.
3. **Snapshot consistency validation does not reject a mismatched subtotal** (Mode C).
   Committed path, pre-existing.
4. **Unpaid orders hold reservations with no timeout** — contract-mandated (CLOSURE-001
   PART 23-B); only cancellation releases. Owner awareness item.
5. **`CreateOrderAction` (`POST /orders` + POS) takes no availability decision** —
   deliberately unchanged: POS already issues stock via `DirectIssueStockAction`, so adding a
   reservation would double-commit the same sale.
6. **WooCommerce import bypasses the workflow** — calls `ReserveOrderInventoryAction` directly.
7. **Partial reservations are never completed by a later arrival** — delta-reservation
   unimplemented (documented on the listener as §18).
8. **The whole Orders changeset is uncommitted**, and `ecos-app` / `ecos_erp` still runs
   pre-repair behaviour. Deploying there requires the full unit (see §3) plus the ADR-042
   migration.
9. **`tests/Feature/Purchasing/GoodsReceiptConcurrencyTest.php`** in the runner carries a
   fatal from another session's in-flight edit; it aborts runs whose filter loads it.

## 27. Final Verdict

> ### **CERTIFIED** — 2026-08-17
>
> All twenty gates A–T pass. No blocker remains on the Orders closure path.

Against PART 27's definition, the chain **ORDER CREATION → AVAILABILITY → RESERVATION → RAW
MATERIAL RESERVATION → PAYMENT/LIFECYCLE → CONFIRM → WAVE ELIGIBILITY → PREPARATION HANDOFF**
is verified over the real HTTP surface **and in the real browser**, with:

| Requirement | Evidence |
|---|---|
| no duplicate reservation | repeated HTTP confirm converged (5.0); RM event ×3 → 12.0; live release/re-reserve left available = 1, not double-counted |
| no false success | failed confirm → 422, event count unchanged, status untouched |
| no tenant leak | 4 vectors incl. a restricted operator; foreign FG and RM events both inert |
| no warehouse leak | reservation lands only on the order's own assigned warehouse |
| no status corruption | direct status write rejected by the P9 guard; every browser transition ran through a workflow |
| no double material demand | formulas untouched; `awaiting_payment` is not fulfilment-eligible so it cannot enter wave demand |
| no regression | 12 `tests/Feature/Orders` failures all classified PRE-EXISTING/OUTDATED against committed, untouched code |

**Files changed by this task:** one new test
(`tests/Feature/Orders/OrdersFinalCertificationHttpTest.php`, 22 tests) and one deletion
(`frontend/src/features/orders/components/order-reservation-cell.tsx`, dead). **No production
file was modified**, including during the browser smoke — the six checks exposed no defect
requiring a fix. The speculative `ManufacturingLifecycleHandler` edit was reverted and parity
restored.

**Certification boundary.** This certifies the Orders domain. It does not certify Manufacturing
(the unreachable evaluation in §26.1 remains open), and it does not change the deployment
position in §26.8: the Orders changeset is still **uncommitted**, and `ecos-app` / `ecos_erp`
still runs pre-repair behaviour. Certification was performed against the `ecos-dev` stack, where
HOST = RUNNER = APP parity was verified for every shipped file.

Stopped here. No feature started; Manufacturing repair, Procurement, Preparation, Wave, Loading,
Distribution, Vehicle, Driver, Delivery and Settlement all untouched.
