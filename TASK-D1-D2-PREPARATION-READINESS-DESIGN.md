# D1 + D2 — DESIGN FOR APPROVAL

**Status: DESIGN ONLY. Nothing implemented. No ADR, schema, or code changed.**
Per instruction: design complete → report affected files, ADR impact, test matrix → **STOP for approval**.
D3/D4/D5/D6 remain as already implemented and are untouched by this design.

---

## 1. The approved contract

**Fulfillment Truth** and **Physical Preparation Truth** are separate and neither overwrites the other.

| | Fulfillment Truth | Physical Preparation Truth |
|---|---|---|
| Question | *May this order be committed?* | *What must physically be supplied to prepare it?* |
| Authority | `ManufacturingAvailabilityService` | `MaterialDemandCalculator` → `wave_material_demand` |
| `allow_negative_stock` | **satisfies** the requirement | **does NOT** offset the physical deficit |

**Worked example (validated against live wave PREP-202608-000002):**

| Product | Materials | Physical | Result |
|---|---|---|---|
| Honey Jar 250g (req 2) | Raw Honey 99.75 · Glass Jar 499 | available | **Ready for Preparation** |
| تجربة التعليقات (req 1) | تجربه — avail **0**, `allow_negative=true` | short 1 | **Waiting for Material** — Required 1 / Available 0 / **Missing 1** / **0%** |

Wave stays valid · both orders stay **In Progress** · reservation untouched.

---

## 2. D1 — make the physical shortage visible

**One override is deleted. No schema change, no new engine.**

`MaterialDemandCalculator.php:244-248` already computes the correct `available = 0, missing = 1, coverage = 0`. Lines **`:257-260`** then overwrite it with `$missing = 0.0; $coveragePct = 100.0;` when the material has `allow_negative_stock = true`. Deleting that block is the whole of D1.

Everything downstream follows automatically:
- `MissingMaterialCalculator.php:29-31` already selects `missing_qty > 0` → the material enters `wave_missing_materials`.
- The Missing Materials tab already renders whatever is in that table → the row appears with the correct quantities.
- `DemandReadRepository::deleteResolvedMissingMaterials()` already prunes rows once `missing_qty` returns to 0.

**Preserved unchanged:** the §18.2 clamps (`available = max(0, on_hand − effective_reserved)`, `missing = max(0, required − available)` — Missing still never exceeds Required), the §18.3 `own_active_member` / `postponed_member` netting, `available_qty` arithmetic, and `ManufacturingAvailabilityService` (fulfillment is not touched).

---

## 3. D2 — per-product readiness (NOT wave-wide blocking)

### 3.1 The obstacle
The product→material explosion **already exists** in `MaterialDemandCalculator.php:92-115` — it knows `$finishedProductId` per BOM line and then **discards it** into a material-keyed aggregate. `wave_material_demand` has no product column, so the reverse edge (short material → affected products) is not persisted anywhere.

### 3.2 The design — reuse the existing explosion, add no second engine
`MaterialDemandCalculator` gains a method that **returns the attribution it already computes** (`productId → materialId[]`), reusing the same `ActiveRecipeResolver::bomIdsByProduct()` authority and the same BOM join. No new traversal, no second recipe engine, no duplicated availability logic.

A thin projection step then stamps readiness onto `wave_product_demand`:

> **product is `waiting_material` if ANY material its active recipe consumes has `missing_qty > 0`; otherwise `ready`.**

It computes **no availability of its own** — it only joins figures already persisted by Layer 2/3.

### 3.3 Where it attaches
`DemandProjectionBuilder::buildFull()` between **L65 (`upsertMissingMaterials`)** and **L89 (`kpiCalc->calculate`)** — the only point where both product rows and material shortage are persisted. Same seam on the incremental path (`buildForProducts` L175 → L197). *(Note: `$missingMaterialIds` at L67 is a dead local sitting exactly at this seam.)*

### 3.4 Why not reuse `preparation_production_requirements`
It is the only other per-product state table in Preparation, and it was considered and rejected on evidence:
- Its **seeding path is dead and broken** — the sole creator (`PRPCalculationService`) is uncalled, writes two columns that do not exist, omits two NOT-NULL columns, and iterates the empty `preparation_wave_items`. The table is unpopulated platform-wide.
- Its **states are manufacturing-oriented**, not material-availability-oriented: `pending / job_created / manufacturing / ready` (DB CHECK-constrained), driven by `ManufacturingJobCreated/Completed` listeners. It cannot express "Waiting for Material" vs "Ready for Preparation" without redefining an enum that already has a live transition path.

