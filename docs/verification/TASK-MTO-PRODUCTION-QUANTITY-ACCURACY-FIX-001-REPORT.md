# TASK-MTO-PRODUCTION-QUANTITY-ACCURACY-FIX-001 — Implementation Report

**Title:** Fix MTO Production Quantity Accuracy / Reservation Double-Count
**Date:** 2026-08-28
**Status:** IMPLEMENTED & VERIFIED (isolated test DB) — not committed, not deployed to the live app, no live data mutated
**Scope of change:** the canonical manufacturing quantity source only (`InventoryAvailabilityEngine`)

---

## 1. Root cause confirmation

The finished-good (FG) manufacturing shortage is computed at exactly one place on the
canonical production path:

`backend/Modules/Manufacturing/AvailabilityEngine/Domain/Services/InventoryAvailabilityEngine.php`

Before the fix (line 52):

```php
$availableFg = $this->inventory->availableQty($warehouseId, $productId, $companyId);
// RC-1: partial manufacturing — only manufacture the shortage
$qtyToManufacture = max(0.0, $requiredQty - $availableFg);
```

`EloquentInventoryReader::availableQty()` returns the single warehouse row's
`InventoryItem::availableQty()`, which is **signed** and never clamped:

```php
// Modules/Inventory/InventoryItems/Domain/Models/InventoryItem.php
public function availableQty(): float
{
    return (float) $this->on_hand_qty - (float) $this->reserved_qty; // may be NEGATIVE
}
```

In ECOS's made-to-order flow the FG is reserved **before** manufacturing runs
(`MoveToPreparationWorkflow` reserves → `ready_for_dispatch`, *then*
`PrepareOrderManufacturingAction` triggers manufacturing). At manufacturing time the FG
therefore has `on_hand = 0` and `reserved ≥ required`, so `availableQty` is **negative**.
Feeding a negative value into `max(0, required − available)` does not shrink the shortage —
it **grows** it, because subtracting a negative re-adds the reservation.

Confirmed against current code, and reproduced empirically by the negative control in §8
(the aggregate-pool case produced exactly **16**, matching the diagnosis).

The single computation site was verified by search: `qty_to_manufacture` /
`qtyToManufacture` is **assigned** only in `InventoryAvailabilityEngine`; every other
occurrence (`ManufacturingPlanner`, `SimulateManufacturingResponse`) merely copies it. The
downstream chain is unchanged and untouched:

```
PrepareOrderManufacturingAction → OrderLifecycleCoordinator → ManufacturingLifecycleHandler
→ ManufacturingApplicationService → ManufacturingWorkflow → InventoryAvailabilityEngine (qty source)
→ ManufacturingPlanner (copies qty) → ManufacturingExecutor → InventoryMutationAdapter (produce FG / consume RM)
```

---

## 2. Exact before/after calculation

Let `required` = ordered/line quantity, `on_hand` = physical FG stock, `reserved` = warehouse
aggregate reservation on that (warehouse, product, company) row, `available = on_hand − reserved`.

**Before:** `qty_to_manufacture = max(0, required − available) = max(0, required − on_hand + reserved)`

When `available < 0` (i.e. `reserved > on_hand`, the routine MTO state):
`qty_to_manufacture = required − on_hand + reserved`. With `on_hand = 0`: **`required + reserved`**.

**After:** `qty_to_manufacture = max(0, required − max(0, available)) = max(0, required − max(0, on_hand − reserved))`

When `on_hand ≤ reserved`: `max(0, on_hand − reserved) = 0` ⇒ `qty_to_manufacture = required`.

| Scenario | on_hand | reserved | required | BEFORE | AFTER |
|---|---|---|---|---|---|
| Single MTO order | 0 | 1 | 1 | **2** | **1** |
| Multi-order reservation pool | 0 | 15 | 1 | **16** | **1** |
| RM scaling (recipe comp ×2) | 0 | 3 | 3 | produce **6** → consume **12** | produce **3** → consume **6** |
| Physical free stock covers it | 1 | 0 | 1 | 0 | 0 (unchanged) |
| Non-negative availability | 20 | 2 | 10 | 0 | 0 (unchanged) |

