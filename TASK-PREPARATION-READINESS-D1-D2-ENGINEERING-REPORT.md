# D1 + D2 — Preparation Readiness — Engineering Report

**Date:** 2026-08-20 · **Environment:** DEV only · **No production change · No DB reset · No data deleted · No destructive git ops.**
**Certification: NOT claimed.** Owner requested PASS/FAIL evidence only.

---

## Contract implemented (owner decision 2026-08-20)

Missing Material and Preparation Eligibility are **two different concepts**:

- `missing_qty` is **always the real physical shortage** — never zeroed by `allow_negative_stock`. Procurement must see the true quantity (the "bought but receipt not yet entered" case).
- `allow_negative_stock` decides **preparation eligibility, per product**, not the Missing figure.

```
missing > 0 AND allow_negative = true   → READY
missing > 0 AND allow_negative = false  → WAITING_MATERIAL
missing = 0                             → READY
```

The previously-designed D1 (delete the override so `missing_materials_count` reflects shortage) was **rejected and NOT implemented that way**; this preserves REAL SHORTAGE **and** PREPARATION ELIGIBILITY as separate concepts.

## Files changed

**Backend:**
- `MaterialDemandCalculator.php` — removed the `allow_negative → missing=0/coverage=100` override (missing is now always real); exposed the product→material attribution it already computes (`materialsByProduct()`); persist `allow_negative` on the material row.
- `ProductReadinessCalculator.php` — **new**, thin: joins persisted `missing_qty` to products via the recipe edge → `material_status` + `blocking_materials_count`. Computes no availability of its own.
- `DemandProjectionBuilder.php` — calls readiness at the Layer-3b seam (full + incremental).
- `DemandReadRepository.php` — `upsertProductReadiness()`; carry `allow_negative` through material upsert.
- `WaveProductDemand.php`, `WaveMaterialDemand.php` — fillable + casts.
- `WaveDemandController.php` — emit `material_status`/`blocking_materials_count`; **per-product** guard on `updatePrepared` + `completePreparation`.
- `RefreshDemandOnStockReceivedListener.php` — **new**: material arrival re-projects affected waves (reuses `InventoryStockReceived`); registered in `DemandAnalysisServiceProvider`.
- migration `2026_08_20_090000_*` — 2 columns on `wave_product_demand`, `allow_negative` on `wave_material_demand` (additive).

**Backend tests:** `ProductReadinessContractTest.php` (new); `MaterialAvailabilityContractTest.php` (allow-negative case updated to the new contract).

**Frontend:** `types/preparation.ts`; `wave-product-demand-page.tsx` (readiness badge column + Mark-Complete disabled while waiting); `en/ar operations.json`.

**Docs:** ADR-027 → **v1.6**, §18.4 superseded + new **§18.6**.

**NOT touched:** `StartPreparationAction` / `shortage_detected` (wave never blocked), reservation, order lifecycle, `ManufacturingAvailabilityService`, `can_manufacture`, Procurement, inventory quantity semantics, `preparation_production_requirements`, D3/D4/D5/D6.

## PASS/FAIL evidence

### Backend tests (serialized gate, `ecos-dev-testrunner`)
| Requirement | Suite | Result |
|---|---|---|
| 1. missing + allow_negative=false → WAITING_MATERIAL | `ProductReadinessContractTest::test_shortage_without_allow_negative_blocks_that_product` | **PASS** |
| 2. missing + allow_negative=true → READY | `...::test_shortage_with_allow_negative_is_reported_and_still_ready` | **PASS** |
| 3. missing_qty non-zero in both cases | both tests assert `missing_qty = 7` | **PASS** |
| 4. Procurement sees real shortage | `MaterialAvailabilityContractTest::test_an_allow_negative_material_still_reports_its_real_physical_shortage` (missing 8, coverage 20%) | **PASS** |
| 5. material receipt → WAITING→READY | `...::test_product_becomes_ready_once_the_missing_material_arrives` | **PASS** |
| 6. readiness independent per product | `...::test_readiness_is_independent_per_product_and_never_blocks_the_wave` | **PASS** |
| 7. wave not blocked globally | same test asserts wave `collecting`, `shortage_detected=false` | **PASS** |
| (idempotency / one-blocker) | `test_reprojection_is_idempotent`, `test_one_blocking_material_is_enough` | **PASS** |

`ProductReadinessContractTest` + `MaterialAvailabilityContractTest` → **OK (20 tests, 62 assertions)**.

| 8. P-03 / made-to-order green | `OrderPreparationFulfillabilityContractTest`, `RecipeToOrderAvailabilityE2ETest` | **PASS** |
| 9. Existing Preparation tests | see caveat below | **PASS (mine) / PRE-EXISTING FAILURES (other session)** |
| 10. No Order/Reservation regression | `OrderAvailabilityLifecycleContractTest`, `OrderEditReservationAndPaymentGuardsTest`, `PaymentProofLifecycleTest` | **PASS** |

Combined reservation/payment run → **OK (80 tests, 205 assertions)**.

**Static gates:** `php -l` OK · Pint **46/46** on the module · PHPStan **L0 OK** · frontend `tsc` 23 (= baseline, none mine) · ESLint 0 · `vite build` exit 0.

### Runtime — real wave PREP-202608-000002 (rebuilt via the real builder)
```
MATERIAL DEMAND (missing = REAL shortage)
  Raw Honey        req 0.5  avail 99.75  missing 0    cover 100%  allow_neg=false
  Glass Jar 250ml  req 2    avail 499    missing 0    cover 100%  allow_neg=false
  تجربه            req 1    avail 0      missing 1    cover 0%    allow_neg=true   ← Procurement sees the real shortage
PRODUCT READINESS (per product)
  Honey Jar 250g          status=ready          blocking=0
  تجربة التعليقات         status=ready           blocking=0   ← short material is drawable on credit → preparation allowed
WAVE  status=collecting  shortage_detected=false            ← not blocked globally
```
**Recovery path verified at runtime:** `RefreshDemandOnStockReceivedListener` resolves and its selection query returns wave `01a01bd2…` for material تجربه — a stock receipt for that material will re-project the wave and flip readiness. (`InventoryStockReceived` has 5 registered listeners incl. this one.)

## Caveat — requirement 9 (concurrent uncommitted work)
The full `tests/Feature/Operations/DemandEngine` directory shows 17 failures (`MaterialDemandCalculatorTest`, `ProductDemandCalculatorTest`, `FinishedGoodOwnReservationDemandTest`). These are **NOT caused by this change**:
- **Proven by control run:** `ProductDemandCalculatorTest` **passes 7/7** against HEAD's `ProductDemandCalculator` and fails only against the working-tree version — an uncommitted "Option A prepared_qty" rewrite by another session.
- `MaterialDemandCalculatorTest` errors on a constructor arg the same session added; HEAD has no constructor.
- **None of the 17 reference this change's code** (`ProductReadinessCalculator`, `materialsByProduct`, `material_status`), and the only test asserting the old allow-negative behaviour was the one I updated. My override removal touches `missing_qty` for allow-negative materials only; no other test asserts that.

I did **not** modify the other session's files beyond my own surgical additions to `MaterialDemandCalculator`, and did not use any destructive git operation.

## Remaining
- Browser verification of the readiness badge is **BLOCKED** — the browser pane has no authenticated session and I cannot enter credentials. Backend + real-data runtime prove the contract; the visual badge is implemented and builds clean.
- Fresh controlled dataset for final E2E certification is deferred per instruction (no data reset yet).
