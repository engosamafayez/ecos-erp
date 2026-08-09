# TASK-PHASE3-STEP2-TEST-CERTIFICATION-001 — Engineering Report

**Date:** 2026-08-09 · **Worktree:** `develop` @ `C:\ecos-develop` · Host PHP 8.4.22
**Scope:** certification only. No application behaviour was modified.

# ✅ STEP 2 = FULLY CERTIFIED · WRITE-PATH REGRESSION = CERTIFIED

---

# 1 — NEW REGRESSION TESTS

`backend/tests/Feature/Inventory/ProductStockStatusWritePathTest.php` — 7 cases.

| Required proof | Case |
| --- | --- |
| 1. Human product **update** cannot write `stock_status` | `test_update_product_request_does_not_accept_stock_status` |
| 2. Removed human write paths cannot alter it | `test_store_product_request_does_not_accept_stock_status`, `test_patch_product_request_does_not_accept_stock_status` |
| 3. Existing validated fields still work | `test_store_request_still_accepts_its_other_fields` (name, product_type, regular_price, sale_price, short_description, channel_ids) · `test_patch_request_still_accepts_its_other_fields` (allow_negative_stock, is_active, manual_cost, regular_price, sale_price) |
| 4. `ProductController::import()` unaffected | `test_import_path_still_accepts_stock_status` |
| 5. Machine ingestion keeps its contract | `test_inbound_importer_still_maps_stock_status` |

**Mechanism asserted:** a key absent from `rules()` never reaches `validated()`, so it cannot be
carried into any action, DTO or model write. The tests assert the rule sets directly.

---

# 2 — TEST RESULTS

Run in isolation, host PHP:

```
php -d memory_limit=2G vendor/bin/phpunit \
    tests/Feature/Inventory/ProductStockStatusWritePathTest.php --no-coverage

.......                                                             7 / 7 (100%)
Time: 08:21.766, Memory: 72.00 MB

OK (7 tests, 18 assertions)
```

**7 tests · 18 assertions · 0 failures.**

---

# 3 — PARENT / CURRENT CONTROL

Two runs of the *same* file, isolated, differing only in whether the uncommitted backend work was
applied.

## 3.1 Ruling out test-order pollution first

The 3 failures were originally seen in a whole-directory run (`tests/Feature/Inventory`, 166 tests).
The cheapest hypothesis was cross-test pollution, so `InventoryCountSessionTest` was run **alone** on
CURRENT before any revert:

```
CURRENT, isolated:   Tests: 17, Assertions: 35, Failures: 3
```

**Same three failures.** Not pollution — so a real control was required.

## 3.2 Control method

1. `git diff HEAD -- backend/ > backend-only.patch` — **18,502 bytes**, scoped to `backend/` only
2. `git checkout -- backend/` → parent-commit state; frontend deliberately left applied (it cannot affect PHPUnit)
3. Run
4. `git apply backend-only.patch`
5. **Verified restored:** 16 changed files; `TenantOwnershipResolver` referenced **3×** each in `Warehouse`, `Order`, `Supplier`; `availability_state` present in `ProductResource` and `InventorySummary`; `'stock_status'` **absent (0)** from all three product write paths

## 3.3 Results

| | Tests | Assertions | Failures |
| --- | --- | --- | --- |
| **CURRENT** (all uncommitted work applied) | 17 | 35 | **3** |
| **PARENT** (`HEAD`, backend reverted) | 17 | 35 | **3** |

Identical counts, identical test names, identical assertion messages:

```
1) InventoryCountSessionTest::test_fifo_consumption_record_created_for_adjustment_out   '8.0000' vs '10.0000'
2) InventoryCountSessionTest::…                                                          '7.0000' vs '10.0000'
3) InventoryCountSessionTest::test_adjustment_creates_ledger_entry   Failed asserting that null is not null
```

---

# 4 — CLASSIFICATION OF INVENTORY FAILURES

