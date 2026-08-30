# TASK-GOLIVE-RECIPE-GATE-TENANT-REPAIR-001 — Engineering Report

**Date:** 2026-08-09 · **Starting commit:** `6149875b` · **Not committed, not deployed.**

---

# 1 — EXECUTIVE SUMMARY

# ✅ F4 + OPTION B IMPLEMENTED AND RUNTIME-CERTIFIED

Three production files changed. No schema migration. No new engine. No new ownership system.

```
RecipeGateTenantRepairTest ......... OK (10 tests, 35 assertions)
RC-10 Lifecycle Certification ...... OK (17 tests, 55 assertions)
Phase 3 + tenant isolation ......... 195 tests, 3 failures - ALL control-proven PRE-EXISTING
PHPStan L0 platform ................ No errors
PHPStan core L6 .................... No errors
```

**The baseline inverted exactly as specified, and only where specified.**

| Evidence | Before (`6149875b`) | After | Result |
| --- | --- | --- | --- |
| `TENANT` | `instock` | **`outofstock`** | ✅ PASS |
| `B` | reserved 1.00, `ready_for_dispatch` | **reserved 0.00, `awaiting_stock`** | ✅ PASS |
| `E` | reserved 1.00, `ready_for_dispatch` | **reserved 0.00, `awaiting_stock`** | ✅ PASS |
| `A`, `C` | `instock`, reserved 1.00 | **unchanged** | ✅ PASS |
| Cross-brand A/B/C | `instock` | **unchanged `instock`** | ✅ PASS |

---

# 2 — OWNER DECISION AND PRECONDITIONS

| Item | State |
| --- | --- |
| Option B | ✅ Owner-approved — implemented as specified |
| F4 boundary = COMPANY | ✅ Runtime-proven prerequisite — honoured, never Brand |
| Cross-brand Raw Material reuse | ✅ Certified prerequisite — regression-guarded |
| Ownership source | `$product->brand?->company_id` — exactly as instructed |

**No precondition was reopened.** No `Product.company_id` added, no `BOM.company_id` added, no brand
equality used, no second recipe engine created, no schema change.

---

# 3 — BASELINE (Part 2) — captured BEFORE any production edit

Full `tests/Feature/Manufacturing/` on a clean `6149875b`:

```
A: recipe=instock    | http=200 | order=ready_for_dispatch | reservation=reserved | reserved_qty=1.00
B: recipe=outofstock | http=200 | order=ready_for_dispatch | reservation=reserved | reserved_qty=1.00
C: recipe=instock    | http=200 | order=ready_for_dispatch | reservation=reserved | reserved_qty=1.00
E: recipe=outofstock | http=200 | order=ready_for_dispatch | reservation=reserved | reserved_qty=1.00
TENANT: recipe=instock (companyA=0, companyB=100)
CROSS_BRAND: A=instock B=instock (reverse B=instock A=instock)

Tests: 174, Assertions: 544, Failures: 1
```

The single baseline failure — `RecipeFoundationTest::test_recipe_ignores_waste_percentage_if_submitted`
— occurred on an unmodified tree, so this run is its own control: **PRE-EXISTING**.

---

# 4 — F4 ROOT CAUSE

`ManufacturingAvailabilityService:61-68` aggregated `inventory_items` filtered only by
`deleted_at IS NULL` and `product_id IN (...)`. **No company predicate existed anywhere on the
path.** Any company's stock of a component satisfied any other company's recipe.

`EloquentProductRepository` carried the same defect in SQL — the `inv_comp` derived table grouped by
`product_id` alone.

---

# 5 — IMPLEMENTATION

### 5.1 — `ManufacturingAvailabilityService` (Part 3, 5)

```php
$companyId = $product->brand?->company_id;

$inventoryTotals = $companyId === null
    ? []
    : DB::table('inventory_items')
        ->whereNull('deleted_at')
        ->where('company_id', $companyId)
        ->whereIn('product_id', $componentIds)
        …
```

### 5.2 — `EloquentProductRepository` (Part 4)