The fix is a **no-op whenever `available ≥ 0`** (make-to-stock and sufficiency cases are
byte-for-byte identical); it changes behaviour only in the negative-availability MTO case.

---

## 3. Why aggregate `reserved_qty` caused the defect

`InventoryItem.reserved_qty` is the **warehouse-aggregate** reservation across *all* orders
for that (warehouse, product, company) row — not the current line's reservation. When
availability is negative, `available = −reserved_pool`, and `required − available =
required + reserved_pool`. So a single line does not merely double (that "2×" is the
special case where `reserved == required`); it inflates by the **entire reservation pool**.
The diagnosis's live example (FG required 1, pool reserved 15) yields **16**, which the
negative control reproduces exactly (§8). This inflated `qty_to_manufacture` then scales raw
material consumption (`qty_to_consume = recipe_qty × qty_to_manufacture`), so both FG
over-production **and** real raw-material over-consumption stem from the same single value.

---

## 4. Chosen correction and why

**Clamp the free finished-goods position at zero before computing the shortage:**

```php
$availableFg       = $this->inventory->availableQty($warehouseId, $productId, $companyId); // signed, reported as-is
$freeFinishedGoods = max(0.0, $availableFg);                                               // free physical stock only
$qtyToManufacture  = max(0.0, $requiredQty - $freeFinishedGoods);                          // RC-1
```

Why this rather than the literal Option A (`max(0, required − on_hand)`):

- It uses the **existing canonical abstraction** (`availableQty()`); no new interface
  method, no order-awareness, **no second reservation system**.
- It preserves the original business meaning — "only manufacture the shortage beyond
  **free** stock." The only defect was that "free" was allowed to go negative; clamping it
  at zero is the minimal, faithful correction.
- It is **strictly safer than bare `on_hand`**. Using `max(0, on_hand − reserved)` keeps
  stock that is physically present but reserved for **other** orders committed to them, so
  the engine neither over-produces (the original bug) nor under-produces (Option A's
  failure mode — see §5). This directly satisfies the task's instruction to prefer a safer
  equivalent when inspection proves one exists.

The reported `available_finished_goods` field is deliberately left **signed** (unchanged), so
telemetry still shows the true reservation state; only the shortage arithmetic changed. The
value object's docblock was updated so no consumer re-derives the shortage from the signed
field.

---

## 5. Shared-stock edge-case analysis (the task's CRITICAL section)

**Question:** is Option A (`required − on_hand`) safe for ECOS MTO semantics, or is
order-aware reservation exclusion required?

**Finding: Option A is NOT safe.** Bare `on_hand` ignores reservations, so it treats
physical stock reserved for **other** orders as free and **under-produces**:

- *Shared stock:* `on_hand = 1` (reserved for order X), new order Y `required = 1` →
  Option A produces `max(0, 1 − 1) = 0`, leaving Y unbacked and double-booking X's unit.
- *Multi-order wave accumulation:* during a wave, sibling orders manufacture into the shared
  warehouse row and reservations are released only later (at shipment/loading — proven by
  `WaveDrivenManufacturingTriggerTest`, which asserts `reserved` falls by the loaded qty at
  transfer time). So `on_hand` accumulates while `reserved` stands. Option A: order 1
  produces 1 (`on_hand` 0→1); order 2 sees `on_hand = 1`, produces 0; … total 1 for N
  orders — catastrophic under-production.

**The clamp handles both correctly** because during the MTO wave `reserved ≥ on_hand`
throughout, so `free = max(0, on_hand − reserved) = 0` and each line produces its full
`required`:

| Case | on_hand | reserved | required | bare on_hand (Option A) | clamp (chosen) |
|---|---|---|---|---|---|
| Shared stock (reserved for others) | 1 | 1 | 1 | 0 ❌ under | **1 ✅** |
| Wave, order k (siblings produced k−1) | k−1 | N (≥k) | 1 | 0 ❌ under | **1 ✅** |
| Genuine free stock | 3 | 1 | 5 | 4 ❌ over-uses reserved unit | **3 ✅** |

