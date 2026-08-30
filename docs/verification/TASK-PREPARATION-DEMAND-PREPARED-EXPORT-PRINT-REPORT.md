# Preparation — Prepared persistence, Product Demand Export/Print

**Date:** 2026-08-14 · No PHPUnit, no E2E, no runtime verification (per instruction). Static only.
**Status:** **IMPLEMENTATION COMPLETE — RUNTIME VERIFICATION PENDING USER REVIEW**

---

## 1 — Decision #2: `manual_prepared_qty` NOT added, and why

Your decision permitted the column **conditionally** — *"إذا كان هذا هو أقل تغيير معماري"*. It is not.
The guarantee you asked for is **already implemented, with zero new columns**:

- `wave_product_demand.prepared_qty` exists — `decimal(12,4)`, default `0`.
- `DemandReadRepository::upsertProductDemand()` **deliberately excludes it** from the upsert update
  list, with the reasoning recorded inline:

```php
// `prepared_qty` is DELIBERATELY ABSENT from this update list.
// It is operator-owned (product-level Prepared, Option A); a demand
// rebuild must refresh what the wave requires without discarding what
// the floor has already prepared. remaining_qty / completion_pct are
// likewise omitted because they are derived from it at read time.
['product_name', 'product_sku', 'required_qty',
 'orders_count', 'data_hash', 'last_calculated_at', 'updated_at'],
```

- `ProductDemandCalculator` sets `prepared = 0.0` **only for a first insert**, and documents that
  `remaining_qty` / `completion_pct` are derived at read time so they cannot drift when Required moves
  under a preserved Prepared.

That satisfies decisions **#1, #3, #4, #5, #6** exactly: Prepared is product-level, operator-owned,
survives rebuild / refresh / postpone, is never distributed across orders, and `order_lines.prepared_qty`
is not written for it.

**Adding `manual_prepared_qty` would have been the larger change** — a second column, a second source of
truth for Prepared, and a migration — to obtain a property the current design already has. So I did not
add it. If you still want the explicit name, say so and I will rename rather than duplicate.

**Your worked example holds as-is:** `Required 5 / Prepared 2 / Remaining 3` → after any rebuild the
upsert refreshes `required_qty` and leaves `prepared_qty` untouched, and Remaining is recomputed as
`5 − 2 = 3`.

---

## 2 — Implemented in this pass

### #12, #13, #14 — Product Demand Export + Print

`wave-product-demand-page.tsx` had neither. Both added, reusing the **existing** Missing Materials
pattern rather than inventing one (`downloadCsv` with a `U+FEFF` BOM, `SmartToolbar.secondaryActions`,
`window.print()`).

**Export covers the FULL row set (#13).** It maps `allItems`, **not** `filtered`, and lists headers
explicitly instead of deriving them from `colVis` — so neither the completion tab nor a hidden column
can silently truncate the sheet. Seven columns: Product · SKU · Required · Prepared · Remaining ·
Orders · Progress.

**Print (#14, #16).** A print-only header identifies the sheet (`hidden print:block`) with wave number
and planning date. All UI chrome is excluded: the toolbar wrapper and the filter/search row both carry
`print:hidden`, and the grid container gains `print:overflow-visible` so rows are not clipped at the
page break. This composes with the platform's existing `@media print` block in `index.css:351`, which
already hides sidebar/navigation via the `.no-print` / `.print-keep` convention.

New i18n keys in **EN + AR**: `productDemand.download`, `.print`, `.printTitle`, and `columns.sku`
(SKU was rendered inside the product cell and had no key of its own).

### #15 — Missing Materials print/export reviewed: **already complete, no change**

Its table has 7 columns (material, required, available, reserved, missing, coverage, affected orders)
and its CSV emits **8** headers — all seven plus SKU. Nothing was missing, so nothing was changed.

### #11, #17 — respected

Priority was not returned to the UI. Inventory Value untouched.

---

## 3 — Files Changed

| File | Change |
|---|---|
| `frontend/src/features/operations/pages/wave-product-demand-page.tsx` | Export + Print, print-chrome exclusions, `usePreparationWave` for the header |
| `frontend/src/i18n/locales/en/operations.json` | `download`, `print`, `printTitle`, `columns.sku` |
| `frontend/src/i18n/locales/ar/operations.json` | same, Arabic |

**No backend file, no migration, no schema change.** `wave-missing-materials-page.tsx` untouched.

---

## 4 — NOT Implemented (deliberately, and flagged)

I stopped short rather than deliver four features at low confidence. **None of these was started**, so
nothing is half-built:

| # | Item | Why not |
|---|---|---|
| **7** | Product → Related Orders drill-down (Required per order only) | Needs a **new backend endpoint** — `grep` confirms no `related-orders` route exists. Backend + frontend + i18n. |
| **8** | Missing Material → Related Orders via Order → Product → Recipe → Material | Same: a new canonical-join endpoint. `MissingMaterialCalculator` already performs that exact join for counting, so the query shape exists and can be reused — but exposing it is new surface. |
| **9** | Active Waves = last 3 + Archive | Substantial: API filter + two frontend surfaces. This is the item previously blocked on the **operational-day definition** (`TASK-PREPARATION-DAILY-WAVE-LIFECYCLE-001` D1), which is still unanswered — "last 3 operational days" cannot be computed without it. |

**#9 in particular is still gated on a business decision you have not yet given**, so it could not have
been completed correctly in this pass regardless.

Also respected: **#10** — no Brand Preparation Rules were designed or implemented.

---

## 5 — Static Verification

| Check | Result |
|---|---|
| TypeScript — my files | **0 errors** |
| TypeScript — total | **24 = the documented baseline** (it was 25 last pass; the concurrent agent's `manual-order-form.tsx` error is now gone) |
| ESLint — changed page | **clean** |
| Vite production build | **✓ built in 5.14s** |
| PHPUnit / E2E | **not run**, per instruction |

No `CERTIFIED` claim is made.

---

## 6 — Decisions Needed

1. **Confirm the `prepared_qty` naming.** The persistence contract you specified is met; only the column
   name differs from `manual_prepared_qty`. Rename, or keep as-is?
2. **Authorise #7 and #8 as their own task** — each needs a new endpoint, so they are a backend change
   rather than a UI change.
3. **#9 still needs the operational-day ruling** (D1 from the Daily Wave Lifecycle diagnostic) before
   "last 3 operational days" can be defined.
4. **Print and column visibility:** printing renders the grid as displayed, so a column hidden via the
   visibility menu is also absent from the printed sheet. **Export is the guaranteed-complete artifact.**
   If the printed sheet must always show all columns regardless of the menu, that needs a change in the
   shared `UniversalDataGrid`, which affects every screen using it — I did not make it unilaterally.

---

> **IMPLEMENTATION COMPLETE — RUNTIME VERIFICATION PENDING USER REVIEW**