Readiness therefore belongs on `wave_product_demand`, which is the projection the operator surfaces actually read.

### 3.5 Schema
Two columns on `wave_product_demand` (uniquely keyed `(preparation_wave_id, product_id)`):

| Column | Type | Meaning |
|---|---|---|
| `material_status` | `string(20)` default `'ready'` | `ready` \| `waiting_material` |
| `blocking_materials_count` | `unsignedInteger` default `0` | how many of its materials are short |

### 3.6 API
`WaveDemandController::presentProductDemand()` (L80-100) is a **single shared shape** feeding the list endpoint *and* all three write endpoints — adding the two fields there reaches every response at once.

### 3.7 Enforcement — per product, never the wave
- **`StartPreparationAction.php:48` is NOT touched.** `shortage_detected` stays unwired and inert. **The wave is never blocked.**
- Enforcement goes on the only per-product write surface that is actually reachable: `WaveDemandController::updatePrepared()` (L109-133) and `completePreparation()` (L142-173) — refuse while `material_status = waiting_material`, in the same style as the existing P-04 quantity refusal.
- `uncompletePreparation()` stays ungated (must always be able to undo).
- `CompleteProductAction` is **not** used — it operates on `preparation_wave_items`, which is empty platform-wide.
- Order status and reservation are **not** read or written by any of this.

### 3.8 Recovery — "material arrives → product becomes Ready"
**This leg has no trigger today.** Every live rebuild trigger is an order-membership or wave-lifecycle event; **no inventory event rebuilds the projection.** The two inventory listeners are **commented out** (`EventPlatformServiceProvider.php:138-139`) *and* filter to `['collecting','preparing']`, which excludes `planning` / `shortage_blocked` — exactly the statuses a wave waits in.

Design: subscribe the demand engine to `InventoryStockReceived` (already bridged to the enterprise bus at `EventPlatformServiceProvider.php:164`), scoped to waves whose `wave_material_demand` contains the moved material, in the same company/warehouse. Idempotent (a rebuild is a recompute), tenant-safe (company predicate), and bounded (no full scan). This reuses the existing bus and the existing `DemandRefreshRequested` path — no new recovery engine.

---

## 4. Exact affected files

### Backend — behaviour
| File | Change |
|---|---|
| `Modules/Operations/DemandAnalysis/Application/Services/MaterialDemandCalculator.php` | **delete** the `allow_negative` override (`:257-260`); expose the product→material attribution it already computes |
| `.../Application/Services/ProductReadinessCalculator.php` | **new**, thin — joins persisted `missing_qty` to products via the existing attribution |
| `.../Application/Services/DemandProjectionBuilder.php` | call it at the L65→L89 seam (and L175→L197) |
| `.../Application/Services/DemandReadRepository.php` | persist the two readiness columns |
| `.../Domain/Models/WaveProductDemand.php` | `$fillable` + casts |
| `.../Presentation/Http/Controllers/WaveDemandController.php` | emit the fields in `presentProductDemand()`; add the per-product guard to `updatePrepared` + `completePreparation` |
| `.../Infrastructure/Database/Migrations/<new>_add_readiness_to_wave_product_demand.php` | **new** — 2 columns, additive, defaults preserve current behaviour |
| `Modules/Platform/EventPlatform/Infrastructure/Providers/EventPlatformServiceProvider.php` | subscribe the demand engine to `InventoryStockReceived` (scoped) |
| `.../DemandAnalysis/Application/Listeners/<StockReceived>Listener.php` | **new or repaired** — correct the status filter to include `planning`/`shortage_blocked` |

### Frontend
| File | Change |
|---|---|
| `features/operations/types/preparation.ts` | add `material_status`, `blocking_materials_count` to `WaveProductDemandItem` |
| `features/operations/pages/wave-product-demand-page.tsx` | new **status column** (badge), reusing the pattern already at `wave-raw-materials-page.tsx:54-69`; disable Mark-Complete while waiting |
| `features/operations/pages/wave-raw-materials-page.tsx` | badge keyed on **physical stock**, not `missing_qty` (today a 0-stock material renders green "Sufficient") |
| `i18n/locales/en/operations.json`, `ar/operations.json` | `wave.productDemand.statusReady` / `statusWaitingMaterial` |