**Is order-aware reservation exclusion required?** **No.** For the ECOS MTO model (FG
reserved before manufacture; `reserved ≥ on_hand` throughout the wave; MTO products carry no
make-to-stock surplus) the clamp yields the exact correct quantity in every realistic case,
**without** making the engine order-aware. See §16 for the one bounded residual and why it
does not arise for MTO.

The chosen behaviour is pinned by test
`test_physical_stock_reserved_for_other_orders_is_not_treated_as_free` (on_hand 1, reserved 1
for others, required 1 → produce 1, not 0).

---

## 6. Files changed

Only four files (two source — one behavioural + one doc-only; two new tests). **No other
file in the worktree was touched** (verified via `git status`; the large pre-existing
uncommitted work from other tasks was left intact).

| File | Change |
|---|---|
| `backend/Modules/Manufacturing/AvailabilityEngine/Domain/Services/InventoryAvailabilityEngine.php` | **Behavioural:** clamp free FG at zero before the shortage (1 substantive line + explanatory comments) |
| `backend/Modules/Manufacturing/AvailabilityEngine/Domain/ValueObjects/AvailabilityResult.php` | **Doc-only:** clarified `available_finished_goods` (signed) and `qty_to_manufacture` (inner `max(0, …)`) docblocks |
| `backend/tests/Feature/Manufacturing/MtoProductionQuantityAccuracyTest.php` | **New** — engine-level exact-number tests (9) |
| `backend/tests/Feature/Orders/MtoManufacturingQuantityIntegrationTest.php` | **New** — full-chain manual + wave exact-number tests (5) |

Deliberately **not** changed: `InventoryItem::availableQty()` (kept signed by design);
`ManufacturingAvailabilityService` (a separate raw-material *status* check, not the
production-quantity path); reservation, custody, loading, delivery, driver, distribution,
finance, and order-status code (all out of scope).

---

## 7. Exact tests added / changed

**New — `MtoProductionQuantityAccuracyTest` (engine level, real reader + seeded `InventoryItem`s):**

1. `test_single_mto_order_manufactures_exactly_required_not_double` — on_hand 0, reserved 1, required 1 → `qty_to_manufacture == 1.0` (not 2); `available_finished_goods == -1.0`; RM required 1.0.
2. `test_aggregate_reservation_pool_does_not_inflate_a_single_line` — on_hand 0, reserved 15, required 1 → `qty_to_manufacture == 1.0` (not 16).
3. `test_free_physical_on_hand_satisfies_requirement_no_manufacturing` — on_hand 1, reserved 0, required 1 → 0, Sufficient.
4. `test_ample_free_physical_on_hand_manufactures_zero` — on_hand 5, reserved 0, required 1 → 0.
5. `test_physical_stock_reserved_for_other_orders_is_not_treated_as_free` — on_hand 1, reserved 1, required 1 → 1 (shared-stock edge case).
6. `test_partial_free_stock_reduces_shortage_reservations_do_not_deepen_it` — on_hand 3, reserved 1, required 5 → 3.
7. `test_raw_material_required_scales_with_actual_production_quantity` — on_hand 0, reserved 3, required 3, recipe ×2 → produce 3, RM required 6.0 (not 12).
8. `test_non_negative_availability_behaviour_is_unchanged` — on_hand 20, reserved 2, required 10 → 0 (regression guard).
9. `test_engine_still_does_not_mutate_inventory` — read-only guarantee preserved.

**New — `MtoManufacturingQuantityIntegrationTest` (full canonical chain, real events/actions):**

1. `test_wave_path_manufactures_exactly_the_ordered_quantity` — order 2 via `WaveStarted` → `qty_produced == 2`, `on_hand == 2`, `reserved == 2`, `production_output` ledger `quantity == 2` (`on_hand_before 0` → `on_hand_after 2`).
2. `test_manual_prepare_path_manufactures_exactly_the_ordered_quantity` — order 2 via `PrepareOrderAction` → `qty_produced == 2`, `on_hand == 2`.
3. `test_raw_material_consumption_equals_recipe_qty_times_production` — recipe comp ×2, order 3 → produce 3, RM on_hand 50 → 44 (consumed exactly 6), `production_consumption` ledger present.
4. `test_repeated_execution_produces_no_additional_finished_goods` — wave ×2 + direct re-fire → 1 transaction, `on_hand` unchanged.
5. `test_non_mto_order_does_not_manufacture` — purchased FG → 0 transactions, stock untouched, line `Skipped`.

