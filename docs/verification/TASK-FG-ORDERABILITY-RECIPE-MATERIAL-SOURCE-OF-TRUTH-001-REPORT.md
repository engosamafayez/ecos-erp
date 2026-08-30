# TASK-FG-ORDERABILITY-RECIPE-MATERIAL-SOURCE-OF-TRUTH-001 — Report

**Date:** 2026-08-14 · No tests run · No certification · `ecos_dev` not modified · No migration.

---

## 1 — Root Cause

**The backend authority you asked for already exists and already implements your contract. The defect
was one frontend surface that bypassed it.**

`ProductResource::resolveCanCommit()` (`:58-68`) is the canonical orderability answer:

```php
$available = agg_available_qty;                                              // signed FG availability

if (AvailabilityState::canCommit($available, allow_negative_stock)) {        // path 1: FG stock
    return true;
}

return $this->product_type === Product::TYPE_FINISHED_GOOD                   // path 2: RECIPE
    && ($this->manufacturing_availability ?? null) === 'instock';
```

with the docblock at `:55-56`: *"Either path suffices — a finished good with no stock is still
orderable when its recipe can be executed."* That is CASE 2, CASE 5 and CASE 6 verbatim.

Path 2 delegates to `ManufacturingAvailabilityService`, whose rule is *"a material is considered
available when `available_qty > 0` **OR** `allow_negative_stock = true`"* — i.e. the overdraw
permission is read from the **raw material**, never from the finished good.

**Where it broke:** `order-inventory-status-card.tsx` never consulted `can_commit`. It read:

```ts
const outOfStock = selectedProducts.filter((p) => p.stock_status === 'outofstock');       // :50
const allNegativeAllowed = outOfStock.every((p) => p.allow_negative_stock === true);      // :51
… selectedProducts.filter((p) => p.stock_status === 'outofstock' && !p.allow_negative_stock)  // :151
```

Two violations of your contract in three lines:

1. **`products.stock_status`** — the WooCommerce channel attribute, forbidden by Parts 2, 5 and 6.
2. **`products.allow_negative_stock` on the finished good** — forbidden by Part 4, which states the
   overdraw permission belongs to the raw material.

There is a third, quieter problem: **`stock_status` is `NULL` on every product in `ecos_dev`**
(verified — FG-000001, RM-000001, RM-000002 all NULL). So `=== 'outofstock'` was never true and the
guard **silently never fired**. It was not merely reading the wrong source; it was inert.

`product-browser.tsx:114` had already been corrected to `can_commit` and carries a comment saying
exactly this. The status card was simply missed.

---

## 2 — Files Changed

**One file:**
- `frontend/src/features/orders/components/order-inventory-status-card.tsx`

No backend file, no migration, no schema, no ADR, no enum, no pricing/cost, no recipe structure, no
inventory formula.

---

## 3 — Existing Authority Reused

| Authority | Role | Reused / Changed |
|---|---|---|
| `ProductResource::resolveCanCommit()` | canonical orderability (FG **or** recipe) | **reused unchanged** |
| `ManufacturingAvailabilityService` | recipe → raw-material availability, honours RM `allow_negative_stock` | **reused unchanged** |
| `AvailabilityState::canCommit()` | signed-availability + allow-negative projection | **reused unchanged** |
| `InventoryAvailabilityEngine` | quantity-aware recipe capacity (`analyse(product, warehouse, requiredQty, company)`) | untouched — see §8 |

**No new service, calculator or engine was created** (Part 12).

---

## 4 — Exact Orderability Flow (after)

```
Finished Product
  → ProductResource::resolveCanCommit()
      ├─ path 1: AvailabilityState::canCommit(signed FG available, FG allow_negative)
      └─ path 2: FINISHED_GOOD && manufacturing_availability === 'instock'
                   → ManufacturingAvailabilityService
                       → Active Recipe → Components → Raw Material
                           → available = on_hand − reserved   (signed, never clamped)
                           → available > 0 OR RM.allow_negative_stock
  → can_commit
      → product-browser.tsx      (already used it)
      → order-inventory-status-card.tsx  ← NOW uses it
```

---

## 5 — How Raw-Material Allow Negative Affects the Final Product

It is the **only** overdraw source consulted. `ManufacturingAvailabilityService` tests
`available_qty > 0 || allow_negative_stock` **per raw material**. If every component is either
available or overdraw-permitted, the recipe is `instock`, so `can_commit = true` and the finished good
stays orderable even at zero FG stock.

The finished good's own `allow_negative_stock` is no longer read by Order Creation. It still
participates inside `AvailabilityState::canCommit()` for path 1 (genuine FG stock), which is correct —
that path is about physical finished-goods inventory, not recipe execution.

