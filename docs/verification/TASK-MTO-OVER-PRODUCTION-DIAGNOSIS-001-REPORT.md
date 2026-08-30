# TASK-MTO-OVER-PRODUCTION-DIAGNOSIS-001 — Engineering Report

**Type:** Diagnosis only. **No code changed. No live business data mutated.** Read-only.
**Date:** 2026-08-28
**Origin:** Discovered during TASK-MTO-MANUFACTURING-TRIGGER-GAP-FIX-001 verification (the first suite to assert produced FG `on_hand`). Deferred there as a separate defect; this report is that diagnosis.

---

## STATUS
DIAGNOSED — NOT FIXED (owner deferred the fix; this report only).

## 1. Summary

Made-to-order manufacturing produces **more finished goods than ordered — and consumes proportionally
more raw material** — because the manufacturing shortage is computed from `available = on_hand − reserved`
while the order's own demand is *already* booked as a reservation. The engine subtracts the reservation and
then adds the required quantity back on top, double-counting. It is **not a fixed 2× factor**; it scales
with the whole reservation pool for that finished good.

## 2. The defect (confirmed in code)

`InventoryAvailabilityEngine::analyse()`
(`Modules/Manufacturing/AvailabilityEngine/Domain/Services/InventoryAvailabilityEngine.php:49-52`):

```php
$availableFg      = $this->inventory->availableQty($warehouseId, $productId, $companyId); // on_hand − reserved
$qtyToManufacture = max(0.0, $requiredQty - $availableFg);                                // required − (on_hand − reserved)
```

`EloquentInventoryReader::availableQty()`
(`…/AvailabilityEngine/Infrastructure/Readers/EloquentInventoryReader.php:27`) returns
`InventoryItem::availableQty()` = **`on_hand_qty − reserved_qty`**.

ECOS is order-driven made-to-order and reserves the finished good **before** manufacturing:
`MoveToPreparationWorkflow` reserves and sets `ready_for_dispatch`, *then* `PrepareOrderManufacturingAction`
runs manufacturing (both the manual `PrepareOrderAction` and the now-fixed automated wave listeners follow
this order). So at manufacture time `on_hand = 0` and `reserved ≥ required`, giving `availableFg = −reserved`:

> **qty_to_manufacture = required + (reserved − on_hand)**   (when that is positive)

The `required` term and the `reserved` term represent the **same demand**, counted twice.

## 3. Severity — worse than "2×"

`availableQty` reads the **warehouse-aggregate** `inventory_items.reserved_qty` (every order's reservation
for that finished good at that warehouse), not the current line's. So each manufactured line is inflated by
the **entire reservation pool**, not merely its own quantity:

| Finished good (live ORD-00014) | line qty | on_hand | reserved (aggregate) | qty produced (buggy) |
|---|---|---|---|---|
| ECOS-FG-000001 | 1 | 0 | 15 | **16** |
| FG-HONEY-250   | 1 | 0 | 6  | **7**  |

The "2×" characterisation in `WaveDrivenManufacturingTriggerTest` is a **single-order fixture artifact**
(there `reserved = required`, so `required + required = 2×required`). In multi-order operation the factor is
`required + total_reserved` and is unbounded by the order's own size.

## 4. Propagation — both FG over-produced and RM over-consumed

- `ManufacturingPlanner::plan()` (`ManufacturingPlanner.php:77-78`) copies the inflated value straight into
  the plan: `qty_to_manufacture` and `finished_goods_to_produce` = `availability->qty_to_manufacture`.
- Per-component `qty_to_consume` = `recipe_qty × qty_to_manufacture`
  (`InventoryAvailabilityEngine::analyseComponent:127` → `ManufacturingPlanner::buildComponents:125`).
- `ManufacturingExecutor::execute()` then `produceFinishedGoods(qty_to_manufacture)` (over-produces FG,
  `on_hand +=` inflated qty + `production_output` ledger + FIFO layer) and `consumeComponent(...)` for each
  component (over-consumes RM, `on_hand -=` inflated qty + `production_consumption` ledger).

So a single manufacture over-produces finished goods **and** silently over-draws raw materials by the same
factor — the RM impact is the more damaging half (real materials consumed).

## 5. Scope / paths affected & why it was hidden

