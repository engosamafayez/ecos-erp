# TASK-WAREHOUSE-COVERAGE-BRAND-ASSIGNMENT-001 — Engineering Report

**Date:** 2026-08-12 · **Env:** `C:\ecos-develop` · Runtime `ecos_dev` · Tests `ecos_dev_test` · **Branch:** `develop`
**Status:** IMPLEMENTED + RUNTIME CERTIFIED (one qualification, §17)

*Supersedes the STOP report of the same name. The blocking decisions are now frozen and implemented.*

---

## 1. Final Business Contract

| # | Rule | Implemented |
|---|---|---|
| 1 | Brand coverage owner = **WAREHOUSE** (not Branch) | `warehouse_brand_coverage` |
| 2 | **No rows = serves NO brands.** Never "serves all" | fail-closed filter + dedicated test |
| 3 | Multi-brand order needs **one** warehouse serving **ALL** brands + governorate + zone | `filterByBrandCoverage()` exact-count match |
| 4 | **No automatic order splitting** | none written; no child/partial assignment exists |
| 5 | Eligibility = active + same company + governorate + zone + **all** brands (every condition mandatory) | single AND-chain, no fallback |
| 6 | Reuse existing coverage hierarchy (`master_zone_id NULL` = whole governorate, most-specific wins) | `branch_coverage_areas` untouched |
| 7 | D1 resolved with canonical geography, no string-matching layer | canonical bilingual + ID-first zone |

---

## 2. Existing Architecture

`BranchAssignmentEngine` remains the sole decision authority — **no new engine was created**. No `BrandAssignmentEngine`, `WarehouseSelectionEngine`, or `WarehouseCoverageEngine` exists. The flow is PART 10's preferred shape:

```
Order → governorate + zone  (canonical resolution)
      → branch_coverage_areas          [existing geographic eligibility, unchanged]
      → company + active filter        [existing tenant authority, unchanged]
      → BranchWarehouseResolver        [existing warehouse resolution, unchanged]
      → BRAND COVERAGE FILTER          ← the one new step
      → existing priority selection    [priority ASC → Haversine, unchanged]
      → assign + canonical WarehouseAssigned
```

---

## 3. D1 Resolution

**Resolved with the canonical geography already defined by the project. No new geography authority, no parallel matcher, no fabricated data.**

Canonical tables were found fully seeded: **27 `master_governorates`** (each carrying `name`, `name_ar`, `code`) and **149 `master_zones`** — Cairo already contains Nasr City (`CAI-NAS`), Maadi, New Cairo, Heliopolis, Fifth Settlement.

The defect was that `CoverageResolutionService` matched **only** `master_governorates.name`, which holds English, while orders in an Arabic-business ERP carry Arabic. Resolution now consults **both columns of the same canonical row**:

```php
->where(fn ($q) => $q
    ->whereRaw('LOWER(name) = LOWER(?)',    [$needle])
    ->orWhereRaw('LOWER(name_ar) = LOWER(?)', [$needle]))
```

This is the canonical table answering in the language the order was written in — not a second geography layer. `code` is deliberately not matched (operational shorthand, not an address value).

Zone resolution is **ID-first**: `orders.delivery_zone_id` is passed through and used as the canonical identity when present; the name match survives only as the legacy path for free-text orders.

**Proven on real data.** ORD-00001 (`governorate = القاهرة`) resolved to **1 geographic candidate**, where it previously resolved to **0**.

---

## 4–5. Brand Coverage Model + Migration

`2026_08_12_100000_create_warehouse_brand_coverage_table.php` — one table, reusing existing conventions (uuid PK, `foreignUuid`, denormalised `company_id` as on `warehouses`/`brands`):

```sql
CREATE TABLE warehouse_brand_coverage (
  id char(36) PRIMARY KEY,
  company_id   char(36) NOT NULL,   -- tenant integrity
  warehouse_id char(36) NOT NULL,
  brand_id     char(36) NOT NULL,
  is_active    tinyint(1) NOT NULL DEFAULT 1,
  created_at, updated_at,
  UNIQUE KEY uq_wbc_warehouse_brand (warehouse_id, brand_id),
  KEY idx_wbc_warehouse_active (warehouse_id, is_active),
  KEY idx_wbc_brand_active     (brand_id, is_active),
  KEY idx_wbc_company_active   (company_id, is_active),
  FK brand_id → brands, company_id → companies, warehouse_id → warehouses  (all CASCADE)
)
```