**Live proof of the rule with current data:** FG-000001 has `available = 0` and
`allow_negative_stock = 0`, so path 1 fails. Its recipe has 2 components; both RM-000001 and RM-000002
sit at `available = −1` with `allow_negative_stock = 1`, so `manufacturing_availability = 'instock'`
and **path 2 makes `can_commit = true`**. That is CASE 4 and CASE 6 behaving correctly.

---

## 6 — What Happened to `product.stock_status` Usage

Removed from Order Creation's decision entirely. It is no longer read in
`order-inventory-status-card.tsx`; the only remaining occurrences in that file are the two comments
explaining why it must not be used. It remains emitted by `ProductResource` for channel/display
purposes, which is its legitimate role.

---

## 7 — What Happened to the Order Creation Stock Check

| | Before | After |
|---|---|---|
| Blocking test | `stock_status === 'outofstock'` (NULL ⇒ never fired) | `can_commit === false` |
| Overdraw test | FG `allow_negative_stock` | folded into `can_commit` (RM-derived) |
| `shortageItems` | `stock_status === 'outofstock' && !allow_negative_stock` | `can_commit === false` |
| "negative" scenario | inferred from `stock_status` | `can_commit !== false && available_qty <= 0` (signed, canonical) |

All three scenarios (`shortage` / `negative` / normal) are preserved; only their inputs changed to
canonical fields. No arithmetic was added on the client and `can_commit` is never recomputed there.

---

## 8 — Remaining Issue / Scope Note (needs your decision)

**Part 3 and Part 10 ask for a producible *quantity* ("Available To Prepare = 3"), which the surface
used here does not provide.** `ManufacturingAvailabilityService` answers a **boolean-ish presence**
question (`available > 0 || allow_negative`), not "how many units can be produced".

The quantity-aware authority **does already exist** —
`InventoryAvailabilityEngine::analyse($productId, $warehouseId, $requiredQty, $companyId)`, which
computes `qtyToManufacture = max(0, requiredQty − availableFg)`, scales each component by it, and
classifies `Sufficient` / `CanManufacture` / `Partial` / `CannotManufacture` / `NoRecipe`. It is
certified (18/18 in the F4 suite).

I did **not** wire it in, because it requires a **warehouse** and a **requested quantity**, and the
Products list has neither. Doing so would mean either changing the list endpoint's contract or adding a
per-line availability call in Order Creation — a scope expansion that Part 12 says I must stop and
raise with you first.

**So:** the *boolean* orderability contract in your Parts 1–7 is now correct and consistent across
both surfaces. The *quantity* contract in Parts 3 and 10 is not yet wired, and needs your decision on
where the warehouse/quantity context should come from.

**On the reported symptom:** with FG-000001's current data, "orderable" is the **contract-correct**
answer (§5), because both of its raw materials permit overdraw. If the Products page is showing a
literal **"In Stock"** badge, please tell me which column you are looking at — the stock-status badge
renders `products.stock_status`, which is NULL here, so I could not reproduce that label and did not
want to change a display rule speculatively.

---

## 9 — Verification Performed

| Check | Result |
|---|---|
| ESLint (changed file) | **clean** |
| TypeScript — my file | **0 errors** |
| TypeScript — total | **25** (baseline 24) → **+1 NEW, not mine** |
| PHPUnit / runtime | **not run**, as instructed |

The +1 is in `frontend/src/features/orders/components/manual-order-form.tsx`, which is **modified in
the worktree by the concurrent agent** (its last commit is 2026-08-03; the change is uncommitted). The
errors are `TS7053` index-signature failures on a status map — consistent with **ADR-042** adding
`confirmed` to the order lifecycle. Not introduced by this task, and not repaired by it.

## 10 — Manual Browser Verification Steps

1. **Order Creation → add FG-000001.** Expect it to be addable, and the inventory status card to show
   the **negative/overdraw** scenario rather than a hard shortage — because both raw materials allow
   negative stock. Previously the card could not detect anything at all, since `stock_status` is NULL.
2. **Temporarily set RM-000001 `allow_negative_stock = OFF`** (Raw Materials → toggle). FG-000001
   should become **non-committable** (CASE 3) and Order Creation should report a genuine shortage
   naming it. Toggle it back afterwards.
3. **Confirm the raw-material numbers are untouched:** RM-000001 and RM-000002 must still read
   `on_hand 0 · reserved 1 · available −1`. Nothing was clamped.
4. **Confirm no order status changed** — ORD-00005 was not touched.

**Stopped here for your manual review, as instructed.**
