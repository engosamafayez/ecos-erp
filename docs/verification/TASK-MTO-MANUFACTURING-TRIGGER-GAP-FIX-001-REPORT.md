# TASK-MTO-MANUFACTURING-TRIGGER-GAP-FIX-001 — Engineering Report

**Type:** Systemic fix + isolated-test-DB verification. **No live business data mutated. ORD-00014 untouched. No live reconciliation performed.**
**Date:** 2026-08-28
**Precedes:** the deferred live reconciliation (still deferred — needs explicit owner authorization).
**Diagnosis of record:** `docs/verification/TASK-MTO-MANUFACTURING-TRIGGER-GAP-DIAGNOSIS-001-REPORT.md`.

---

## 1. Summary

Made-to-order finished goods were never produced into warehouse stock because the canonical
Manufacturing lifecycle never fired for wave-driven orders. This change makes the automated
preparation **wave** reach the **same canonical manufacturing trigger** the manual prepare
endpoint already used, and aligns the two stale order-status gates that neutralised the
trigger even when it was invoked. No second manufacturing engine was created; no manufacturing
logic was duplicated. The existing Warehouse→Vehicle custody transfer was **not** changed — the
tests prove it works correctly once the produced stock actually exists.

## 2. What the diagnosis got right, and the one thing it missed

The diagnosis named two breaks:

- **BREAK B (trigger omission)** — the wave path (`HandlePreparationWaveStarted` /
  `HandlePreparationWavePreparationStarted`) ran `MoveToPreparationWorkflow` only and never
  invoked `PrepareOrderManufacturingAction`. **Confirmed and fixed.**
- **BREAK A (stale status vocabulary)** — `ManufacturingLifecycleHandler::supports()` gated on
  `['pending','processing','preparing']`, none of which exist in ADR-042 V3. **Confirmed and fixed.**

**The diagnosis missed a SECOND stale status gate.** `ManufacturingPolicy` (Rule 2,
`MANUFACTURING_ALLOWED_STATUSES`) independently gated on the identical stale list
`['pending','processing','preparing']`. Empirically, after the handler alone was aligned, the
policy still rejected every real order with `OrderStatusNotAllowed` — the failing run reported
the exact message *"Order status 'in_progress' does not allow manufacturing. Allowed: pending,
processing, preparing."* Both gates had to be aligned to V3 for the trigger to fire. This is the
second half of BREAK A and is documented here because it was not in the diagnosis.

## 3. Changes (production)

| File | Change |
|---|---|
| `Modules/Operations/OrderLifecycle/Application/Handlers/ManufacturingLifecycleHandler.php` | `SUPPORTED_STATUSES` → `['in_progress','confirmed','ready_for_dispatch']` (BREAK A, gate 1). |
| `Modules/Manufacturing/ManufacturingPolicy/Domain/Services/ManufacturingPolicy.php` | `MANUFACTURING_ALLOWED_STATUSES` (Rule 2) → `['in_progress','confirmed','ready_for_dispatch']` (BREAK A, gate 2 — the gate the diagnosis missed). |
| `Modules/Commerce/Orders/Application/Listeners/HandlePreparationWaveStarted.php` | After `MoveToPreparationWorkflow` reaches Ready for Dispatch, invoke the canonical `PrepareOrderManufacturingAction::execute()` (BREAK B). |
| `Modules/Commerce/Orders/Application/Listeners/HandlePreparationWavePreparationStarted.php` | Same, for the automated WaveEngine path (BREAK B). |

Both listeners call the manufacturing trigger **after** the fulfilment transaction commits
(never wrapped in it), guarded on the order actually being `ready_for_dispatch` — mirroring the
manual `PrepareOrderAction` exactly. Per-order fault isolation (try/catch) is preserved, so a
single line's manufacturing failure is captured as line state, not a wave-halting rollback.

## 4. Answers to the required report items

**Exact canonical trigger used.** `PrepareOrderManufacturingAction::execute($order)` →
`OrderLifecycleCoordinator::handle` → `ManufacturingLifecycleHandler::handle` →
`ManufacturingApplicationService::manufactureProduct(trigger_type='order_lifecycle')` →
`ManufacturingExecutor::execute` → `InventoryMutationAdapter::produceFinishedGoods`. This is the
identical chain the manual `PrepareOrderAction` invokes. No new engine; the wave path is now a
second caller of the same action.