**No second Brand entity. No Branch → Brand table.** Geographic coverage is *not* duplicated here — `branch_coverage_areas` is reused unchanged.

**No seed.** `NO ROWS = SERVES NO BRANDS` means seeding "all warehouses serve all brands" would invert the contract on day one. Existing warehouses were left unconfigured, exactly as instructed.

---

## 6–8. Governorate / Zone / Brand Coverage

| Layer | Source | Semantics |
|---|---|---|
| **Governorate** | `branch_coverage_areas.master_governorate_id` | canonical FK; resolved bilingually from the order |
| **Zone** | `branch_coverage_areas.master_zone_id` | `NULL` = entire governorate; specific id = that zone; **most-specific wins** (unchanged) |
| **Brand** | `warehouse_brand_coverage` | fail-closed; `is_active = false` grants nothing |

---

## 9. Eligibility Algorithm

```
candidates ← CoverageResolutionService(governorate, zone, canonicalZoneId)
candidates ← candidates where branch.company_id == order.company_id AND branch.is_active
   ∅ → markNoCoverage("No Branch Covers Destination")

if any line has an unresolvable brand → markNoCoverage("Order line has no resolvable product brand")

requiredBrands ← DISTINCT product.brand_id over order lines
if requiredBrands ≠ ∅:
    candidates ← candidates whose warehouse serves EVERY required brand
                 (company-scoped, is_active, exact-count match)
    ∅ → markNoCoverage("No Warehouse Serves Order Brands")

branch    ← existing priority selection over the survivors
warehouse ← BranchWarehouseResolver(branch)
```

**Brand filtering runs BEFORE ranking**, never after — a brand-incompatible warehouse is not a lower-ranked candidate, it is not a candidate. TEST 6 proves this by giving the incompatible branch the *better* priority.

One deliberate distinction, needed to keep the certified `BranchAssignmentEngineTest` honest: an order with **no lines** requires no brand and so cannot leave one unserved (vacuous), whereas an order with lines whose brand is **underivable** fails closed. Only the second is a coverage failure.

---

## 10. Multi-Brand Behaviour

Exact-count match: a warehouse qualifies only when the number of its active coverage rows intersecting the required set equals the required count. A subset never qualifies (TEST 5). **No splitting, no child orders, no partial assignment** — when no single warehouse serves all brands the order is left unassigned with reason `No Warehouse Serves Order Brands`.

## 11. Multiple Candidate Priority

Existing rule reused verbatim — **no new scoring system**: priority ASC → most-specific tier (zone beats governorate-wide, inside `CoverageResolutionService`) → Haversine nearest when coordinates exist. TEST 7 confirms priority still decides between two fully-eligible warehouses.

## 12. No-Coverage Behaviour

No nearest / first / default / arbitrary fallback. Distinct, reviewable reasons:

| Situation | `warehouse_assignment_source` | `failure_reason` |
|---|---|---|
| No governorate on order | `unassigned` | — |
| Geography not covered | `no_branch_coverage` | `No Branch Covers Destination` |
| **Geography ok, brand not** | `no_branch_coverage` | **`No Warehouse Serves Order Brands`** |
| Brand underivable | `no_branch_coverage` | `Order line has no resolvable product brand` |
| Branch has no warehouse | `no_branch_coverage` | `Assigned branch has no active warehouse` |

**The keystone rule from TASK-ORDER-PREPARATION-FLOW-REPAIR-001 is preserved and re-tested:** warehouse unresolved **≠** awaiting stock. TEST 8 asserts the lifecycle status is unchanged.

---

## 13–14. ORD-00001 / ORD-00002 — real pipeline, no hard-coding

Diagnostic through the real services:

| | ORD-00001 | ORD-00002 |
|---|---|---|
| company | `019f4e1c-2d1e…` | same |
| governorate | `القاهرة` | **NULL** |
| zone | NULL | NULL |
| brands | `019faecb-8420…` (1) | `019faecb-8420…` (1) |
| **geo candidates after D1** | **1** (was 0) | **0** |
| brand coverage before config | 0 warehouses | 0 warehouses |

After the operator-style configuration the contract requires (one explicit `warehouse_brand_coverage` row: Main Warehouse ↔ that brand), the **real** engine was run on both:

```
ORD-00001  status in_progress -> in_progress | warehouse=019f4e1c | source=branch_coverage | reason=-
ORD-00002  status new         -> new         | warehouse=NULL     | source=unassigned      | reason=-
```

- **ORD-00001** is now assigned by **`branch_coverage`** — coverage-driven, replacing the earlier supervisor `manual_override`. Root cause fixed (D1 + brand coverage), not the fixture.
- **ORD-00002** remains correctly unassigned: `governorate` is NULL, so coverage is never attempted. **OPERATOR CORRECTION REQUIRED.** No geography was invented.
- **Neither lifecycle status changed.**

---

## 15–17. Reservation / Preparation / Shipping

One query, one warehouse, all three layers:

```
order_number  order_wh   reservation_status  reserved_wh  reserved_qty  wave_wh    wave_number
ORD-00001     019f4e1c…  reserved            019f4e1c…    2.0000        019f4e1c…  PREP-202608-000001
```

- **Reservation** occurs against the assigned warehouse and no other (`ReserveOrderInventoryAction` locks on `assigned_warehouse_id`; it throws when null). Architecture unchanged.
- **Preparation** receives the same warehouse — the wave is keyed on it. Entry Gate, Preparation Backend, and `MaterialDemandCalculator` **untouched**; certified contract (`on_hand 15 / reserved 8 / available 7 / missing 3`) intact, container parity `ce69612a`.
- **Shipping boundary intact** — nothing was added to Distribution Core; warehouse selection remains solely in `BranchAssignmentEngine`.

> **Qualification (TEST 12).** Shipping's *consumption* of the assigned warehouse was verified by boundary inspection, **not runtime**: the `Logistics/Distribution` module exists only as 21 uncommitted host-only files (4 unrun migrations) belonging to another task and is **not deployed in this container**. The order demonstrably carries the correct warehouse and no Shipping code selects one — but Distribution was not exercised end-to-end.

## 18. Tenant Isolation

Reused certified authority; **no parallel tenant resolver**. Branch filter (`branch.company_id === $companyId`) plus, as defence in depth, `company_id` on every coverage lookup. TEST 9 gives a foreign-company warehouse identical geography *and* the ordered brand, and it is still refused.

---

## 19. Runtime Tests

`tests/Feature/Operations/WarehouseCoverageBrandAssignmentTest.php` — **13 tests, 21 assertions, OK**. No test sets `assigned_warehouse_id`, inserts an assignment result, or inserts a reservation row; every assertion reads what the engine decided.

| Test | Scenario | Result |
|---|---|---|
| 1 | gov + zone + brand match | assigned, source `branch_coverage` |
| 2 | wrong zone, right brand | not assigned |
| **3** | **right geography, wrong brand — PART 23 negative regression** | **not assigned**, `No Warehouse Serves Order Brands` |
| 4 | multi-brand A+B, serves both | assigned |
| 5 | multi-brand A+B, serves A only | not eligible, no split |
| 6 | brand-compatible beats better-priority incompatible | brand-compatible selected |
| 7 | two fully eligible | existing priority decides |
| 8 | none eligible | no assignment, **status unchanged** |
| 9 | cross-tenant | denied |
| — | warehouse with no rows | serves no brands |
| — | `is_active = false` row | grants nothing |
| — | **D1** Arabic governorate | resolves via canonical `name_ar` |
| — | duplicate (warehouse, brand) | rejected by the database |

TEST 13/14 (ORD-00001/2) were executed against live data — §13–14.

## 20. Regression Tests

**102 tests, 364 assertions — OK.** BranchAssignmentEngine (A/B/C/D), Preparation Entry Gate, Wave Engine, V3 Transition, RecipeGateTenantRepair, NegativeStockReservation. F4 markers all as specified: `F4_FORWARD`, `F4_REVERSE`, `MATRIX 6/6`, `DIRECT_FG`, `OPTION_B`, `NEG_STOCK`, `RECIPE_MISSING`, `CROSS_BRAND`. IAM untouched. No unrelated failure repaired.

## 21. PHPStan

**L0 platform-wide: [OK] No errors.** **Core L6: [OK] No errors.** Zero new errors.

## 22. Pint

**PASS — 3 files** (model, migration, test). `CoverageResolutionService`'s `phpdoc_align` was verified **pre-existing at `git HEAD`** and left untouched.