```sql
LEFT JOIN (
    SELECT ii_c.product_id, ii_c.company_id, {$compAvailExpr} AS avail
    FROM inventory_items ii_c
    WHERE ii_c.deleted_at IS NULL
    GROUP BY ii_c.product_id, ii_c.company_id
) inv_comp ON inv_comp.product_id = comp_chk.id
          AND inv_comp.company_id = (
              SELECT b_own.company_id FROM brands b_own WHERE b_own.id = products.brand_id
          )
```

The two express **one rule in two languages** and do not diverge. Neither scopes by `brand_id`.

### 5.3 — `ReserveOrderInventoryAction` (Parts 11, 12)

```php
if ($product?->can_manufacture && $this->manufacturingIsExecutable($product)) {
```

`ManufacturingAvailabilityService` is **constructor-injected**; the material-level rule is read from
it, never recomputed.

**Design note (Part 12).** The gate is a *condition on the existing branch*, not a new early exit.
An unexecutable recipe simply falls through to the shortage path that was already there, so
`AwaitingStock` is produced by the existing V3 workflow. **No status is written by hand.**

---

# 6 — FAIL-CLOSED (Part 5) — and an honest boundary

| Path | Behaviour |
| --- | --- |
| Service, null company | `$inventoryTotals = []` — no inventory exposed |
| Repository, null company | Correlated subselect yields NULL → never matches → `COALESCE(avail,0)=0` |

Neither ever falls back to the global pool, and neither treats NULL as unrestricted.

**⚠️ Precise semantics, stated rather than glossed.** Fail-closed governs *inventory visibility*: a
company-less product sees zero. It does **not** override the material-level negative-stock rule of
Part 10, which Part 10 makes authoritative and forbids duplicating. So a company-less product whose
components all have `allow_negative_stock = true` still evaluates `instock` — the material rule
carries it, not leaked inventory. `test_part8_fail_closed_when_finished_good_has_no_company` uses an
OFF material and certifies the fail-closed path directly.

This is the conservative reading: it satisfies Part 5's binding requirement (no cross-company
inventory is ever exposed) without inventing a business rule Part 13 warns against.

---

# 7 — RUNTIME CERTIFICATION (Parts 6-21, 29)

`RecipeGateTenantRepairTest` — **OK (10 tests, 35 assertions)**

```
F4_FORWARD:     companyA=0 companyB=100 -> recipe=outofstock
F4_REVERSE:     companyB=0 companyA=100 -> recipe=outofstock
MATRIX:         6/6 multi-material policy combinations behaved as specified
DIRECT_FG:      recipe=outofstock fg_stock=10 -> order=ready_for_dispatch reserved=1.00
OPTION_B:       recipe=outofstock fg_stock=0  -> order=awaiting_stock reservation=awaiting_stock reserved=0.00
NEG_STOCK:      recipe=instock(allow_negative) -> order=ready_for_dispatch reserved=1.00
RECIPE_MISSING: recipe=recipe_missing fg_stock=0 -> order=ready_for_dispatch reserved=1.00
CROSS_BRAND:    one raw material (Brand A) -> A=instock B=instock C=instock
```

| Part | Requirement | Result |
| --- | --- | --- |
| 6 | Company B stock must not satisfy Company A recipe | ✅ **PASS** |
| 7 | Reverse direction | ✅ **PASS** |
| 8 | Own-company stock still satisfies (no over-restriction) | ✅ **PASS** |
| 8 | Null company fails closed | ✅ **PASS** |
| 10/19 | 3 materials × 6 policy combinations | ✅ **PASS** — 6/6 |
| 13 | `recipe_missing` does NOT block | ✅ **PASS** |
| 14 | Direct FG stock is NOT gated by the recipe | ✅ **PASS** — reserved 1.00, `ready_for_dispatch` |
| 15 | Unexecutable recipe → reserved 0, `AwaitingStock` | ✅ **PASS** |
| 16 | `allow_negative_stock` keeps reservation alive | ✅ **PASS** |
| 17 | No phantom reservation | ✅ **PASS** — see §8 |
| 18 | Order state through the real HTTP/V3 path | ✅ **PASS** |
| 20/21 | Cross-brand reuse survives; company boundary enforced | ✅ **PASS** |

