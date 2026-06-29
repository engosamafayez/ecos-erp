# Implementation Progress — Manufacturing & Procurement

Architecture frozen: 2026-06-29 (ARCHITECTURE-FREEZE.md)
Implementation began: 2026-06-29

---

## PKG-01 — Product Foundation

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-MFG-IMP-001
**Architect Decision:** All 4 fields from MFG-M001 (partial)

---

### Migration Summary

| Migration File | Table | Operation | Columns Added |
|----------------|-------|-----------|---------------|
| `2026_06_29_000001_add_manufacturing_fields_to_products_table.php` | `products` | ALTER | `cost_source`, `can_manufacture`, `can_disassemble`, `allow_negative_stock` |

**Column Specifications:**

| Column | Type | Default | Nullable | Notes |
|--------|------|---------|----------|-------|
| `cost_source` | VARCHAR(20) | `'purchase'` | No | CostSource enum: purchase \| recipe \| hybrid |
| `can_manufacture` | BOOLEAN | `false` | No | Has a recipe; may be produced |
| `can_disassemble` | BOOLEAN | `false` | No | May be disassembled back into components |
| `allow_negative_stock` | BOOLEAN | `false` | No | Evaluated on raw materials at consumption time (RC-2) |

**Rollback:** `dropColumn(['cost_source', 'can_manufacture', 'can_disassemble', 'allow_negative_stock'])`

---

### Files Created

| File | Purpose |
|------|---------|
| `backend/Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_29_000001_add_manufacturing_fields_to_products_table.php` | Adds 4 manufacturing-capability columns to `products` |
| `backend/Modules/Inventory/Products/Domain/Enums/CostSource.php` | Backed enum: `Purchase`, `Recipe`, `Hybrid` with `label()` and `isManufacturingRelevant()` |
| `backend/tests/Feature/Inventory/ProductManufacturingFieldsTest.php` | 26 tests covering migration, defaults, enum casting, capability flags, factory states, backward compatibility |

---

### Files Modified

| File | Change |
|------|--------|
| `backend/Modules/Inventory/Products/Domain/Models/Product.php` | Added `cost_source`, `can_manufacture`, `can_disassemble`, `allow_negative_stock` to `$fillable` and `casts()`. Updated `@property` docblock. Imported `CostSource` and `ProductStockStatus` explicitly. |
| `backend/Modules/Inventory/Products/Application/DTO/ProductDTO.php` | Added `cost_source` (required, default `'purchase'`), `can_manufacture`, `can_disassemble`, `allow_negative_stock` as constructor-promoted properties. Updated `fromArray()`. |
| `backend/Modules/Inventory/Products/Presentation/Http/Requests/StoreProductRequest.php` | Added `cost_source` (required, `Rule::enum(CostSource::class)`), `can_manufacture`, `can_disassemble`, `allow_negative_stock` (all boolean). |
| `backend/Modules/Inventory/Products/Presentation/Http/Requests/UpdateProductRequest.php` | Same validation additions as StoreProductRequest. |
| `backend/Modules/Inventory/Products/Presentation/Http/Resources/ProductResource.php` | Added `cost_source`, `can_manufacture`, `can_disassemble`, `allow_negative_stock` to API response. |
| `backend/Modules/Inventory/Products/Infrastructure/Database/Factories/ProductFactory.php` | Added new fields to `definition()`. Added factory states: `manufacturable()`, `hybrid()`, `allowsNegativeStock()`. |

---

### Architecture Alignment

| Architecture Decision | Implementation |
|----------------------|----------------|
| RC-2: `allow_negative_stock` on raw materials only | Column added to `products` — no enforcement here; Decision Engine evaluates at consumption time |
| RC-3: FIFO cost in consumptions | Deferred — no consumptions in PKG-01 |
| RC-5: Hybrid cost as strategy | `cost_source = 'hybrid'` flag on Product; cost history logic deferred to PKG-10 |
| MFG-M001: Product manufacturing fields | ✅ `can_manufacture`, `can_disassemble`, `allow_negative_stock`, `cost_source` added |

---

### Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|-----------|
| Existing products default `cost_source = 'purchase'` | Low | Correct default — all pre-manufacturing products are purchase-costed |
| `cost_source` has no DB-level CHECK constraint | Low | `Rule::enum(CostSource::class)` enforces valid values at API boundary; enum cast prevents invalid values via model |
| New required `cost_source` field in API | Medium | Existing API clients must now supply `cost_source`. Default `'purchase'` in DTO means factory/test code without explicit value continues to work. |

---

### Compatibility Notes

- **Existing tests:** All existing Product factory usages continue to work. New columns have `NOT NULL DEFAULT` values — no existing row creation breaks.
- **API:** `cost_source` is now required in `StoreProductRequest` and `UpdateProductRequest`. Clients not supplying it will receive a 422 validation error. Default `'purchase'` applies when creating via factory (tests).
- **ProductDTO:** `cost_source` has a default of `'purchase'` in the constructor. `fromArray()` falls back to `'purchase'` if key is absent, preserving backward compatibility in programmatic usage.
- **ProductResource:** New fields are always present in responses (not `whenLoaded`). No breaking change for clients that ignore extra fields.
- **product_type:** Remains unchanged. Still accepts `finished_good` | `raw_material`. No business logic tied to it in PKG-01.

---