**Why the wave path now reaches it.** Both wave listeners run the fulfilment workflow and then,
only when the order became Ready for Dispatch, call `PrepareOrderManufacturingAction` — the seam
the manual path already used. Previously the action had exactly one caller (the manual endpoint),
so wave-driven orders (the real production path) never manufactured.

**How the V3 status correction affects eligibility.** `MoveToPreparationWorkflow` flips the order
to `ready_for_dispatch` *before* the trigger runs, so `ready_for_dispatch` is the operative status
at manufacture time. Both status gates now admit `in_progress | confirmed | ready_for_dispatch`
(ADR-042 §7 + the post-flip status). With the pre-V3 list, the handler answered `StatusIgnored`
and/or the policy answered `OrderStatusNotAllowed`, so the line was `Skipped` and nothing was ever
produced.

*(Manufacturing-transaction, production-output, warehouse and custody evidence, idempotency, and
full regression results are in §5, populated from the isolated-test-DB run.)*

## 5. Verification (isolated test DB `ecos_dev_test`, via `scripts/test-gate.sh`)

**Browser E2E: not observed.** All verification is backend integration on the isolated test DB
per requirement #9 ("use isolated test DB for all implementation verification"). A live/browser
run of the full wave→manufacture→load→deliver flow was deliberately NOT performed — it would touch
dev/live data and ORD-00014, which the task forbids, and the live reconciliation is explicitly
deferred.

**Final gated result: `OK (87 tests, 283 assertions)`**, exit 0, advisory lock acquired and
released cleanly (no contention). Suites, all green:

| Suite | Role |
|---|---|
| `WaveDrivenManufacturingTriggerTest` (new, 7) | The wave-driven E2E chain (below). |
| `OrderManufacturingIntegrationTest` (16) | Manual prepare path — restored from 12/16 red to green. |
| `OrderLifecycleCoordinatorTest` (~22) | Coordinator/handler contract at V3 vocabulary. |
| `ManufacturingPolicyTest` (~22) | Policy Rule-2 contract at V3 vocabulary. |
| `RecipeToOrderAvailabilityE2ETest` | Reservation/recipe-executability regression (unaffected). |
| `OrderPreparationFulfillabilityContractTest` | Fulfillability contract regression (unaffected). |

Command:
```
docker exec -e GATE_WAIT=2400 ecos-dev-testrunner ./scripts/test-gate.sh \
  tests/Feature/Orders/WaveDrivenManufacturingTriggerTest.php \
  tests/Feature/Orders/OrderManufacturingIntegrationTest.php \
  tests/Feature/Operations/OrderLifecycleCoordinatorTest.php \
  tests/Unit/Manufacturing/ManufacturingPolicyTest.php \
  tests/Feature/Manufacturing/RecipeToOrderAvailabilityE2ETest.php \
  tests/Feature/Orders/OrderPreparationFulfillabilityContractTest.php --no-coverage
```

**Baseline (before the fix):** `OrderManufacturingIntegrationTest` was **12/16 red**, the tell being
`Expected 'manufacturing_triggered', Actual 'status_ignored'` — the coordinator ignoring the real
V3 status. After the handler alone was aligned, the policy still rejected with the literal
`"…Allowed: pending, processing, preparing."`, exposing the second gate.

### Evidence proven by the wave-driven E2E (`WaveDrivenManufacturingTriggerTest`)

- **Canonical manufacturing transaction** — `test_wave_driven_…`: firing the real `WaveStarted`
  event leaves the order `ready_for_dispatch`, the line `manufacturing_state = Executed`
  (`manufacturing_started_at`/`_completed_at` set), and **exactly one** `manufacturing_transactions`
  row carrying `product_id`, `warehouse_id`, and `order_line_id` (RC-10). Before the fire:
  `manufacturing_transactions = 0`.
- **Production output → warehouse stock** — FG `on_hand` rises from **0** to the transaction's
  recorded `qty_produced` (and ≥ the ordered quantity), with a `production_output`
  `stock_ledger_entries` row whose `on_hand_before = 0` and `on_hand_after` = produced qty.
- **Raw materials consumed** — the component's `on_hand` falls and a `production_consumption`
  ledger row is written.
- **Engine path** — `test_engine_path_…`: the automated `WavePreparationStarted` listener
  manufactures identically (one transaction, `on_hand > 0`).