Every business assertion ran through the **real** path: HTTP → `FulfillmentController` →
`FulfillmentEngine` → workflow → `ReserveOrderInventoryAction` → persistence.

---

# 8 — TRANSACTION SAFETY (Part 17)

With the recipe unexecutable, asserted directly against the database:

| Assertion | Result |
| --- | --- |
| Order line `reserved_qty` = 0 | ✅ PASS |
| FG `InventoryItem.reserved_qty` = 0 | ✅ PASS |
| FG `on_hand_qty` untouched | ✅ PASS |
| Raw material `reserved_qty` = 0, `on_hand_qty` untouched | ✅ PASS |
| `stock_ledger_entries` rows = 0 | ✅ PASS |
| `inventory_layer_consumptions` rows = 0 | ✅ PASS |

No stock deduction, no FIFO consumption, no ledger mutation, no partial transaction.

---

# 9 — RC-10 RE-CERTIFICATION (Part 22)

```
Rc10LifecycleCertificationTest .... OK (17 tests, 55 assertions)
```

All 17 green, including `test_insufficient_stock_diverts_to_awaiting_stock`. **No certified test was
modified, weakened or skipped.** ✅ **PASS**

---

# 10 — PHASE 3 REGRESSION (Part 23)

`tests/Feature/Inventory/` + `WarehouseTenantIsolationTest` + `SupplierTenantIsolationTest` +
`OrderTenantScopeTest`:

```
Tests: 195, Assertions: 471, Failures: 3
```

| Isolation | Result |
| --- | --- |
| Product (GD-1, `ProductPopulationScopeTest`) | ✅ PASS |
| Warehouse (RC-6) | ✅ PASS |
| Supplier (D-8) | ✅ PASS |
| Order | ✅ PASS |
| Availability derivation (Step 1) | ✅ PASS |

**This run also exercised the modified repository SQL** — the correlated subquery inside the JOIN
condition executes correctly on MySQL. No SQL error occurred anywhere in 195 tests.

### Pre-existing failure control (Part 24)

The 3 failures are all `InventoryCountSessionTest`. Control executed by reverting **only** the four
tracked files to `6149875b` (explicit paths; work preserved as a patch and restored afterwards with
marker verification):

| Test | Message | Post-change | Control at `6149875b` | Classification |
| --- | --- | --- | --- | --- |
| `test_approve_posts_adjustment_out_for_negative_variance` | expected `'7.0000'`, actual `'10.0000'` (line 216) | FAIL | FAIL — identical | **PRE-EXISTING** |
| `test_fifo_consumption_record_created_for_adjustment_out` | expected `'8.0000'`, actual `'10.0000'` (line 236) | FAIL | FAIL — identical | **PRE-EXISTING** |
| `test_adjustment_creates_ledger_entry` | `null is not null` (line 354) | FAIL | FAIL — identical | **PRE-EXISTING** |

Test name + failure message + line number + expected/actual all match. Nothing hidden, nothing deleted.

`RecipeFoundationTest::test_recipe_ignores_waste_percentage_if_submitted` is likewise PRE-EXISTING
(present in the §3 baseline on an unmodified tree).

---

# 11 — STATIC VALIDATION (Part 27)

| Check | Control at `6149875b` | After | Result |
| --- | --- | --- | --- |
| PHPStan L0 (platform, 4,153 files) | No errors | **No errors** | ✅ PASS |
| PHPStan core L6 | — | **No errors** | ✅ PASS |
| `php -l` on all changed files | — | Clean | ✅ PASS |

No frontend file was touched, so the TypeScript baseline of 24 is untouched by construction.
No suppression added, no baseline regenerated, no `--no-verify`, Guardian unmodified.

---

# 11b — GUARDIAN ⚠️ **FAIL (exit 1) — NOT caused by this task**

`engineering/quality-guardian/guardian.sh pre-push`:

```
PHP Syntax              ✓ PASS
Composer Validate       ○ SKIP
Laravel Bootstrap       ✓ PASS
Laravel Pint            ✗ FAIL
PHPStan                 ✓ PASS
ESLint                  ✓ PASS
TypeScript              ✓ PASS
Vite Production Build   ✓ PASS

GUARDIAN_EXIT=1
```

**7 of 8 validators pass. The single failure is Pint, on two files this task never opened:**

```
NEW Pint violations in 2 file(s) not in the baseline:
  backend/tests/Feature/Inventory/ProductPopulationScopeTest.php   fixers: ordered_imports
  backend/tests/Feature/Operations/V3TransitionResolutionTest.php  fixers: binary_operator_spaces
```

Attribution, evidenced:

| Question | Evidence |
| --- | --- |
| Are they in this task's diff? | **No** — `git diff HEAD --name-only` returns 4 files; neither is among them |
| When were they last changed? | **`6149875b`** — the starting commit, by the previous task's release commit |
| Why does Guardian see them? | The Pint validator scans the **push range** `f0d7822abace...HEAD`, i.e. already-committed work, not the working tree |
| Was Guardian already failing at `6149875b`? | **Yes** — by construction, since the violations were introduced by that commit |

**Did this task add any Pint debt?** No.

- `EloquentProductRepository.php` reports `concat_space`, `unary_operator_spaces`,
  `not_operator_with_successor_space` — the **identical three fixers** were produced by
  `git show HEAD:` of the same file *before* my edit. Pre-existing, and inside the 628-file baseline.
- This task's three test files were Pint-fixed to clean (`{"tool":"pint","result":"passed"}`)
  so no new debt is introduced. That is the ratchet working as intended.

**Not fixed here.** The two violating files are outside this task's declared scope, and the task
enumerates exactly what to modify. The remedy is one command —
`cd backend && php vendor/bin/pint backend/tests/Feature/Inventory/ProductPopulationScopeTest.php backend/tests/Feature/Operations/V3TransitionResolutionTest.php`
— formatting-only, zero behavioural risk. **Whether to apply it is the owner's call, not mine.**

The success criterion "GUARDIAN_EXIT=0" is therefore **NOT met**, and is **not claimed**. It cannot
be met without touching files this task was not authorised to change. No baseline was regenerated,
no suppression added, and Guardian was not modified to make this pass.

---

# 12 — ADR-027 (Part 25)

Updated **additively** to v1.2 with a new **Section 16**:

- 16.1 Option B recipe gate (amends Section 3 Case 2 only)
- 16.2 Direct FG stock remains independently reservable
- 16.3 `ManufacturingAvailabilityService` is the single authority
- 16.4 F4 company scoping + fail-closed
- 16.5 **Raw Materials are a COMPANY resource, not a Brand resource** — brand scoping forbidden
- 16.6 Implementation map

A pointer was also added at Section 3 Case 2, which states the superseded rule verbatim. Without it
the ADR would contradict itself. **That pointer is added text; nothing was deleted or rewritten.**

---

# 13 — REMAINING FINDINGS

### 13.1 — `products.company_id` is a legacy NOT NULL column ⚠️ **OBSERVATION**

`products` still carries a `company_id` column (NOT NULL), populated by `ProductFactory:37` from the
brand. The `Product` **model never references it** — brand→company is the model-level contract, as
ADR-013 and this task require.

For a product with `brand_id = NULL` the two disagree: the column holds a company, the derived
ownership is null. This implementation follows the **instructed** source (`brand?->company_id`) and
fails closed. Not changed here — the task explicitly forbids using or adding `Product.company_id`.
**Flagged for separate triage; no runtime defect demonstrated.**

### 13.2 — Cross-company `InventoryItem` validity ❌ **UNVERIFIED** (carried forward)

The F4 tests deliberately construct an inventory row for a Company-A product under Company B,
because that is the **only** fixture shape that discriminates the fix: with distinct products the
unscoped query already returns zero and would pass before *and* after. Whether such a row is
architecturally legitimate remains **UNVERIFIED** from the prior task. F4 enforces the boundary
regardless of how such a row arises. This is not upgraded to proven.

