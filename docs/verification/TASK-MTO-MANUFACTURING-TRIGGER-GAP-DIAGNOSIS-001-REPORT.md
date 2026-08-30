# TASK-MTO-MANUFACTURING-TRIGGER-GAP-DIAGNOSIS-001 — Engineering Report

**Type:** Diagnosis only. **No live business data was mutated.** All inspection was read-only against `ecos_dev`.
**Date:** 2026-08-26
**Origin:** Escalated from TASK-DRIVER-CUSTODY-INVENTORY-TRANSFER-LIVE-CERTIFICATION-001 (owner chose Option 3 — fix the systemic trigger gap first).

---

## 1. Root cause (summary)

Made-to-order finished goods are never produced into warehouse stock, because the canonical
Manufacturing lifecycle **never fires**. There are **two independent breaks**, either of which alone
would prevent production:

- **BREAK B — the automated path omits the manufacturing trigger.**
  The normal, automated route that drives orders forward is the preparation **wave**:
  `WaveStarted` → `HandlePreparationWaveStarted` → `FulfillmentEngine::run(MoveToPreparationWorkflow)`.
  That path **reserves stock and sets the order to `ready_for_dispatch`, but never triggers manufacturing.**
  Manufacturing is invoked **only** by `PrepareOrderManufacturingAction`, whose **sole caller** is the
  **manual** operator endpoint `PrepareOrderAction` (`POST /orders/{id}/prepare`, `OrderController::prepare`).
  So any order that flows through the wave (i.e. the real production path) is never manufactured.

- **BREAK A — stale status vocabulary makes the trigger a no-op even when invoked.**
  `ManufacturingLifecycleHandler::supports()` gates on `['pending','processing','preparing']`
  (`ManufacturingLifecycleHandler.php:47-51`). **None of these exist in the ADR-042 Order FSM V3**
  (`in_progress`, `confirmed`, `ready_for_dispatch`, …). So even on the manual `prepare` path — which
  *does* call the trigger — the handler returns `false` → `StatusIgnored` → **Skipped**. Manufacturing is
  therefore dead on **both** paths, which is why there are **zero** manufacturing transactions system-wide.

---

## 2. Expected lifecycle (canonical)

```
Order (In Progress / Confirmed)
  → PREPARE: reserve + MANUFACTURE   ← production output posts here
        ManufacturingExecutor::produceFinishedGoods
        → warehouse on_hand += qty  (+ production_output ledger + FIFO receipt layer + manufacturing_transaction)
  → Ready for Dispatch
  → WAREHOUSE → VEHICLE transfer  (driver Confirm Received → TransferLoadedStockToVehicleAction → ShipStock)
        → warehouse on_hand -= qty  (+ vehicle_custody_transfer / sales_issue ledger; reservation consumed)
  → Vehicle custody (already credited at load)
  → Driver delivery
```

The four movements are distinct: **production output → warehouse** (missing), **warehouse → vehicle** (correctly refused), **vehicle custody** (present), **driver delivery** (out of scope).

## 3. Actual lifecycle (live, ORD-00014, from `order_events`)

| When | Event | Status change |
|---|---|---|
| 2026-08-21 07:18 | order_created (manual); awaiting warehouse; reservation postponed | — |
| 2026-08-24 15:21 | reservation_reserved (logical/negative commitment, FG on_hand 0) | — |
| 2026-08-24 15:21 | initiate_order → In Progress | → in_progress |
| 2026-08-25 08:00 | **ready_for_dispatch (wave start)** | in_progress → ready_for_dispatch |
| 2026-08-25 16:00 | returned_to_in_progress (`wave_ended`) | ready_for_dispatch → in_progress |
| 2026-08-26 08:00 | **ready_for_dispatch (wave start)** | in_progress → ready_for_dispatch |

**No `prepare` event, no manufacturing event.** `order_lines.manufacturing_started_at` is `NULL` for both
lines → `PrepareOrderManufacturingAction` **never ran** for this order. The `ready_for_dispatch` transitions
came from the **wave** path (`HandlePreparationWaveStarted`), which does not manufacture.