---

## PKG-02A — Recipe Foundation

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-MFG-IMP-002

---

### Migration Summary

| Migration File | Table | Operation | Details |
|----------------|-------|-----------|---------|
| `2026_06_29_000002_add_bom_version_number_to_bills_of_materials.php` | `bills_of_materials` | ALTER | Adds `bom_version_number UNSIGNED INT DEFAULT 1`, index on (product_id, bom_version_number). Backfills existing rows to 1. |

---

### BOM Layer Refactoring (Phase 1)

| Change | Details |
|--------|---------|
| `waste_percentage` removed from business layer | Removed from `BillOfMaterialLine.$fillable` + `casts()`, `BomLineDTO`, `BomResource`, `StoreBomRequest`, `UpdateBomRequest`, `CreateBomAction`, `UpdateBomAction`, `BomSeeder` |
| `waste_percentage` column preserved in DB | Backward-compatible: existing data unaffected, column defaults to 0 |
| `bom_version_number` added to BOM layer | `BillOfMaterial.$fillable` + `casts()`, `BomResource`, `CreateBomAction` passes it via repository, `EloquentBomRepository.nextVersionNumber()` |
| `BomRepositoryInterface` extended | Added `nextVersionNumber(string $productId): int` |

---

### Files Created (7)

| File | Purpose |
|------|---------|
| `2026_06_29_000002_add_bom_version_number_to_bills_of_materials.php` | Adds versioning integer column |
| `Recipe.php` | Recipe aggregate — domain language over `bills_of_materials` table |
| `RecipeLine.php` | Component line — domain language over `bill_of_material_lines` table (no waste_percentage) |
| `RecipeRepositoryInterface.php` | Contract: findById, findActiveByProduct, findAllByProduct, create, activate, nextVersionNumber, nextBomNumber |
| `EloquentRecipeRepository.php` | Eloquent implementation; manages active-version deactivation on create/activate |
| `RecipeNotFoundException.php` | Domain exception |
| `tests/Feature/Manufacturing/RecipeFoundationTest.php` | 22 tests |

---

### Files Modified (12)

| File | Change |
|------|--------|
| `BillOfMaterial.php` | Added `bom_version_number` to fillable + casts |
| `BillOfMaterialLine.php` | Removed `waste_percentage` from fillable + casts |
| `BomLineDTO.php` | Removed `waste_percentage` property |
| `BomResource.php` | Removed `waste_percentage` from line output; added `bom_version_number` to header |
| `StoreBomRequest.php` | Removed `waste_percentage` rule; added `Rule::notIn([$product_id])` on component |
| `UpdateBomRequest.php` | Same as StoreBomRequest |
| `CreateBomAction.php` | Removed `waste_percentage` from line map; added `bom_version_number` via `nextVersionNumber()` |
| `UpdateBomAction.php` | Removed `waste_percentage` from line map |
| `BomRepositoryInterface.php` | Added `nextVersionNumber(string $productId): int` |
| `EloquentBomRepository.php` | Added `nextVersionNumber()` implementation |
| `BomServiceProvider.php` | Registered `RecipeRepositoryInterface → EloquentRecipeRepository` |
| `BomSeeder.php` | Removed `waste_percentage`; added `bom_version_number: 1` |
| `Product.php` | Added `recipes()` HasMany, `activeRecipe()` HasOne with `ofMany`, `hasRecipe()` method; imported Recipe |

---

### Architecture Alignment

| Architecture Decision | Implementation |
|----------------------|----------------|
| BOM = persistence layer, Recipe = domain language | Recipe + RecipeLine model same tables as BOM. No parallel schema. |
| Copy-on-write versioning | `bom_version_number` integer added. Sequential numbering via `nextVersionNumber()`. Trigger logic (when in-use BOM is modified) deferred to PKG-02B. |
| No percentage quantities | `waste_percentage` removed from all new Recipe operations. DB column stays for backward compat. |
| Unit comes from Product | No `unit_id` on `RecipeLine` — unit derived from `component.unit` relationship. |
| One active version per product | `EloquentRecipeRepository.create()` and `activate()` call `deactivateOthers()`. |
| Component ≠ output product | `Rule::notIn([$product_id])` on `lines.*.raw_material_id` in both BOM requests. |

---

### Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|-----------|
| Recipe and BillOfMaterial share the same table | Low | Intentional DDD design. Both models are read/write compatible. No ORM conflicts. |
| `waste_percentage` removed from BOM API response | Medium | Any frontend currently reading `lines[].waste_percentage` will get `undefined`. All values were 0 anyway since it was never enforced. |
| `bom_version_number` required for new BOMs | Low | `EloquentBomRepository.create()` sets it via `nextVersionNumber()`. Default in DB is 1 — old rows safe. |

---

### Compatibility Notes

- **BOM CRUD API**: existing `/api/boms` endpoints remain fully functional. BOM models untouched except for `bom_version_number` addition.
- **`waste_percentage` submissions**: silently ignored. Callers sending it will not receive a 422 — the field is not validated, just dropped.
- **BOM rows created before this migration**: all set to `bom_version_number = 1` by the backfill.

---

## PKG-02B and PKG-03 through PKG-11 — Pending

See [ARCHITECTURE-FREEZE.md](ARCHITECTURE-FREEZE.md) §7 for implementation package order and dependencies.