| # | Failure | Classification |
| --- | --- | --- |
| 1 | `test_fifo_consumption_record_created_for_adjustment_out` (line 236) | **PRE-EXISTING** |
| 2 | FIFO consumption quantity (line 216) | **PRE-EXISTING** |
| 3 | `test_adjustment_creates_ledger_entry` (line 354) | **PRE-EXISTING** |

**REGRESSION: none. ENVIRONMENTAL: none. UNRESOLVED: none.**

Reproduced identically with every uncommitted change reverted. **No Inventory test was modified.**

> **Recorded, not fixed:** these three are genuine platform defects in inventory-count adjustment —
> FIFO consumption records the wrong quantity and one adjustment writes no ledger entry. They are
> outside this task's scope and belong to the inventory-count backlog, not Phase 3. **They were
> failing before any of this work began.**

---

# 5 — PHPSTAN

| Configuration | Result |
| --- | --- |
| `phpstan.neon.dist` — level 0, platform (`Modules` + `app`) | ✅ `[OK] No errors` |
| `phpstan-core.neon.dist` — level 6 (`app/Core`, Contracts, Traits) | ✅ `[OK] No errors` |

No baseline regenerated, no `ignoreErrors` entry added.

---

# 6 — GUARDIAN

```
PHP Syntax    ✓ PASS  11s      ESLint                 ✓ PASS  97s
Composer      ○ SKIP   0s      TypeScript             ✓ PASS  90s
Laravel Boot  ✓ PASS   3s      Vite Production Build  ✓ PASS  18s
Laravel Pint  ✓ PASS   0s
PHPStan       ✓ PASS   5s

All checks passed.        GUARDIAN_EXIT=0
```

---

# 7 — TYPESCRIPT / ESLINT / i18n

| Gate | Result |
| --- | --- |
| TypeScript | ✅ PASS — **baseline 24, held** |
| ESLint | ✅ PASS |
| i18n missing keys | ✅ **0** — Step 2 reused the existing `inStock` / `outOfStock` keys |
| EN/AR parity | ✅ Held |
| RTL-unsafe additions | ✅ 0 |

Host PHP lint: `No syntax errors detected` across all six changed/added backend files.

**No suppression added. `--no-verify` not used. The `ecos-app` container was not used for worktree
verification.**

---

# 8 — FINAL STEP 2 CERTIFICATION

# ✅ STEP 2 = FULLY CERTIFIED

| Condition | Result |
| --- | --- |
| New write-path tests pass | ✅ 7/7, 18 assertions |
| Human write paths cannot write `stock_status` | ✅ Asserted on all three request classes |
| Other validated fields unaffected | ✅ Asserted |
| `import()` / machine ingestion intact | ✅ Asserted |
| 3 Inventory failures classified | ✅ **PRE-EXISTING**, proven by parent control |
| PHPStan (both configs) | ✅ Clean |
| Guardian | ✅ `GUARDIAN_EXIT=0` |
| TypeScript baseline 24 | ✅ Held |

**Write-path regression = CERTIFIED.** The gap recorded at the end of
TASK-PHASE3-GD2-STEP2-CLOSE-001 is now closed with executed evidence.

---

# 9 — REMAINING PHASE 3 BLOCKERS

| Step | Status |
| --- | --- |
| **1** — derive `availability_state` | ✅ COMPLETE |
| **2** — repoint availability presentation | ✅ **FULLY CERTIFIED** |
| **8** — close human write path | ✅ **CERTIFIED** |
| **3** — reconcile products stats/list | ⛔ **GD-1** — cross-company product population |
| **4–6** — RC-10 transition track | ⛔ **PD-1 + PD-2** — must ship as one release |
| **7** — remove V2 translation layers | ⛔ **PD-2** |

**3 of 8 steps complete and certified. Phase 3 is NOT complete.**

**Three owner decisions gate everything that remains: PD-1, PD-2, GD-1.**

Separately tracked, not Phase 3: the three pre-existing `InventoryCountSessionTest` defects (§4).

---

**No application behaviour modified. GD-2, Step 2, Step 1 and Step 8 not reopened. Step 3 not started.
RC-10 untouched. No Inventory test modified. No suppression, no `--no-verify`, no deployment.**