- Affects **every made-to-order line** in the order-driven flow, on **both** the manual `PrepareOrderAction`
  path and the automated wave path (both reserve before manufacturing).
- Hidden until now because (a) manufacturing never fired at all before the trigger-gap fix, and (b) no test
  asserted produced FG `on_hand` until `WaveDrivenManufacturingTriggerTest`, which — once it observed the
  overshoot — deliberately asserted **invariants** (on_hand rose from 0; equals the transaction record;
  ≥ ordered qty) rather than exact numbers, and asserted the transfer/idempotency **deltas** exactly. Those
  assertions remain valid before and after any fix.

## 6. Root cause

`available = on_hand − reserved` correctly answers *"how much finished good is free for **new** demand"* —
the make-to-stock question. A made-to-order manufacture exists to **fulfil** the reserved demand, so it must
produce the **physical shortage** (`required − physical_on_hand`), never re-subtract the reservation it is
satisfying. Using the "available-to-others" figure as the manufacturing basis is the category error.

## 7. Fix options (for a separate implementation task — owner decision)

**Option A — base the FG shortage on physical `on_hand` (recommended, surgical).**
`qty_to_manufacture = max(0, required − on_hand)`. Add an `onHandQty()` method to `InventoryReadInterface` +
`EloquentInventoryReader` and use it at `InventoryAvailabilityEngine:49`. Raw-material availability semantics
(line 128) are **left untouched** — the fix is scoped to the finished-good shortage. For pure made-to-order
(`on_hand = 0`) this yields exactly `required`.
*Edge case:* physical FG stock reserved by **other** orders would be treated as usable → possible
under-produce. Rare in pure MTO (on_hand is 0); the fix task should decide whether to guard it.

**Option C — reservation-aware (most precise).**
Exclude only the current line's **own** reservation:
`qty_to_manufacture = max(0, required − (on_hand − (reserved − own_reserved)))`. Correct even with shared
physical stock reserved by others, but requires threading order/line reservation context into a currently
product-scoped, order-agnostic engine (larger change, new inputs).

**Option B — ordering (not recommended).**
Manufacture **before** reserving the finished good, so `reserved = 0` at manufacture time. Correct but a
large change to the ADR-027 reservation/preparation lifecycle; high blast radius.

**Recommendation:** Option A, with the shared-stock edge case documented; escalate to Option C only if
physical FG stock coexisting with other-order reservations is a real scenario in this deployment.

## 8. Tests required by any fix

- A made-to-order line with `on_hand = 0`, `reserved = required` (and separately, `reserved > required` from
  other orders) produces **exactly `required`** finished goods and consumes **exactly `recipe_qty × required`**
  raw material — asserted as exact numbers (this is the assertion the current suite deliberately avoided).
- Physical-stock case: `on_hand ≥ required` → `qty_to_manufacture = 0` (no manufacture; stock used).
- Partial physical stock: `0 < on_hand < required` → produce `required − on_hand`.
- Confirm no existing manufacturing/availability unit test bakes in the inflated quantity (the invariant-based
  `WaveDrivenManufacturingTriggerTest` and the exact-delta transfer/idempotency assertions are safe).

## 9. Live-data status

**No mutation.** Read-only diagnosis. ORD-00014, inventory, reservations, and product policies are unchanged.
The deferred live reconciliation of ORD-00014 remains deferred — and this defect is precisely why an unfixed
reconciliation would have over-produced (16 ECOS-FG + 7 Honey for a 1+1 order).

## 10. Evidence index

- `InventoryAvailabilityEngine.php:49-52` (FG shortage) and `:127-129` (RM scaling).
- `EloquentInventoryReader.php:27` (`availableQty` = on_hand − reserved).
- `ManufacturingPlanner.php:77-78` (`qty_to_manufacture`/`finished_goods_to_produce` from availability) and `:125` (component `qty_to_consume`).
- `ManufacturingExecutor.php` / `InventoryMutationAdapter::produceFinishedGoods` + `consumeComponent` (execution consumes the plan quantities).
- Reserve-before-manufacture ordering: `MoveToPreparationWorkflow` (reserve → ready_for_dispatch) precedes `PrepareOrderManufacturingAction` in both `PrepareOrderAction` and the wave listeners.
- Live ORD-00014: both FGs `on_hand = 0`, `reserved = 15 / 6` (warehouse aggregate); the source of the 16 / 7 figures above.