No existing test was modified. The prior invariant-based
`WaveDrivenManufacturingTriggerTest` was intentionally left as-is (its invariants hold across
the fix) and re-run for regression.

---

## 8. Test results

All runs via the isolated gate: `docker exec -e GATE_WAIT=2400 ecos-dev-testrunner sh -c 'cd /var/www/html && ./scripts/test-gate.sh <paths>'`.

**Baseline (before fix)** — the two directly-affected existing suites:
```
OK (24 tests, 126 assertions)
```
(`InventoryAvailabilityEngineTest` 18 + `WaveDrivenManufacturingTriggerTest` 6 — both green before any change.)

**Full verification (with fix)** — 9 suites in one invocation:
```
OK (130 tests, 487 assertions)
```
Suites: `MtoProductionQuantityAccuracyTest` (9, new) · `MtoManufacturingQuantityIntegrationTest` (5, new) ·
`InventoryAvailabilityEngineTest` (18) · `WaveDrivenManufacturingTriggerTest` (6) ·
`ManufacturingWorkflowTest` · `ManufacturingApplicationServiceTest` · `ManufacturingExecutorTest` ·
`RecipeToOrderAvailabilityE2ETest` · `OrderManufacturingIntegrationTest`.

**Negative control (buggy/original engine restored, new suites only)** — proves the tests
catch the defect (they are not vacuously green):
```
FAILURES!  Tests: 14, Assertions: 35, Failures: 7.
```
The 7 failures are exactly the negative-availability tests, failing with the precise
over-production magnitudes:

| Test | Got (buggy) | Expected (fixed) |
|---|---|---|
| single MTO order | 2.0 | 1.0 |
| aggregate pool (reserved 15) | **16.0** | 1.0 |
| RM scaling produced qty | 6.0 | 3.0 |
| wave path produced | 4.0 | 2.0 |
| manual path produced | 4.0 | 2.0 |
| RM-consumption test produced | 6.0 | 3.0 |
| idempotency produced | 4.0 | 2.0 |

The other 7 tests (availability ≥ 0) passed on both engines, confirming the fix is a no-op
outside the negative-availability case. The fixed engine was restored to the container after
this control (verified: 2 clamp lines present).

---

## 9. Manual path verification

`test_manual_prepare_path_manufactures_exactly_the_ordered_quantity` drives the real operator
entry point `PrepareOrderAction` (→ `FulfillmentEngine::run(MoveToPreparationWorkflow)` reserve
→ `ready_for_dispatch` → `PrepareOrderManufacturingAction`). Order qty 2 → **produced exactly
2**, `on_hand == 2`. Negative control: the same path produced **4** on the buggy engine.

---

## 10. Wave path verification

`test_wave_path_manufactures_exactly_the_ordered_quantity` fires the real `WaveStarted` event
(→ `HandlePreparationWaveStarted` → same canonical trigger). Order qty 2 → **produced exactly
2**, `on_hand == 2`, `reserved == 2` (reservation intact), `production_output` ledger
`quantity == 2`. Negative control: **4** on the buggy engine. The wave trigger implemented by
TASK-MTO-MANUFACTURING-TRIGGER-GAP-FIX-001 is preserved and exercised end-to-end.

---

## 11. FG / RM quantity reconciliation

For an order of `required` units of an MTO FG (on_hand 0, reserved `required`), recipe
component qty `c`:

- **FG produced** `= qty_to_manufacture = required` → `manufacturing_transactions.qty_produced`,
  `production_output` ledger `quantity`, and warehouse `on_hand` increment all equal `required`
  (pinned exactly for order 2 and order 3).