### 13.3 — `StoreBomRequest` has no company constraint ⚠️ **UNVERIFIED** — NOT touched

Confirmed statically only: `raw_material_id` is validated as `exists:products,id` plus not-self, with
no company rule. **Per the task's explicit instruction this was NOT fixed and NOT expanded into.**
No concrete exploitable cross-company BOM write path was demonstrated at runtime during this task,
so no separate STOP was raised. Recommend separate triage.

---

# 14 — STOP CONDITIONS (Part 28)

| # | Condition | Fired? |
| --- | --- | --- |
| 1 | Company A can still use Company B inventory | ❌ No — `outofstock` |
| 2 | Company B can still use Company A inventory | ❌ No — `outofstock` |
| 3 | Cross-brand reuse breaks | ❌ No — A/B/C all `instock` |
| 4 | F4 became Brand-level | ❌ No — company predicate only |
| 5 | Direct FG stock blocked by recipe | ❌ No — reserved 1.00, `ready_for_dispatch` |
| 6 | Recipe unavailable still reserves | ❌ No — reserved 0.00 |
| 7 | `AwaitingStock` unreachable via V3 | ❌ No — reached through the existing workflow |
| 8 | RC-10 regressed | ❌ No — 17/17 |
| 9 | Phase 3 regressed | ❌ No — 3 failures control-proven pre-existing |
| 10 | Second recipe engine required | ❌ No — service injected |
| 11 | Schema migration required | ❌ No |
| 12 | Negative-stock lifecycle redesign required | ❌ No |
| 13 | New permission/security decision required | ❌ No |
| 14 | Behaviour contradicts the ownership contract | ❌ No |

**No STOP condition fired.**

---

# 15 — FINAL STATE

| | |
| --- | --- |
| Base commit | `6149875b` |
| Production files changed | **3** |
| Documentation changed | **1** (ADR-027, additive) |
| Schema migrations | **0** |
| Committed / deployed | **NO** — as instructed |
| Working tree | **SAFE** — scoped, patch-verified, markers confirmed |

```
 M backend/Modules/Commerce/Orders/Application/Actions/ReserveOrderInventoryAction.php
 M backend/Modules/Inventory/Products/Infrastructure/Repositories/EloquentProductRepository.php
 M backend/Modules/Manufacturing/BillsOfMaterials/Domain/Services/ManufacturingAvailabilityService.php
 M docs/adr/ADR-027-reservation-ownership-policy.md
?? backend/tests/Feature/Manufacturing/RecipeGateTenantRepairTest.php   (new regression guard)
```

`RecipeToOrderAvailabilityE2ETest` scenarios B, E and TENANT were tightened from observation-only to
asserting the corrected behaviour — that is the specified baseline inversion, applied to this task's
own untracked evidence tests. **No certified test was weakened.**

---

# 16 — FINAL CERTIFICATION

| Criterion | Status |
| --- | --- |
| F4 | ✅ **CERTIFIED** |
| Company isolation (both directions) | ✅ **CERTIFIED** |
| Cross-brand Raw Material reuse | ✅ **CERTIFIED** |
| Option B | ✅ **CERTIFIED** |
| Recipe unavailable → no reservation | ✅ **CERTIFIED** |
| Order → `AwaitingStock` via existing V3 workflow | ✅ **CERTIFIED** |
| Recipe executable → reservation allowed | ✅ **CERTIFIED** |
| Direct FG stock regression | ✅ **PASS** |
| RC-10 | ✅ **CERTIFIED** — 17/17 |
| Phase 3 regression | ✅ **PASS** — 3 control-proven pre-existing |
| PHPStan | ✅ **PASS** — L0 and core L6 clean |
| Guardian | ⚠️ **FAIL (exit 1)** — 7/8 validators pass; Pint fails on 2 files untouched by this task and pre-existing at `6149875b`. See §11b. **Not claimed as met.** |
| Working tree | ✅ **SAFE** |

---

**Implementation + certification only. Not committed, not deployed. No release commit created, no
Go-Live certification started. Guardian unmodified, no baseline regenerated, no suppression added.**
