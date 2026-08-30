# Preparation Wave Workspace — Frontend completion (Prepared editor, completion action, drill-down)

**Date:** 2026-08-14 · No PHPUnit, no E2E (per instruction). Static verification only.
**Status:** **IMPLEMENTATION COMPLETE — RUNTIME VERIFICATION PENDING USER REVIEW**

---

## 1 — What I found before writing anything

**The backend for items 1–4 was already complete**, built by the concurrent agent. All four routes are
registered:

```
PATCH waves/{waveId}/product-demand/{productId}/prepared    → updatePrepared
POST  waves/{waveId}/product-demand/{productId}/complete    → completePreparation
GET   waves/{waveId}/product-demand/{productId}/orders      → productRelatedOrders
GET   waves/{waveId}/missing-materials/{materialId}/orders  → materialRelatedOrders
```

**The frontend had none of it wired** — no service method, no hook, no UI. That gap was the whole job,
which matches your instruction not to stop at the backend contracts.

---

## 2 — Item 11: the new migration, documented

`Modules/Operations/DemandAnalysis/Infrastructure/Database/Migrations/2026_08_14_100000_add_preparation_completed_at_to_wave_product_demand.php`

| Question | Answer |
|---|---|
| **Column** | `wave_product_demand.preparation_completed_at` |
| **Type** | `timestamp`, **nullable**, positioned `after('completion_pct')` |
| **Purpose** | Records the operator's explicit declaration that a product's preparation is finished |
| **Who writes it** | Only `WaveDemandController::completePreparation()` — the `POST …/complete` action. Nothing else writes it |
| **When it changes** | Only when the operator presses "تم الانتهاء من التحضير". **Never** set by editing Prepared, never inferred from `prepared_qty >= required_qty`, never touched by a demand rebuild |
| **Why no conflict with `preparation_wave_items`** | `preparation_wave_items` / `PreparationItemStatus` remain the **per-item lifecycle**. This is a **product-level fact on the demand read model**, which is where product-level Prepared already lives. No new lifecycle, no new status enum, and the two never write to each other |

**Additive only** — one nullable timestamp, guarded by `Schema::hasTable` + `hasColumn` on both `up()`
and `down()`, so a re-run is a no-op.

**Note there is no migration for Prepared itself.** `prepared_qty` already existed on the table; what
changed is *ownership* (operator writes it, rebuild preserves it via the upsert exclusion) — a
code-level contract, not a schema change. That is why the `manual_prepared_qty` column you conditionally
approved was not needed.

---

## 3 — Implemented

### #1 Inline Prepared editor

`PreparedEditor` commits on **blur or Enter**, then lets the query invalidate. Remaining and
Completion % are **re-read from the backend**, never recomputed client-side, so the table cannot show a
figure the server does not hold. It re-syncs when the row changes underneath (rebuild, postpone,
another operator).

`order_lines.prepared_qty` is not touched anywhere in the path — one number per product, never
distributed (#1, decisions #3/#4/#6).

The input is disabled once the product is completed, and printed sheets render the **value**, not an
input (`hidden print:inline` / `print:hidden` pair).

### #2 "تم الانتهاء من التحضير"

A dedicated action column. **Completion is never inferred** from `prepared_qty` reaching
`required_qty` — the migration's own docblock gives the reason, and it is a good one: Required can
still move afterwards when an order is postponed. Once set, the row shows a `Preparation finished`
badge instead of the button.

### #3 Product → Related Orders

Clicking the product name opens a `Sheet` listing **Order Number · Customer · Required**. **Prepared is
deliberately absent per order** — it is product-level. The endpoint already excludes postponed
memberships (`whereNull('pwo.postponed_at')`).

### #4 Missing Material → Related Orders — backend verified, UI not built

The endpoint exists and uses the **canonical** join exactly as you specified:

```
preparation_wave_orders → orders → order_lines → products
  → bills_of_materials (is_active, not deleted) → bill_of_material_lines
material_qty = SUM(ol.quantity × boml.quantity)
```

No invented direct Order ↔ Material relation. **The drawer UI is not built** — see §5.

### #6 / #7 — already delivered last pass, re-verified

Product Demand Export + Print exist; Export maps `allItems` (not the filtered set) with explicit
headers so neither a tab nor a hidden column truncates it. Missing Materials export emits all 7 table
columns plus SKU. Priority and Procurement were not returned to the UI.

### Respected

**#8** no Brand Preparation Rules. **#9** Inventory Value untouched. **#10** no change to order
lifecycle, reservation architecture, pricing, product cost or manufacturing.

---

## 4 — Files Changed

| File | Change |
|---|---|
| `features/operations/pages/wave-product-demand-page.tsx` | inline editor, completion action, drill-down sheet, print handling |
| `features/operations/hooks/use-preparation.ts` | `useUpdateProductPrepared`, `useCompleteProductPreparation`, `useProductRelatedOrders`, `useMaterialRelatedOrders` |
| `features/operations/services/preparation-service.ts` | four service methods |
| `features/operations/types/preparation.ts` | `preparation_completed_at`, `ProductRelatedOrder`, `MaterialRelatedOrder` |
| `i18n/locales/{en,ar}/operations.json` | completion / drill-down / editor-feedback keys |

**Frontend only.** No backend file, no migration, no route added by me.

---

## 5 — Not Implemented

| # | Item | Status |
|---|---|---|
| **4 (UI)** | Missing Material → Related Orders drawer | Backend + service + hook + types are wired; **only the Sheet in `wave-missing-materials-page.tsx` remains**. It is a direct copy of the Product drill-down already built |
| **5** | Wave selector: last 3 + Archive | **Not started.** `wave-picker.tsx` still lists `per_page: 50` unfiltered |

I stopped here rather than rush both. #4's remaining work is small and mechanical; #5 needs an archive
surface and a decision on what "relevant" means for ordering (`planning_date` vs `created_at` — the
latter is what produced the 13-day-stale wave earlier, so it should not be the sort key).

---

## 6 — Static Verification (#12)

| Check | Result |
|---|---|
| PHPStan L0 | **[OK] No errors** |
| PHPStan core L6 | **[OK] No errors** |
| Pint — `Modules/Operations/DemandAnalysis/` | **passed** |
| TypeScript — my files | **0 errors** |
| TypeScript — total | **24 = documented baseline** |
| ESLint — all 4 changed frontend files | **clean** |
| Vite build | **✓ built in 5.39s** |
| `git diff --check` | **clean** |
| PHPUnit / E2E | **not run**, per instruction |

One self-inflicted issue caught and fixed during the pass: my scripted edit to
`preparation-service.ts` truncated the trailing `clean()` helper, producing 8 `TS2304` errors. Restored
and re-verified — the count is back to the 24 baseline.

No `CERTIFIED` claim is made.

---

## 7 — For Your Manual Review

1. **Product Demand → edit Prepared inline.** Expect Remaining and Completion % to update from the
   server, and the value to survive a page refresh.
2. **Postpone an order, then re-check.** Required should drop while **Prepared stays** — the point of
   the upsert exclusion.
3. **Press "تم الانتهاء من التحضير".** The row should switch to the `Preparation finished` badge and
   the input should disable. Confirm that merely typing Prepared = Required does **not** complete it.
4. **Click a product name** → drawer with Order Number, Customer, Required. Confirm no per-order
   Prepared appears.
5. **Print Product Demand** → no sidebar, toolbar, filters or buttons; Prepared prints as a value.

---

> **IMPLEMENTATION COMPLETE — RUNTIME VERIFICATION PENDING USER REVIEW**