- **Idempotency** — re-firing the wave (order now `ready_for_dispatch`, terminal-filtered) and a
  direct re-invocation of `PrepareOrderManufacturingAction` both leave
  `manufacturing_transactions = 1` and FG `on_hand` unchanged (Executed-line guard).
- **Downstream Warehouse→Vehicle (existing path, unchanged)** —
  `test_manufactured_stock_transfers_…`: after production, loading `L` via
  `VehicleInventoryService::recordLoad` credits vehicle custody `0 → L`; then
  `TransferLoadedStockToVehicleAction` drives warehouse `on_hand` **down by exactly L** and
  `reserved` **down by exactly L**, writing one `sales_issue` / `vehicle_custody_transfer`
  ledger row. Reconciliation asserted: **warehouse decrement == vehicle custody credit == L**.
  A repeated transfer is a no-op (one ledger row; warehouse fell by exactly `L` total).
- **Negative control** — `test_non_mto_order_…`: a purchased, recipe-less good with physical FG
  stock reaches `ready_for_dispatch` and the wave path DOES reach manufacturing evaluation, but the
  policy rejects it → `Skipped`, **0** transactions, FG `on_hand` untouched, no `production_output`.

Absolute produced/warehouse magnitudes are asserted as invariants (on_hand rose from zero, equals
the transaction record, ≥ ordered qty) rather than fixed numbers, because of the §6 over-production
defect; the transfer/idempotency **deltas** are asserted exactly.

## 6. DISCOVERED DEFECT — manufacturing over-produces 2× (pre-existing, out of scope, owner decision)

Verifying the produced quantity precisely (this suite is the first to assert FG `on_hand`)
uncovered a **separate, pre-existing** defect: a made-to-order line produces **twice** the
ordered quantity.

**Root cause.** `InventoryAvailabilityEngine::analyse()`
(`Modules/Manufacturing/AvailabilityEngine/Domain/Services/InventoryAvailabilityEngine.php:52`)
computes `qty_to_manufacture = max(0, required − availableFg)`, and `availableFg`
(`EloquentInventoryReader::availableQty`) is `on_hand − reserved`. In ECOS's order-driven flow the
order's **own** reservation has already committed `required` on the finished good before
manufacturing runs (on_hand 0, reserved `required`), so `availableFg = −required` and the engine
computes `required − (−required) = 2 × required`.

**Scope.** This lives inside the manufacturing engine (the exact code this task must not modify),
it is **independent of the trigger gap**, and it hits the manual `PrepareOrderAction` path
identically — that path simply never had a test asserting `on_hand`, so it went unnoticed. The
directional E2E chain the task requires (fires → RM consumed → FG produced → warehouse increases →
transfer → custody, idempotent) is unaffected; only the produced magnitude is wrong. It is left
for a **separate task + owner decision** (the likely fix: base the finished-goods shortage on
physical `on_hand`, not `on_hand − reserved`, since ECOS has no manufacture-to-stock path). The
regression suite here asserts the exact transfer/idempotency **deltas**, which hold both before and
after any such fix.

## 7. What was deliberately NOT changed

`TransferLoadedStockToVehicleAction`, `ShipStockAction`, `allow_negative_stock` policy, the
Loading-Complete custody gate, driver confirmation semantics, Group finalization, Trip lifecycle,
driver delivery, Day Settlement, and Distribution logic are all untouched. The custody transfer
needed no change: once manufacturing posts real, reserved FG stock, the existing transfer draws it
down correctly (§5). ORD-00014 and all live data are untouched; the live reconciliation remains
deferred pending explicit owner authorization.

## 8. Pre-existing failures observed (NOT caused by this change)

- `DistributionSweepClosureTest::test_a_manual_group_holding_a_template_zone_yields_a_zoneless_duplicate`
  — a distribution group/zone defect the test itself documents ("THE DEFECT … yields a zoneless
  duplicate"); no inventory/manufacturing assertion; Break B's fault-isolated call cannot affect it.
- `OrderLifecycleCoordinatorTest` carried stale assertions of the already-removed `can_manufacture`
  Rule 3 (ADR-027 §16 v1.5). Those call sites also used the pre-V3 status vocabulary, so the same
  edit that aligned the status also switched their rejection mechanism to the still-valid
  missing-recipe rule. `ManufacturingPolicyTest`'s sibling already encoded the post-Rule-3 contract.