- **RM consumed** `= c × required` (order 3, c = 2 → 6; asserted via `on_hand` 50 → 44).

Both reconcile to the *actual* (corrected) production quantity, not the inflated one.
Pre-fix these were `required + reserved` and `c × (required + reserved)` respectively.

---

## 12. Idempotency verification

`test_repeated_execution_produces_no_additional_finished_goods`: a second `WaveStarted` plus a
direct `PrepareOrderManufacturingAction` re-fire leave `manufacturing_transactions` at 1 and
`on_hand` unchanged. The existing `WaveDrivenManufacturingTriggerTest` idempotency and
custody-transfer-idempotency tests also remain green. The executor's `plan_id`-unique guard and
the Executed-line guard are untouched.

---

## 13. Regression results

- The two directly-affected suites: **green before (24) and green after** (included in the
  130) → no regression.
- Broader manufacturing suites (`ManufacturingWorkflowTest`,
  `ManufacturingApplicationServiceTest`, `ManufacturingExecutorTest`,
  `RecipeToOrderAvailabilityE2ETest`, `OrderManufacturingIntegrationTest`): **all green**.
- No failures or errors of any kind in the full run (`OK (130 tests, 487 assertions)`); no
  `setUp`/`RefreshDatabase` contention errors were observed, so no runs were discarded.
- By construction the change is inert for `available ≥ 0`; the existing engine suite (whose
  cases are all non-negative) passing unchanged confirms this.

---

## 14. Live-data confirmation

**No live business data was mutated.** All verification ran in the isolated `ecos_dev_test`
database via the test gate. Nothing was run against `ecos-dev-app` (the live app container was
left untouched — the fix was deployed only to `ecos-dev-testrunner` for verification, and the
container engine was returned to the fixed state after the negative control). No inventory,
reservations, orders, trips, vehicle custody, or ledgers were touched outside the test DB.

---

## 15. ORD-00014 was NOT reconciled

The live shipment ORD-00014 was **not** manufactured, reconciled, or touched in any way. Its
reconciliation remains a separate, deferred follow-up requiring explicit owner authorization.

---

## 16. Remaining risks / deferred issues

- **Bounded surplus residual (out-of-model for MTO):** the clamp uses the *aggregate*
  reservation, so in a pure make-to-stock **surplus** scenario — `on_hand > reserved ≥ 0`
  while the current order's own reservation is included in `reserved` — it can over-produce by
  up to the order's own reserved qty (e.g. on_hand 10, reserved 7, required 4 → produces 1
  where a fully order-aware engine would produce 0). This requires a manufacturable product to
  carry physical stock exceeding **all** reservations, which does not occur for genuine MTO
  products (on_hand is 0, or during a wave accumulates only to `< reserved`). Eliminating it
  precisely would require making the engine **order-aware** (excluding the line's own
  reservation), which is explicitly out of scope ("do not invent a second reservation
  system"). Recommended only if a hybrid make-to-stock + manufacture product is introduced.
- **Not committed / not deployed:** per instructions, no commit, push, or deployment to the
  live app was performed. The change lives in the worktree working tree
  (`git status`: 2 modified source files + 2 untracked tests) and in the testrunner container.
- **Downstream compensation explicitly avoided:** no changes were made to `ShipStockAction`,
  `TransferLoadedStockToVehicleAction`, vehicle inventory, delivery, or finance; the
  correction is entirely at the manufacturing quantity source.

---

### Appendix — the change

```diff
-        $availableFg = $this->inventory->availableQty($warehouseId, $productId, $companyId);
-        // RC-1: partial manufacturing — only manufacture the shortage
-        $qtyToManufacture = max(0.0, $requiredQty - $availableFg);
+        $availableFg = $this->inventory->availableQty($warehouseId, $productId, $companyId);
+        // Free physical stock only — a reservation is a commitment, never extra demand.
+        $freeFinishedGoods = max(0.0, $availableFg);
+        // RC-1: partial manufacturing — only manufacture the shortage beyond free stock.
+        $qtyToManufacture = max(0.0, $requiredQty - $freeFinishedGoods);
```