## 4. Missing trigger (precise)

- **`HandlePreparationWaveStarted`** (`Modules/Commerce/Orders/Application/Listeners/HandlePreparationWaveStarted.php`)
  runs only `MoveToPreparationWorkflow` — no `PrepareOrderManufacturingAction`.
- **`MoveToPreparationWorkflow`** (`Modules/Operations/Fulfillment/Application/Workflows/`) reserves and sets
  `ready_for_dispatch` (line 153); it does not manufacture. (V3 note in its docblock: "Preparing is an
  invisible engine state — orders stay In Progress.")
- **`PrepareOrderManufacturingAction`** is called by **exactly one** caller: `PrepareOrderAction` (the manual
  operator endpoint). The wave path does not use it.
- **`ManufacturingLifecycleHandler::supports()`** = `['pending','processing','preparing']` — stale vs ADR-042 V3.

## 5. Canonical event/service that SHOULD post production (identified with confidence)

```
PrepareOrderManufacturingAction  (Commerce/Orders)
  → OrderLifecycleCoordinator::handle
    → ManufacturingLifecycleHandler::handle          ← BREAK A here (stale supports())
      → ManufacturingApplicationService::manufactureProduct  (trigger_type='order_lifecycle')
        → (Workflow/Pipeline: availability + plan)
          → ManufacturingExecutor::execute
            → InventoryMutationAdapter::produceFinishedGoods
                on_hand += qty ; production_output ledger ; FIFO layer ; manufacturing_transaction
```

The seam that **should invoke** this in the automated flow is the wave-start path
(`HandlePreparationWaveStarted`) — it currently invokes reservation-only (`MoveToPreparationWorkflow`). ← BREAK B here.

## 6. Affected order path

**All orders driven by the automated preparation wave** (the normal path). The manual `prepare` endpoint
invokes the trigger but is (a) not part of the automated flow and (b) itself neutralised by BREAK A. Net: no
order in the environment has ever manufactured (`manufacturing_transactions = 0`; all 22 `order_lines.manufacturing_state = NULL`).

## 7. Why warehouse stock was zero

`InventoryMutationAdapter::produceFinishedGoods` is the **only** canonical writer that increments FG `on_hand`
for made-to-order goods (movement `production_output`). It never ran, so both FGs sit at `on_hand = 0`
despite having active recipes (`bills_of_materials`, v1, is_active) and being physically loaded onto the vehicle.

## 8. Why the Warehouse → Vehicle transfer correctly refused

At driver Confirm Received, `TransferLoadedStockToVehicleAction` → `ShipStockAction` found FG `on_hand = 0`
with `allow_negative_stock = false` → `InsufficientStockException`. **This is the approved rule working as
designed** (no overdraft for a product that forbids it). The transfer code is correct and deployed
(host = `ecos-dev-app`, sha256 match). The defect is strictly upstream (production output never posted).

## 9. Proposed systemic fix (for the separate implementation task — NOT done here)

1. **Fix BREAK A** — align `ManufacturingLifecycleHandler::SUPPORTED_STATUSES` with ADR-042 V3. The set must
   match the status the order actually holds when manufacturing runs. Because `MoveToPreparationWorkflow`
   flips to `ready_for_dispatch` *before* `PrepareOrderAction` calls manufacturing, the fix must also settle
   **ordering**: either manufacture while the order is still `in_progress`/`confirmed` (preferred — production
   precedes the dispatch-ready state), or have the handler accept the post-flip status. Owner/design decision.
2. **Fix BREAK B** — the automated wave path must trigger manufacturing the way the manual `prepare` does.
   Candidate seams (owner to choose the canonical one):
   - (a) `HandlePreparationWaveStarted` invokes `PrepareOrderManufacturingAction` after `MoveToPreparationWorkflow` (mirror `PrepareOrderAction`);
   - (b) fold manufacturing into the fulfillment pipeline so **both** manual and wave paths inherit it (single seam — preferred for "one writer");
   - (c) an event-driven trigger on the reserve/preparation transition.
3. **Idempotency & interactions** — preserve `manufacturing_state`/`plan_id`-unique guards (no double-produce
   on wave re-run), and confirm RM consumption honours each RM's `allow_negative_stock`.

## 10. Regression tests required (for the fix task)

- A made-to-order order carried through the **automated wave** (`WaveStarted`) posts `production_output`
  (FG `on_hand` increments) and sets each line's `manufacturing_state = Executed`.
- `ManufacturingLifecycleHandler::supports()` returns true for the V3 status(es) at which manufacturing runs.
- End-to-end: after production posts, the Warehouse → Vehicle transfer **succeeds** (on_hand decremented,
  one `vehicle_custody_transfer` row) — the full chain green.
- RM consumption respects `allow_negative_stock` (sufficient RM → no negative; permitted RM → negative allowed).
- Idempotency: re-running the wave / re-prepare does not double-produce (0 duplicate `manufacturing_transactions`).

## 11. Live-data status

**No mutation performed.** All findings are read-only. The custody-transfer code from
TASK-DRIVER-CUSTODY-INVENTORY-TRANSFER-001 is correct and deployed. ORD-00014, its inventory, product policies,
and reservations are unchanged. `allow_negative_stock` was **not** altered. No stock was created manually.

## 12. Reconciliation plan for the existing shipment (deferred — needs explicit owner authorization AFTER the fix)

Once the trigger gap is fixed and verified, reconcile ORD-00014 through the corrected canonical flow. Exact
impact captured for the driver-confirmed line only:

**Manufacture 1 × FG-HONEY-250** (canonical `manufactureProduct`) consumes, at warehouse `019f4e1c-2e1b…`:

| Raw material | consume | on_hand before → after | allow_negative | ok |
|---|---|---|---|---|
| PKG-JAR-250 (Glass Jar 250ml) | 1.00 | 540 → 539 | false | ✅ sufficient |
| RM-HONEY-01 (Raw Honey) | 0.25 | 100 → 99.75 | false | ✅ sufficient |

…produces 1 Honey FG (`on_hand 0 → 1`) + `production_output` + FIFO + `manufacturing_transaction`. Then
`TransferLoadedStockToVehicleAction` (Honey, qty 1): FG `on_hand 1 → 0`, reserved `6 → 5`, one
`vehicle_custody_transfer`/`sales_issue` row. Net warehouse FG on_hand returns to 0 (produce +1, ship −1); the
auditable change is the ledger provenance + reservation consumed + RM drawn down.

**ECOS-FG-000001 is excluded** from the transfer reconciliation: it is **not** driver-confirmed
(`driver_confirmed_at = NULL`), so no Warehouse → Vehicle transfer applies. Manufacturing it now (its RM
`ECOS-RM-000001` would go `0 → −1`, allowed since that RM permits negative) without a following transfer would
create a warehouse+vehicle double-count. Leave it until the driver confirms it through the corrected flow.

---

### Evidence index (read-only)
- `manufacturing_transactions` = 0; `order_lines.manufacturing_state` all NULL; ORD-00014 `manufacturing_started_at` NULL.
- ORD-00014 warehouse: FG-HONEY-250 & ECOS-FG-000001 `on_hand = 0`, `reserved = 6 / 15`, `allow_negative_stock = false`.
- Active recipes exist: `bills_of_materials` (2 rows, v1, is_active); components in `bill_of_material_lines`.
- No `vehicle_custody_transfer` / outbound `stock_ledger_entries` for either loading task.
- `ManufacturingLifecycleHandler.php:47-51` supported statuses vs `OrderStatus` (ADR-042 V3) — disjoint.
- `HandlePreparationWaveStarted.php` runs `MoveToPreparationWorkflow` only (no manufacturing).