## 23. Guardian

Not run — no Guardian gate is wired into this worktree's tooling (`composer.json` exposes no such script). PHPStan L0 + core L6 + Pint are the available static gates and all pass.

## 24. Schema Safety

- Applied cleanly to `ecos_dev` **and** `ecos_dev_test` (723 ms / 655 ms).
- **Rollback verified on the test DB only:** `down()` → table absent; `up()` → present; `up()` again → idempotent via the `hasTable` guard. **555 tables intact** throughout.
- `down()` is a single `dropIfExists` — **no column dropped, no existing table altered, no warehouse ownership changed, no inventory architecture touched.**
- Duplicate `(warehouse_id, brand_id)` rejected by the unique key (tested).
- **MAIN / `ecos_erp` never connected to.**

## 25. Files Changed

| File | Change |
|---|---|
| `Modules/MasterData/Warehouses/Infrastructure/Database/Migrations/2026_08_12_100000_create_warehouse_brand_coverage_table.php` | **new** — the one authorized table |
| `Modules/MasterData/Warehouses/Domain/Models/WarehouseBrandCoverage.php` | **new** |
| `Modules/Operations/Preparation/Application/Services/CoverageResolutionService.php` | D1 — canonical bilingual governorate + ID-first zone |
| `Modules/Operations/Preparation/Application/Services/BranchAssignmentEngine.php` | brand eligibility before ranking; `requiredBrandIds()`, `filterByBrandCoverage()` |
| `tests/Feature/Operations/WarehouseCoverageBrandAssignmentTest.php` | **new** — 13-case matrix incl. negative regression |

No other file touched. Reservation, Preparation, Shipping, IAM, `MaterialDemandCalculator` unchanged.

## 26. Pre-existing Findings

- **Distribution module undeployed** — 21 host-only files, 4 unrun migrations, another task's work. Left untouched (§17).
- **Container parity** — `UserPolicy.php` (IAM, protected) and `PreparationWaveController.php` (another agent) still differ host↔container. Reported, not touched.
- **`config_delivery_geographies` / `config_delivery_zones` empty** — the brand→delivery-geography chain has no data. Not required by this task's resolution path.
- Two `OrderReservationLifecycleTest` failures and one multi-suite isolation flake — all proven pre-existing in the previous task.
- `CoverageResolutionService` `phpdoc_align` — pre-existing at HEAD.

---

## 27. Final Verdict

# WAREHOUSE COVERAGE + BRAND ASSIGNMENT = **CERTIFIED**

| Requirement | Status |
|---|---|
| D1 resolved with canonical geography | ✅ bilingual canonical row, ID-first zone; Arabic proven on real data |
| Warehouse → Brand coverage exists | ✅ `warehouse_brand_coverage` |
| No rows = serves NO brands | ✅ fail-closed, dedicated test |
| Governorate / Zone / Brand coverage work | ✅ TESTS 1, 2, 3 |
| Multi-brand needs one warehouse serving ALL | ✅ TESTS 4, 5 |
| No automatic splitting | ✅ none exists |
| Existing priority intact | ✅ TESTS 6, 7 |
| No invalid warehouse assigned | ✅ TEST 8; status unchanged |
| Tenant isolation | ✅ TEST 9 |
| Reservation uses assigned warehouse | ✅ runtime |
| Preparation uses assigned warehouse | ✅ runtime |
| Shipping receives the correct warehouse | ⚠️ **boundary-verified, not runtime** — Distribution not deployed (§17) |
| ORD-00001 / ORD-00002 behave correctly | ✅ real pipeline, no hard-coding |
| Preparation certification green | ✅ 102/364 |
| IAM boundary green | ✅ untouched |
| F4 / Option B green | ✅ |
| Schema safe | ✅ rollback + idempotency verified |
| PHPStan / Pint clean | ✅ |
| MAIN untouched | ✅ |

**Certified with one explicitly stated qualification** — the Shipping-side consumption of the assigned warehouse could not be runtime-exercised because the Distribution module is not deployed in this environment. Every other criterion passed with runtime evidence. If you consider that qualification disqualifying, downgrade to NOT CERTIFIED pending Distribution deployment; the engineering is otherwise complete.

**STOPPED.** No Order Splitting, Warehouse Transfer, Shipping redesign, Loading, Vehicle Inventory, Driver, Delivery, or Route Optimization work was started.