**Not touched:** `StartPreparationAction`, `shortage_detected`, `AnalyzeMaterialsAction`, `preparation_wave_items`, `preparation_production_requirements`, `ManufacturingAvailabilityService`, reservation, order lifecycle, `can_manufacture`, ADR-027 §19.

---

## 5. ADR impact — ADR-027 v1.6

**§18.4** currently reads: *"…its `missing_qty` is reported as **0** and coverage as 100%, so it never blocks preparation and never enters the missing-materials / procurement queue."*

Amend to (history preserved, clause superseded not deleted):
- `missing_qty` and `coverage_pct` report the **true physical position**; a credit-covered material **does** enter the missing-materials / procurement queue, because the shortage must be purchased before that product can physically be prepared.
- `allow_negative_stock` continues to govern **fulfillment only** (`ManufacturingAvailabilityService`, reservation, order lifecycle) — unchanged.

**New §18.6 — Per-product preparation readiness:** a physically short material blocks **only the products whose recipe consumes it**. The wave remains valid, other products stay preparable, the order stays In Progress, and reservation is untouched. Readiness is a derived projection over `wave_material_demand`, not a second availability engine.

§18.1, §18.2 (clamps), §18.3 (netting), §17.x and §19 are unchanged.

---

## 6. Test matrix

| # | Case | Expected |
|---|---|---|
| 1 | RM: required 1, on_hand 0, `allow_negative=true` | recipe **executable**; order **reserves → In Progress**; demand row: required 1 / available 0 / **missing 1** / **0%** |
| 2 | Same RM | appears in `wave_missing_materials` and on the Missing Materials tab |
| 3 | RM: required 1, on_hand 10 | missing 0, coverage 100%, **not** in the missing queue |
| 4 | Wave with 2 products, one short | short product `waiting_material`; other product `ready`; **wave status unchanged**; **order status unchanged** |
| 5 | `completePreparation` on a `waiting_material` product | **refused** (422), with the blocking material named |
| 6 | `completePreparation` on a `ready` product | succeeds (existing quantity guard still applies) |
| 7 | `updatePrepared` on a `waiting_material` product | refused |
| 8 | `uncompletePreparation` on a blocked product | still allowed |
| 9 | `StartPreparation` on a wave containing a short product | **succeeds** — the wave is never blocked (regression guard for the D2 correction) |
| 10 | Missing material is received into stock | demand rebuilds automatically; product flips `waiting_material → ready`; no operator action |
| 11 | Stock received for another company/warehouse | our wave is **not** touched (tenant + scope) |
| 12 | Replay the same stock event | converges, no duplicate rows, no state flip-flop |
| 13 | Reservation regression | `OrderPreparationFulfillabilityContractTest`, `RecipeToOrderAvailabilityE2ETest`, `OrderAvailabilityLifecycleContractTest` stay green |
| 14 | D3/D4/D5/D6 regression | `OrderEditReservationAndPaymentGuardsTest` + `PaymentProofLifecycleTest` stay green |

---

## 7. Consequences you should accept before approving

1. **`missing_materials_count` KPI changes.** `WaveKpiCalculator.php:48` counts `missing_qty > 0`; credit-covered shortages will now be counted. Intended (visibility), but wave KPIs and the dashboard alert list will move.
2. **A new refusal appears.** Marking a blocked product prepared/complete now fails where it previously succeeded. That is the point of D2, but it is behaviour-changing for operators.
3. **Numbers will move on first recalculation.** The live projection is stale (`reserved_qty=1` recorded 23:33:02 vs live `2` at 23:35:22). Wiring the inventory trigger fixes the staleness and will change displayed figures.
4. **Blast radius is contained** to `Modules/Operations/DemandAnalysis` + its UI — verified: the `missing_qty` hits in Manufacturing are unrelated value-object fields, not this table.

---

## 8. Deliberately NOT in scope (reported, not fixed)
`wave_manufacturing_demand` has no writer · `AnalyzeMaterialsAction` broken/dead · `preparation_production_requirements` unpopulated (`PRPCalculationService` dead and structurally broken) · `preparation_wave_items` empty platform-wide (makes `CompleteProductAction` and the `CompleteWaveAction` guard inert) · the Raw Materials tab is absent from the workspace tab bar despite route+page+i18n existing · `expected_today` / `in_transit_qty` hardcoded 0.

---

**Awaiting approval. No implementation will start until you approve this design. No DEV data will be reset or deleted.**
